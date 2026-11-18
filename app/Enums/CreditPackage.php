<?php

declare(strict_types=1);

namespace App\Enums;

use App\Money\Nanodollars;

/**
 * Fixed credit packages, in whole US dollars. Purchases are fake: no payment provider.
 */
enum CreditPackage: int
{
    case Five = 5;
    case Ten = 10;
    case TwentyFive = 25;

    public function amount(): Nanodollars
    {
        return Nanodollars::of($this->value * Nanodollars::PER_DOLLAR);
    }
}
