<?php

declare(strict_types=1);

namespace App\Pixelforge\Data;

use App\Enums\GenerationStatus;
use Carbon\CarbonImmutable;

/**
 * The generation object as Pixelforge returns it (in API responses and in webhook payloads).
 */
final readonly class Generation
{
    /**
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public string $id,
        public GenerationStatus $status,
        public array $metadata,
        public Rates $rates,
        public ?TokenUsage $usage,
        public ?string $errorCode,
        public ?string $errorMessage,
        public ?string $imageUrl,
        public ?CarbonImmutable $imageUrlExpiresAt,
        public ?CarbonImmutable $createdAt,
        public ?CarbonImmutable $startedAt,
        public ?CarbonImmutable $completedAt,
    ) {}

    /**
     * @param  array{id: string, status: string, metadata?: array<string, string>|null, rates: array{input_text_tokens: string, input_image_tokens: string, output_tokens: string, reasoning_tokens: string}, usage?: array{input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int}|null, error?: array{code: string, message: string}|null, image_url?: string|null, image_url_expires_at?: string|null, created_at?: string|null, started_at?: string|null, completed_at?: string|null}  $generation
     */
    public static function fromArray(array $generation): self
    {
        return new self(
            id: $generation['id'],
            status: GenerationStatus::from($generation['status']),
            metadata: $generation['metadata'] ?? [],
            rates: Rates::fromArray($generation['rates']),
            usage: isset($generation['usage']) ? TokenUsage::fromArray($generation['usage']) : null,
            errorCode: $generation['error']['code'] ?? null,
            errorMessage: $generation['error']['message'] ?? null,
            imageUrl: $generation['image_url'] ?? null,
            imageUrlExpiresAt: self::date($generation['image_url_expires_at'] ?? null),
            createdAt: self::date($generation['created_at'] ?? null),
            startedAt: self::date($generation['started_at'] ?? null),
            completedAt: self::date($generation['completed_at'] ?? null),
        );
    }

    public function generationUuid(): ?string
    {
        return $this->metadata['generation_uuid'] ?? null;
    }

    public function appEnv(): ?string
    {
        return $this->metadata['app_env'] ?? null;
    }

    private static function date(?string $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value)->utc();
    }
}
