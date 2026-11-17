<?php

declare(strict_types=1);

namespace App\Pixelforge\Data;

final readonly class CreatedGeneration
{
    public function __construct(
        public Generation $generation,
        /** True when Pixelforge answered with Idempotent-Replayed: an earlier attempt already created it. */
        public bool $replayed,
    ) {}
}
