<?php

declare(strict_types=1);

namespace App\Money;

use App\Pixelforge\Data\TokenUsage;

/**
 * The cheapest and the most expensive outcome of a generation, in tokens, provider cost and user price.
 */
final readonly class CostEstimate
{
    public function __construct(
        public TokenUsage $min,
        public TokenUsage $max,
        public Nanodollars $minCost,
        public Nanodollars $maxCost,
        public Nanodollars $minPrice,
        public Nanodollars $maxPrice,
    ) {}
}
