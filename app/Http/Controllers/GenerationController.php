<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SubmitGeneration;
use App\Enums\Quality;
use App\Enums\Size;
use App\Exceptions\InsufficientCredits;
use App\Exceptions\PixelforgeException;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    private const int PER_PAGE = 12;

    public function create(PixelforgeClient $pixelforge): Response
    {
        try {
            $pricing = $pixelforge->getPricing()->toArray();
        } catch (PixelforgeException) {
            $pricing = null;
        }

        return Inertia::render('generations/Create', [
            'pricing' => $pricing,
            'markupPercent' => config()->integer('pixelforge.markup_percent'),
            'sizes' => array_map(fn (Size $size): array => ['value' => $size->value, 'label' => $size->label()], Size::cases()),
            'qualities' => array_map(fn (Quality $quality): array => ['value' => $quality->value, 'label' => $quality->label()], Quality::cases()),
            'limits' => [
                'promptCharacters' => StoreGenerationRequest::MAX_PROMPT_CHARACTERS,
                'referenceImages' => StoreGenerationRequest::MAX_REFERENCE_IMAGES,
            ],
        ]);
    }

    public function store(StoreGenerationRequest $request, SubmitGeneration $submitGeneration): RedirectResponse
    {
        try {
            $submitGeneration->handle($this->user($request), $request->toInput());
        } catch (InsufficientCredits $exception) {
            return back()->withErrors(['credits' => $exception->getMessage()]);
        } catch (PixelforgeException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Pricing is unavailable right now, so nothing was submitted. Try again in a minute.']);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Generation submitted. You pay only if it succeeds.']);

        return to_route('generations.index');
    }

    public function index(Request $request): Response
    {
        $generations = $this->user($request)->generations()->latest('id')->paginate(self::PER_PAGE);
        $hasPending = $generations->getCollection()->contains(fn (Generation $generation): bool => ! $generation->status->isFinal());

        return Inertia::render('generations/Index', [
            'generations' => GenerationResource::collection($generations),
            'hasPending' => $hasPending,
        ]);
    }

    public function show(Generation $generation): Response
    {
        $this->authorize('view', $generation);

        return Inertia::render('generations/Show', [
            'generation' => GenerationResource::make($generation),
            'hasPending' => ! $generation->status->isFinal(),
        ]);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
