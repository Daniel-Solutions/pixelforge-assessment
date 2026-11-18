<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Error codes from Pixelforge (HTTP errors and failed generations), plus two of our own
 * for failures that never reached a Pixelforge response.
 */
enum PixelforgeErrorCode: string
{
    case InvalidApiKey = 'invalid_api_key';
    case ValidationFailed = 'validation_failed';
    case ContentPolicyViolation = 'content_policy_violation';
    case NotFound = 'not_found';
    case IdempotencyKeyReused = 'idempotency_key_reused';
    case RateLimitExceeded = 'rate_limit_exceeded';
    case ConcurrencyLimitExceeded = 'concurrency_limit_exceeded';
    case DailyLimitExceeded = 'daily_limit_exceeded';
    case Overloaded = 'overloaded';
    case DateRangeTooLong = 'date_range_too_long';
    case GenerationFailed = 'generation_failed';
    case InternalError = 'internal_error';

    /** Timeout or connection error: the request may or may not have reached Pixelforge. */
    case ConnectionFailed = 'connection_failed';

    /** A response we could not map to a documented code (e.g. a bare 500). */
    case Unknown = 'unknown_error';

    /** Our retries ran out on a retryable error. */
    case RetriesExhausted = 'retries_exhausted';

    /**
     * Errors worth retrying with the same Idempotency-Key.
     */
    public function isRetryable(): bool
    {
        return in_array($this, [
            self::RateLimitExceeded,
            self::ConcurrencyLimitExceeded,
            self::Overloaded,
            self::ConnectionFailed,
        ], true);
    }

    /**
     * A failure Pixelforge reported for an accepted generation (as opposed to one we decided locally).
     */
    public function isProviderGenerationFailure(): bool
    {
        return $this === self::GenerationFailed || $this === self::InternalError;
    }

    /**
     * Errors that mean our configuration or code is wrong, not the user's input.
     */
    public function isOurBug(): bool
    {
        return in_array($this, [self::InvalidApiKey, self::IdempotencyKeyReused, self::ValidationFailed], true);
    }

    /**
     * What the user reads. Every message says whether they were charged (never) and if retrying helps.
     */
    public function userMessage(): string
    {
        return match ($this) {
            self::ContentPolicyViolation => "Prompt rejected by the provider's content policy. You were not charged. Rephrase the prompt and try again.",
            self::DailyLimitExceeded => 'Daily provider limit reached, try again tomorrow. You were not charged.',
            self::GenerationFailed => 'The provider could not produce an image for this prompt. You were not charged. Try again or adjust the prompt.',
            self::InternalError => 'Generation failed at the provider. You were not charged. Retrying usually works.',
            self::RetriesExhausted, self::Overloaded, self::RateLimitExceeded, self::ConcurrencyLimitExceeded, self::ConnectionFailed => 'The provider stayed busy or unreachable, so we stopped trying. You were not charged. Try again in a few minutes.',
            self::InvalidApiKey, self::IdempotencyKeyReused, self::ValidationFailed, self::NotFound, self::DateRangeTooLong, self::Unknown => 'Something went wrong on our side while sending this generation. You were not charged. Try again; if it keeps failing, contact support.',
        };
    }
}
