<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\GenerationStatus;
use App\Enums\PixelforgeErrorCode;
use App\Models\Generation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the user sees of a generation: their price, never our provider cost.
 *
 * @mixin Generation
 */
class GenerationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $usage = $this->resource->usage();

        return [
            'id' => $this->uuid,
            'prompt' => $this->prompt,
            'size' => $this->size->value,
            'size_label' => $this->size->label(),
            'quality' => $this->quality->value,
            'quality_label' => $this->quality->label(),
            'reference_images' => $this->reference_images,
            'status' => $this->status->value,
            'is_final' => $this->status->isFinal(),
            'status_detail' => $this->statusDetail(),
            'image_url' => $this->resource->imageHasExpired() ? null : $this->image_url,
            'image_expired' => $this->resource->imageHasExpired(),
            'estimate' => [
                'min' => $this->estimated_min->forDisplay(),
                'max' => $this->estimated_max->forDisplay(),
            ],
            'charged' => $this->charged_amount?->forDisplay(),
            'was_charged' => $this->status === GenerationStatus::Succeeded,
            'failure_message' => $this->status === GenerationStatus::Failed ? $this->failure_message : null,
            'can_retry' => $this->status === GenerationStatus::Failed,
            'tokens' => [
                'estimated_min' => $this->estimated_tokens['min'],
                'estimated_max' => $this->estimated_tokens['max'],
                'actual' => $this->status === GenerationStatus::Succeeded ? $usage?->toArray() : null,
            ],
            'created_at' => $this->created_at->toIso8601ZuluString(),
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
            'image_url_expires_at' => $this->image_url_expires_at?->toIso8601ZuluString(),
        ];
    }

    private function statusDetail(): string
    {
        return match ($this->status) {
            GenerationStatus::Queued => match (true) {
                $this->pixelforge_id !== null => 'Queued at the provider.',
                $this->last_submit_error === PixelforgeErrorCode::ConcurrencyLimitExceeded => 'Waiting for a free slot at the provider.',
                $this->last_submit_error !== null => 'The provider is busy. Retrying automatically.',
                default => 'Sending to the provider.',
            },
            GenerationStatus::Processing => 'Generating your image.',
            GenerationStatus::Succeeded => 'Ready.',
            GenerationStatus::Failed => (string) $this->failure_message,
        };
    }
}
