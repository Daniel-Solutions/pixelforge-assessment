/**
 * Live estimate for the Generate form. Mirrors app/Actions/EstimateGenerationCost.php and
 * shares its test vectors (tests/Fixtures/estimate-vectors.json). The stored estimate
 * always comes from the backend.
 */
import { providerCost, withMarkup } from '@/lib/money';
import type { Pricing, Quality, Size, TokenCounts } from '@/types';

export type Estimate = {
    min: TokenCounts;
    max: TokenCounts;
    minPrice: bigint;
    maxPrice: bigint;
};

/** Unicode characters, like PHP's mb_strlen (code points, not UTF-16 units). */
export function characterCount(text: string): number {
    return Array.from(text).length;
}

/** Splits a decimal string ("0.15") into an integer fraction: 15 / 100. */
function fraction(value: string): { numerator: bigint; denominator: bigint } {
    const [whole, decimals = ''] = value.split('.');
    const denominator = 10n ** BigInt(decimals.length);

    return {
        numerator: BigInt(whole) * denominator + BigInt(decimals || '0'),
        denominator,
    };
}

function ceilDiv(value: bigint, divisor: bigint): bigint {
    return (value + divisor - 1n) / divisor;
}

export function estimate(
    prompt: string,
    size: Size,
    quality: Quality,
    referenceImageCount: number,
    pricing: Pricing,
    markupPercent: number,
): Estimate {
    const tokens = pricing.tokens;
    const expected = ceilDiv(
        BigInt(characterCount(prompt)),
        BigInt(tokens.characters_per_text_token),
    );
    const variance = fraction(tokens.text_token_variance);
    const imageTokens = referenceImageCount * tokens.per_reference_image;
    const outputTokens = tokens.output[quality][size];
    const reasoning = tokens.reasoning[quality];

    const min: TokenCounts = {
        input_text_tokens: Number(
            (expected * (variance.denominator - variance.numerator)) /
                variance.denominator,
        ),
        input_image_tokens: imageTokens,
        output_tokens: outputTokens,
        reasoning_tokens: reasoning.min,
    };
    const max: TokenCounts = {
        input_text_tokens: Number(
            ceilDiv(
                expected * (variance.denominator + variance.numerator),
                variance.denominator,
            ),
        ),
        input_image_tokens: imageTokens,
        output_tokens: outputTokens,
        reasoning_tokens: reasoning.max,
    };

    return {
        min,
        max,
        minPrice: withMarkup(providerCost(min, pricing.rates), markupPercent),
        maxPrice: withMarkup(providerCost(max, pricing.rates), markupPercent),
    };
}
