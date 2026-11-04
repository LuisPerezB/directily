<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\ForecastRun;
use App\Support\Horizons\HorizonCatalog;

/**
 * Respaldo de cada horizonte de una ejecución (E2-21): el motor marca un horizonte `insufficient_data`
 * cuando la historia es menor que 2×H o el backtesting no tiene ventanas de H días, y `degraded` si no hubo
 * período final independiente. La API nunca presenta como completo un horizonte sin respaldo.
 *
 * Estados: ok | degraded | insufficient_data | not_computed (H mayor que el horizonte de la ejecución) |
 * unknown (ejecución anterior a la etapa 2, sin dato de respaldo). `insufficient_data` es siempre el ESTADO; el motivo es un CÓDIGO
 * (`reasons[].reason_code`: coverage_insufficient | insufficient_horizon; `reasons[].code` conserva el código histórico) — ADR-V25-02.
 */
final class HorizonSupport
{
    /**
     * Código histórico del motivo → `reason_code` unificado (ADR-V25-02). Las ejecuciones anteriores a W2-E guardan solo `code`; al leerlas se
     * les agrega `reason_code` para que el cliente vea un único vocabulario sin reescribir lo persistido.
     */
    public const REASON_CODES = [
        'history_lt_2h' => 'coverage_insufficient',
        'no_backtest' => 'coverage_insufficient',
        'backtest_horizon_lt_h' => 'insufficient_horizon',
        'no_evaluated_observations' => 'insufficient_horizon',
        'no_independent_holdout' => 'no_independent_holdout',
    ];

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
            'reasons' => $this->reasons((array) ($row['reasons'] ?? [])),
            'history_days' => $row['history_days'] ?? null,
            'required_history_days' => $row['required_history_days'] ?? null,
            'backtest_horizon_days' => $row['backtest_horizon_days'] ?? null,
            'independent_holdout' => $row['independent_holdout'] ?? null,
            'evaluated_obs' => $row['evaluated_obs'] ?? null,
        ];
    }

    /**
     * @param  array<int|string, mixed>  $reasons
     * @return list<array<string, mixed>>
     */
    private function reasons(array $reasons): array
    {
        return array_values(array_map(function ($reason) {
            if (is_array($reason) && ! isset($reason['reason_code']) && isset($reason['code'])) {
                $reason['reason_code'] = self::REASON_CODES[$reason['code']] ?? $reason['code'];
            }

            return $reason;
        }, $reasons));
    }

    /**
     * Estado de todos los horizontes del dominio calculados en la ejecución.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(?ForecastRun $run): array
    {
        $out = [];
        foreach (HorizonCatalog::demand() as $h) {
            $out[(string) $h] = $this->forHorizon($run, (int) $h);
        }

        return $out;
    }
}
