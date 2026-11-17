<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\LedgerEntryType;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Money\Nanodollars;
use Illuminate\Support\Facades\DB;

/**
 * Appends a money movement and updates the cached balance. The ledger is the truth;
 * users.balance is a cache kept in step under a row lock on the user.
 */
class RecordLedgerEntry
{
    public function handle(
        int $userId,
        LedgerEntryType $type,
        Nanodollars $amount,
        ?Generation $generation = null,
        ?string $purchaseToken = null,
    ): LedgerEntry {
        return DB::transaction(function () use ($userId, $type, $amount, $generation, $purchaseToken): LedgerEntry {
            $user = User::query()->lockForUpdate()->findOrFail($userId);

            $entry = LedgerEntry::query()->create([
                'user_id' => $user->id,
                'generation_id' => $generation?->id,
                'type' => $type,
                'amount' => $amount,
                'purchase_token' => $purchaseToken,
            ]);

            $user->update(['balance' => $user->balance->add($amount)]);

            return $entry;
        });
    }
}
