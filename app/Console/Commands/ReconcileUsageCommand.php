<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\CompareUsageWithPixelforge;
use App\Data\UsageComparison;
use App\Exceptions\PixelforgeException;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('pixelforge:reconcile-usage {--from= : First UTC day (YYYY-MM-DD), default 30 days ago} {--to= : Last UTC day (YYYY-MM-DD), default today}')]
#[Description('Compare our provider cost per UTC day with Pixelforge GET /v1/usage')]
class ReconcileUsageCommand extends Command
{
    public function handle(CompareUsageWithPixelforge $compareUsageWithPixelforge): int
    {
        $to = CarbonImmutable::parse($this->option('to') ?? 'today', 'UTC');
        $from = CarbonImmutable::parse($this->option('from') ?? $to->subDays(29)->toDateString(), 'UTC');

        try {
            $days = $compareUsageWithPixelforge->handle($from, $to);
        } catch (PixelforgeException $exception) {
            $this->error("Pixelforge refused the usage request: {$exception->errorCode->value} ({$exception->getMessage()})");

            return self::FAILURE;
        }

        $this->table(
            ['Date (UTC)', 'Ours: gens', 'Pixelforge: gens', 'Ours: cost', 'Pixelforge: cost', 'Difference', ''],
            array_map(fn (UsageComparison $day): array => [
                $day->date,
                $day->ourGenerations,
                $day->pixelforgeGenerations,
                $day->ourCost->toDecimalString(),
                $day->pixelforgeCost->toDecimalString(),
                $day->costDifference()->toDecimalString(),
                $day->matches() ? '✓' : '✗',
            ], $days),
        );

        $mismatches = array_filter($days, fn (UsageComparison $day): bool => ! $day->matches());

        if ($mismatches !== []) {
            $this->error(count($mismatches).' day(s) differ.');

            return self::FAILURE;
        }

        $this->info('All days match: difference 0.000000000.');

        return self::SUCCESS;
    }
}
