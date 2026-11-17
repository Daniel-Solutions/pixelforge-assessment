<?php

declare(strict_types=1);

use App\Exceptions\InexactAmount;
use App\Money\ProviderCost;
use App\Pixelforge\Data\Rates;
use App\Pixelforge\Data\TokenUsage;

function docRates(): Rates
{
    return new Rates(inputText: '5.00', inputImage: '10.00', output: '40.00', reasoning: '12.50');
}

it('prices each token type at the rate per million', function (TokenUsage $usage, string $cost): void {
    expect(ProviderCost::of($usage, docRates())->toDecimalString())->toBe($cost);
})->with([
    'docs: maximum cost example' => [new TokenUsage(inputText: 115, inputImage: 1_530, output: 6_450, reasoning: 8_000), '0.373875000'],
    'docs: usage example' => [new TokenUsage(inputText: 41, inputImage: 0, output: 3_300, reasoning: 2_950), '0.169080000'],
    'docs: generation example' => [new TokenUsage(inputText: 12, inputImage: 765, output: 6_450, reasoning: 3_184), '0.305510000'],
    'text only' => [new TokenUsage(inputText: 115, inputImage: 0, output: 0, reasoning: 0), '0.000575000'],
    'images only' => [new TokenUsage(inputText: 0, inputImage: 1_530, output: 0, reasoning: 0), '0.015300000'],
    'output only' => [new TokenUsage(inputText: 0, inputImage: 0, output: 6_450, reasoning: 0), '0.258000000'],
    'reasoning only' => [new TokenUsage(inputText: 0, inputImage: 0, output: 0, reasoning: 8_000), '0.100000000'],
    'one odd reasoning token' => [new TokenUsage(inputText: 0, inputImage: 0, output: 0, reasoning: 1), '0.000012500'],
]);

it('uses the rates it is given, not the current price list', function (): void {
    $rates = new Rates(inputText: '2.50', inputImage: '10.00', output: '20.00', reasoning: '12.50');

    expect(ProviderCost::of(new TokenUsage(inputText: 100, inputImage: 0, output: 1_000, reasoning: 0), $rates)->toDecimalString())
        ->toBe('0.020250000');
});

it('refuses to round a cost that does not fit in nanodollars', function (): void {
    $rates = new Rates(inputText: '0.0001', inputImage: '10.00', output: '40.00', reasoning: '12.50');

    ProviderCost::of(new TokenUsage(inputText: 1, inputImage: 0, output: 0, reasoning: 0), $rates);
})->throws(InexactAmount::class);
