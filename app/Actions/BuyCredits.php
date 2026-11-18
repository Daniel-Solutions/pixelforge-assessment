<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\CreditPackage;
use App\Enums\LedgerEntryType;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * A fake purchase: one positive ledger entry. The form sends a one-time token, so a double click
 * (or a resubmitted form) buys once; the unique purchase_token index is the backstop.
 */
class BuyCredits
{
    public function __construct(private RecordLedgerEntry $recordLedgerEntry) {}

    /**
     * @return bool false when this token was already used (nothing bought again)
     */
    public function handle(User $user, CreditPackage $package, string $purchaseToken): bool
    {
        if (LedgerEntry::query()->where('purchase_token', $purchaseToken)->exists()) {
            return false;
        }

        try {
            $this->recordLedgerEntry->handle(
                userId: $user->id,
                type: LedgerEntryType::Purchase,
                amount: $package->amount(),
                purchaseToken: $purchaseToken,
            );
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }
}
