import { describe, expect, it } from 'vite-plus/test';
import pricingFixture from '../../../tests/Fixtures/pricing.json';
import vectors from '../../../tests/Fixtures/estimate-vectors.json';
import { characterCount, estimate } from '@/lib/estimate';
import { toDecimalString } from '@/lib/money';
import type { Pricing, Quality, Size } from '@/types';

const pricing: Pricing = {
    ...pricingFixture,
    tokens: {
        ...pricingFixture.tokens,
        text_token_variance: String(pricingFixture.tokens.text_token_variance),
    },
};

describe('estimate', () => {
    it('counts Unicode characters like mb_strlen', () => {
        expect(characterCount('ação👍')).toBe(5);
    });

    it.each(vectors)('$name', (vector) => {
        const result = estimate(
            vector.prompt.repeat(vector.repeat),
            vector.size as Size,
            vector.quality as Quality,
            vector.reference_images,
            pricing,
            25,
        );

        expect(result.min).toEqual(vector.min_tokens);
        expect(result.max).toEqual(vector.max_tokens);
        expect(toDecimalString(result.minPrice)).toBe(vector.min_price);
        expect(toDecimalString(result.maxPrice)).toBe(vector.max_price);
    });
});
