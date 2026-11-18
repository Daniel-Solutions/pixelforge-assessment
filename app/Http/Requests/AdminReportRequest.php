<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class AdminReportRequest extends FormRequest
{
    public const int DEFAULT_DAYS = 30;

    public function authorize(): bool
    {
        return Gate::allows('view-admin-report');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    public function to(): CarbonImmutable
    {
        $to = $this->validated('to');

        return is_string($to) ? CarbonImmutable::parse($to, 'UTC') : CarbonImmutable::today('UTC');
    }

    public function from(): CarbonImmutable
    {
        $from = $this->validated('from');

        return is_string($from) ? CarbonImmutable::parse($from, 'UTC') : $this->to()->subDays(self::DEFAULT_DAYS - 1);
    }
}
