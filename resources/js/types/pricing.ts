export type Size = '1024x1024' | '1536x1024' | '1024x1536';

export type Quality = 'low' | 'medium' | 'high';

export type TokenType =
    | 'input_text_tokens'
    | 'input_image_tokens'
    | 'output_tokens'
    | 'reasoning_tokens';

export type TokenCounts = Record<TokenType, number>;

/** USD per 1M tokens as exact decimal strings. */
export type Rates = Record<TokenType, string>;

/** The price list from GET /v1/pricing, as the backend passes it. */
export type Pricing = {
    rates: Rates;
    tokens: {
        characters_per_text_token: number;
        text_token_variance: string;
        per_reference_image: number;
        output: Record<Quality, Record<Size, number>>;
        reasoning: Record<Quality, { min: number; max: number }>;
    };
};
