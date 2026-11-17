<?php

declare(strict_types=1);

namespace App\Money;

use App\Pixelforge\Data\Rates;
use App\Pixelforge\Data\TokenUsage;
use Brick\Math\BigDecimal;

/**
 * What Pixelforge charges: the sum over token types of tokens × rate ÷ 1,000,000, never rounded.
 */
final class ProviderCost
{
    /** Rates are per 1,000,000 tokens. */
    private const int RATE_UNIT_DECIMALS = 6;

    public static function of(TokenUsage $usage, Rates $rates): Nanodollars
    {
        $perMillion = BigDecimal::of($rates->inputText)->multipliedBy($usage->inputText)
            ->plus(BigDecimal::of($rates->inputImage)->multipliedBy($usage->inputImage))
            ->plus(BigDecimal::of($rates->output)->multipliedBy($usage->output))
            ->plus(BigDecimal::of($rates->reasoning)->multipliedBy($usage->reasoning));

        return Nanodollars::fromBigDecimal($perMillion->withPointMovedLeft(self::RATE_UNIT_DECIMALS));
    }
}
