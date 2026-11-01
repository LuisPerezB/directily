<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\ForecastRun;

/**
 * Respaldo de cada horizonte de una ejecución (E2-21): el motor marca un horizonte `insufficient_data`
 * cuando la historia es menor que 2×H o el backtesting no tiene ventanas de H días, y `degraded` si no hubo
 * período final independiente. La API nunca presenta como completo un horizonte sin respaldo.
 *
 * Estados: ok | degraded | insufficient_data | not_computed (H mayor que el horizonte de la ejecución) |
 * unknown (ejecución anterior a la etapa 2, sin dato de respaldo).
 */
final class HorizonSupport
{
    /** @return array<string, mixed> */
    public function forHorizon(?ForecastRun $run, int $horizon): array
    {
        if ($run === null) {
            return ['horizon' => $horizon, 'status' => 'not_computed', 'reasons' => [
                ['code' => 'no_run', 'message' => 'Aún no hay un pronóstico generado para esta empresa.'],
            ]];
        }
        if ($horizon > (int) $run->horizon_days) {
            return ['horizon' => $horizon, 'status' => 'not_computed', 'reasons' => [[
                'code' => 'beyond_run_horizon',
                'message' => "La ejecución v{$run->version} se calculó a {$run->horizon_days} días: no incluye {$horizon} días.",
            ]]];
        }
        $support = (array) ($run->summary['horizon_support'] ?? []);
        $row = $support[(string) $horizon] ?? null;
        if (! is_array($row)) {
            return ['horizon' => $horizon, 'status' => 'unknown', 'reasons' => [[
                'code' => 'legacy_run',
                'message' => 'Ejecución anterior a la verificación de horizontes: no se conoce si la historia respalda este horizonte.',
            ]]];
        }

        return [
            'horizon' => $horizon,
            'status' => (string) ($row['status'] ?? 'unknown'),
            'reasons' => array_values((array) ($row['reasons'] ?? [])),
            'history_days' => $row['history_days'] ?? null,
            'required_history_days' => $row['required_history_days'] ?? null,
            'backtest_horizon_days' => $row['backtest_horizon_days'] ?? null,
            'independent_holdout' => $row['independent_holdout'] ?? null,
            'evaluated_obs' => $row['evaluated_obs'] ?? null,
        ];
    }

    /**
     * Estado de todos los horizontes del dominio calculados en la ejecución.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(?ForecastRun $run): array
    {
        $out = [];
        foreach ((array) config('engine.horizons', []) as $h) {
            $out[(string) $h] = $this->forHorizon($run, (int) $h);
        }

        return $out;
    }
}
