<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\LedgerEntryType;
use App\Models\User;
use App\Money\Nanodollars;

/**
 * New users start with a small credit so they can try a few generations.
 */
class GrantWelcomeCredit
{
    public function __construct(private RecordLedgerEntry $recordLedgerEntry) {}

    public function handle(User $user): void
    {
        $this->recordLedgerEntry->handle(
            userId: $user->id,
            type: LedgerEntryType::Purchase,
            amount: Nanodollars::of(config()->integer('pixelforge.welcome_credit_nanodollars')),
            purchaseToken: "welcome-{$user->id}",
        );
    }
}
