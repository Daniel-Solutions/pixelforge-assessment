<?php

declare(strict_types=1);

namespace App\Pixelforge\Data;

use App\Enums\Quality;
use App\Enums\Size;

/**
 * The body of POST /v1/generations. Pixelforge rejects unknown fields with 422, so this sends only documented ones.
 */
final readonly class NewGeneration
{
    /**
     * @param  list<string>  $referenceImages
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public string $prompt,
        public Size $size,
        public Quality $quality,
        public array $referenceImages,
        public array $metadata,
    ) {}

    /**
     * @return array{prompt: string, size: string, quality: string, reference_images?: list<string>, metadata?: array<string, string>}
     */
    public function toArray(): array
    {
        return array_filter([
            'prompt' => $this->prompt,
            'size' => $this->size->value,
            'quality' => $this->quality->value,
            'reference_images' => $this->referenceImages,
            'metadata' => $this->metadata,
        ], fn (mixed $value): bool => $value !== []);
    }
}
