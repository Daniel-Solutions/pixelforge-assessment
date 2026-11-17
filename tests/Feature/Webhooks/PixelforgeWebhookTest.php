<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use App\Enums\LedgerEntryType;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\WebhookEvent;
use App\Money\Nanodollars;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Testing\TestResponse;

/**
 * @param  array<string, mixed>  $event
 */
function deliverWebhook(array $event, ?string $signature = null, ?string $body = null): TestResponse
{
    $body ??= json_encode($event, JSON_THROW_ON_ERROR);
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_PIXELFORGE_EVENT_ID' => $event['id'] ?? ''];

    if ($signature !== '') {
        $server['HTTP_PIXELFORGE_SIGNATURE'] = $signature ?? pixelforgeSignature($body);
    }

    return test()->call('POST', route('webhooks.pixelforge'), server: $server, content: $body);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function eventFor(Generation $generation, string $status, array $overrides = []): array
{
    $object = $status === 'succeeded'
        ? succeededPixelforgeGeneration(['id' => $generation->pixelforge_id ?? 'gen_unknown'])
        : pixelforgeGeneration(['id' => $generation->pixelforge_id ?? 'gen_unknown', 'status' => $status]);

    $object['metadata'] = ['generation_uuid' => $generation->uuid, 'app_env' => 'testing'];

    if ($status === 'processing') {
        $object['started_at'] = '2026-11-04T14:03:13Z';
    }

    if ($status === 'failed') {
        $object['error'] = ['code' => 'generation_failed', 'message' => 'The model could not produce an image for this prompt.'];
        $object['completed_at'] = '2026-11-04T14:03:20Z';
    }

    return pixelforgeEvent("generation.{$status}", array_replace($object, $overrides));
}

describe('signature', function (): void {
    it('accepts a valid signature', function (): void {
        deliverWebhook(pixelforgeEvent('ping', []))->assertOk()->assertJson(['result' => 'ignored']);
    });

    it('rejects bad signatures with 400 and stores nothing', function (callable $sign): void {
        $event = pixelforgeEvent('ping', []);
        $body = json_encode($event, JSON_THROW_ON_ERROR);

        deliverWebhook($event, signature: $sign($body))->assertStatus(400);

        expect(WebhookEvent::query()->count())->toBe(0);
    })->with([
        'missing header' => [fn (string $body): string => ''],
        'malformed header' => [fn (string $body): string => 'garbage'],
        'no v1' => [fn (string $body): string => 't='.time()],
        'no timestamp' => [fn (string $body): string => 'v1='.hash_hmac('sha256', $body, 'whsec_test_secret')],
        'wrong secret' => [fn (string $body): string => pixelforgeSignature($body, secret: 'whsec_other')],
        'timestamp 301 s old' => [fn (string $body): string => pixelforgeSignature($body, time() - 301)],
        'timestamp 301 s ahead' => [fn (string $body): string => pixelforgeSignature($body, time() + 301)],
        'signed without timestamp prefix' => [fn (string $body): string => 't='.time().',v1='.hash_hmac('sha256', $body, 'whsec_test_secret')],
    ]);

    it('accepts a timestamp just inside the tolerance', function (): void {
        $event = pixelforgeEvent('ping', []);
        $body = json_encode($event, JSON_THROW_ON_ERROR);

        deliverWebhook($event, signature: pixelforgeSignature($body, time() - 299))->assertOk();
    });

    it('rejects a body changed after signing', function (): void {
        $generation = Generation::factory()->processing()->create();
        $event = eventFor($generation, 'succeeded');
        $signedBody = json_encode($event, JSON_THROW_ON_ERROR);
        $tamperedBody = str_replace('"reasoning_tokens":3184', '"reasoning_tokens":1', $signedBody);

        deliverWebhook($event, signature: pixelforgeSignature($signedBody), body: $tamperedBody)->assertStatus(400);

        expect($generation->refresh()->status)->toBe(GenerationStatus::Processing);
    });

    it('verifies the raw bytes, not re-encoded JSON', function (): void {
        $generation = Generation::factory()->processing()->create();
        $body = json_encode(eventFor($generation, 'succeeded'), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

        deliverWebhook(json_decode($body, true), body: $body)->assertOk();

        expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded);
    });

    it('rejects everything when no secret is configured', function (): void {
        config(['pixelforge.webhook_secret' => '']);

        deliverWebhook(pixelforgeEvent('ping', []))->assertStatus(400);
    });
});

describe('charging', function (): void {
    it('charges once when the same event is delivered twice', function (): void {
        $generation = Generation::factory()->processing()->create();
        $event = eventFor($generation, 'succeeded');

        deliverWebhook($event)->assertOk()->assertJson(['result' => 'applied']);
        deliverWebhook($event)->assertOk()->assertJson(['result' => 'duplicate']);

        $generation->refresh();
        expect($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->provider_cost?->toDecimalString())->toBe('0.305510000')
            ->and($generation->charged_amount?->toDecimalString())->toBe('0.381887500')
            ->and(LedgerEntry::query()->count())->toBe(1)
            ->and(WebhookEvent::query()->count())->toBe(1);

        $charge = LedgerEntry::query()->sole();
        expect($charge->type)->toBe(LedgerEntryType::GenerationCharge)
            ->and($charge->amount->amount)->toBe(-381_887_500)
            ->and($charge->generation_id)->toBe($generation->id)
            ->and($generation->user->refresh()->balance->amount)->toBe(-381_887_500);
    });

    it('charges once when two different events report the same success', function (): void {
        $generation = Generation::factory()->processing()->create();

        deliverWebhook(eventFor($generation, 'succeeded'))->assertOk()->assertJson(['result' => 'applied']);
        deliverWebhook(eventFor($generation, 'succeeded'))->assertOk()->assertJson(['result' => 'stale']);

        expect(LedgerEntry::query()->count())->toBe(1)
            ->and(WebhookEvent::query()->count())->toBe(2);
    });

    it('never charges a failed generation', function (): void {
        $generation = Generation::factory()->processing()->create();

        deliverWebhook(eventFor($generation, 'failed'))->assertOk()->assertJson(['result' => 'applied']);

        $generation->refresh();
        expect($generation->status)->toBe(GenerationStatus::Failed)
            ->and($generation->charged_amount?->amount)->toBe(0)
            ->and($generation->provider_cost?->amount)->toBe(0)
            ->and($generation->failure_code)->toBe('generation_failed')
            ->and($generation->failure_message)->toContain('You were not charged')
            ->and(LedgerEntry::query()->count())->toBe(0)
            ->and($generation->user->refresh()->balance->amount)->toBe(0);
    });

    it('cannot store a second charge for the same generation even if the logic slipped', function (): void {
        $generation = Generation::factory()->succeeded()->create();
        LedgerEntry::query()->create(['user_id' => $generation->user_id, 'generation_id' => $generation->id, 'type' => LedgerEntryType::GenerationCharge, 'amount' => $generation->charged_amount]);

        expect(fn () => LedgerEntry::query()->create(['user_id' => $generation->user_id, 'generation_id' => $generation->id, 'type' => LedgerEntryType::GenerationCharge, 'amount' => $generation->charged_amount]))
            ->toThrow(UniqueConstraintViolationException::class);
    });
});

describe('ordering', function (): void {
    it('keeps succeeded when processing arrives late', function (): void {
        $generation = Generation::factory()->processing()->create();

        deliverWebhook(eventFor($generation, 'succeeded'))->assertOk();
        deliverWebhook(eventFor($generation, 'processing'))->assertOk()->assertJson(['result' => 'stale']);

        expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded)
            ->and(LedgerEntry::query()->count())->toBe(1);
    });

    it('keeps failed when processing arrives late', function (): void {
        $generation = Generation::factory()->processing()->create();

        deliverWebhook(eventFor($generation, 'failed'))->assertOk();
        deliverWebhook(eventFor($generation, 'processing'))->assertOk()->assertJson(['result' => 'stale']);

        expect($generation->refresh()->status)->toBe(GenerationStatus::Failed);
    });

    it('never turns a failure into a success or the other way round', function (): void {
        $generation = Generation::factory()->processing()->create();

        deliverWebhook(eventFor($generation, 'failed'))->assertOk();
        deliverWebhook(eventFor($generation, 'succeeded'))->assertOk()->assertJson(['result' => 'stale']);

        expect($generation->refresh()->status)->toBe(GenerationStatus::Failed)
            ->and(LedgerEntry::query()->count())->toBe(0);
    });

    it('applies succeeded without ever seeing processing', function (): void {
        $generation = Generation::factory()->sent()->create();

        deliverWebhook(eventFor($generation, 'succeeded'))->assertOk()->assertJson(['result' => 'applied']);

        $generation->refresh();
        expect($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->started_at?->toIso8601ZuluString())->toBe('2026-11-04T14:03:13Z')
            ->and($generation->completed_at?->toIso8601ZuluString())->toBe('2026-11-04T14:03:29Z')
            ->and($generation->image_url)->not->toBeNull()
            ->and($generation->usage()?->toArray())->toBe(['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184]);
    });

    it('moves queued to processing', function (): void {
        $generation = Generation::factory()->sent()->create();

        deliverWebhook(eventFor($generation, 'processing'))->assertOk()->assertJson(['result' => 'applied']);

        expect($generation->refresh()->status)->toBe(GenerationStatus::Processing)
            ->and($generation->started_at?->toIso8601ZuluString())->toBe('2026-11-04T14:03:13Z');
    });
});

describe('matching', function (): void {
    it('finds the generation by uuid when the webhook beats the POST response', function (): void {
        $generation = Generation::factory()->create();
        $event = eventFor($generation, 'succeeded', ['id' => 'gen_early']);

        deliverWebhook($event)->assertOk()->assertJson(['result' => 'applied']);

        $generation->refresh();
        expect($generation->pixelforge_id)->toBe('gen_early')
            ->and($generation->rates)->not->toBeNull()
            ->and($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and(LedgerEntry::query()->count())->toBe(1);
    });

    it('records but ignores events for generations it does not know', function (): void {
        $event = pixelforgeEvent('generation.succeeded', succeededPixelforgeGeneration(['id' => 'gen_stranger', 'metadata' => []]));

        deliverWebhook($event)->assertOk()->assertJson(['result' => 'unknown']);

        expect(WebhookEvent::query()->sole()->result?->value)->toBe('unknown')
            ->and(LedgerEntry::query()->count())->toBe(0);
    });

    it('ignores events from another environment even if the uuid matches', function (): void {
        $generation = Generation::factory()->create();
        $event = eventFor($generation, 'succeeded', ['id' => 'gen_prod']);
        $event['data']['object']['metadata']['app_env'] = 'production';

        deliverWebhook($event)->assertOk()->assertJson(['result' => 'unknown']);

        expect($generation->refresh()->status)->toBe(GenerationStatus::Queued);
    });

    it('stores the event with its payload and processing time', function (): void {
        $generation = Generation::factory()->processing()->create();
        $event = eventFor($generation, 'succeeded');

        deliverWebhook($event)->assertOk();

        $stored = WebhookEvent::query()->sole();
        expect($stored->event_id)->toBe($event['id'])
            ->and($stored->type)->toBe('generation.succeeded')
            ->and($stored->pixelforge_generation_id)->toBe($generation->pixelforge_id)
            ->and($stored->payload['data']['object']['usage']['reasoning_tokens'])->toBe(3184)
            ->and($stored->processed_at)->not->toBeNull();
    });

    it('rejects a signed body that is not an event', function (): void {
        deliverWebhook(['id' => 'x'], body: '"just a string"')->assertStatus(400);
    });
});

describe('local failure, provider success', function (): void {
    it('records the provider cost but does not charge or resurrect a generation we gave up on', function (): void {
        $generation = Generation::factory()->create([
            'status' => GenerationStatus::Failed,
            'failure_code' => 'retries_exhausted',
            'provider_cost' => Nanodollars::zero(),
            'charged_amount' => Nanodollars::zero(),
        ]);
        $event = eventFor($generation, 'succeeded', ['id' => 'gen_late']);

        deliverWebhook($event)->assertOk()->assertJson(['result' => 'billed_after_local_failure']);
        deliverWebhook($event)->assertOk()->assertJson(['result' => 'duplicate']);
        deliverWebhook(eventFor($generation->refresh(), 'succeeded'))->assertOk()->assertJson(['result' => 'stale']);

        $generation->refresh();
        expect($generation->status)->toBe(GenerationStatus::Failed)
            ->and($generation->pixelforge_id)->toBe('gen_late')
            ->and($generation->provider_cost?->toDecimalString())->toBe('0.305510000')
            ->and($generation->charged_amount?->amount)->toBe(0)
            ->and(LedgerEntry::query()->count())->toBe(0);
    });
});
