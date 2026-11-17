<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\ApplyGenerationState;
use App\Enums\PixelforgeErrorCode;
use App\Exceptions\PixelforgeException;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Webhooks are not guaranteed. Every minute, poll Pixelforge for generations it accepted that are
 * still pending after a while and apply what it says, through the same ApplyGenerationState.
 */
class ReconcilePendingGenerations implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Normal runs take 5-30 s, [slow] up to 90 s, plus queue time: older than this is overdue. */
    public const int STALE_AFTER_SECONDS = 120;

    /** Stay well under the 60 requests per minute shared with sending. */
    public const int MAX_PER_RUN = 20;

    public int $uniqueFor = 120;

    public function handle(PixelforgeClient $pixelforge, ApplyGenerationState $applyGenerationState): void
    {
        $overdue = Generation::query()
            ->pending()
            ->whereNotNull('pixelforge_id')
            ->where('provider_created_at', '<=', now()->subSeconds(self::STALE_AFTER_SECONDS))
            ->oldest('provider_created_at')
            ->limit(self::MAX_PER_RUN)
            ->get();

        foreach ($overdue as $generation) {
            try {
                $result = $applyGenerationState->handle($generation, $pixelforge->getGeneration((string) $generation->pixelforge_id));
            } catch (PixelforgeException $exception) {
                Log::warning('pixelforge.reconcile.error', ['generation_uuid' => $generation->uuid, 'code' => $exception->errorCode->value]);

                if (in_array($exception->errorCode, [PixelforgeErrorCode::RateLimitExceeded, PixelforgeErrorCode::InvalidApiKey], true)) {
                    return;
                }

                continue;
            }

            Log::info('pixelforge.reconcile', ['generation_uuid' => $generation->uuid, 'result' => $result->value]);
        }
    }
}
