<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use App\Enums\PixelforgeErrorCode;
use App\Enums\Quality;
use App\Enums\Size;
use App\Exceptions\PixelforgeException;
use App\Pixelforge\Data\NewGeneration;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function newGeneration(): NewGeneration
{
    return new NewGeneration(
        prompt: 'A lighthouse on a cliff at dawn, oil painting',
        size: Size::Landscape,
        quality: Quality::High,
        referenceImages: ['https://example.com/style.png'],
        metadata: ['generation_uuid' => 'uuid-1', 'app_env' => 'testing'],
    );
}

it('creates a generation with only the documented fields, the API key and the idempotency key', function (): void {
    Http::fake(['pixelforge.test/v1/generations' => Http::response(pixelforgeGeneration(), 202)]);

    $created = app(PixelforgeClient::class)->createGeneration(newGeneration(), 'key-123');

    expect($created->replayed)->toBeFalse()
        ->and($created->generation->id)->toBe('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K')
        ->and($created->generation->status)->toBe(GenerationStatus::Queued)
        ->and($created->generation->rates->reasoning)->toBe('12.50');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://pixelforge.test/v1/generations'
        && $request->hasHeader('Authorization', 'Bearer pf_test_key')
        && $request->hasHeader('Idempotency-Key', 'key-123')
        && $request->data() === [
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/style.png'],
            'metadata' => ['generation_uuid' => 'uuid-1', 'app_env' => 'testing'],
        ]);
});

it('omits empty reference images and metadata', function (): void {
    expect((new NewGeneration('cat', Size::Square, Quality::Low, [], []))->toArray())
        ->toBe(['prompt' => 'cat', 'size' => '1024x1024', 'quality' => 'low']);
});

it('reports an idempotent replay', function (): void {
    Http::fake(['pixelforge.test/v1/generations' => Http::response(pixelforgeGeneration(['status' => 'processing']), 202, ['Idempotent-Replayed' => 'true'])]);

    $created = app(PixelforgeClient::class)->createGeneration(newGeneration(), 'key-123');

    expect($created->replayed)->toBeTrue()
        ->and($created->generation->status)->toBe(GenerationStatus::Processing);
});

it('turns error responses into typed exceptions', function (int $status, string $code, array $headers, PixelforgeErrorCode $expected, ?int $retryAfter, bool $retryable): void {
    Http::fake(['pixelforge.test/v1/generations' => Http::response(pixelforgeError($code, 'Nope.'), $status, $headers)]);

    try {
        app(PixelforgeClient::class)->createGeneration(newGeneration(), 'key-123');
        $this->fail('Expected a PixelforgeException.');
    } catch (PixelforgeException $exception) {
        expect($exception->errorCode)->toBe($expected)
            ->and($exception->httpStatus)->toBe($status)
            ->and($exception->retryAfter)->toBe($retryAfter)
            ->and($exception->isRetryable())->toBe($retryable)
            ->and($exception->getMessage())->toBe('Nope.');
    }
})->with([
    'overloaded' => [503, 'overloaded', ['Retry-After' => '2'], PixelforgeErrorCode::Overloaded, 2, true],
    'rate limit' => [429, 'rate_limit_exceeded', ['Retry-After' => '17'], PixelforgeErrorCode::RateLimitExceeded, 17, true],
    'concurrency' => [429, 'concurrency_limit_exceeded', ['Retry-After' => '5'], PixelforgeErrorCode::ConcurrencyLimitExceeded, 5, true],
    'daily limit' => [429, 'daily_limit_exceeded', [], PixelforgeErrorCode::DailyLimitExceeded, null, false],
    'content policy' => [400, 'content_policy_violation', [], PixelforgeErrorCode::ContentPolicyViolation, null, false],
    'bad key' => [401, 'invalid_api_key', [], PixelforgeErrorCode::InvalidApiKey, null, false],
    'validation' => [422, 'validation_failed', [], PixelforgeErrorCode::ValidationFailed, null, false],
    'idempotency reuse' => [409, 'idempotency_key_reused', [], PixelforgeErrorCode::IdempotencyKeyReused, null, false],
    'undocumented 500' => [500, 'kaboom', [], PixelforgeErrorCode::Unknown, null, true],
    'undocumented 418' => [418, 'teapot', [], PixelforgeErrorCode::Unknown, null, false],
]);

