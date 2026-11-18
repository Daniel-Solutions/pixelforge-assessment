<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\BuyCredits;
use App\Enums\CreditPackage;
use App\Http\Requests\BuyCreditsRequest;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CreditsController extends Controller
{
    private const int RECENT_ENTRIES = 20;

    public function index(Request $request): Response
    {
        return Inertia::render('credits/Index', [
            'packages' => array_map(fn (CreditPackage $package): array => [
                'value' => $package->value,
                'amount' => $package->amount()->forDisplay(2),
            ], CreditPackage::cases()),
            'entries' => $this->user($request)->ledgerEntries()->with('generation:id,uuid,prompt')->latest('id')->limit(self::RECENT_ENTRIES)->get()
                ->map(fn (LedgerEntry $entry): array => [
                    'id' => $entry->id,
                    'type' => $entry->type->value,
                    'amount' => $entry->amount->forDisplay(),
                    'generation' => $entry->generation === null ? null : ['id' => $entry->generation->uuid, 'prompt' => $entry->generation->prompt],
                    'created_at' => $entry->created_at->toIso8601ZuluString(),
                ]),
        ]);
    }

    public function store(BuyCreditsRequest $request, BuyCredits $buyCredits): RedirectResponse
    {
        $bought = $buyCredits->handle($this->user($request), $request->package(), $request->purchaseToken());

        Inertia::flash('toast', $bought
            ? ['type' => 'success', 'message' => "Added {$request->package()->amount()->format(2)} in credits."]
            : ['type' => 'info', 'message' => 'That purchase was already recorded.']);

        return to_route('credits.index');
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
