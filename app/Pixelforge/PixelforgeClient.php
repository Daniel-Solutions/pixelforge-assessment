<?php

declare(strict_types=1);

namespace App\Pixelforge;

use App\Enums\PixelforgeErrorCode;
use App\Exceptions\PixelforgeException;
use App\Pixelforge\Data\CreatedGeneration;
use App\Pixelforge\Data\NewGeneration;
use App\Pixelforge\Data\Pricing;
use App\Pixelforge\Data\RemoteGeneration;
use App\Pixelforge\Data\UsageDay;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The only place that talks to Pixelforge: it builds URLs, reads raw JSON and turns errors into PixelforgeException.
 */
class PixelforgeClient
{
    public const string PRICING_CACHE_KEY = 'pixelforge.pricing';

    /**
     * POST /v1/generations. A replay (same key, same body) returns the existing generation and is not billed again.
     *
     * @throws PixelforgeException
     */
    public function createGeneration(NewGeneration $generation, string $idempotencyKey): CreatedGeneration
    {
        $response = $this->send('POST /generations', fn (): Response => $this->request()
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->post('/generations', $generation->toArray()));

        return new CreatedGeneration(
            generation: RemoteGeneration::fromArray($response->json()),
            replayed: $response->header('Idempotent-Replayed') === 'true',
        );
    }

    /**
     * GET /v1/generations/{id}
     *
     * @throws PixelforgeException
     */
    public function getGeneration(string $pixelforgeId): RemoteGeneration
    {
        $response = $this->send('GET /generations/{id}', fn (): Response => $this->request()
            ->get('/generations/'.rawurlencode($pixelforgeId)));

        return RemoteGeneration::fromArray($response->json());
    }

    /**
     * GET /v1/pricing (no auth), cached. Feeds estimates only; real costs use the generation's own rates.
     *
     * @throws PixelforgeException
     */
    public function getPricing(): Pricing
    {
        /** @var array{rates: array{input_text_tokens: string, input_image_tokens: string, output_tokens: string, reasoning_tokens: string}, tokens: array{characters_per_text_token: int, text_token_variance: float|int|string, per_reference_image: int, output: array<string, array<string, int>>, reasoning: array<string, array{min: int, max: int}>}} $pricing */
        $pricing = Cache::remember(
            self::PRICING_CACHE_KEY,
            config()->integer('pixelforge.pricing_cache_seconds'),
            fn (): array => $this->send('GET /pricing', fn (): Response => Http::baseUrl($this->baseUrl())
                ->acceptJson()
                ->timeout(config()->integer('pixelforge.timeout'))
                ->get('/pricing'))->json(),
        );

        return Pricing::fromArray($pricing);
    }

    /**
     * GET /v1/usage: billed usage per UTC day for this API key (inclusive, at most 92 days).
     *
     * @return list<UsageDay>
     *
     * @throws PixelforgeException
     */
    public function getUsage(CarbonImmutable $startDate, CarbonImmutable $endDate): array
    {
        $response = $this->send('GET /usage', fn (): Response => $this->request()->get('/usage', [
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
        ]));

        /** @var list<array{date: string, generations: int, input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int, cost_usd: string}> $days */
        $days = $response->json('data');

        return array_map(UsageDay::fromArray(...), $days);
    }

    /**
     * @throws PixelforgeException when no API key is configured (a deploy mistake, not a user error)
     */
    private function request(): PendingRequest
    {
        $apiKey = config('pixelforge.api_key');

        if (! is_string($apiKey) || $apiKey === '') {
            Log::critical('pixelforge.api_key_missing');

            throw new PixelforgeException(PixelforgeErrorCode::InvalidApiKey, 'PIXELFORGE_API_KEY is not set.');
        }

        return Http::baseUrl($this->baseUrl())
            ->withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout(config()->integer('pixelforge.timeout'));
    }

    private function baseUrl(): string
    {
        return rtrim(config()->string('pixelforge.base_url'), '/');
    }

    /**
     * Runs one request, logs it, and throws PixelforgeException on anything but 2xx.
     *
     * @param  callable(): Response  $request
     *
     * @throws PixelforgeException
     */
    private function send(string $endpoint, callable $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException $exception) {
            Log::warning('pixelforge.request', ['endpoint' => $endpoint, 'status' => null, 'code' => PixelforgeErrorCode::ConnectionFailed->value]);

            throw new PixelforgeException(PixelforgeErrorCode::ConnectionFailed, $exception->getMessage());
        }

        if ($response->successful()) {
            Log::info('pixelforge.request', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'rate_limit_remaining' => $response->header('X-RateLimit-Remaining') ?: null,
            ]);

            return $response;
        }

        $exception = $this->toException($response);

        Log::log($exception->errorCode->isOurBug() ? 'critical' : 'warning', 'pixelforge.request', [
            'endpoint' => $endpoint,
            'status' => $response->status(),
            'code' => $exception->errorCode->value,
            'retry_after' => $exception->retryAfter,
            'message' => $exception->getMessage(),
        ]);

        throw $exception;
    }

    private function toException(Response $response): PixelforgeException
    {
        $code = PixelforgeErrorCode::tryFrom((string) $response->json('error.code')) ?? PixelforgeErrorCode::Unknown;

        $retryAfter = $response->header('Retry-After');

        return new PixelforgeException(
            errorCode: $code,
            message: (string) ($response->json('error.message') ?? "Pixelforge returned HTTP {$response->status()}."),
            httpStatus: $response->status(),
            retryAfter: is_numeric($retryAfter) ? (int) $retryAfter : null,
        );
    }
}
