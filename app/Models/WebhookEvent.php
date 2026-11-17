<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StateChange;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $event_id
 * @property string $type
 * @property string|null $pixelforge_generation_id
 * @property StateChange|null $result
 * @property array<string, mixed> $payload
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $processed_at
 */
class WebhookEvent extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'result' => StateChange::class,
            'payload' => 'array',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
