/**
 * Money in the browser mirrors app/Money/Nanodollars.php: integer nanodollars as BigInt,
 * never floats. 1 USD = 1_000_000_000 nanodollars.
 */
import type { Rates, TokenCounts } from '@/types';

const DECIMALS = 9;
const PER_DOLLAR = 10n ** BigInt(DECIMALS);
const RATE_UNIT = 1_000_000n;

/** Parses an exact decimal USD string ("0.467343750") into nanodollars. */
export function parseUsd(usd: string): bigint {
    const match = /^(-?)(\d+)(?:\.(\d+))?$/.exec(usd);

    if (match === null) {
        throw new Error(`"${usd}" is not a decimal amount.`);
    }

    const [, sign, whole, fraction = ''] = match;

    if (fraction.length > DECIMALS && /[^0]/.test(fraction.slice(DECIMALS))) {
        throw new Error(`"${usd}" is finer than a nanodollar.`);
    }

    const nanodollars =
        BigInt(whole) * PER_DOLLAR +
        BigInt(fraction.slice(0, DECIMALS).padEnd(DECIMALS, '0'));

    return sign === '-' ? -nanodollars : nanodollars;
}

function divideHalfUp(value: bigint, divisor: bigint): bigint {
    const half = divisor / 2n;

    return value >= 0n
        ? (value + half) / divisor
        : -((-value + half) / divisor);
}

function decimal(value: bigint, decimals: number): string {
    const negative = value < 0n;
    const digits = (negative ? -value : value)
        .toString()
        .padStart(decimals + 1, '0');
    const whole = decimals > 0 ? digits.slice(0, -decimals) : digits;
    const fraction = decimals > 0 ? `.${digits.slice(-decimals)}` : '';

    return `${negative ? '-' : ''}${whole}${fraction}`;
}

/** The exact amount with nine decimal places. */
export function toDecimalString(nanodollars: bigint): string {
    return decimal(nanodollars, DECIMALS);
}

/** Display only ("$0.4673"). Never parse the result back into money. */
export function formatUsd(nanodollars: bigint, decimals = 4): string {
    const absolute = nanodollars < 0n ? -nanodollars : nanodollars;
    const scaled = divideHalfUp(absolute, 10n ** BigInt(DECIMALS - decimals));

    return `${nanodollars < 0n ? '-' : ''}$${decimal(scaled, decimals)}`;
}

/** Adds a percentage markup, rounding half up to the nanodollar. */
export function withMarkup(nanodollars: bigint, percent: number): bigint {
    return divideHalfUp(nanodollars * BigInt(100 + percent), 100n);
}

/** Provider cost: sum of tokens × rate ÷ 1,000,000. Throws if not exact. */
export function providerCost(usage: TokenCounts, rates: Rates): bigint {
    const perMillion = (Object.keys(rates) as (keyof Rates)[]).reduce(
        (sum, type) => sum + BigInt(usage[type]) * parseUsd(rates[type]),
        0n,
    );

    if (perMillion % RATE_UNIT !== 0n) {
        throw new Error('Cost is finer than a nanodollar.');
    }

    return perMillion / RATE_UNIT;
}
