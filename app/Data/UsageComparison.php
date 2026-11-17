<?php

declare(strict_types=1);

namespace App\Data;

use App\Money\Nanodollars;
use App\Pixelforge\Data\TokenUsage;

/**
 * One UTC day: what we recorded as provider cost next to what Pixelforge billed.
 */
final readonly class UsageComparison
{
    public function __construct(
        public string $date,
        public int $ourGenerations,
        public TokenUsage $ourTokens,
        public Nanodollars $ourCost,
        public int $pixelforgeGenerations,
        public TokenUsage $pixelforgeTokens,
        public Nanodollars $pixelforgeCost,
    ) {}

    public function costDifference(): Nanodollars
    {
        return $this->ourCost->subtract($this->pixelforgeCost);
    }

    public function matches(): bool
    {
        return $this->ourGenerations === $this->pixelforgeGenerations
            && $this->ourTokens == $this->pixelforgeTokens
            && $this->ourCost->amount === $this->pixelforgeCost->amount;
    }
}
