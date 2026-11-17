<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Quality;
use App\Enums\Size;
use App\Money\CostEstimate;
use App\Money\ProviderCost;
use App\Pixelforge\Data\Pricing;
use App\Pixelforge\Data\TokenUsage;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The single source of truth for estimates. resources/js/lib/estimate.ts mirrors it for live
 * feedback and shares its test vectors, but the stored estimate always comes from here.
 */
class EstimateGenerationCost
{
    public function handle(
        string $prompt,
        Size $size,
        Quality $quality,
        int $referenceImageCount,
        Pricing $pricing,
        int $markupPercent,
    ): CostEstimate {
        $characters = mb_strlen($prompt);
        $expectedTextTokens = intdiv($characters + $pricing->charactersPerTextToken - 1, $pricing->charactersPerTextToken);
        $variance = BigDecimal::of($pricing->textTokenVariance);

        $imageTokens = $referenceImageCount * $pricing->tokensPerReferenceImage;
        $outputTokens = $pricing->outputTokens($size, $quality);
        $reasoning = $pricing->reasoningTokens($quality);

        $min = new TokenUsage(
            inputText: BigDecimal::of($expectedTextTokens)->multipliedBy(BigDecimal::of(1)->minus($variance))->toScale(0, RoundingMode::Floor)->toInt(),
            inputImage: $imageTokens,
            output: $outputTokens,
            reasoning: $reasoning['min'],
        );
        $max = new TokenUsage(
            inputText: BigDecimal::of($expectedTextTokens)->multipliedBy(BigDecimal::of(1)->plus($variance))->toScale(0, RoundingMode::Ceiling)->toInt(),
            inputImage: $imageTokens,
            output: $outputTokens,
            reasoning: $reasoning['max'],
        );

        $minCost = ProviderCost::of($min, $pricing->rates);
        $maxCost = ProviderCost::of($max, $pricing->rates);

        return new CostEstimate(
            min: $min,
            max: $max,
            minCost: $minCost,
            maxCost: $maxCost,
            minPrice: $minCost->withMarkup($markupPercent),
            maxPrice: $maxCost->withMarkup($markupPercent),
        );
    }
}
