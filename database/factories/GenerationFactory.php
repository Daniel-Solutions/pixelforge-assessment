<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Enums\Quality;
use App\Enums\Size;
use App\Models\Generation;
use App\Models\User;
use App\Money\Nanodollars;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $uuid = (string) Str::uuid();

        return [
            'uuid' => $uuid,
            'idempotency_key' => $uuid,
            'user_id' => User::factory(),
            'status' => GenerationStatus::Queued,
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => Size::Landscape,
            'quality' => Quality::High,
            'reference_images' => ['https://example.com/style.png'],
            'estimated_min' => Nanodollars::of(95_250_000),
            'estimated_max' => Nanodollars::of(467_343_750),
        ];
    }

    /**
     * Accepted by Pixelforge: it has an id and the rates fixed at creation.
     */
    public function sent(string $pixelforgeId = 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K'): static
    {
        return $this->state(fn (): array => [
            'pixelforge_id' => $pixelforgeId,
            'submit_attempts' => 1,
            'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
            'provider_created_at' => now(),
        ]);
    }

    public function processing(): static
    {
        return $this->sent()->state(fn (): array => [
            'status' => GenerationStatus::Processing,
            'started_at' => now(),
        ]);
    }

    /**
     * Succeeded with the docs example usage: provider cost 0.305510000, charged 0.381887500.
     */
    public function succeeded(): static
    {
        return $this->sent()->state(fn (): array => [
            'status' => GenerationStatus::Succeeded,
            'input_text_tokens' => 12,
            'input_image_tokens' => 765,
            'output_tokens' => 6450,
            'reasoning_tokens' => 3184,
            'provider_cost' => Nanodollars::of(305_510_000),
            'charged_amount' => Nanodollars::of(381_887_500),
            'image_url' => 'https://pixelforge.test/images/a.png',
            'image_url_expires_at' => now()->addDay(),
            'started_at' => now(),
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->sent()->state(fn (): array => [
            'status' => GenerationStatus::Failed,
            'failure_code' => 'internal_error',
            'failure_message' => 'Generation failed at the provider. You were not charged. Retrying usually works.',
            'provider_cost' => Nanodollars::zero(),
            'charged_amount' => Nanodollars::zero(),
            'completed_at' => now(),
        ]);
    }
}
