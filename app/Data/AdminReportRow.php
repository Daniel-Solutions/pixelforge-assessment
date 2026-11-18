<?php

declare(strict_types=1);

namespace App\Data;

use App\Money\Nanodollars;

/**
 * Sums for one group (a user on a day, a day, a user, or everything). Profit is derived, never stored.
 */
final readonly class AdminReportRow
{
    public function __construct(
        public int $succeeded,
        public int $failed,
        public Nanodollars $charged,
        public Nanodollars $providerCost,
    ) {}

    public static function empty(): self
    {
        return new self(0, 0, Nanodollars::zero(), Nanodollars::zero());
    }

    public function plus(self $other): self
    {
        return new self(
            $this->succeeded + $other->succeeded,
            $this->failed + $other->failed,
            $this->charged->add($other->charged),
            $this->providerCost->add($other->providerCost),
        );
    }

    public function profit(): Nanodollars
    {
        return $this->charged->subtract($this->providerCost);
    }

    /**
     * @return array{succeeded: int, failed: int, charged: array{display: string, exact: string}, provider_cost: array{display: string, exact: string}, profit: array{display: string, exact: string}}
     */
    public function toArray(): array
    {
        return [
            'succeeded' => $this->succeeded,
            'failed' => $this->failed,
            'charged' => $this->charged->forDisplay(9),
            'provider_cost' => $this->providerCost->forDisplay(9),
            'profit' => $this->profit()->forDisplay(9),
        ];
    }
}
