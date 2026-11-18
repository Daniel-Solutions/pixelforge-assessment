<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Money\Nanodollars;
use RuntimeException;

/**
 * The user's available balance does not cover the most this generation can cost.
 */
final class InsufficientCredits extends RuntimeException
{
    public function __construct(public readonly Nanodollars $required, public readonly Nanodollars $available)
    {
        parent::__construct("Not enough credits: this generation can cost up to {$required->format()}.");
    }
}
