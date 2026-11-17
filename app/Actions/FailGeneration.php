<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Enums\PixelforgeErrorCode;
use App\Models\Generation;
use App\Money\Nanodollars;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fails a generation that Pixelforge never accepted (rejected on create, or our retries ran out). Always free.
 */
class FailGeneration
{
    public function handle(Generation $generation, PixelforgeErrorCode $code): void
    {
        DB::transaction(function () use ($generation, $code): void {
            $locked = Generation::query()->lockForUpdate()->findOrFail($generation->id);

            if ($locked->status->isFinal() || $locked->pixelforge_id !== null) {
                return;
            }

            $locked->update([
                'status' => GenerationStatus::Failed,
                'failure_code' => $code->value,
                'failure_message' => $code->userMessage(),
                'provider_cost' => Nanodollars::zero(),
                'charged_amount' => Nanodollars::zero(),
                'completed_at' => now(),
            ]);
        });

        Log::log($code->isOurBug() ? 'critical' : 'warning', 'generation.failed_before_provider', [
            'generation_uuid' => $generation->uuid,
            'code' => $code->value,
        ]);

        $generation->refresh();
    }
}
