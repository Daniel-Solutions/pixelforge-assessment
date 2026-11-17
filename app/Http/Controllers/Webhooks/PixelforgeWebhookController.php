<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Actions\ProcessPixelforgeWebhook;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PixelforgeWebhookController extends Controller
{
    /**
     * The signature was verified over these exact bytes, so we parse the same bytes.
     */
    public function __invoke(Request $request, ProcessPixelforgeWebhook $processPixelforgeWebhook): JsonResponse
    {
        $event = json_decode($request->getContent(), true);

        if (! is_array($event) || ! is_string($event['id'] ?? null) || ! is_string($event['type'] ?? null)) {
            return response()->json(['error' => 'invalid_payload'], Response::HTTP_BAD_REQUEST);
        }

        /** @var array{id: string, type: string, data?: array{object?: array<string, mixed>}} $event */
        $result = $processPixelforgeWebhook->handle($event);

        return response()->json(['received' => true, 'result' => $result->value]);
    }
}
