<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Models\ForecastRun;
use App\Services\Analytics\Support\ForecastNode;
use Illuminate\Support\Facades\DB;

/**
 * Demanda agregada pronosticada por producto (Demand Core, ya persistida) en los primeros H días de una corrida de pronóstico: Σ de `forecast_values.yhat` del nivel
 * producto (todas las sucursales). Es la otra mitad de la demanda frente a la de los compradores identificados (Customer Core): ninguna de las dos incluye a la otra, y
 * una ausencia de datos aquí es «se desconoce», no cero. Una sola definición para la preparación de abastecimiento del Radar y el desajuste inventario-demanda del motor.
 */
final class AggregateDemandLoader
{
    /**
     * @param  list<int>  $productIds
     * @return array<int, float> unidades pronosticadas por producto (solo los productos con pronóstico)
     */
    public function units(?ForecastRun $run, array $productIds, int $horizon): array
    {
        if ($run === null || $productIds === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($productIds, 1000) as $chunk) {
            $rows = DB::table('forecast_values')
                ->where('tenant_id', (int) $run->tenant_id)->where('forecast_run_id', $run->id)->where('granularity', 'day')->where('horizon_step', '<=', $horizon)
                ->where('level', ForecastNode::LEVEL_PRODUCT)->whereIn('product_id', $chunk)
                ->selectRaw('product_id, SUM(yhat) AS units')->groupBy('product_id')->get();
            foreach ($rows as $r) {
                $out[(int) $r->product_id] = round((float) $r->units, 4);
            }
        }

        return $out;
    }
}
