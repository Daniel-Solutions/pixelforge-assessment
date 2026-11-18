<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\AdminReportRow;
use App\Data\UsageComparison;
use App\Enums\GenerationStatus;
use App\Exceptions\PixelforgeException;
use App\Models\Generation;
use App\Money\Nanodollars;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Revenue, provider cost and profit per UTC day of completion and per user. The day is the day
 * the generation completed, the same rule Pixelforge uses in /v1/usage, so each day can be checked
 * against what Pixelforge reports. All sums are integer SUMs in the database.
 */
class BuildAdminReport
{
    /** /v1/usage is cached briefly: the report is opened often and the rate limit is shared with sending. */
    private const int USAGE_CACHE_SECONDS = 60;

    public function __construct(private CompareUsageWithPixelforge $compareUsageWithPixelforge) {}

    /**
     * @return array{days: list<array{date: string, total: array<string, mixed>, pixelforge_cost: array{display: string, exact: string}|null, matches: bool|null, difference: array{display: string, exact: string}|null, users: list<array{user_id: int, name: string, email: string, totals: array<string, mixed>}>}>, users: list<array{user_id: int, name: string, email: string, totals: array<string, mixed>}>, total: array<string, mixed>, pixelforge_available: bool}
     */
    public function handle(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = Generation::query()
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->whereNotNull('generations.completed_at')
            ->whereBetween('generations.completed_at', [$from->startOfDay(), $to->endOfDay()])
            ->selectRaw('DATE(generations.completed_at) as day, generations.user_id, users.name, users.email')
            ->selectRaw('SUM(CASE WHEN generations.status = ? THEN 1 ELSE 0 END) as succeeded', [GenerationStatus::Succeeded->value])
            ->selectRaw('SUM(CASE WHEN generations.status = ? THEN 1 ELSE 0 END) as failed', [GenerationStatus::Failed->value])
            ->selectRaw('COALESCE(SUM(generations.charged_amount), 0) as charged_sum, COALESCE(SUM(generations.provider_cost), 0) as provider_cost_sum')
            ->groupBy('day', 'generations.user_id', 'users.name', 'users.email')
            ->orderByDesc('day')
            ->orderBy('users.name')
            ->toBase()
            ->get();

        $pixelforge = $this->pixelforgeByDay($from, $to);

        $days = [];
        $users = [];
        $total = AdminReportRow::empty();

        foreach ($rows as $row) {
            $sums = new AdminReportRow(
                succeeded: (int) $row->succeeded,
                failed: (int) $row->failed,
                charged: Nanodollars::of((int) $row->charged_sum),
                providerCost: Nanodollars::of((int) $row->provider_cost_sum),
            );
            $day = (string) $row->day;
            $user = ['user_id' => (int) $row->user_id, 'name' => (string) $row->name, 'email' => (string) $row->email];

            $days[$day]['total'] = ($days[$day]['total'] ?? AdminReportRow::empty())->plus($sums);
            $days[$day]['users'][] = [...$user, 'totals' => $sums->toArray()];
            $users[$user['user_id']] = [...$user, 'sums' => ($users[$user['user_id']]['sums'] ?? AdminReportRow::empty())->plus($sums)];
            $total = $total->plus($sums);
        }

        return [
            'days' => array_map(function (string $date, array $day) use ($pixelforge): array {
                /** @var AdminReportRow $sums */
                $sums = $day['total'];
                $reported = $pixelforge[$date] ?? null;

                return [
                    'date' => $date,
                    'total' => $sums->toArray(),
                    'pixelforge_cost' => $reported?->pixelforgeCost->forDisplay(9),
                    'matches' => $reported === null ? null : $reported->pixelforgeCost->amount === $sums->providerCost->amount,
                    'difference' => $reported === null ? null : $sums->providerCost->subtract($reported->pixelforgeCost)->forDisplay(9),
                    'users' => $day['users'],
                ];
            }, array_keys($days), $days),
            'users' => array_values(array_map(fn (array $user): array => [
                'user_id' => $user['user_id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'totals' => $user['sums']->toArray(),
            ], $users)),
            'total' => $total->toArray(),
            'pixelforge_available' => $pixelforge !== null,
        ];
    }

    /**
     * @return array<string, UsageComparison>|null null when Pixelforge cannot be asked (error, or range over 92 days)
     */
    private function pixelforgeByDay(CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        try {
            $comparisons = Cache::remember(
                "pixelforge.usage.{$from->toDateString()}.{$to->toDateString()}",
                self::USAGE_CACHE_SECONDS,
                fn (): array => $this->compareUsageWithPixelforge->handle($from, $to),
            );
        } catch (PixelforgeException) {
            return null;
        }

        $byDay = [];

        foreach ($comparisons as $comparison) {
            $byDay[$comparison->date] = $comparison;
        }

        return $byDay;
    }
}
