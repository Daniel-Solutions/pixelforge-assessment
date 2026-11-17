<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\Quality;
use App\Enums\Size;

/**
 * What the user asked for, built only from validated request data.
 */
final readonly class GenerationInput
{
    /**
     * @param  list<string>  $referenceImages
     */
    public function __construct(
        public string $prompt,
        public Size $size,
        public Quality $quality,
        public array $referenceImages,
    ) {}
}
