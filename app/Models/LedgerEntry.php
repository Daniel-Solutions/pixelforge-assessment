<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\AsNanodollars;
use App\Enums\LedgerEntryType;
use App\Money\Nanodollars;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $generation_id
 * @property LedgerEntryType $type
 * @property Nanodollars $amount
 * @property string|null $purchase_token
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class LedgerEntry extends Model
{
    protected function casts(): array
    {
        return [
            'type' => LedgerEntryType::class,
            'amount' => AsNanodollars::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Generation, $this>
     */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}
