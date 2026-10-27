<?php

declare(strict_types=1);

namespace App\Http\Requests\Analytics;

use App\Services\Analytics\Support\Granularity;
use Illuminate\Validation\Rule;

class SalesSummaryExportRequest extends AnalyticsRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->nodeRules(),
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'granularity' => ['nullable', Rule::in(Granularity::ALL)],
            'delimiter' => ['nullable', Rule::in([',', ';'])],
        ];
    }
}
