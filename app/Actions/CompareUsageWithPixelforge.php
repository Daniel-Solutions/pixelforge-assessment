<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\UsageComparison;
use App\Models\Generation;
use App\Money\Nanodollars;
use App\Pixelforge\Data\TokenUsage;
use App\Pixelforge\PixelforgeClient;
use Carbon\CarbonImmutable;

/**
 * Per UTC day of completion, our summed provider cost and tokens next to GET /v1/usage.
 * Pixelforge bills a generation on the day it completed, and only if it succeeded, so we sum every
 * generation with a provider cost by DATE(completed_at). All sums are integer SUMs in the database.
 */
class CompareUsageWithPixelforge
{
    public function __construct(private PixelforgeClient $pixelforge) {}

    /**
     * @return list<UsageComparison>
     */
    public function handle(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ours = Generation::query()
            ->where('provider_cost', '>', 0)
            ->whereBetween('completed_at', [$from->startOfDay(), $to->endOfDay()])
            ->selectRaw('DATE(completed_at) as day, COUNT(*) as generations, SUM(provider_cost) as cost')
            ->selectRaw('SUM(input_text_tokens) as input_text, SUM(input_image_tokens) as input_image, SUM(output_tokens) as output, SUM(reasoning_tokens) as reasoning')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $comparisons = [];
        $pixelforgeDays = [];

        foreach ($this->pixelforge->getUsage($from, $to) as $day) {
            $pixelforgeDays[$day->date] = $day;
        }

        // Every day either side knows about: a cost we recorded on a day Pixelforge did not report is a mismatch too.
        $dates = array_unique([...array_keys($pixelforgeDays), ...$ours->keys()->map(fn (mixed $date): string => (string) $date)->all()]);
        sort($dates);

        foreach ($dates as $date) {
            $row = $ours->get($date);
            $day = $pixelforgeDays[$date] ?? null;

            $comparisons[] = new UsageComparison(
                date: $date,
                ourGenerations: (int) ($row->generations ?? 0),
                ourTokens: new TokenUsage(
                    inputText: (int) ($row->input_text ?? 0),
                    inputImage: (int) ($row->input_image ?? 0),
                    output: (int) ($row->output ?? 0),
                    reasoning: (int) ($row->reasoning ?? 0),
                ),
                ourCost: Nanodollars::of((int) ($row->cost ?? 0)),
                pixelforgeGenerations: $day->generations ?? 0,
                pixelforgeTokens: $day->tokens ?? new TokenUsage(0, 0, 0, 0),
                pixelforgeCost: $day->cost ?? Nanodollars::zero(),
            );
        }

        return $comparisons;
    }
}
