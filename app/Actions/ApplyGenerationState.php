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

            $wasAcceptedBefore = $locked->pixelforge_id !== null;

            $locked->fill([
                'pixelforge_id' => $remote->id,
                'rates' => $locked->rates ?? $remote->rates->toArray(),
                'provider_created_at' => $locked->provider_created_at ?? $remote->createdAt,
                'last_submit_error' => null,
            ]);

            $change = match (true) {
                ! $wasAcceptedBefore && $locked->status === GenerationStatus::Failed && $remote->status === GenerationStatus::Succeeded => $this->recordCostOnly($locked, $remote),
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

    private function moveForward(Generation $generation, RemoteGeneration $remote): StateChange
    {
        match ($remote->status) {
            GenerationStatus::Processing => $generation->fill([
                'status' => GenerationStatus::Processing,
                'started_at' => $remote->startedAt,
            ]),
            GenerationStatus::Succeeded => $this->fillSucceeded($generation, $remote),
            GenerationStatus::Failed => $generation->fill([
                'status' => GenerationStatus::Failed,
                'failure_code' => $remote->errorCode,
                'failure_message' => (PixelforgeErrorCode::tryFrom((string) $remote->errorCode) ?? PixelforgeErrorCode::InternalError)->userMessage(),
                'provider_cost' => Nanodollars::zero(),
                'charged_amount' => Nanodollars::zero(),
                'started_at' => $generation->started_at ?? $remote->startedAt,
                'completed_at' => $remote->completedAt ?? now(),
            ]),
            GenerationStatus::Queued => throw new LogicException('Queued is never ahead of a stored status.'),
        };

        return StateChange::Applied;
    }

    private function fillSucceeded(Generation $generation, RemoteGeneration $remote): void
    {
        $usage = $remote->usage ?? throw new LogicException("Succeeded generation {$remote->id} has no usage.");
        $providerCost = ProviderCost::of($usage, $remote->rates);

        $generation->fill([
            'status' => GenerationStatus::Succeeded,
            'input_text_tokens' => $usage->inputText,
            'input_image_tokens' => $usage->inputImage,
            'output_tokens' => $usage->output,
            'reasoning_tokens' => $usage->reasoning,
            'provider_cost' => $providerCost,
            'charged_amount' => $providerCost->withMarkup(config()->integer('pixelforge.markup_percent')),
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
            'provider_cost' => ProviderCost::of($usage, $remote->rates),
            'completed_at' => $remote->completedAt,
        ]);

        Log::warning('generation.billed_after_local_failure', ['generation_uuid' => $generation->uuid, 'pixelforge_id' => $remote->id]);

        return StateChange::BilledAfterLocalFailure;
    }
}
