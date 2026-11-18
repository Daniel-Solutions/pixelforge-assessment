<?php

declare(strict_types=1);

use App\Actions\ApplyGenerationState;
use App\Actions\RecordLedgerEntry;
use App\Actions\SubmitGeneration;
use App\Data\GenerationInput;
use App\Enums\LedgerEntryType;
use App\Enums\Quality;
use App\Enums\Size;
use App\Exceptions\InsufficientCredits;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Money\Nanodollars;
use App\Pixelforge\Data\RemoteGeneration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    Http::fake(['pixelforge.test/v1/pricing' => Http::response(json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/pricing.json'), true))]);
    Queue::fake();
});

/**
 * High, 1536x1024, 45-character prompt, 1 reference image: estimated max price 0.457150000.
 */
function expensiveInput(): GenerationInput
{
    return new GenerationInput('A lighthouse on a cliff at dawn, oil painting', Size::Landscape, Quality::High, ['https://example.com/style.png']);
}

function userWithBalance(int $nanodollars): User
{
    $user = User::factory()->create();
    app(RecordLedgerEntry::class)->handle($user->id, LedgerEntryType::Purchase, Nanodollars::of($nanodollars));

    return $user->refresh();
}

it('blocks a generation whose maximum price exceeds the available balance', function (): void {
    $user = userWithBalance(457_149_999);

    expect(fn () => app(SubmitGeneration::class)->handle($user, expensiveInput()))
        ->toThrow(InsufficientCredits::class, 'Not enough credits: this generation can cost up to $0.4572.');

    expect(Generation::query()->count())->toBe(0);
});

it('allows a generation whose maximum price fits exactly', function (): void {
    $user = userWithBalance(457_150_000);

    app(SubmitGeneration::class)->handle($user, expensiveInput());

    expect($user->refresh()->availableBalance()->amount)->toBe(0)
        ->and($user->balance->amount)->toBe(457_150_000);
});

it('reserves the maximum of pending generations, so a second submit cannot spend the same credit', function (): void {
    $user = userWithBalance(800_000_000);

    app(SubmitGeneration::class)->handle($user, expensiveInput());

    expect(fn () => app(SubmitGeneration::class)->handle($user, expensiveInput()))->toThrow(InsufficientCredits::class)
        ->and(Generation::query()->count())->toBe(1);
});

it('charges the real price on success and frees the reservation on failure', function (): void {
    $user = userWithBalance(1_000_000_000);
    $first = app(SubmitGeneration::class)->handle($user, expensiveInput());
    $second = app(SubmitGeneration::class)->handle($user, expensiveInput());
    expect($user->refresh()->availableBalance()->amount)->toBe(1_000_000_000 - 2 * 457_150_000);

    app(ApplyGenerationState::class)->handle($first, RemoteGeneration::fromArray(succeededPixelforgeGeneration([
        'id' => 'gen_first', 'metadata' => ['generation_uuid' => $first->uuid],
    ])));
    app(ApplyGenerationState::class)->handle($second, RemoteGeneration::fromArray(pixelforgeGeneration([
        'id' => 'gen_second', 'status' => 'failed', 'error' => ['code' => 'generation_failed', 'message' => 'No.'],
    ])));

    $user->refresh();
    expect($user->balance->amount)->toBe(1_000_000_000 - 381_887_500)
        ->and($user->availableBalance()->amount)->toBe(1_000_000_000 - 381_887_500)
        ->and((int) $user->ledgerEntries()->sum('amount'))->toBe($user->balance->amount);
});

it('shows the not-enough-credits error on the form', function (): void {
    $this->actingAs(userWithBalance(1))
        ->from(route('generations.create'))
        ->post(route('generations.store'), [
            'prompt' => 'A lighthouse', 'size' => '1024x1024', 'quality' => 'low', 'reference_images' => [],
        ])
        ->assertRedirect(route('generations.create'))
        ->assertSessionHasErrors(['credits' => 'Not enough credits: this generation can cost up to $0.0218.']);
});

it('buys a credit package once per token', function (): void {
    $user = User::factory()->create();
    $token = (string) Str::uuid();

    $this->actingAs($user)->post(route('credits.store'), ['package' => 10, 'purchase_token' => $token])->assertRedirect(route('credits.index'));
    $this->actingAs($user)->post(route('credits.store'), ['package' => 10, 'purchase_token' => $token])->assertRedirect(route('credits.index'));

    expect($user->refresh()->balance->amount)->toBe(10_000_000_000)
        ->and(LedgerEntry::query()->where('type', LedgerEntryType::Purchase)->count())->toBe(1);
});

it('only sells the fixed packages', function (array $form, string $error): void {
    $this->actingAs(User::factory()->create())
        ->post(route('credits.store'), $form)
        ->assertSessionHasErrors($error);

    expect(LedgerEntry::query()->count())->toBe(0);
})->with([
    'unknown package' => [['package' => 7, 'purchase_token' => (string) Str::uuid()], 'package'],
    'missing token' => [['package' => 5], 'purchase_token'],
]);

it('shows balance, packages and the user\'s own ledger', function (): void {
    $user = User::factory()->withCredit(5)->create();
    User::factory()->withCredit(25)->create();

    $this->actingAs($user)
        ->get(route('credits.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('credits/Index')
            ->has('packages', 3)
            ->where('packages.2.amount.display', '$25.00')
            ->has('entries', 1)
            ->where('entries.0.amount.display', '$5.0000')
            ->where('auth.user.balance.display', '$5.0000')
            ->where('auth.user.available_balance.display', '$5.0000'));
});

it('gives new users a $1 welcome credit', function (): void {
    $this->post(route('register.store'), [
        'name' => 'New', 'email' => 'new@example.com', 'password' => 'password', 'password_confirmation' => 'password',
    ]);

    $user = User::query()->where('email', 'new@example.com')->sole();
    expect($user->balance->amount)->toBe(1_000_000_000)
        ->and($user->ledgerEntries()->sole()->type)->toBe(LedgerEntryType::Purchase);
});

it('seeds the configured accounts once, with their welcome credit', function (): void {
    config(['pixelforge.admin' => ['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-pass'], 'pixelforge.demo' => ['email' => 'demo@example.com', 'password' => 'secret-pass']]);

    $this->seed();
    $this->seed();

    expect(User::query()->where('email', 'admin@example.com')->sole()->is_admin)->toBeTrue()
        ->and(User::query()->where('email', 'demo@example.com')->sole()->is_admin)->toBeFalse()
        ->and(LedgerEntry::query()->count())->toBe(2);
});

it('issues a fresh purchase token from the server on every page view', function (): void {
    $user = User::factory()->create();

    $first = $this->actingAs($user)->get(route('credits.index'))->inertiaProps('purchaseToken');
    $second = $this->actingAs($user)->get(route('credits.index'))->inertiaProps('purchaseToken');

    expect(Str::isUuid($first))->toBeTrue()->and($first)->not->toBe($second);
});

it('keeps the billing records of a user who has generations', function (): void {
    $user = User::factory()->create();
    Generation::factory()->for($user)->succeeded()->create();

    $this->actingAs($user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasErrors('password');

    expect($user->fresh())->not->toBeNull();
});
