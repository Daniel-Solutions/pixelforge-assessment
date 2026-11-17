import { describe, expect, it } from 'vite-plus/test';
import {
    formatUsd,
    parseUsd,
    providerCost,
    toDecimalString,
    withMarkup,
} from '@/lib/money';
import type { Rates } from '@/types';

const rates: Rates = {
    input_text_tokens: '5.00',
    input_image_tokens: '10.00',
    output_tokens: '40.00',
    reasoning_tokens: '12.50',
};

describe('money', () => {
    it('parses and prints exact decimals', () => {
        expect(parseUsd('0.373875000')).toBe(373_875_000n);
        expect(parseUsd('12.50')).toBe(12_500_000_000n);
        expect(toDecimalString(1n)).toBe('0.000000001');
        expect(toDecimalString(-467_343_750n)).toBe('-0.467343750');
        expect(() => parseUsd('0.0000000001')).toThrow();
        expect(() => parseUsd('1e-9')).toThrow();
    });

    it('prices the docs examples exactly', () => {
        expect(
            toDecimalString(
                providerCost(
                    {
                        input_text_tokens: 115,
                        input_image_tokens: 1530,
                        output_tokens: 6450,
                        reasoning_tokens: 8000,
                    },
                    rates,
                ),
            ),
        ).toBe('0.373875000');
        expect(
            toDecimalString(
                providerCost(
                    {
                        input_text_tokens: 41,
                        input_image_tokens: 0,
                        output_tokens: 3300,
                        reasoning_tokens: 2950,
                    },
                    rates,
                ),
            ),
        ).toBe('0.169080000');
        expect(
            toDecimalString(
                providerCost(
                    {
                        input_text_tokens: 12,
                        input_image_tokens: 765,
                        output_tokens: 6450,
                        reasoning_tokens: 3184,
                    },
                    rates,
                ),
            ),
        ).toBe('0.305510000');
    });

    it('rounds the 25% markup half up to the nanodollar', () => {
        expect(withMarkup(373_875_000n, 25)).toBe(467_343_750n);
        expect(withMarkup(1n, 25)).toBe(1n);
        expect(withMarkup(2n, 25)).toBe(3n);
        expect(withMarkup(3n, 25)).toBe(4n);
        expect(withMarkup(6n, 25)).toBe(8n);
    });

    it('formats for display with four places', () => {
        expect(formatUsd(467_343_750n)).toBe('$0.4673');
        expect(formatUsd(49_999n)).toBe('$0.0000');
        expect(formatUsd(50_000n)).toBe('$0.0001');
        expect(formatUsd(467_343_750n, 9)).toBe('$0.467343750');
        expect(formatUsd(-1_000_000_000n)).toBe('-$1.0000');
    });
});
