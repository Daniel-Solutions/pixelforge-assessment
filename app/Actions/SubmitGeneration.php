<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\GenerationInput;
use App\Jobs\SendGenerationToPixelforge;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records the generation locally with its estimate, then sends it to Pixelforge from a queued job.
 * The row exists before any network call, so every attempt reuses the same Idempotency-Key.
 */
class SubmitGeneration
{
    public function __construct(
        private PixelforgeClient $pixelforge,
        private EstimateGenerationCost $estimateGenerationCost,
    ) {}

    public function handle(User $user, GenerationInput $input): Generation
    {
        $estimate = $this->estimateGenerationCost->handle(
            prompt: $input->prompt,
            size: $input->size,
            quality: $input->quality,
            referenceImageCount: count($input->referenceImages),
            pricing: $this->pixelforge->getPricing(),
            markupPercent: config()->integer('pixelforge.markup_percent'),
        );

        return DB::transaction(function () use ($user, $input, $estimate): Generation {
            $uuid = (string) Str::uuid();

            $generation = $user->generations()->create([
                'uuid' => $uuid,
                'idempotency_key' => $uuid,
                'prompt' => $input->prompt,
                'size' => $input->size,
                'quality' => $input->quality,
                'reference_images' => $input->referenceImages,
                'estimated_min' => $estimate->minPrice,
                'estimated_max' => $estimate->maxPrice,
                'estimated_tokens' => ['min' => $estimate->min->toArray(), 'max' => $estimate->max->toArray()],
            ]);

            SendGenerationToPixelforge::dispatch($generation)->afterCommit();

            return $generation;
        });
    }
}
