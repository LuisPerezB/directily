<?php

declare(strict_types=1);

namespace App\Http\Requests\Analytics;

/** POST /models/demand/rollback: ejecución de producción anterior a la que se vuelve. */
class RollbackModelRequest extends AnalyticsRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['run_id' => ['required', 'integer', 'min:1']];
    }

    public function messages(): array
    {
        return ['run_id.required' => 'Indique la ejecución (run_id) a la que desea volver.'];
    }
}
