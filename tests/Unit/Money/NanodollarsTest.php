<?php

declare(strict_types=1);

use App\Exceptions\InexactAmount;
use App\Money\Nanodollars;

it('parses exact decimal strings without floats', function (string $decimal, int $nanodollars): void {
    expect(Nanodollars::fromDecimalString($decimal)->amount)->toBe($nanodollars);
})->with([
    'nine places' => ['0.373875000', 373_875_000],
    'two places' => ['12.50', 12_500_000_000],
    'integer' => ['5', 5_000_000_000],
    'one nanodollar' => ['0.000000001', 1],
    'zero' => ['0.000000000', 0],
]);

it('refuses amounts finer than a nanodollar instead of rounding them', function (): void {
    Nanodollars::fromDecimalString('0.0000000001');
})->throws(InexactAmount::class);

it('refuses strings that are not decimals', function (): void {
    Nanodollars::fromDecimalString('1e-9');
})->throws(InexactAmount::class);

it('prints nine decimal places', function (int $nanodollars, string $decimal): void {
    expect(Nanodollars::of($nanodollars)->toDecimalString())->toBe($decimal);
})->with([
    [373_875_000, '0.373875000'],
    [12_500_000_000, '12.500000000'],
    [1, '0.000000001'],
    [0, '0.000000000'],
    [-467_343_750, '-0.467343750'],
]);

it('adds, subtracts and multiplies by integers', function (): void {
    $a = Nanodollars::of(300);
    $b = Nanodollars::of(120);

    expect($a->add($b)->amount)->toBe(420)
        ->and($a->subtract($b)->amount)->toBe(180)
        ->and($b->subtract($a)->amount)->toBe(-180)
        ->and($a->multiply(3)->amount)->toBe(900)
        ->and($a->amount)->toBe(300);
});

it('applies a 25% markup rounding half up to the nanodollar', function (int $cost, int $price): void {
    expect(Nanodollars::of($cost)->withMarkup(25)->amount)->toBe($price);
})->with([
    'doc example, exact' => [373_875_000, 467_343_750],
    '1 -> 1.25 rounds down' => [1, 1],
    '2 -> 2.5 rounds up' => [2, 3],
    '3 -> 3.75 rounds up' => [3, 4],
    '6 -> 7.5 rounds up' => [6, 8],
    '7 -> 8.75 rounds up' => [7, 9],
    'zero' => [0, 0],
]);

it('formats for display with four places, half up', function (int $nanodollars, string $formatted): void {
    expect(Nanodollars::of($nanodollars)->format())->toBe($formatted);
})->with([
    [467_343_750, '$0.4673'],
    [2_150_000, '$0.0022'],
    [49_999, '$0.0000'],
    [50_000, '$0.0001'],
    [5_000_000_000, '$5.0000'],
    [-1_000_000_000, '-$1.0000'],
]);

it('formats with any number of places', function (): void {
    expect(Nanodollars::of(467_343_750)->format(9))->toBe('$0.467343750')
        ->and(Nanodollars::of(467_343_750)->format(2))->toBe('$0.47');
});
