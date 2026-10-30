<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 2 · P2 Comportamiento de compra (docs/architecture/v2/P2-comportamiento.md). Aditivo y reversible.
 *
 * Las escribe el motor (Customer Core) y las lee Laravel. Todas con FKs compuestas (tenant_id, x_id):
 * - customer_predictions: probabilidad de compra, cantidad condicionada, esperanza, monto supuesto, comprometido
 *   y banderas de calidad por comprador × producto × horizonte (7/30/90). `expected_amount` es un SUPUESTO
 *   (esperanza × último precio neto), nunca una venta confirmada.
 * - customer_product_rankings: top-k de productos probables por comprador y horizonte.
 * - customer_activity: frecuencia, riesgo de inactividad (relativo al patrón propio, no «abandono») y cambio de comportamiento.
 * - customer_reconciliations: agregado de demanda vs compradores modelados vs comprometido vs residual (nunca se suman las vistas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_predictions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('core_run_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedSmallInteger('horizon_days')->comment('7, 30 o 90');
            $table->date('as_of');
            $table->decimal('purchase_probability', 9, 6)->nullable()->comment('P(≥1 compra válida en (as_of, as_of+H]); NULL = abstención');
            $table->decimal('probability_raw', 9, 6)->nullable()->comment('Antes de calibrar y de la coherencia entre horizontes');
            $table->boolean('probability_coherence_adjusted')->default(false)->comment('Corregida por P7 ≤ P30 ≤ P90');
            $table->string('calibration_status', 20)->nullable()->comment('calibrated, degraded, uncalibrated');
            $table->decimal('quantity_if_purchase', 16, 4)->nullable()->comment('Suma de TODAS las compras de la ventana, dado ≥ 1 compra');
            $table->decimal('quantity_lo', 16, 4)->nullable();
            $table->decimal('quantity_hi', 16, 4)->nullable();
            $table->decimal('expected_quantity', 16, 4)->nullable()->comment('purchase_probability × quantity_if_purchase (incluye no compra = 0)');
            $table->decimal('committed_quantity', 16, 4)->default(0)->comment('Pendiente de pedidos/contratos confirmados con entrega dentro de H');
            $table->decimal('incremental_quantity', 16, 4)->nullable()->comment('max(expected − committed, 0): la esperanza no suma el comprometido');
            $table->decimal('total_view_quantity', 16, 4)->nullable()->comment('max(expected, committed)');
            $table->decimal('assumed_unit_price', 14, 4)->nullable()->comment('Supuesto: último precio neto de la relación');
            $table->decimal('expected_amount', 16, 4)->nullable()->comment('Supuesto, no venta confirmada');
            $table->char('currency', 3)->nullable();
            $table->decimal('median_interval_days', 10, 2)->nullable()->comment('Frecuencia de la relación: mediana de intervalos propios');
            $table->unsignedInteger('days_since_last_purchase')->nullable();
            $table->unsignedInteger('n_purchases')->nullable();
            $table->string('model_family', 40)->nullable();
            $table->string('model_role', 10)->nullable()->comment('primary, fallback');
            $table->jsonb('quality_flags')->nullable();
            $table->unique(['core_run_id', 'customer_id', 'product_id', 'horizon_days'], 'customer_predictions_natural_key');
            $table->index(['tenant_id', 'core_run_id', 'horizon_days', 'purchase_probability'], 'customer_predictions_rank_idx');
            $table->index(['tenant_id', 'core_run_id', 'customer_id', 'horizon_days'], 'customer_predictions_customer_idx');
            $table->index(['tenant_id', 'core_run_id', 'product_id', 'horizon_days'], 'customer_predictions_product_idx');
            $table->foreign(['tenant_id', 'core_run_id'])->references(['tenant_id', 'id'])->on('core_runs')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
        });

        Schema::create('customer_product_rankings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('core_run_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedSmallInteger('horizon_days');
            $table->unsignedSmallInteger('rank');
            $table->decimal('purchase_probability', 9, 6)->nullable();
            $table->decimal('expected_quantity', 16, 4)->nullable();
            $table->decimal('expected_amount', 16, 4)->nullable();
            $table->char('currency', 3)->nullable();
            $table->unique(['core_run_id', 'customer_id', 'horizon_days', 'rank'], 'customer_rankings_natural_key');
            $table->index(['tenant_id', 'core_run_id', 'customer_id', 'horizon_days'], 'customer_rankings_customer_idx');
            $table->foreign(['tenant_id', 'core_run_id'])->references(['tenant_id', 'id'])->on('core_runs')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
        });

        Schema::create('customer_activity', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('core_run_id');
            $table->unsignedBigInteger('customer_id');
            $table->date('as_of');
            $table->date('last_purchase_date')->nullable();
            $table->unsignedInteger('days_since_last_purchase')->nullable();
            $table->unsignedInteger('n_purchase_days')->default(0);
            $table->decimal('purchase_frequency_per_30d', 10, 4)->nullable()->comment('Días de compra (cualquier producto) por 30 días');
            $table->decimal('median_interval_days', 10, 2)->nullable();
            $table->decimal('mean_interval_days', 10, 2)->nullable();
            $table->decimal('interval_p75_days', 10, 2)->nullable();
            $table->decimal('interval_p90_days', 10, 2)->nullable();
            $table->unsignedSmallInteger('n_intervals')->default(0);
            $table->decimal('inactivity_risk', 7, 4)->nullable()->comment('Percentil empírico de la brecha actual en sus propios intervalos; NULL = historial insuficiente');
            $table->string('inactivity_status', 25)->comment('active, at_risk, inactive_by_pattern, insufficient_history (ausencia respecto al patrón; NO abandono)');
            $table->string('behavior_change', 25)->comment('increase, decrease, stable, insufficient_history');
            $table->decimal('frequency_ratio', 10, 4)->nullable();
            $table->decimal('behavior_p_value', 9, 6)->nullable();
            $table->decimal('spend_recent', 16, 4)->nullable();
            $table->decimal('spend_prior', 16, 4)->nullable();
            $table->decimal('spend_ratio', 10, 4)->nullable();
            $table->char('currency', 3)->nullable();
            $table->unique(['core_run_id', 'customer_id'], 'customer_activity_natural_key');
            $table->index(['tenant_id', 'core_run_id', 'inactivity_status'], 'customer_activity_status_idx');
            $table->foreign(['tenant_id', 'core_run_id'])->references(['tenant_id', 'id'])->on('core_runs')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers')->cascadeOnDelete();
        });

        Schema::create('customer_reconciliations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('core_run_id');
            $table->unsignedBigInteger('forecast_run_id')->nullable()->comment('Demanda agregada vigente usada');
            $table->unsignedBigInteger('product_id');
            $table->unsignedSmallInteger('horizon_days');
            $table->decimal('aggregate_quantity', 16, 4)->nullable()->comment('Σ demanda diaria pronosticada del producto (todas las sucursales) en (as_of, as_of+H]');
            $table->unsignedSmallInteger('aggregate_days')->nullable()->comment('Días cubiertos por el agregado');
            $table->decimal('buyers_total_quantity', 16, 4)->default(0)->comment('Σ max(esperanza, comprometido) de compradores modelados');
            $table->decimal('buyers_committed_quantity', 16, 4)->default(0);
            $table->decimal('buyers_incremental_quantity', 16, 4)->default(0);
            $table->decimal('buyers_expected_quantity', 16, 4)->default(0)->comment('Σ esperanza probabilística (sin descontar comprometido)');
            $table->unsignedInteger('n_buyers')->default(0);
            $table->decimal('committed_outside_model', 16, 4)->default(0)->comment('Comprometido sin predicción individual o sin comprador atribuible');
            $table->decimal('residual_quantity', 16, 4)->nullable()->comment('Anónimos, compradores nuevos y población no modelada; NULL si no se puede calcular');
            $table->decimal('excess_quantity', 16, 4)->nullable()->comment('Exceso de compradores sobre el agregado (inconsistencia)');
            $table->decimal('excess_ratio', 10, 4)->nullable();
            $table->decimal('residual_share', 10, 4)->nullable();
            $table->string('status', 25)->comment('ok, inconsistent, aggregate_unavailable, aggregate_incomplete');
            $table->string('severity', 10)->nullable()->comment('minor, major (solo inconsistent)');
            $table->unique(['core_run_id', 'product_id', 'horizon_days'], 'customer_reconciliations_natural_key');
            $table->index(['tenant_id', 'core_run_id', 'horizon_days', 'status'], 'customer_reconciliations_status_idx');
            $table->foreign(['tenant_id', 'core_run_id'])->references(['tenant_id', 'id'])->on('core_runs')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'forecast_run_id'])->references(['tenant_id', 'id'])->on('forecast_runs')->nullOnDelete();
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
        });

        DB::statement("COMMENT ON TABLE customer_predictions IS 'Customer Core: expected_amount es un supuesto (esperanza × último precio neto), no ventas confirmadas'");
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_reconciliations');
        Schema::dropIfExists('customer_activity');
        Schema::dropIfExists('customer_product_rankings');
        Schema::dropIfExists('customer_predictions');
    }
};
