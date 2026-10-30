<?php

declare(strict_types=1);

namespace App\Http\Requests\Analytics;

use Illuminate\Validation\Rule;

class StoreForecastRunRequest extends AnalyticsRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'horizon_days' => ['nullable', 'integer', Rule::in($this->horizons())],
            'cutoff_date' => ['nullable', 'date_format:Y-m-d', 'before:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'horizon_days.in' => 'El horizonte debe ser 7, 14, 30, 60 o 90 días.',
            'cutoff_date.before' => 'La fecha de corte debe ser anterior a hoy.',
        ];
    }
}
