<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\AsNanodollars;
use App\Enums\GenerationStatus;
use App\Enums\PixelforgeErrorCode;
use App\Enums\Quality;
use App\Enums\Size;
use App\Money\Nanodollars;
use App\Pixelforge\Data\NewGeneration;
use App\Pixelforge\Data\Rates;
use App\Pixelforge\Data\TokenUsage;
use Carbon\CarbonImmutable;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string|null $pixelforge_id
 * @property string $idempotency_key
 * @property GenerationStatus $status
 * @property string $prompt
 * @property Size $size
 * @property Quality $quality
 * @property list<string> $reference_images
 * @property Nanodollars $estimated_min
 * @property Nanodollars $estimated_max
 * @property array{input_text_tokens: string, input_image_tokens: string, output_tokens: string, reasoning_tokens: string}|null $rates
 * @property int|null $input_text_tokens
 * @property int|null $input_image_tokens
 * @property int|null $output_tokens
 * @property int|null $reasoning_tokens
 * @property Nanodollars|null $provider_cost
 * @property Nanodollars|null $charged_amount
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property string|null $image_url
 * @property CarbonImmutable|null $image_url_expires_at
 * @property int $submit_attempts
 * @property PixelforgeErrorCode|null $last_submit_error
 * @property CarbonImmutable|null $provider_created_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'queued',
        'submit_attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'size' => Size::class,
            'quality' => Quality::class,
            'reference_images' => 'array',
            'rates' => 'array',
            'estimated_min' => AsNanodollars::class,
            'estimated_max' => AsNanodollars::class,
            'provider_cost' => AsNanodollars::class,
            'charged_amount' => AsNanodollars::class,
            'last_submit_error' => PixelforgeErrorCode::class,
            'image_url_expires_at' => 'immutable_datetime',
            'provider_created_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasOne<LedgerEntry, $this>
     */
    public function charge(): HasOne
    {
        return $this->hasOne(LedgerEntry::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereIn('status', GenerationStatus::pending());
    }

    /**
     * The exact request body, rebuilt the same way on every attempt so Idempotency-Key replays match.
     */
    public function toNewGeneration(string $appEnv): NewGeneration
    {
        return new NewGeneration(
            prompt: $this->prompt,
            size: $this->size,
            quality: $this->quality,
            referenceImages: $this->reference_images,
            metadata: ['generation_uuid' => $this->uuid, 'app_env' => $appEnv],
        );
    }

    public function usage(): ?TokenUsage
    {
        if ($this->input_text_tokens === null || $this->input_image_tokens === null || $this->output_tokens === null || $this->reasoning_tokens === null) {
            return null;
        }

        return new TokenUsage($this->input_text_tokens, $this->input_image_tokens, $this->output_tokens, $this->reasoning_tokens);
    }

    public function storedRates(): ?Rates
    {
        return $this->rates === null ? null : Rates::fromArray($this->rates);
    }

    public function isWaitingForSlot(): bool
    {
        return $this->status === GenerationStatus::Queued && $this->pixelforge_id === null && $this->last_submit_error !== null;
    }

    public function imageHasExpired(): bool
    {
        return $this->image_url_expires_at !== null && $this->image_url_expires_at->isPast();
    }
}
