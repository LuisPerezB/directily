<?php

declare(strict_types=1);

namespace App\Support\Results;

use App\Models\CoreRun;
use App\Models\ForecastRun;

/**
 * Envoltura común de resultados de los cores (ADR-V2-03). Toda respuesta de resultados de la etapa 2
 * debe incluir estos campos además de su carga específica en `data`.
 *
 * result_type: forecast | conditional_simulation | causal_estimate | optimized_plan | evaluation
 * quality_status: ok | degraded | insufficient_data | not_configured | insufficient_evidence
 * uncertainty_source: empirical_holdout | conformal | model | unknown | none
 */
final class ResultEnvelope
{
    public const RESULT_TYPES = ['forecast', 'conditional_simulation', 'causal_estimate', 'optimized_plan', 'evaluation'];

    /**
     * Campos de la envoltura que son MAPAS (objeto JSON). Salen siempre como objeto, aunque estén vacíos o tengan claves numéricas
     * (`model_version`: `{"7": …, "30": …}`); `assumptions`, `limitations` y `entity_keys` son listas y no se tocan.
     */
    public const MAP_FIELDS = ['target_definition', 'model_version'];

    /**
     * @param  array<string, mixed>  $overrides  campos explícitos (entity_keys, unit, currency, target_definition, ...)
     * @return array<string, mixed>
     */
    public static function fromCoreRun(CoreRun $run, array $overrides = []): array
    {
        return self::normalize(array_merge([
            'tenant_id' => (int) $run->tenant_id,
            'run_id' => (int) $run->id,
            'core' => $run->core,
            'run_version' => (int) $run->version,
            'status' => $run->status,
            'as_of' => $run->as_of?->toDateString(),
            'horizon_start' => $run->horizon_start?->toDateString(),
            'horizon_end' => $run->horizon_end?->toDateString(),
            'target_definition' => $run->target_definition,
            'entity_keys' => null,
            'unit' => null,
            'currency' => null,
            'result_type' => $run->result_type,
            'model_version' => $run->model_versions,
            'data_snapshot_id' => $run->data_snapshot_id !== null ? (int) $run->data_snapshot_id : null,
            'config_version' => $run->config_version,
            'assumptions' => $run->assumptions ?? [],
            'limitations' => $run->limitations ?? [],
            'quality_status' => $run->quality_status,
            'uncertainty_source' => $run->uncertainty_source,
        ], $overrides));
    }

    /**
     * Envoltura equivalente para la demanda (forecast_runs, etapa 1).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function fromForecastRun(ForecastRun $run, array $overrides = []): array
    {
        $cutoff = $run->cutoff_date;

        return self::normalize(array_merge([
            'tenant_id' => (int) $run->tenant_id,
            'run_id' => (int) $run->id,
            'core' => 'demand',
            'run_version' => (int) $run->version,
            'status' => $run->status,
            'as_of' => $cutoff?->toDateString(),
            'horizon_start' => $cutoff?->addDay()->toDateString(),
            'horizon_end' => $cutoff?->addDays((int) $run->horizon_days)->toDateString(),
            'target_definition' => ['metric' => 'units_demanded', 'aggregation' => 'sum'],
            'entity_keys' => null,
            'unit' => 'product_unit',
            'currency' => null,
            'result_type' => 'forecast',
            'model_version' => $run->selection,
            'data_snapshot_id' => $run->data_snapshot_id !== null ? (int) $run->data_snapshot_id : null,
            'config_version' => $run->config_version,
            'assumptions' => [],
            'limitations' => (array) ($run->summary['warnings'] ?? []),
            'quality_status' => $run->status === 'completed' ? 'ok' : null,
            'uncertainty_source' => 'conformal',
        ], $overrides));
    }

    /**
     * Aplica la forma JSON de los campos que son mapas (ver MAP_FIELDS), también cuando el llamador los sobrescribe con un arreglo.
     *
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    public static function normalize(array $envelope): array
    {
        foreach (self::MAP_FIELDS as $field) {
            if (array_key_exists($field, $envelope)) {
                $envelope[$field] = JsonMap::of($envelope[$field]);
            }
        }

        return $envelope;
    }
}
