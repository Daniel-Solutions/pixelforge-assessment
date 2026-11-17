<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use App\Jobs\ReconcilePendingGenerations;
use App\Models\Generation;
use App\Models\LedgerEntry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;

it('applies the state of a generation stuck in processing, once', function (): void {
    $generation = Generation::factory()->processing()->create(['provider_created_at' => now()->subMinutes(5)]);
    Http::fake(['pixelforge.test/v1/generations/*' => Http::response(succeededPixelforgeGeneration(['id' => $generation->pixelforge_id]))]);

    dispatch_sync(new ReconcilePendingGenerations);
    dispatch_sync(new ReconcilePendingGenerations);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->charged_amount?->toDecimalString())->toBe('0.381887500')
        ->and(LedgerEntry::query()->count())->toBe(1);

    Http::assertSentCount(1);
});

it('applies a failure found by polling, for free', function (): void {
    $generation = Generation::factory()->sent()->create(['provider_created_at' => now()->subMinutes(5)]);
    Http::fake(['pixelforge.test/v1/generations/*' => Http::response(pixelforgeGeneration([
        'id' => $generation->pixelforge_id,
        'status' => 'failed',
        'error' => ['code' => 'internal_error', 'message' => 'Oops.'],
    ]))]);

    dispatch_sync(new ReconcilePendingGenerations);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Failed)
        ->and($generation->charged_amount?->amount)->toBe(0)
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('leaves recent, unsent and final generations alone', function (): void {
    Generation::factory()->processing()->create(['provider_created_at' => now()->subSeconds(30)]);
    Generation::factory()->create();
    Generation::factory()->succeeded()->create(['provider_created_at' => now()->subHour()]);
    Http::fake();

    dispatch_sync(new ReconcilePendingGenerations);

    Http::assertNothingSent();
});

it('stops when Pixelforge rate-limits and carries on past other errors', function (): void {
    Generation::factory()->processing()->create(['pixelforge_id' => 'gen_a', 'provider_created_at' => now()->subMinutes(9)]);
    Generation::factory()->processing()->create(['pixelforge_id' => 'gen_b', 'provider_created_at' => now()->subMinutes(8)]);
    Generation::factory()->processing()->create(['pixelforge_id' => 'gen_c', 'provider_created_at' => now()->subMinutes(7)]);
    Http::fake([
        'pixelforge.test/v1/generations/gen_a' => Http::response(pixelforgeError('not_found'), 404),
        'pixelforge.test/v1/generations/gen_b' => Http::response(pixelforgeError('rate_limit_exceeded'), 429, ['Retry-After' => '30']),
        'pixelforge.test/v1/generations/gen_c' => Http::response(succeededPixelforgeGeneration(['id' => 'gen_c'])),
    ]);

    dispatch_sync(new ReconcilePendingGenerations);

    Http::assertSentCount(2);
});

it('polls at most twenty generations per run', function (): void {
    Generation::factory()->count(21)->sequence(fn ($sequence): array => ['pixelforge_id' => "gen_{$sequence->index}"])
        ->processing()->create(['provider_created_at' => now()->subMinutes(5)]);
    Http::fake(['pixelforge.test/v1/generations/*' => Http::response(pixelforgeGeneration(['status' => 'processing']))]);

    dispatch_sync(new ReconcilePendingGenerations);

    Http::assertSentCount(20);
});

it('is scheduled every minute', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->description, ReconcilePendingGenerations::class));

    expect($event?->expression)->toBe('* * * * *');
});
