<?php

declare(strict_types=1);

namespace App\Http\Requests\Analytics;

use Illuminate\Validation\Rule;

class AlertIndexRequest extends AnalyticsRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in(['open', 'acknowledged', 'resolved'])],
            'type' => ['nullable', 'string', 'max:30', 'regex:/^[a-z_]+$/'],
            'severity' => ['nullable', Rule::in(['info', 'warning', 'critical'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
