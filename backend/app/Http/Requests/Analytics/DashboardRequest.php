<?php

declare(strict_types=1);

namespace App\Http\Requests\Analytics;

use Illuminate\Validation\Rule;

class DashboardRequest extends AnalyticsRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'horizon' => ['nullable', 'integer', Rule::in($this->horizons())],
            'branch_id' => ['nullable', 'integer', $this->existsInTenant('branches')],
        ];
    }
}
