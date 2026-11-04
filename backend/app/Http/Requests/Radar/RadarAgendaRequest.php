<?php

declare(strict_types=1);

namespace App\Http\Requests\Radar;

/** Agenda de una fecha (por defecto hoy en la zona de la empresa) con los mismos filtros que las oportunidades. */
class RadarAgendaRequest extends RadarFiltersRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + ['date' => ['nullable', 'date_format:Y-m-d']];
    }
}
