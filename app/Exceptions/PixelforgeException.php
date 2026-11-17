<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\PixelforgeErrorCode;
use RuntimeException;

/**
 * Any failed call to Pixelforge: an error response, a timeout or a connection error.
 */
final class PixelforgeException extends RuntimeException
{
    public function __construct(
        public readonly PixelforgeErrorCode $errorCode,
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Documented transient errors, plus any undocumented 5xx: retrying with the same Idempotency-Key is always safe.
     */
    public function isRetryable(): bool
    {
        return $this->errorCode->isRetryable()
            || ($this->errorCode === PixelforgeErrorCode::Unknown && $this->httpStatus >= 500);
    }
}
