<?php

declare(strict_types=1);

use App\Actions\EstimateGenerationCost;
use App\Enums\Quality;
use App\Enums\Size;
use App\Pixelforge\Data\Pricing;

function docPricing(): Pricing
{
    return Pricing::fromArray(json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/pricing.json'), true));
}

it('estimates the docs example: 400 chars, 2 images, high, 1536x1024', function (): void {
    $estimate = (new EstimateGenerationCost)->handle(str_repeat('a', 400), Size::Landscape, Quality::High, 2, docPricing(), 25);

    expect($estimate->min->inputText)->toBe(85)
        ->and($estimate->max->inputText)->toBe(115)
        ->and($estimate->min->inputImage)->toBe(1_530)
        ->and($estimate->max->inputImage)->toBe(1_530)
        ->and($estimate->min->output)->toBe(6_450)
        ->and($estimate->max->output)->toBe(6_450)
        ->and($estimate->min->reasoning)->toBe(1_000)
        ->and($estimate->max->reasoning)->toBe(8_000)
        ->and($estimate->maxCost->toDecimalString())->toBe('0.373875000')
        ->and($estimate->maxPrice->toDecimalString())->toBe('0.467343750')
        ->and($estimate->minCost->toDecimalString())->toBe('0.286225000')
        ->and($estimate->minPrice->toDecimalString())->toBe('0.357781250');
});

it('estimates text tokens as ceil(chars / 4) with a 15% band', function (string $prompt, int $min, int $max): void {
    $estimate = (new EstimateGenerationCost)->handle($prompt, Size::Square, Quality::Low, 0, docPricing(), 25);

    expect([$estimate->min->inputText, $estimate->max->inputText])->toBe([$min, $max]);
})->with([
    '1 char -> e=1' => ['a', 0, 2],
    '4 chars -> e=1' => ['abcd', 0, 2],
    '5 chars -> e=2' => ['abcde', 1, 3],
    '40 chars -> e=10' => [str_repeat('x', 40), 8, 12],
    '4000 chars -> e=1000' => [str_repeat('x', 4000), 850, 1150],
]);

it('counts multibyte characters as one character each', function (): void {
    $prompt = str_repeat('ação👍', 8);

    expect(mb_strlen($prompt))->toBe(40)
        ->and(strlen($prompt))->toBeGreaterThan(40);

    $estimate = (new EstimateGenerationCost)->handle($prompt, Size::Square, Quality::Low, 0, docPricing(), 25);

    expect([$estimate->min->inputText, $estimate->max->inputText])->toBe([8, 12]);
});

it('takes output and reasoning tokens from the pricing tables', function (Size $size, Quality $quality, int $output, int $reasoningMin, int $reasoningMax): void {
    $estimate = (new EstimateGenerationCost)->handle('a', $size, $quality, 0, docPricing(), 25);

    expect($estimate->min->output)->toBe($output)
        ->and($estimate->max->output)->toBe($output)
        ->and($estimate->min->reasoning)->toBe($reasoningMin)
        ->and($estimate->max->reasoning)->toBe($reasoningMax);
})->with([
    [Size::Square, Quality::Low, 280, 0, 500],
    [Size::Portrait, Quality::Medium, 1_650, 200, 2_000],
    [Size::Square, Quality::High, 4_300, 1_000, 8_000],
]);

it('matches the vectors shared with the frontend estimate', function (array $vector): void {
    $estimate = (new EstimateGenerationCost)->handle(
        str_repeat($vector['prompt'], $vector['repeat']),
        Size::from($vector['size']),
        Quality::from($vector['quality']),
        $vector['reference_images'],
        docPricing(),
        25,
    );

    expect($estimate->min->toArray())->toBe($vector['min_tokens'])
        ->and($estimate->max->toArray())->toBe($vector['max_tokens'])
        ->and($estimate->minPrice->toDecimalString())->toBe($vector['min_price'])
        ->and($estimate->maxPrice->toDecimalString())->toBe($vector['max_price']);
})->with(fn (): array => collect(json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/estimate-vectors.json'), true))
    ->mapWithKeys(fn (array $vector): array => [$vector['name'] => [$vector]])
    ->all());
