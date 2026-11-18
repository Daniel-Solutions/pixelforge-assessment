<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Enums\LedgerEntryType;
use App\Enums\PixelforgeErrorCode;
use App\Enums\StateChange;
use App\Models\Generation;
use App\Money\Nanodollars;
use App\Money\ProviderCost;
use App\Pixelforge\Data\Rates;
use App\Pixelforge\Data\RemoteGeneration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * The only place that moves a generation through Pixelforge's lifecycle. Webhooks, the send job
 * and reconciliation all call it, so the rules live in one place:
 *
 * - the row is locked, so two deliveries of the same state cannot both apply it;
 * - status only moves forward (queued < processing < succeeded | failed), so late events are ignored;
 * - the user is charged only on the move into succeeded, and the ledger's unique (generation_id, type)
 *   index makes a second charge impossible even if this logic had a bug.
 */
class ApplyGenerationState
{
    public function __construct(private RecordLedgerEntry $recordLedgerEntry) {}

    public function handle(Generation $generation, RemoteGeneration $remote): StateChange
    {
        $change = DB::transaction(function () use ($generation, $remote): StateChange {
            $locked = Generation::query()->lockForUpdate()->findOrFail($generation->id);

            if ($locked->pixelforge_id !== null && $locked->pixelforge_id !== $remote->id) {
                Log::error('generation.pixelforge_id_mismatch', ['generation_uuid' => $locked->uuid, 'local' => $locked->pixelforge_id, 'remote' => $remote->id]);

                return StateChange::Unknown;
            }

            $locked->fill([
                'pixelforge_id' => $remote->id,
                'rates' => $locked->rates ?? $remote->rates->toArray(),
                'provider_created_at' => $locked->provider_created_at ?? $remote->createdAt,
                'last_submit_error' => null,
            ]);

            $change = match (true) {
                $this->wasBilledAfterLocalFailure($locked, $remote) => $this->recordCostOnly($locked, $remote),
                $remote->status->rank() <= $locked->status->rank() => StateChange::Stale,
                default => $this->moveForward($locked, $remote),
            };

            $locked->save();

            if ($change === StateChange::Applied && $locked->status === GenerationStatus::Succeeded && $locked->charged_amount !== null) {
                $this->recordLedgerEntry->handle(
                    userId: $locked->user_id,
                    type: LedgerEntryType::GenerationCharge,
                    amount: Nanodollars::zero()->subtract($locked->charged_amount),
                    generation: $locked,
                );
            }

            return $change;
        });

        $generation->refresh();

        return $change;
    }

    /**
     * Only called when the remote status is ahead of ours, so it is processing, succeeded or failed (never queued).
     */
    private function moveForward(Generation $generation, RemoteGeneration $remote): StateChange
    {
        if ($remote->status === GenerationStatus::Succeeded) {
            $this->fillSucceeded($generation, $remote);
        } elseif ($remote->status === GenerationStatus::Failed) {
            $generation->fill([
                'status' => GenerationStatus::Failed,
                'failure_code' => $remote->errorCode,
                'failure_message' => (PixelforgeErrorCode::tryFrom((string) $remote->errorCode) ?? PixelforgeErrorCode::InternalError)->userMessage(),
                'provider_cost' => Nanodollars::zero(),
                'charged_amount' => Nanodollars::zero(),
                'started_at' => $generation->started_at ?? $remote->startedAt,
                'completed_at' => $remote->completedAt ?? now(),
            ]);
        } else {
            $generation->fill([
                'status' => GenerationStatus::Processing,
                'started_at' => $remote->startedAt,
            ]);
        }

        return StateChange::Applied;
    }

    /**
     * A succeeded event for a row we failed locally (before Pixelforge's own failure codes were ever seen),
     * with no provider cost recorded yet, means we gave up on the submit but Pixelforge did create and finish it.
     * This holds whether or not a processing event linked the row first. A failure reported by Pixelforge
     * itself is final there and is never turned into a billed one.
     */
    private function wasBilledAfterLocalFailure(Generation $generation, RemoteGeneration $remote): bool
    {
        return $generation->status === GenerationStatus::Failed
            && ! (PixelforgeErrorCode::tryFrom((string) $generation->failure_code)?->isProviderGenerationFailure() ?? false)
            && $remote->status === GenerationStatus::Succeeded
            && ($generation->provider_cost === null || $generation->provider_cost->amount === 0);
    }

    /**
     * The rates stored when the generation was created; the payload's only if we never stored any.
     */
    private function ratesFor(Generation $generation, RemoteGeneration $remote): Rates
    {
        return $generation->storedRates() ?? $remote->rates;
    }

    private function fillSucceeded(Generation $generation, RemoteGeneration $remote): void
    {
        $usage = $remote->usage ?? throw new LogicException("Succeeded generation {$remote->id} has no usage.");
        $providerCost = ProviderCost::of($usage, $this->ratesFor($generation, $remote));
        $chargedAmount = $providerCost->withMarkup(config()->integer('pixelforge.markup_percent'));

        if ($chargedAmount->amount > $generation->estimated_max->amount) {
            // Pixelforge documents token ranges that keep usage inside our estimate; if it ever reports more,
            // we still charge the real price (cost + markup) but flag it, because the reservation was smaller.
            Log::warning('generation.charged_above_estimate', [
                'generation_uuid' => $generation->uuid,
                'charged' => $chargedAmount->toDecimalString(),
                'estimated_max' => $generation->estimated_max->toDecimalString(),
            ]);
        }

        $generation->fill([
            'status' => GenerationStatus::Succeeded,
            'input_text_tokens' => $usage->inputText,
            'input_image_tokens' => $usage->inputImage,
            'output_tokens' => $usage->output,
            'reasoning_tokens' => $usage->reasoning,
            'provider_cost' => $providerCost,
            'charged_amount' => $chargedAmount,
            'image_url' => $remote->imageUrl,
            'image_url_expires_at' => $remote->imageUrlExpiresAt,
            'started_at' => $generation->started_at ?? $remote->startedAt,
            'completed_at' => $remote->completedAt,
        ]);
    }

    /**
     * We gave up on the submit (timeouts) but Pixelforge had in fact created and finished it.
     * Pixelforge bills it, so we record the provider cost to keep /v1/usage reconciled, but the user
     * already saw a failure and is not charged. The status stays failed: it never moves back.
     */
    private function recordCostOnly(Generation $generation, RemoteGeneration $remote): StateChange
    {
        $usage = $remote->usage ?? throw new LogicException("Succeeded generation {$remote->id} has no usage.");

        $generation->fill([
            'input_text_tokens' => $usage->inputText,
            'input_image_tokens' => $usage->inputImage,
            'output_tokens' => $usage->output,
            'reasoning_tokens' => $usage->reasoning,
            'provider_cost' => ProviderCost::of($usage, $this->ratesFor($generation, $remote)),
            'completed_at' => $remote->completedAt,
        ]);

        Log::warning('generation.billed_after_local_failure', ['generation_uuid' => $generation->uuid, 'pixelforge_id' => $remote->id]);

        return StateChange::BilledAfterLocalFailure;
    }
}
