<?php

declare(strict_types=1);

use App\Models\Generation;
use App\Money\Nanodollars;
use Illuminate\Support\Facades\Http;

/**
 * @param  array<string, int|string>  $day
 * @return array<string, mixed>
 */
function usageResponse(array $day): array
{
    $zero = ['generations' => 0, 'input_text_tokens' => 0, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0, 'cost_usd' => '0.000000000'];

    return [
        'object' => 'usage',
        'start_date' => '2026-11-01',
        'end_date' => '2026-11-02',
        'data' => [
            array_replace($zero, ['date' => '2026-11-01'], $day),
            array_replace($zero, ['date' => '2026-11-02']),
        ],
    ];
}

beforeEach(function (): void {
    // Two succeeded generations on Nov 1 (UTC), one just before midnight; failures cost nothing.
    Generation::factory()->succeeded()->create([
        'input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184,
        'provider_cost' => Nanodollars::of(305_510_000), 'completed_at' => '2026-11-01 08:00:00',
    ]);
    Generation::factory()->succeeded()->create([
        'input_text_tokens' => 41, 'input_image_tokens' => 0, 'output_tokens' => 3300, 'reasoning_tokens' => 2950,
        'provider_cost' => Nanodollars::of(169_080_000), 'completed_at' => '2026-11-01 23:59:59',
    ]);
    Generation::factory()->failed()->create(['completed_at' => '2026-11-01 12:00:00']);
});

it('reports zero difference when every day matches', function (): void {
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(usageResponse([
        'generations' => 2, 'input_text_tokens' => 53, 'input_image_tokens' => 765, 'output_tokens' => 9750, 'reasoning_tokens' => 6134, 'cost_usd' => '0.474590000',
    ]))]);

    $this->artisan('pixelforge:reconcile-usage', ['--from' => '2026-11-01', '--to' => '2026-11-02'])
        ->expectsOutputToContain('0.474590000')
        ->expectsOutputToContain('All days match: difference 0.000000000.')
        ->assertSuccessful();
});

it('prints the difference and fails when a day does not match', function (): void {
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(usageResponse([
        'generations' => 3, 'input_text_tokens' => 60, 'input_image_tokens' => 765, 'output_tokens' => 10030, 'reasoning_tokens' => 6134, 'cost_usd' => '0.486090000',
    ]))]);

    $this->artisan('pixelforge:reconcile-usage', ['--from' => '2026-11-01', '--to' => '2026-11-02'])
        ->expectsOutputToContain('-0.011500000')
        ->expectsOutputToContain('1 day(s) differ.')
        ->assertFailed();
});

it('reports a range Pixelforge refuses', function (): void {
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(pixelforgeError('date_range_too_long', 'Too long.'), 422)]);

    $this->artisan('pixelforge:reconcile-usage', ['--from' => '2026-01-01', '--to' => '2026-11-02'])
        ->expectsOutputToContain('date_range_too_long')
        ->assertFailed();
});

it('flags a day where we recorded cost but Pixelforge reported none', function (): void {
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(['object' => 'usage', 'data' => []])]);

    $this->artisan('pixelforge:reconcile-usage', ['--from' => '2026-11-01', '--to' => '2026-11-02'])
        ->expectsOutputToContain('2026-11-01')
        ->expectsOutputToContain('1 day(s) differ.')
        ->assertFailed();
});
