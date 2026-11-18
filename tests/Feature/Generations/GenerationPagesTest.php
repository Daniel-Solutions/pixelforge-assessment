<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use App\Jobs\SendGenerationToPixelforge;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    Queue::fake();
});

function fakePricing(): void
{
    Http::fake(['pixelforge.test/v1/pricing' => Http::response(json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/pricing.json'), true))]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validGenerationForm(array $overrides = []): array
{
    return array_replace([
        'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/style.png'],
    ], $overrides);
}

it('sends guests to the login page', function (string $route): void {
    $this->get(route($route))->assertRedirect(route('login'));
})->with(['generations.create', 'generations.index']);

it('shows the generate form with pricing for the live estimate', function (): void {
    fakePricing();

    $this->actingAs(User::factory()->withCredit()->create())
        ->get(route('generations.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('generations/Create')
            ->where('markupPercent', 25)
            ->where('pricing.tokens.text_token_variance', '0.15')
            ->where('pricing.rates.output_tokens', '40.00')
            ->has('sizes', 3)
            ->has('qualities', 3)
            ->where('limits.promptCharacters', 4000));
});

it('still shows the form when pricing is unavailable', function (): void {
    Http::fake(['pixelforge.test/v1/pricing' => Http::response(pixelforgeError('overloaded'), 503)]);

    $this->actingAs(User::factory()->withCredit()->create())
        ->get(route('generations.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('pricing', null));
});

it('submits a generation and redirects to the history', function (): void {
    fakePricing();

    $user = User::factory()->withCredit()->create();

    $this->actingAs($user)
        ->post(route('generations.store'), validGenerationForm())
        ->assertRedirect(route('generations.index'));

    $generation = Generation::query()->sole();
    expect($generation->user_id)->toBe($user->id)
        ->and($generation->reference_images)->toBe(['https://example.com/style.png'])
        ->and($generation->estimated_max->toDecimalString())->toBe('0.457150000');

    Queue::assertPushed(SendGenerationToPixelforge::class);
});

it('ignores fields the user must never set', function (): void {
    fakePricing();

    $user = User::factory()->withCredit()->create();
    $other = User::factory()->withCredit()->create();

    $this->actingAs($user)->post(route('generations.store'), validGenerationForm([
        'user_id' => $other->id,
        'status' => 'succeeded',
        'charged_amount' => 1,
        'provider_cost' => 1,
        'pixelforge_id' => 'gen_hijack',
        'is_admin' => true,
    ]))->assertRedirect();

    $generation = Generation::query()->sole();
    expect($generation->user_id)->toBe($user->id)
        ->and($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->charged_amount)->toBeNull()
        ->and($generation->provider_cost)->toBeNull()
        ->and($generation->pixelforge_id)->toBeNull()
        ->and($user->refresh()->is_admin)->toBeFalse();
});

it('validates input exactly like Pixelforge does', function (array $form, string $error): void {
    fakePricing();

    $this->actingAs(User::factory()->withCredit()->create())
        ->post(route('generations.store'), validGenerationForm($form))
        ->assertSessionHasErrors($error);

    expect(Generation::query()->count())->toBe(0);
})->with([
    'empty prompt' => [['prompt' => ''], 'prompt'],
    'prompt over 4000 characters' => [['prompt' => str_repeat('é', 4001)], 'prompt'],
    'unknown size' => [['size' => '512x512'], 'size'],
    'unknown quality' => [['quality' => 'ultra'], 'quality'],
    'five reference images' => [['reference_images' => array_fill(0, 5, 'https://example.com/a.png')], 'reference_images'],
    'non-http reference image' => [['reference_images' => ['ftp://example.com/a.png']], 'reference_images.0'],
    'not a URL' => [['reference_images' => ['not a url']], 'reference_images.0'],
]);

it('accepts a prompt of exactly 4000 multibyte characters', function (): void {
    fakePricing();

    $this->actingAs(User::factory()->withCredit()->create())
        ->post(route('generations.store'), validGenerationForm(['prompt' => str_repeat('é', 4000)]))
        ->assertSessionHasNoErrors();
});

it('tells the user when pricing is down instead of submitting', function (): void {
    Http::fake(['pixelforge.test/v1/pricing' => Http::response(pixelforgeError('overloaded'), 503)]);

    $this->actingAs(User::factory()->withCredit()->create())
        ->from(route('generations.create'))
        ->post(route('generations.store'), validGenerationForm())
        ->assertRedirect(route('generations.create'));

    expect(Generation::query()->count())->toBe(0);
});

it('lists only the user\'s own generations, newest first, and polls while one is pending', function (): void {
    $user = User::factory()->withCredit()->create();
    $older = Generation::factory()->for($user)->succeeded()->create();
    $newer = Generation::factory()->for($user)->processing()->create();
    Generation::factory()->create();

    $this->actingAs($user)
        ->get(route('generations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('generations/Index')
            ->has('generations.data', 2)
            ->where('generations.data.0.id', $newer->uuid)
            ->where('generations.data.1.id', $older->uuid)
            ->where('generations.data.1.charged.display', '$0.3819')
            ->where('generations.data.1.charged.exact', '0.381887500')
            ->missing('generations.data.1.provider_cost')
            ->where('hasPending', true));
});

it('stops polling when every generation is final', function (): void {
    $user = User::factory()->withCredit()->create();
    Generation::factory()->for($user)->succeeded()->create();
    Generation::factory()->for($user)->failed()->create();

    $this->actingAs($user)
        ->get(route('generations.index'))
        ->assertInertia(fn (Assert $page) => $page->where('hasPending', false));
});

it('explains a failure in plain words and offers a retry', function (): void {
    $user = User::factory()->withCredit()->create();
    $generation = Generation::factory()->for($user)->failed()->create();

    $this->actingAs($user)
        ->get(route('generations.show', $generation))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('generations/Show')
            ->where('generation.data.status', 'failed')
            ->where('generation.data.status_detail', 'Generation failed at the provider. You were not charged. Retrying usually works.')
            ->where('generation.data.was_charged', false)
            ->where('generation.data.can_retry', true));
});

it('says when a generation is waiting for a free slot', function (): void {
    $user = User::factory()->withCredit()->create();
    $generation = Generation::factory()->for($user)->create(['last_submit_error' => 'concurrency_limit_exceeded']);

    $this->actingAs($user)
        ->get(route('generations.show', $generation))
        ->assertInertia(fn (Assert $page) => $page->where('generation.data.status_detail', 'Waiting for a free slot at the provider.'));
});

it('hides an expired image', function (): void {
    $user = User::factory()->withCredit()->create();
    $generation = Generation::factory()->for($user)->succeeded()->create(['image_url_expires_at' => now()->subMinute()]);

    $this->actingAs($user)
        ->get(route('generations.show', $generation))
        ->assertInertia(fn (Assert $page) => $page
            ->where('generation.data.image_url', null)
            ->where('generation.data.image_expired', true));
});

it('does not show another user\'s generation', function (): void {
    $generation = Generation::factory()->succeeded()->create();

    $this->actingAs(User::factory()->withCredit()->create())
        ->get(route('generations.show', $generation))
        ->assertForbidden();
});

it('retries a failed generation as a new one with a new idempotency key', function (): void {
    fakePricing();

    $user = User::factory()->withCredit()->create();
    $failed = Generation::factory()->for($user)->failed()->create();

    $this->actingAs($user)
        ->post(route('generations.retry', $failed))
        ->assertRedirect(route('generations.index'));

    $retry = Generation::query()->whereKeyNot($failed->id)->sole();
    expect($retry->prompt)->toBe($failed->prompt)
        ->and($retry->idempotency_key)->not->toBe($failed->idempotency_key)
        ->and($retry->status)->toBe(GenerationStatus::Queued);
});

it('does not retry someone else\'s or a successful generation', function (): void {
    $user = User::factory()->withCredit()->create();

    $this->actingAs($user)->post(route('generations.retry', Generation::factory()->failed()->create()))->assertForbidden();
    $this->actingAs($user)->post(route('generations.retry', Generation::factory()->for($user)->succeeded()->create()))->assertForbidden();
});
