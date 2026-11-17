<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/**
 * A Pixelforge generation object like the docs show, with overrides merged in.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pixelforgeGeneration(array $overrides = []): array
{
    return array_replace([
        'id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K',
        'object' => 'generation',
        'status' => 'queued',
        'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/style.png'],
        'metadata' => [],
        'image_url' => null,
        'image_url_expires_at' => null,
        'usage' => null,
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'error' => null,
        'created_at' => '2026-11-04T14:03:11Z',
        'started_at' => null,
        'completed_at' => null,
    ], $overrides);
}

/**
 * The same generation after it succeeded with the docs example usage (cost 0.305510000).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function succeededPixelforgeGeneration(array $overrides = []): array
{
    return pixelforgeGeneration(array_replace([
        'status' => 'succeeded',
        'usage' => [
            'input_text_tokens' => 12,
            'input_image_tokens' => 765,
            'output_tokens' => 6450,
            'reasoning_tokens' => 3184,
            'total_tokens' => 10411,
        ],
        'image_url' => 'https://pixelforge.test/images/gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K.png?sig=abc',
        'image_url_expires_at' => '2026-11-05T14:03:29Z',
        'started_at' => '2026-11-04T14:03:13Z',
        'completed_at' => '2026-11-04T14:03:29Z',
    ], $overrides));
}

/**
 * @return array{error: array{type: string, code: string, message: string}}
 */
function pixelforgeError(string $code, string $message = 'Something happened.'): array
{
    return ['error' => ['type' => 'api_error', 'code' => $code, 'message' => $message]];
}

/**
 * A Pixelforge webhook event wrapping a generation object.
 *
 * @param  array<string, mixed>  $generation
 * @return array<string, mixed>
 */
function pixelforgeEvent(string $type, array $generation, ?string $eventId = null): array
{
    return [
        'id' => $eventId ?? 'evt_'.bin2hex(random_bytes(8)),
        'object' => 'event',
        'type' => $type,
        'created_at' => '2026-11-04T14:03:29Z',
        'data' => ['object' => $generation],
    ];
}

/**
 * The Pixelforge-Signature header for a raw body, computed like Pixelforge does.
 */
function pixelforgeSignature(string $body, ?int $timestamp = null, string $secret = 'whsec_test_secret'): string
{
    $timestamp ??= time();

    return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
}
