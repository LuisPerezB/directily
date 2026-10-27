<?php

declare(strict_types=1);

namespace App\Http\Requests\Core;

use App\Enums\Sector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateTenantRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:150'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'regex:/^\d{13}$/'],
            'sector' => ['sometimes', Rule::in(Sector::values())],
            'timezone' => ['sometimes', 'timezone:all'],
            'data_retention_months' => ['sometimes', 'integer', 'min:12', 'max:120'],
            'settings' => ['sometimes', 'array:default_horizon_days,service_level,review_period_days,default_lead_time_days,forecast_schedule,stockout_risk_threshold'],
            'settings.default_horizon_days' => ['sometimes', 'integer', Rule::in([7, 30, 90])],
            'settings.service_level' => ['sometimes', 'numeric', 'min:0.5', 'max:0.999'],
            'settings.review_period_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'settings.default_lead_time_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'settings.forecast_schedule' => ['sometimes', Rule::in(['daily', 'weekly', 'manual'])],
            'settings.stockout_risk_threshold' => ['sometimes', 'numeric', 'min:0.05', 'max:0.95'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['tax_id.regex' => 'El RUC debe tener 13 dígitos.', 'settings.array' => 'Configuración no reconocida.'];
    }
}
