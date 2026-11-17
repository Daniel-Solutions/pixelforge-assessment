<?php

declare(strict_types=1);

use App\Actions\SubmitGeneration;
use App\Data\GenerationInput;
use App\Enums\GenerationStatus;
use App\Enums\Quality;
use App\Enums\Size;
use App\Jobs\SendGenerationToPixelforge;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Http::fake(['pixelforge.test/v1/pricing' => Http::response(json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/pricing.json'), true))]);
    Queue::fake();
});

it('stores a queued generation with its estimate and sends it from a job', function (): void {
    $user = User::factory()->create();

    $generation = app(SubmitGeneration::class)->handle($user, new GenerationInput(
        prompt: str_repeat('a', 400),
        size: Size::Landscape,
        quality: Quality::High,
        referenceImages: ['https://example.com/a.png', 'https://example.com/b.png'],
    ));

    expect($generation->user_id)->toBe($user->id)
        ->and($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->pixelforge_id)->toBeNull()
        ->and($generation->idempotency_key)->toBe($generation->uuid)
        ->and($generation->estimated_min->toDecimalString())->toBe('0.357781250')
        ->and($generation->estimated_max->toDecimalString())->toBe('0.467343750')
        ->and($generation->provider_cost)->toBeNull()
        ->and($generation->charged_amount)->toBeNull();

    Queue::assertPushed(SendGenerationToPixelforge::class, fn (SendGenerationToPixelforge $job): bool => $job->generation->is($generation));
});
