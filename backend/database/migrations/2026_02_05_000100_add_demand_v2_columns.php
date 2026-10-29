<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 2 · P1 Demanda (docs/architecture/v2/P1-demanda.md). Aditiva y reversible.
 *
 * forecast_values:
 * - p50: mediana condicional SOLO si el motor la evaluó con pérdida pinball en el período final
 *   reservado (nivel serie, granularidad diaria); NULL en caso contrario. No es el yhat (media).
 * - quality_status: aptitud estadística de la fila para su horizonte: ok | degraded | insufficient_data
 *   (la historia o el backtest no respaldan ese horizonte; ver forecast_runs.summary.horizon_support).
 * - case_label: caso especial de la serie (producto nuevo, descontinuado, apertura/cierre de sucursal,
 *   evento extraordinario, historia escasa...). NULL = serie regular.
 * forecasting_series.lifecycle: último caso especial detectado para la serie (misma codificación).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forecast_values', function (Blueprint $table) {
            $table->decimal('p50', 16, 4)->nullable()->after('hi_95')
                ->comment('Mediana conformal evaluada con pinball; NULL si no se evaluó o no mejora al yhat');
            $table->string('quality_status', 20)->nullable()->after('revenue_yhat')
                ->comment('ok, degraded, insufficient_data; NULL = ejecución anterior a la etapa 2');
            $table->string('case_label', 60)->nullable()->after('quality_status')
                ->comment('new_product_*, discontinued_zero, branch_closed_declared, branch_opening, sparse_history, exceptional_event');
        });

        Schema::table('forecasting_series', function (Blueprint $table) {
            $table->string('lifecycle', 60)->nullable()->after('is_active')
                ->comment('Caso especial vigente: new_product_*, discontinued_zero, no_recent_sales, branch_closed_declared, ...');
        });
    }

    public function down(): void
    {
        Schema::table('forecasting_series', function (Blueprint $table) {
            $table->dropColumn('lifecycle');
        });
        Schema::table('forecast_values', function (Blueprint $table) {
            $table->dropColumn(['p50', 'quality_status', 'case_label']);
        });
    }
};
