<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\ApplyGenerationState;
use App\Actions\FailGeneration;
use App\Enums\PixelforgeErrorCode;
use App\Exceptions\PixelforgeException;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * POST /v1/generations for one local generation. Transient errors (overloaded, rate or concurrency
 * limit, timeouts) are retried after Retry-After plus backoff and jitter, always with the same
 * Idempotency-Key, until the deadline. Anything else fails the generation for free.
 */
class SendGenerationToPixelforge implements ShouldQueue
{
    use Queueable;

    /** Retries are bounded by retryUntil(), not by a count. */
    public int $tries = 0;

    /**
     * Seconds before retrying after an unexpected exception (Pixelforge errors pick their own delay).
     * Without it the worker would retry at once and burn the shared 60 requests/minute.
     *
     * @var list<int>
     */
    public array $backoff = [5, 15, 30, 60];

    /** Stop retrying this long after the user pressed Generate. */
    public const int DEADLINE_MINUTES = 10;

    /** Longest wait between two attempts, in seconds. */
    private const int MAX_BACKOFF_SECONDS = 60;

    public function __construct(public Generation $generation) {}

    public function retryUntil(): DateTimeInterface
    {
        return $this->generation->created_at->addMinutes(self::DEADLINE_MINUTES);
    }

    public function handle(PixelforgeClient $pixelforge, ApplyGenerationState $applyGenerationState, FailGeneration $failGeneration): void
    {
        $generation = $this->generation->refresh();

        // Already accepted (an earlier attempt, or a webhook that beat the response) or already final.
        if ($generation->pixelforge_id !== null || $generation->status->isFinal()) {
            return;
        }

        Log::withContext(['generation_uuid' => $generation->uuid, 'attempt' => $this->attempts()]);
        $generation->increment('submit_attempts');

        try {
            $created = $pixelforge->createGeneration(
                $generation->toNewGeneration(config()->string('app.env')),
                $generation->idempotency_key,
            );
        } catch (PixelforgeException $exception) {
            if (! $exception->isRetryable()) {
                $failGeneration->handle($generation, $exception->errorCode);

                return;
            }

            $generation->update(['last_submit_error' => $exception->errorCode]);
            $this->release($this->retryDelay($exception));

            return;
        }

        $applyGenerationState->handle($generation, $created->generation);
    }

    /**
     * Called when the deadline passes (or an unexpected error is thrown on the last attempt).
     */
    public function failed(?Throwable $exception): void
    {
        app(FailGeneration::class)->handle($this->generation, PixelforgeErrorCode::RetriesExhausted);
    }

    /**
     * Wait at least what Pixelforge asked for, growing with each attempt, plus jitter so queued
     * generations do not all retry in the same second.
     */
    private function retryDelay(PixelforgeException $exception): int
    {
        $backoff = min(self::MAX_BACKOFF_SECONDS, 2 ** min($this->attempts(), 6));

        return max($exception->retryAfter ?? 0, $backoff) + random_int(0, 3);
    }
}
