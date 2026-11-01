<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Customer;

use App\Http\Requests\Customer\CustomerEvaluationRequest;
use App\Models\CoreRun;
use App\Models\ModelEvaluation;
use App\Services\Customer\CustomerEnvelope;
use App\Services\Customer\CustomerRunService;
use App\Support\Results\JsonMap;
use Illuminate\Http\JsonResponse;

/**
 * Evaluación del Customer Core: métricas del holdout (Brier, log loss, curvas de calibración con n, PR-AUC, precision@k /
 * recall@k, MAE y sesgo de cantidad) frente a la referencia, y el manifiesto del protocolo (fechas de entrenamiento,
 * calibración, selección y holdout; maduración de etiquetas).
 */
class CustomerEvaluationController extends CustomerController
{
    public function show(CustomerEvaluationRequest $request, CustomerRunService $service): JsonResponse
    {
        $tenant = $this->tenant();
        $run = $request->filled('run_id')
            ? CoreRun::query()->where('core', 'customer')->where('operation', 'train')->where('status', 'completed')->findOrFail((int) $request->integer('run_id'))
            : $service->latestEvaluation();
        if ($run === null) {
            return response()->json(['data' => ['manifest' => null, 'metrics' => (object) [], 'models' => (object) []],
                'envelope' => CustomerEnvelope::missing($tenant, 'evaluation', 'Aún no hay un entrenamiento de comportamiento de compra completado.')]);
        }
        $allowed = $service->allowedHorizons($tenant);
        $horizon = $request->filled('horizon') ? $this->horizon($request) : null;   // 402 si el plan no lo incluye
        $rows = ModelEvaluation::query()->where('core', 'customer')->where('core_run_id', $run->id)
            ->when($horizon !== null, fn ($q) => $q->where('horizon_days', $horizon))
            ->when($horizon === null, fn ($q) => $q->where(fn ($w) => $w->whereNull('horizon_days')->orWhereIn('horizon_days', $allowed)))
            ->orderBy('id')->get();
        $metrics = [];
        foreach ($rows as $row) {
            $metrics[$row->level_key][] = [
                'model_family' => $row->model_family, 'evaluation_type' => $row->evaluation_type,
                'is_baseline' => (bool) $row->is_baseline, 'is_selected' => (bool) $row->is_selected,
                'horizon_days' => $row->horizon_days !== null ? (int) $row->horizon_days : null, 'n_obs' => (int) $row->n_obs,
                'mae' => $row->mae, 'bias' => $row->bias, 'wape' => $row->wape, 'rmse' => $row->rmse, 'coverage_80' => $row->coverage_80,
                'metrics' => JsonMap::of($row->metrics), 'extra' => JsonMap::of($row->extra),
            ];
        }
        $manifest = $run->evaluation_manifest;
        if (is_array($manifest) && isset($manifest['horizons']) && $horizon === null) {
            $manifest['horizons'] = array_filter($manifest['horizons'], fn ($key) => in_array((int) $key, $allowed, true), ARRAY_FILTER_USE_KEY);
        } elseif (is_array($manifest) && isset($manifest['horizons'])) {
            $manifest['horizons'] = array_filter($manifest['horizons'], fn ($key) => (int) $key === $horizon, ARRAY_FILTER_USE_KEY);
        }

        return response()->json(['data' => [
            'run_id' => $run->id, 'as_of' => $run->as_of?->toDateString(),
            // Mapas (objeto JSON aunque estén vacíos o con claves numéricas): manifiesto, métricas por nivel, modelos por horizonte y resumen.
            'manifest' => $this->manifestForJson($manifest), 'metrics' => (object) $metrics,
            'models' => JsonMap::orEmpty($run->model_versions), 'summary' => JsonMap::of($run->summary),
            'notes' => [
                'El holdout solo evalúa el procedimiento y aplica la regla de promoción versionada; no participa en la elección de modelo, variables, calibrador ni umbrales.',
                'Los cortes consecutivos con ventanas solapadas no son independientes: no hay intervalos de confianza por fila.',
                'Datos sintéticos o de demostración comprueban el procedimiento; no validan precisión comercial.',
            ],
        ], 'envelope' => CustomerEnvelope::forRun($run, $tenant, $horizon, ['entity_keys' => null, 'result_type' => 'evaluation'])]);
    }

    /** El manifiesto es un mapa y su `horizons` (horizonte → bloque) también: objetos aunque queden vacíos o con claves numéricas. */
    private function manifestForJson(mixed $manifest): mixed
    {
        if (is_array($manifest) && isset($manifest['horizons']) && is_array($manifest['horizons'])) {
            $manifest['horizons'] = (object) $manifest['horizons'];
        }

        return JsonMap::of($manifest);
    }
}
