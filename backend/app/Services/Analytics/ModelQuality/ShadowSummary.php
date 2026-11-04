<?php

declare(strict_types=1);

namespace App\Services\Analytics\ModelQuality;

use App\Models\ModelEvaluation;
use App\Models\ShadowForecastValue;
use App\Services\Analytics\Support\ModelCatalog;

/**
 * Resumen por ejecución y familia de las predicciones del challenger (shadow mode, V2.5 · W3-B) y de si ya fueron evaluadas contra lo realizado.
 * Nunca devuelve los valores pronosticados: el shadow no se sirve, solo se informa cuántos hay, de qué fechas y si hay evaluación prospectiva.
 *
 * Estados: `evaluated` (hay evaluación prospectiva) o `pending` (todavía no hay ventas realizadas posteriores al corte que puedan compararse,
 * o todos los días quedaron censurados).
 */
final class ShadowSummary
{
    public const EVALUATED = 'evaluated';

    public const PENDING = 'pending';

    /**
     * @param  list<int>  $runIds
     * @return list<array<string, mixed>>
     */
    public static function forRuns(array $runIds): array
    {
        if ($runIds === []) {
            return [];
        }
        $shadow = ShadowForecastValue::query()->whereIn('forecast_run_id', $runIds)
            ->selectRaw('forecast_run_id, model_family, MAX(model_registry_id) AS model_registry_id, COUNT(*) AS n_values, COUNT(DISTINCT node_key) AS n_nodes, MIN(target_date) AS first_target_date, MAX(target_date) AS last_target_date')
            ->groupBy('forecast_run_id', 'model_family')
            ->orderByDesc('forecast_run_id')->orderBy('model_family')
            ->get();
        $evaluated = ModelEvaluation::query()
            ->where('evaluation_type', ModelEvaluation::TYPE_SHADOW_PROSPECTIVE)->where('level', 'overall')
            ->whereIn('forecast_run_id', $runIds)
            ->get(['forecast_run_id', 'model_family', 'n_obs'])
            ->keyBy(fn (ModelEvaluation $e) => $e->forecast_run_id.':'.$e->model_family);

        return $shadow->map(function (ShadowForecastValue $row) use ($evaluated): array {
            $key = $row->forecast_run_id.':'.$row->model_family;

            return [
                'forecast_run_id' => (int) $row->forecast_run_id,
                'model_family' => $row->model_family,
                'label' => ModelCatalog::label($row->model_family),
                'model_registry_id' => $row->model_registry_id !== null ? (int) $row->model_registry_id : null,
                'served' => false,
                'n_values' => (int) $row->n_values,
                'n_nodes' => (int) $row->n_nodes,
                'first_target_date' => $row->first_target_date !== null ? substr((string) $row->first_target_date, 0, 10) : null,
                'last_target_date' => $row->last_target_date !== null ? substr((string) $row->last_target_date, 0, 10) : null,
                'status' => $evaluated->has($key) ? self::EVALUATED : self::PENDING,
                'n_obs_evaluated' => $evaluated->has($key) ? (int) $evaluated->get($key)->n_obs : null,
            ];
        })->values()->all();
    }
}
