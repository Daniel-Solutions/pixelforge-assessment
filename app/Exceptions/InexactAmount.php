<?php

declare(strict_types=1);

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when an amount cannot be represented exactly in nanodollars.
 * We never round a provider amount silently.
 */
final class InexactAmount extends InvalidArgumentException
{
    public static function for(string $value): self
    {
        return new self("Amount [{$value}] is not an exact number of nanodollars.");
    }
}
