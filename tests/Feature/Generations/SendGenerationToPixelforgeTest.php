<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use App\Enums\PixelforgeErrorCode;
use App\Jobs\SendGenerationToPixelforge;
use App\Models\Generation;
use App\Models\LedgerEntry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function runSendJob(Generation $generation, int $attempt = 1): SendGenerationToPixelforge
{
    $job = (new SendGenerationToPixelforge($generation))->withFakeQueueInteractions();
    $job->job->attempts = $attempt;
    app()->call([$job, 'handle']);

    return $job;
}

it('sends the generation with its own idempotency key and metadata, then stores the Pixelforge id and rates', function (): void {
    $generation = Generation::factory()->create();
    Http::fake(['pixelforge.test/v1/generations' => Http::response(pixelforgeGeneration(['id' => 'gen_new']), 202)]);

    runSendJob($generation)->assertNotReleased();

    $generation->refresh();
    expect($generation->pixelforge_id)->toBe('gen_new')
        ->and($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->rates)->toEqual(['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'])
        ->and($generation->provider_created_at?->toIso8601ZuluString())->toBe('2026-11-04T14:03:11Z')
        ->and($generation->submit_attempts)->toBe(1);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Idempotency-Key', $generation->idempotency_key)
        && $request['metadata'] === ['generation_uuid' => $generation->uuid, 'app_env' => 'testing']
        && $request['prompt'] === $generation->prompt);
});

it('retries an overloaded provider after Retry-After with the same idempotency key', function (): void {
    $generation = Generation::factory()->create();
    Http::fakeSequence('pixelforge.test/v1/generations')
        ->push(pixelforgeError('overloaded'), 503, ['Retry-After' => '2'])
        ->push(pixelforgeGeneration(['id' => 'gen_retried']), 202);

    $first = runSendJob($generation);

    expect($first->job->isReleased())->toBeTrue()
        ->and($first->job->releaseDelay)->toBeGreaterThanOrEqual(2)->toBeLessThanOrEqual(5)
        ->and($generation->refresh()->status)->toBe(GenerationStatus::Queued)
        ->and($generation->last_submit_error)->toBe(PixelforgeErrorCode::Overloaded);

    runSendJob($generation, attempt: 2)->assertNotReleased();

    expect($generation->refresh()->pixelforge_id)->toBe('gen_retried')
        ->and($generation->last_submit_error)->toBeNull()
        ->and($generation->submit_attempts)->toBe(2);

    $keys = Http::recorded()->map(fn (array $pair): string => $pair[0]->header('Idempotency-Key')[0])->unique();
    expect($keys->all())->toBe([$generation->idempotency_key]);
});

it('waits for a free slot when the concurrency limit is hit', function (): void {
    $generation = Generation::factory()->create();
    Http::fake(['pixelforge.test/v1/generations' => Http::response(pixelforgeError('concurrency_limit_exceeded'), 429, ['Retry-After' => '5'])]);

    $job = runSendJob($generation);

    expect($job->job->releaseDelay)->toBeGreaterThanOrEqual(5)
        ->and($generation->refresh()->isWaitingForSlot())->toBeTrue();
});

it('backs off further on each attempt, capped at a minute', function (): void {
    $generation = Generation::factory()->create();
    Http::fake(['pixelforge.test/v1/generations' => Http::response(pixelforgeError('rate_limit_exceeded'), 429, ['Retry-After' => '1'])]);

    expect(runSendJob($generation, attempt: 4)->job->releaseDelay)->toBeGreaterThanOrEqual(16)->toBeLessThanOrEqual(19)
        ->and(runSendJob($generation, attempt: 9)->job->releaseDelay)->toBeGreaterThanOrEqual(60)->toBeLessThanOrEqual(63);
});

it('retries timeouts because the generation may have been created', function (): void {
    $generation = Generation::factory()->create();
    Http::fake(fn () => throw new ConnectionException('Operation timed out'));

    expect(runSendJob($generation)->job->isReleased())->toBeTrue()
        ->and($generation->refresh()->status)->toBe(GenerationStatus::Queued);
});

it('fails right away, for free, on errors that retrying cannot fix', function (int $status, string $code, string $message): void {
    $generation = Generation::factory()->create();
    Http::fake(['pixelforge.test/v1/generations' => Http::response(pixelforgeError($code), $status)]);

    runSendJob($generation)->assertNotReleased();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->failure_code)->toBe($code)
        ->and($generation->failure_message)->toContain($message)
        ->and($generation->charged_amount?->amount)->toBe(0)
        ->and($generation->provider_cost?->amount)->toBe(0)
        ->and(LedgerEntry::query()->count())->toBe(0);
})->with([
    'content policy' => [400, 'content_policy_violation', "Prompt rejected by the provider's content policy"],
    'daily limit' => [429, 'daily_limit_exceeded', 'Daily provider limit reached, try again tomorrow'],
    'bad API key' => [401, 'invalid_api_key', 'Something went wrong on our side'],
    'idempotency reuse' => [409, 'idempotency_key_reused', 'Something went wrong on our side'],
]);

it('treats an idempotent replay as success and applies the state it reports', function (): void {
    $generation = Generation::factory()->create();
    Http::fake(['pixelforge.test/v1/generations' => Http::response(succeededPixelforgeGeneration(), 202, ['Idempotent-Replayed' => 'true'])]);

    runSendJob($generation);

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->provider_cost?->toDecimalString())->toBe('0.305510000')
        ->and($generation->charged_amount?->toDecimalString())->toBe('0.381887500')
        ->and(LedgerEntry::query()->count())->toBe(1);
});

it('does not send again once Pixelforge has the generation', function (): void {
    $generation = Generation::factory()->sent()->create();

    runSendJob($generation);

    Http::assertNothingSent();
});

it('fails the generation for free when the retry deadline passes', function (): void {
    $generation = Generation::factory()->create(['last_submit_error' => PixelforgeErrorCode::Overloaded]);

    (new SendGenerationToPixelforge($generation))->failed(null);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Failed)
        ->and($generation->failure_code)->toBe('retries_exhausted')
        ->and($generation->failure_message)->toContain('You were not charged')
        ->and($generation->charged_amount?->amount)->toBe(0);
});

it('gives up ten minutes after the generation was created', function (): void {
    $generation = Generation::factory()->create();

    expect((new SendGenerationToPixelforge($generation))->retryUntil()->getTimestamp())
        ->toBe($generation->created_at->addMinutes(10)->getTimestamp());
});

it('does not fail a generation that Pixelforge accepted in the meantime', function (): void {
    $generation = Generation::factory()->processing()->create();

    (new SendGenerationToPixelforge($generation))->failed(null);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Processing)
        ->and($generation->failure_code)->toBeNull();
});

it('backs off after unexpected errors instead of retrying at once', function (): void {
    expect((new SendGenerationToPixelforge(Generation::factory()->create()))->backoff)->toBe([5, 15, 30, 60]);
});
