<?php

declare(strict_types=1);

namespace App\Http\Requests\Analytics;

use Illuminate\Validation\Rule;

class UpdateAlertRequest extends AnalyticsRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['acknowledged', 'resolved', 'open'])],
        ];
    }
}
