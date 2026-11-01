<?php

declare(strict_types=1);

namespace App\Http\Requests\DataCore;

use App\Models\DataCoverageGap;
use App\Support\Tenancy\TenantRule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Declaración de un hueco interno de cobertura (ADR-V25-23). La fuente y la sucursal deben ser de la MISMA empresa y no estar
 * dadas de baja; el rango incluye ambos extremos. `basis` solo admite `declared`: `inferred` lo producen reglas documentadas, no usuarios.
 */
final class StoreCoverageGapRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'entity' => ['required', 'string', Rule::in(DataCoverageGap::ENTITIES)],
            'gap_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:1990-01-01'],
            'gap_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:gap_from', 'before_or_equal:2100-12-31'],
            'data_source_id' => ['sometimes', 'nullable', 'integer', TenantRule::exists('data_sources')->whereNull('deleted_at')],
            'branch_id' => ['sometimes', 'nullable', 'integer', TenantRule::exists('branches')->whereNull('deleted_at')],
            'reason' => ['sometimes', 'nullable', 'string', Rule::in(DataCoverageGap::REASONS)],
            'basis' => ['sometimes', 'nullable', 'string', Rule::in(['declared'])],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $from = $this->input('gap_from');
            $to = $this->input('gap_to');
            if ($validator->errors()->hasAny(['gap_from', 'gap_to']) || ! is_string($from) || ! is_string($to)) {
                return;
            }
            if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1 > DataCoverageGap::MAX_SPAN_DAYS) {
                $validator->errors()->add('gap_to', 'Un hueco no puede abarcar más de '.DataCoverageGap::MAX_SPAN_DAYS.' días; divídalo en varios.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'entity.in' => 'La entidad debe ser una de: '.implode(', ', DataCoverageGap::ENTITIES).'.',
            'gap_to.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
            'basis.in' => 'Los huecos declarados por usuarios son siempre «declared»; «inferred» lo producen reglas documentadas.',
            'data_source_id.exists' => 'La fuente no existe en esta empresa.',
            'branch_id.exists' => 'La sucursal no existe en esta empresa.',
        ];
    }
}
