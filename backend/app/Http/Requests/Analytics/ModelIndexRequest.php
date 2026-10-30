<?php

declare(strict_types=1);

namespace App\Http\Requests\Analytics;

use App\Services\Analytics\Support\ModelStatus;
use Illuminate\Validation\Rule;

/** GET /models: acepta los estados nuevos (ADR-V2-07) y los de la etapa 1. */
class ModelIndexRequest extends AnalyticsRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in(ModelStatus::accepted())],
            'role' => ['nullable', Rule::in(ModelStatus::ROLES)],
            'core' => ['nullable', Rule::in(['demand', 'customer', 'supply', 'cost', 'scenario'])],
            'run_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return ['status.in' => 'El estado debe ser: '.implode(', ', ModelStatus::accepted()).'.'];
    }
}
