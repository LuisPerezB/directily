<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\ForecastRun;
use App\Models\Tenant;
use App\Support\Results\ResultEnvelope;
use App\Support\Tenancy\TenantContext;

/**
 * Envoltura común de resultados (ADR-V2-03) para la demanda: arma los campos propios del core (unidad,
 * moneda, claves, supuestos, limitaciones, calidad, procedencia de la incertidumbre) sobre
 * `ResultEnvelope::fromForecastRun`. Aditiva: no reemplaza ningún campo del contrato de la etapa 1.
 */
final class DemandEnvelope
{
    public function __construct(private readonly HorizonSupport $support, private readonly TenantContext $context) {}

    /**
     * @param  array<string, mixed>  $extra  sobrescrituras (p. ej. entity_keys, target_definition)
     * @return array<string, mixed>
     */
    public function for(ForecastRun $run, ?int $horizon = null, array $extra = []): array
    {
        $summary = (array) ($run->summary ?? []);
        // Sin horizonte (detalle de la ejecución) la calidad es la de la ejecución y se listan todos los horizontes;
        // con horizonte (series, listados) manda el respaldo de ESE horizonte.
        $allHorizons = $horizon === null;
        $support = $allHorizons ? $this->support->all($run) : $this->support->forHorizon($run, $horizon);
        $horizon ??= (int) $run->horizon_days;
        $limitations = (array) ($summary['limitations'] ?? []);
        $messages = $limitations !== []
            ? array_values(array_map(fn ($l) => is_array($l) ? (string) ($l['message'] ?? $l['code'] ?? '') : (string) $l, $limitations))
            : array_values(array_map('strval', (array) ($summary['warnings'] ?? [])));
        $codes = array_values(array_filter(array_map(fn ($l) => is_array($l) ? ($l['code'] ?? null) : null, $limitations)));
        $quality = $run->status === ForecastRun::STATUS_COMPLETED
            ? (string) ($summary['quality_status'] ?? 'ok')
            : null;
        // Un horizonte sin respaldo nunca hereda la calidad "ok" del resto de la ejecución.
        $status = $support['status'] ?? null;
        if ($quality !== null && in_array($status, ['insufficient_data', 'not_computed'], true)) {
            $quality = 'insufficient_data';
        } elseif ($quality === 'ok' && $status === 'degraded') {
            $quality = 'degraded';
        }
        $cutoff = $run->cutoff_date;
        $currency = $this->context->has() ? $this->context->get()?->currency : Tenant::query()->whereKey($run->tenant_id)->value('currency');

        return ResultEnvelope::fromForecastRun($run, array_merge([
            'horizon_end' => $cutoff?->addDays($horizon)->toDateString(),
            'entity_keys' => ['branch_id', 'product_id'],
            'currency' => $currency,
            'target_definition' => [
                'metric' => 'units_demanded', 'aggregation' => 'sum', 'frequency' => 'D', 'horizon_days' => $horizon,
                'population' => 'series sucursal×producto con ventas válidas (sin anuladas ni devoluciones)',
                'quantiles' => ['P10' => 'lo_80', 'P50' => 'p50 (null si no se evaluó)', 'P90' => 'hi_80'],
            ],
            'assumptions' => array_values(array_map('strval', (array) ($summary['assumptions'] ?? []))),
            'limitations' => $messages,
            'limitation_codes' => $codes,
            'quality_status' => $quality,
            'uncertainty_source' => (string) ($summary['uncertainty_source'] ?? 'conformal'),
            // Mapa horizonte → respaldo; como objeto para que un JsonResource no lo renumere como lista.
            'horizon_support' => $allHorizons ? (object) $support : $support,
            'p50' => $summary['p50']['published'] ?? null,
        ], $extra));
    }
}
