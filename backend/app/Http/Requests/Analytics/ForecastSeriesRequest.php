<?php

declare(strict_types=1);

namespace App\Http\Requests\Analytics;

use App\Services\Analytics\Support\Granularity;
use Illuminate\Validation\Rule;

class ForecastSeriesRequest extends AnalyticsRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->runRule(),
            ...$this->nodeRules(),
            'granularity' => ['nullable', Rule::in(Granularity::ALL)],
            'horizon' => ['nullable', 'integer', Rule::in($this->horizons())],
            'history_days' => ['nullable', 'integer', 'min:7', 'max:730'],
        ];
    }
}
