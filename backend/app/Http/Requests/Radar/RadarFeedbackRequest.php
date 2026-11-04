<?php

declare(strict_types=1);

namespace App\Http\Requests\Radar;

use App\Services\Radar\AgendaStatusMap;
use Illuminate\Validation\Rule;

/** Seguimiento de una oportunidad: estado heredado, nota, clave de idempotencia y, al posponer, la fecha de reactivación (V25-AGD-005). */
class RadarFeedbackRequest extends RadarRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'state' => ['required', 'string', Rule::in(AgendaStatusMap::STATES)],
            'note' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120', 'regex:/^[A-Za-z0-9._:\-]+\z/'],
            'snoozed_until' => ['nullable', 'required_if:state,pospuesto', 'date_format:Y-m-d'],
        ];
    }
}
