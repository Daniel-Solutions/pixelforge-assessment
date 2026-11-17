<?php

declare(strict_types=1);

namespace App\Enums;

enum LedgerEntryType: string
{
    case Purchase = 'purchase';
    case GenerationCharge = 'generation_charge';
}
