<?php

declare(strict_types=1);

namespace App\Money;

use App\Exceptions\InexactAmount;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

/**
 * An immutable USD amount stored as an integer number of nanodollars (1 USD = 1,000,000,000).
 * It matches the 9 decimal places Pixelforge uses, so provider amounts are exact.
 */
final readonly class Nanodollars
{
    public const int PER_DOLLAR = 1_000_000_000;

    private const int DECIMALS = 9;

    private function __construct(public int $amount) {}

    public static function of(int $amount): self
    {
        return new self($amount);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Parses an exact decimal USD string such as "0.373875000".
     */
    public static function fromDecimalString(string $usd): self
    {
        if (preg_match('/^-?\d+(\.\d+)?$/', $usd) !== 1) {
            throw InexactAmount::for($usd);
        }

        return self::fromBigDecimal(BigDecimal::of($usd));
    }

    /**
     * Converts a USD amount to nanodollars, refusing anything finer than one nanodollar.
     */
    public static function fromBigDecimal(BigDecimal $usd): self
    {
        try {
            return new self($usd->withPointMovedRight(self::DECIMALS)->toBigInteger()->toInt());
        } catch (MathException) {
            throw InexactAmount::for($usd->toString());
        }
    }

    public function add(self $other): self
    {
        return new self($this->amount + $other->amount);
    }

    public function subtract(self $other): self
    {
        return new self($this->amount - $other->amount);
    }

    public function multiply(int $factor): self
    {
        return new self($this->amount * $factor);
    }

    /**
     * Adds a percentage markup, rounding half up to the nanodollar.
     * For 25% this is intdiv(amount * 125 + 50, 100), the same as intdiv(amount * 5 + 2, 4).
     */
    public function withMarkup(int $percent): self
    {
        return new self(self::divideHalfUp($this->amount * (100 + $percent), 100));
    }

    /**
     * The exact amount with 9 decimal places, e.g. "0.467343750".
     */
    public function toDecimalString(): string
    {
        return self::decimal($this->amount, self::DECIMALS);
    }

    /**
     * Display string such as "$0.4673". Display only: never parse it back into money.
     */
    public function format(int $decimals = 4): string
    {
        $scaled = self::divideHalfUp(abs($this->amount), 10 ** (self::DECIMALS - $decimals));

        return ($this->amount < 0 ? '-' : '').'$'.self::decimal($scaled, $decimals);
    }

    /**
     * For Resources: the rounded display string and the exact 9-place amount (shown in a tooltip).
     *
     * @return array{display: string, exact: string}
     */
    public function forDisplay(int $decimals = 4): array
    {
        return ['display' => $this->format($decimals), 'exact' => $this->toDecimalString()];
    }

    private static function divideHalfUp(int $value, int $divisor): int
    {
        $half = intdiv($divisor, 2);

        return $value >= 0
            ? intdiv($value + $half, $divisor)
            : -intdiv(-$value + $half, $divisor);
    }

    private static function decimal(int $value, int $decimals): string
    {
        $digits = str_pad((string) abs($value), $decimals + 1, '0', STR_PAD_LEFT);
        $whole = substr($digits, 0, -$decimals ?: null);
        $fraction = $decimals > 0 ? '.'.substr($digits, -$decimals) : '';

        return ($value < 0 ? '-' : '').$whole.$fraction;
    }
}
