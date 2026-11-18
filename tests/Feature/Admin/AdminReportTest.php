<?php

declare(strict_types=1);

use App\Models\Generation;
use App\Models\User;
use App\Money\Nanodollars;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @param  list<array{date: string, cost: string}>  $days
 */
function fakeUsage(array $days): void
{
    Http::fake(['pixelforge.test/v1/usage*' => Http::response([
        'object' => 'usage',
        'data' => array_map(fn (array $day): array => [
            'date' => $day['date'], 'generations' => 0, 'input_text_tokens' => 0, 'input_image_tokens' => 0,
            'output_tokens' => 0, 'reasoning_tokens' => 0, 'cost_usd' => $day['cost'],
        ], $days),
    ])]);
}

beforeEach(function (): void {
    $this->alice = User::factory()->create(['name' => 'Alice']);
    $this->bob = User::factory()->create(['name' => 'Bob']);

    $succeeded = fn (User $user, string $completedAt, int $cost) => Generation::factory()->for($user)->succeeded()->create([
        'completed_at' => $completedAt,
        'provider_cost' => Nanodollars::of($cost),
        'charged_amount' => Nanodollars::of($cost)->withMarkup(25),
    ]);

    $succeeded($this->alice, '2026-11-01 08:00:00', 305_510_000);
    $succeeded($this->bob, '2026-11-01 23:59:59', 305_510_000);
    $succeeded($this->alice, '2026-11-02 00:00:05', 169_080_000);
    $succeeded($this->alice, '2026-10-20 12:00:00', 169_080_000);
    Generation::factory()->for($this->alice)->failed()->create(['completed_at' => '2026-11-01 12:00:00']);
    Generation::factory()->for($this->alice)->processing()->create();
});

it('sums revenue, provider cost and profit per UTC day of completion and per user', function (): void {
    fakeUsage([['date' => '2026-11-01', 'cost' => '0.611020000'], ['date' => '2026-11-02', 'cost' => '0.170000000']]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.report', ['from' => '2026-11-01', 'to' => '2026-11-02']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Report')
            ->has('report.days', 2)
            ->where('report.days.0.date', '2026-11-02')
            ->where('report.days.0.total.succeeded', 1)
            ->where('report.days.0.total.charged.exact', '0.211350000')
            ->where('report.days.0.total.provider_cost.exact', '0.169080000')
            ->where('report.days.0.total.profit.exact', '0.042270000')
            ->where('report.days.0.matches', false)
            ->where('report.days.0.difference.exact', '-0.000920000')
            ->where('report.days.1.date', '2026-11-01')
            ->where('report.days.1.total.succeeded', 2)
            ->where('report.days.1.total.failed', 1)
            ->where('report.days.1.total.charged.exact', '0.763775000')
            ->where('report.days.1.total.provider_cost.exact', '0.611020000')
            ->where('report.days.1.total.profit.exact', '0.152755000')
            ->where('report.days.1.pixelforge_cost.exact', '0.611020000')
            ->where('report.days.1.matches', true)
            ->has('report.days.1.users', 2)
            ->where('report.users.0.name', 'Alice')
            ->where('report.users.0.totals.succeeded', 2)
            ->where('report.users.0.totals.failed', 1)
            ->where('report.users.0.totals.charged.exact', '0.593237500')
            ->where('report.users.0.totals.provider_cost.exact', '0.474590000')
            ->where('report.users.1.name', 'Bob')
            ->where('report.users.1.totals.profit.exact', '0.076377500')
            ->where('report.total.succeeded', 3)
            ->where('report.total.failed', 1)
            ->where('report.total.charged.exact', '0.975125000')
            ->where('report.total.provider_cost.exact', '0.780100000')
            ->where('report.total.profit.exact', '0.195025000')
            ->where('report.pixelforge_available', true));
});

it('defaults to the last 30 days', function (): void {
    $this->travelTo('2026-11-17 10:00:00');
    fakeUsage([]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.report'))
        ->assertInertia(fn (Assert $page) => $page->where('from', '2026-10-19')->where('to', '2026-11-17')->has('report.days', 3));
});

it('still shows our numbers when Pixelforge usage is unavailable', function (): void {
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(pixelforgeError('date_range_too_long'), 422)]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.report', ['from' => '2026-01-01', 'to' => '2026-11-02']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.pixelforge_available', false)
            ->where('report.days.0.pixelforge_cost', null)
            ->where('report.total.succeeded', 4));
});

it('rejects an inverted range', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.report', ['from' => '2026-11-02', 'to' => '2026-11-01']))
        ->assertSessionHasErrors('to');
});

it('is only for admins', function (): void {
    $this->actingAs($this->alice)->get(route('admin.report'))->assertForbidden();
    $this->post(route('logout'));
    $this->get(route('admin.report'))->assertRedirect(route('login'));
});
