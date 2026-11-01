<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * V2.5 · W3 Calidad ML P0 (arquitecto; ADR-V25-11 y ADR-V25-21).
 *
 * - Customer Core (ADR-V25-11): `customer_predictions` gana las cantidades que exige §7 / §25.2 además de las vigentes
 *   (`purchase_probability`, `quantity_if_purchase`, `expected_quantity` = P × E[cantidad | compra], que se conservan):
 *   `expected_orders` = E(nº pedidos en H) (modelo de conteo; nunca se deriva de P), `quantity_per_order` =
 *   E(cantidad por pedido | ocurre), `expected_units` = E(nº pedidos) × E(cantidad por pedido) (independencia
 *   documentada), y la VENTANA de recompra como cuantiles del tiempo hasta la siguiente compra contado desde `as_of`
 *   (`window_p10_days`, `window_p50_days`, `window_p90_days`; nunca un día exacto) con su estado; `segment` = clave del
 *   segmento con que se calibró la fila.
 * - Política de promoción (ADR-V25-21): `model_promotion_decisions` registra, por ejecución y alcance (clase de demanda,
 *   horizonte, segmento), la decisión de la política heredada (`legacy`: WAPE ≥ x % y fracción de ventanas) y la de la
 *   política V2.5 (gates) calculadas EN PARALELO, cuál de las dos gobernó lo servido (`is_effective`), los gates con su
 *   evidencia y el motivo; la aprobación humana separada (`approved_by`, `approved_at`) la completa W4 · S06.
 * - Shadow mode (§8): `shadow_forecast_values` guarda las predicciones del challenger que NO se sirven, para evaluarlas
 *   después del corte contra lo realizado (`model_evaluations.evaluation_type = 'shadow_prospective'`).
 * - `model_registry` gana UNIQUE(tenant_id, id) para que las tablas nuevas lo referencien con FK compuesta.
 * Todo aditivo y reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_predictions', function (Blueprint $table) {
            $table->decimal('expected_orders', 12, 4)->nullable()->comment('E(nº pedidos en el horizonte); modelo de conteo, no derivado de P');
            $table->decimal('quantity_per_order', 14, 4)->nullable()->comment('E(cantidad por pedido | ocurre)');
            $table->decimal('expected_units', 14, 4)->nullable()->comment('E(nº pedidos) × E(cantidad por pedido) (independencia documentada)');
            $table->decimal('window_p10_days', 8, 2)->nullable()->comment('Cuantil 10 % de días hasta la siguiente compra, contados desde as_of');
            $table->decimal('window_p50_days', 8, 2)->nullable()->comment('Mediana de días hasta la siguiente compra');
            $table->decimal('window_p90_days', 8, 2)->nullable()->comment('Cuantil 90 % de días hasta la siguiente compra');
            $table->string('window_status', 24)->nullable()->comment('estimated, insufficient_data, overdue, censored');
            $table->string('segment', 60)->nullable()->comment('Segmento con el que se calibró la fila (p. ej. frequency:high)');
        });

        Schema::table('model_registry', function (Blueprint $table) {
            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('model_promotion_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('core', 20)->comment('demand, customer');
            $table->unsignedBigInteger('forecast_run_id')->nullable();
            $table->unsignedBigInteger('core_run_id')->nullable();
            $table->string('scope_key', 80)->comment('Alcance de la decisión: class:intermittent, horizon:30, segment:..., global');
            $table->string('policy', 20)->comment('legacy (regla heredada parametrizada) o v25 (gates de promoción)');
            $table->char('policy_version', 64)->comment('SHA-256 de los parámetros de la política');
            $table->string('candidate_family', 60);
            $table->string('champion_family', 60)->nullable()->comment('Modelo vigente antes de la decisión (o el baseline)');
            $table->unsignedBigInteger('candidate_model_registry_id')->nullable();
            $table->unsignedBigInteger('champion_model_registry_id')->nullable();
            $table->string('decision', 24)->comment('promote, reject, keep_champion, publish_baseline, shadow');
            $table->boolean('is_effective')->default(false)->comment('true = esta política gobernó lo servido en la ejecución');
            $table->jsonb('gates')->default('{}')->comment('Resultado de cada gate con su evidencia (métrica, umbral, ventanas, segmento)');
            $table->jsonb('metrics')->default('{}')->comment('Métricas comparadas del candidato y del champion/baseline');
            $table->text('reason');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete()->comment('Aprobación humana separada (W4 · S06)');
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('decided_at')->useCurrent();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'core', 'decided_at']);
            $table->foreign(['tenant_id', 'forecast_run_id'])->references(['tenant_id', 'id'])->on('forecast_runs')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'core_run_id'])->references(['tenant_id', 'id'])->on('core_runs')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'candidate_model_registry_id'])->references(['tenant_id', 'id'])->on('model_registry')->nullOnDelete();
            $table->foreign(['tenant_id', 'champion_model_registry_id'])->references(['tenant_id', 'id'])->on('model_registry')->nullOnDelete();
        });
        DB::statement("ALTER TABLE model_promotion_decisions ADD CONSTRAINT model_promotion_decisions_policy_check CHECK (policy IN ('legacy', 'v25'))");
        DB::statement("ALTER TABLE model_promotion_decisions ADD CONSTRAINT model_promotion_decisions_decision_check CHECK (decision IN ('promote', 'reject', 'keep_champion', 'publish_baseline', 'shadow'))");

        Schema::create('shadow_forecast_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('forecast_run_id');
            $table->unsignedBigInteger('model_registry_id')->nullable();
            $table->string('model_family', 60);
            $table->string('node_key', 120);
            $table->string('level', 20)->comment('series, product, category, branch, total');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->date('target_date');
            $table->smallInteger('horizon_step');
            $table->decimal('yhat', 16, 4);
            $table->decimal('lo_80', 16, 4)->nullable();
            $table->decimal('hi_80', 16, 4)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['tenant_id', 'forecast_run_id', 'model_family', 'node_key', 'target_date'], 'shadow_forecast_values_node_unique');
            $table->index(['tenant_id', 'target_date']);
            $table->foreign(['tenant_id', 'forecast_run_id'])->references(['tenant_id', 'id'])->on('forecast_runs')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'model_registry_id'])->references(['tenant_id', 'id'])->on('model_registry')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shadow_forecast_values');
        Schema::dropIfExists('model_promotion_decisions');
        Schema::table('model_registry', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'id']);
        });
        Schema::table('customer_predictions', function (Blueprint $table) {
            $table->dropColumn(['expected_orders', 'quantity_per_order', 'expected_units', 'window_p10_days', 'window_p50_days',
                'window_p90_days', 'window_status', 'segment']);
        });
    }
};