it('turns timeouts and connection errors into a retryable exception', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    expect(fn () => app(PixelforgeClient::class)->createGeneration(newGeneration(), 'key-123'))
        ->toThrow(fn (PixelforgeException $exception) => expect($exception->errorCode)->toBe(PixelforgeErrorCode::ConnectionFailed)
            ->and($exception->isRetryable())->toBeTrue());
});

it('falls back to a generic message when the error body is not JSON', function (): void {
    Http::fake(['pixelforge.test/*' => Http::response('Bad Gateway', 502)]);

    expect(fn () => app(PixelforgeClient::class)->getGeneration('gen_1'))
        ->toThrow(PixelforgeException::class, 'Pixelforge returned HTTP 502.');
});

it('retrieves a succeeded generation with usage, image and dates', function (): void {
    Http::fake(['pixelforge.test/v1/generations/gen_1' => Http::response(succeededPixelforgeGeneration(['id' => 'gen_1']))]);

    $generation = app(PixelforgeClient::class)->getGeneration('gen_1');

    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->usage?->reasoning)->toBe(3184)
        ->and($generation->imageUrl)->toStartWith('https://pixelforge.test/images/')
        ->and($generation->completedAt?->toIso8601ZuluString())->toBe('2026-11-04T14:03:29Z')
        ->and($generation->imageUrlExpiresAt?->toIso8601ZuluString())->toBe('2026-11-05T14:03:29Z');
});

it('reads failure details from a failed generation', function (): void {
    Http::fake(['pixelforge.test/v1/generations/gen_1' => Http::response(pixelforgeGeneration([
        'status' => 'failed',
        'error' => ['code' => 'internal_error', 'message' => 'Something went wrong on our side while generating.'],
    ]))]);

    $generation = app(PixelforgeClient::class)->getGeneration('gen_1');

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->errorCode)->toBe('internal_error')
        ->and($generation->usage)->toBeNull();
});

it('maps 404 to not_found', function (): void {
    Http::fake(['pixelforge.test/v1/generations/gen_missing' => Http::response(pixelforgeError('not_found'), 404)]);

    expect(fn () => app(PixelforgeClient::class)->getGeneration('gen_missing'))
        ->toThrow(fn (PixelforgeException $exception) => expect($exception->errorCode)->toBe(PixelforgeErrorCode::NotFound));
});

it('caches pricing and sends no API key for it', function (): void {
    Http::fake(['pixelforge.test/v1/pricing' => Http::response(json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/pricing.json'), true))]);

    $client = app(PixelforgeClient::class);
    $first = $client->getPricing();
    $second = $client->getPricing();

    expect($first->textTokenVariance)->toBe('0.15')
        ->and($second->outputTokens(Size::Landscape, Quality::High))->toBe(6450)
        ->and($second->reasoningTokens(Quality::Medium))->toBe(['min' => 200, 'max' => 2000]);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));
});

it('reads usage per UTC day with exact costs', function (): void {
    Http::fake(['pixelforge.test/v1/usage*' => Http::response([
        'object' => 'usage',
        'start_date' => '2026-11-01',
        'end_date' => '2026-11-02',
        'data' => [
            ['date' => '2026-11-01', 'generations' => 3, 'input_text_tokens' => 41, 'input_image_tokens' => 0, 'output_tokens' => 3300, 'reasoning_tokens' => 2950, 'cost_usd' => '0.169080000'],
            ['date' => '2026-11-02', 'generations' => 0, 'input_text_tokens' => 0, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0, 'cost_usd' => '0.000000000'],
        ],
    ])]);

    $days = app(PixelforgeClient::class)->getUsage(now()->setDate(2026, 11, 1), now()->setDate(2026, 11, 2));

    expect($days)->toHaveCount(2)
        ->and($days[0]->date)->toBe('2026-11-01')
        ->and($days[0]->cost->amount)->toBe(169_080_000)
        ->and($days[0]->tokens->reasoning)->toBe(2950)
        ->and($days[1]->cost->amount)->toBe(0);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'start_date=2026-11-01')
        && str_contains($request->url(), 'end_date=2026-11-02'));
});
