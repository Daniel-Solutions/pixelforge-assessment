<?php

declare(strict_types=1);

namespace App\Pixelforge\Data;

use App\Money\Nanodollars;

/**
 * One UTC day of GET /v1/usage: generations that completed successfully that day.
 */
final readonly class UsageDay
{
    public function __construct(
        public string $date,
        public int $generations,
        public TokenUsage $tokens,
        public Nanodollars $cost,
    ) {}

    /**
     * @param  array{date: string, generations: int, input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int, cost_usd: string}  $day
     */
    public static function fromArray(array $day): self
    {
        return new self(
            date: $day['date'],
            generations: $day['generations'],
            tokens: TokenUsage::fromArray($day),
            cost: Nanodollars::fromDecimalString($day['cost_usd']),
        );
    }
}
