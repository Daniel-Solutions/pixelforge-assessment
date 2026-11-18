<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SubmitGeneration;
use App\Exceptions\InsufficientCredits;
use App\Exceptions\PixelforgeException;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * "Retry" on a failed generation submits the same request as a brand-new generation (new Idempotency-Key).
 */
class RetryGenerationController extends Controller
{
    public function __invoke(Request $request, Generation $generation, SubmitGeneration $submitGeneration): RedirectResponse
    {
        $this->authorize('retry', $generation);

        /** @var User $user */
        $user = $request->user();

        try {
            $submitGeneration->handle($user, $generation->toInput());
        } catch (InsufficientCredits $exception) {
            return back()->withErrors(['credits' => $exception->getMessage()]);
        } catch (PixelforgeException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Pricing is unavailable right now, so nothing was submitted. Try again in a minute.']);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Submitted again as a new generation.']);

        return to_route('generations.index');
    }
}
