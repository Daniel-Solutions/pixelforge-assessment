<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pixelforge-Signature: t=<unix>,v1=<hex>. v1 is HMAC-SHA256 of "{t}.{raw body}" with the webhook secret.
 * Anything missing, malformed, wrong or older than the tolerance gets a 400 and nothing else happens.
 */
class VerifyPixelforgeSignature
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $reason = $this->rejectionReason($request);

        if ($reason !== null) {
            Log::warning('pixelforge.webhook.rejected', ['reason' => $reason, 'event_id' => $request->header('Pixelforge-Event-Id')]);

            return response()->json(['error' => 'invalid_signature'], Response::HTTP_BAD_REQUEST);
        }

        return $next($request);
    }

    private function rejectionReason(Request $request): ?string
    {
        $secret = config('pixelforge.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            Log::critical('pixelforge.webhook.secret_missing');

            return 'secret_not_configured';
        }

        $parts = $this->parse((string) $request->header('Pixelforge-Signature', ''));

        if ($parts === null) {
            return 'missing_or_malformed_header';
        }

        if (abs(now()->getTimestamp() - $parts['timestamp']) > config()->integer('pixelforge.webhook_tolerance_seconds')) {
            return 'timestamp_outside_tolerance';
        }

        $expected = hash_hmac('sha256', $parts['timestamp'].'.'.$request->getContent(), $secret);

        foreach ($parts['signatures'] as $signature) {
            if (hash_equals($expected, $signature)) {
                return null;
            }
        }

        return 'signature_mismatch';
    }

    /**
     * @return array{timestamp: int, signatures: list<string>}|null
     */
    private function parse(string $header): ?array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '' && ctype_xdigit($value)) {
                $signatures[] = strtolower($value);
            }
        }

        return $timestamp === null || $signatures === [] ? null : ['timestamp' => $timestamp, 'signatures' => $signatures];
    }
}
