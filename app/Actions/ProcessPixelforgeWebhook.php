<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\StateChange;
use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Pixelforge\Data\RemoteGeneration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Records a verified webhook event and applies it, in one transaction. A redelivered event id is a
 * no-op; if applying throws, the event row rolls back too and Pixelforge's retry gets a fresh chance.
 */
class ProcessPixelforgeWebhook
{
    private const string GENERATION_EVENT_PREFIX = 'generation.';

    public function __construct(private ApplyGenerationState $applyGenerationState) {}

    /**
     * @param  array{id: string, type: string, data?: array{object?: array<string, mixed>}}  $event
     */
    public function handle(array $event): StateChange
    {
        /** @var array<string, mixed>|null $object */
        $object = $event['data']['object'] ?? null;

        $result = DB::transaction(function () use ($event, $object): StateChange {
            $inserted = WebhookEvent::query()->insertOrIgnore([
                'event_id' => $event['id'],
                'type' => $event['type'],
                'pixelforge_generation_id' => is_string($object['id'] ?? null) ? $object['id'] : null,
                'payload' => json_encode($event, JSON_THROW_ON_ERROR),
                'received_at' => now(),
            ]);

            if ($inserted === 0) {
                return StateChange::Duplicate;
            }

            $result = $this->apply($event['type'], $object);

            WebhookEvent::query()->where('event_id', $event['id'])->update([
                'result' => $result->value,
                'processed_at' => now(),
            ]);

            return $result;
        });

        Log::info('pixelforge.webhook', [
            'event_id' => $event['id'],
            'type' => $event['type'],
            'pixelforge_id' => $object['id'] ?? null,
            'result' => $result->value,
        ]);

        return $result;
    }

    /**
     * @param  array<string, mixed>|null  $object
     */
    private function apply(string $type, ?array $object): StateChange
    {
        if (! str_starts_with($type, self::GENERATION_EVENT_PREFIX) || $object === null) {
            return StateChange::Ignored;
        }

        /** @phpstan-ignore argument.type (the payload is the documented generation object) */
        $remote = RemoteGeneration::fromArray($object);
        $generation = $this->findLocalGeneration($remote);

        if ($generation === null) {
            return StateChange::Unknown;
        }

        return $this->applyGenerationState->handle($generation, $remote);
    }

    /**
     * By Pixelforge id, or by our uuid in metadata when the webhook beat the POST response.
     * Events for another environment's generations are not ours, even if a row happens to match.
     */
    private function findLocalGeneration(RemoteGeneration $remote): ?Generation
    {
        if ($remote->appEnv() !== null && $remote->appEnv() !== config()->string('app.env')) {
            return null;
        }

        return Generation::query()->where('pixelforge_id', $remote->id)->first()
            ?? ($remote->generationUuid() === null ? null : Generation::query()->where('uuid', $remote->generationUuid())->first());
    }
}
