<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Motor predictivo: series, características, trabajos, registro de modelos,
 * ejecuciones de pronóstico, valores, evaluaciones y comparación con reales.
 *
 * Propiedad de escritura (contrato Laravel <-> motor Python, ver docs/06):
 * - Laravel CREA forecast_runs y training_jobs en estado "queued".
 * - El motor Python ACTUALIZA training_jobs/forecast_runs y ESCRIBE forecasting_series,
 *   model_registry, model_evaluations, forecast_values, forecast_actuals,
 *   recommendations, alerts (tipo forecast/inventario) y data_quality_reports (readiness).
 * - Cada predicción queda asociada a versión de modelo, fecha de generación,
 *   horizonte y feature_set_version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecasting_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('product_id');
            $table->string('unique_id', 64)->comment('Identificador de serie en el motor: b{branch_id}-p{product_id}');
            $table->date('first_sale_date')->nullable();
            $table->date('last_sale_date')->nullable();
            $table->unsignedInteger('history_days')->default(0);
            $table->unsignedInteger('nonzero_days')->default(0);
            $table->decimal('total_units', 16, 4)->default(0);
            $table->decimal('adi', 10, 4)->nullable()->comment('Average Demand Interval');
            $table->decimal('cv2', 10, 4)->nullable()->comment('Coeficiente de variación al cuadrado (tamaños no nulos)');
            $table->string('demand_class', 20)->default('insufficient')->comment('smooth, erratic, intermittent, lumpy, insufficient');
            $table->unsignedInteger('stockout_days')->default(0)->comment('Días con stock <= 0 según inventory_snapshots');
            $table->unsignedInteger('censored_days')->default(0)->comment('Días sin venta y sin stock: venta potencialmente censurada');
            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_profiled_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'branch_id', 'product_id']);
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
        });

        Schema::create('forecast_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete()->comment('NULL = definición estándar del motor');
            $table->string('feature_set_version', 40);
            $table->string('name', 100);
            $table->string('kind', 20)->comment('calendar, lag, rolling, commercial, geographic, external, static');
            $table->text('description')->nullable();
            $table->jsonb('config')->default('{}');
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['tenant_id', 'feature_set_version', 'name']);
        });

        Schema::create('forecast_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version')->comment('Correlativo por empresa: 1, 2, 3...');
            $table->string('run_type', 20)->default('production')->comment('production, historical (corte retroactivo; nunca es current)');
            $table->string('status', 20)->default('queued')->comment('queued, running, completed, failed, cancelled');
            $table->string('triggered_by', 20)->default('user')->comment('user, schedule, system, api');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('horizon_days')->default(90);
            $table->string('frequency', 5)->default('D');
            $table->date('cutoff_date')->nullable()->comment('Último día de datos usado para entrenar');
            $table->date('data_from')->nullable();
            $table->date('data_to')->nullable();
            $table->string('feature_set_version', 40)->nullable();
            $table->string('strategy', 20)->default('auto');
            $table->jsonb('options')->default('{}')->comment('{cutoff_date?, horizons:[7,30,90], force_models?:[]}');
            $table->jsonb('selection')->nullable()->comment('Modelo ganador por clase de demanda + respaldo');
            $table->jsonb('summary')->nullable()->comment('series_count, métricas backtest vs baseline, totales por horizonte');
            $table->boolean('is_current')->default(false);
            $table->text('error_message')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'version']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status', 'created_at']);
        });
        // Solo una ejecución vigente por empresa.
        DB::statement('CREATE UNIQUE INDEX forecast_runs_one_current_per_tenant ON forecast_runs (tenant_id) WHERE is_current');

        Schema::create('training_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('forecast_run_id')->nullable();
            $table->string('job_type', 30)->comment('forecast_pipeline, evaluate_actuals, readiness, retrain_check');
            $table->string('status', 20)->default('queued')->comment('queued, running, succeeded, failed, cancelled');
            $table->string('stage', 40)->nullable()->comment('loading_data, profiling, building_features, backtesting, selecting, training, predicting, reconciling, recommending, persisting');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('triggered_by', 20)->default('user');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('params')->default('{}');
            $table->jsonb('result')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('model_registry_id')->nullable()->comment('FK añadida abajo');
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->foreign(['tenant_id', 'forecast_run_id'])->references(['tenant_id', 'id'])->on('forecast_runs')->cascadeOnDelete();
        });

        Schema::create('model_registry', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete()->comment('NULL = modelo global/sectorial');
            $table->string('version', 100)->comment('p. ej. t12-r5-lightgbm_global');
            $table->string('model_family', 40)->comment('naive, seasonal_naive, window_average, auto_ets, croston_sba, adida, tsb, lightgbm_global, timesfm');
            $table->char('strategy_level', 1)->comment('A=estadístico, B=global, C=sectorial, D=personalizado, E=fundacional');
            $table->string('scope', 20)->default('tenant')->comment('tenant, sector, global');
            $table->string('sector', 50)->nullable();
            $table->string('demand_class', 20)->nullable()->comment('Clase de demanda para la que fue seleccionado');
            $table->string('status', 20)->default('candidate')->comment('candidate, production, fallback, archived, rejected');
            $table->jsonb('hyperparameters')->default('{}');
            $table->string('feature_set_version', 40)->nullable();
            $table->date('training_data_from')->nullable();
            $table->date('training_data_to')->nullable();
            $table->char('training_data_hash', 64)->nullable()->comment('Huella de los datos de entrenamiento (reproducibilidad)');
            $table->unsignedInteger('series_count')->default(0);
            $table->string('artifact_path', 500)->nullable();
            $table->char('artifact_sha256', 64)->nullable();
            $table->jsonb('library_versions')->default('{}');
            $table->integer('random_seed')->nullable();
            $table->unsignedBigInteger('training_job_id')->nullable();
            $table->unsignedBigInteger('forecast_run_id')->nullable();
            $table->timestampTz('trained_at')->nullable();
            $table->timestampTz('promoted_at')->nullable();
            $table->timestampTz('retired_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'version']);
            $table->index(['tenant_id', 'status']);
            $table->foreign('training_job_id')->references('id')->on('training_jobs')->nullOnDelete();
            $table->foreign('forecast_run_id')->references('id')->on('forecast_runs')->nullOnDelete();
        });

        Schema::table('training_jobs', function (Blueprint $table) {
            $table->foreign('model_registry_id')->references('id')->on('model_registry')->nullOnDelete();
        });

        Schema::create('model_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('model_registry_id')->nullable();
            $table->unsignedBigInteger('forecast_run_id');
            $table->string('model_family', 40);
            $table->string('evaluation_type', 20)->comment('backtest (rolling-origin), realized (vs. ventas reales posteriores)');
            $table->string('level', 20)->comment('overall, demand_class, horizon, branch, category, product, series');
            $table->string('level_key', 80)->nullable()->comment('p. ej. smooth, h1-7, b3, c7, p45');
            $table->unsignedSmallInteger('horizon_days')->nullable();
            $table->boolean('is_baseline')->default(false)->comment('Modelo estadístico de referencia');
            $table->boolean('is_selected')->default(false)->comment('Fue el modelo elegido para este nivel');
            $table->decimal('wape', 12, 6)->nullable();
            $table->decimal('mae', 16, 6)->nullable();
            $table->decimal('rmse', 16, 6)->nullable();
            $table->decimal('bias', 12, 6)->nullable()->comment('sum(pred-actual)/sum(actual); positivo = sobreestima');
            $table->decimal('coverage_80', 6, 4)->nullable();
            $table->decimal('coverage_95', 6, 4)->nullable();
            $table->unsignedInteger('n_obs')->default(0);
            $table->unsignedSmallInteger('n_windows')->default(0);
            $table->jsonb('cutoffs')->nullable();
            $table->jsonb('extra')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['tenant_id', 'forecast_run_id', 'evaluation_type', 'level']);
            $table->index(['tenant_id', 'model_registry_id']);
            $table->foreign(['tenant_id', 'forecast_run_id'])->references(['tenant_id', 'id'])->on('forecast_runs')->cascadeOnDelete();
            $table->foreign('model_registry_id')->references('id')->on('model_registry')->nullOnDelete();
        });

        // Volumen alto: sin timestamps ni FKs a catálogos (los ids provienen del mismo tenant
        // vía la ejecución, que sí tiene FK compuesta). Retención: ver docs/07.
        Schema::create('forecast_values', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('forecast_run_id');
            $table->string('node_key', 80)->comment('total | branch:{id} | category:{id} | branch_category:{b}:{c} | product:{id} | series:{b}:{p}');
            $table->string('level', 20)->comment('total, branch, category, branch_category, product, series');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('granularity', 8)->default('day')->comment('day, week (lunes), month (día 1)');
            $table->date('target_date')->comment('Día, inicio de semana ISO o inicio de mes');
            $table->unsignedSmallInteger('period_days')->default(1)->comment('Días de pronóstico incluidos en el período');
            $table->unsignedSmallInteger('horizon_step')->comment('Días (o períodos) desde el corte: 1..H');
            $table->decimal('yhat', 16, 4);
            $table->decimal('lo_80', 16, 4)->nullable();
            $table->decimal('hi_80', 16, 4)->nullable();
            $table->decimal('lo_95', 16, 4)->nullable();
            $table->decimal('hi_95', 16, 4)->nullable();
            $table->unsignedBigInteger('model_registry_id')->nullable();
            $table->string('model_family', 40)->nullable();
            $table->decimal('unit_price_assumed', 14, 4)->nullable()->comment('Supuesto de precio (último precio neto observado)');
            $table->decimal('revenue_yhat', 16, 4)->nullable()->comment('yhat * precio supuesto; agregado en niveles superiores');
            $table->unique(['forecast_run_id', 'node_key', 'granularity', 'target_date'], 'forecast_values_natural_key');
            $table->index(['forecast_run_id', 'level', 'granularity', 'target_date'], 'forecast_values_level_idx');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'forecast_run_id'])->references(['tenant_id', 'id'])->on('forecast_runs')->cascadeOnDelete();
        });

        Schema::create('forecast_actuals', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('forecast_run_id');
            $table->string('node_key', 80);
            $table->string('level', 20);
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->date('target_date');
            $table->unsignedSmallInteger('horizon_step');
            $table->decimal('yhat', 16, 4);
            $table->decimal('lo_80', 16, 4)->nullable();
            $table->decimal('hi_80', 16, 4)->nullable();
            $table->decimal('actual', 16, 4);
            $table->decimal('abs_error', 16, 4);
            $table->boolean('is_censored')->default(false)->comment('Día con quiebre de stock: la venta no refleja la demanda');
            $table->timestampTz('evaluated_at')->useCurrent();
            $table->unique(['forecast_run_id', 'node_key', 'target_date'], 'forecast_actuals_natural_key');
            $table->index(['tenant_id', 'target_date']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'forecast_run_id'])->references(['tenant_id', 'id'])->on('forecast_runs')->cascadeOnDelete();
        });

        Schema::table('data_quality_reports', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'forecast_run_id'])->references(['tenant_id', 'id'])->on('forecast_runs')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('data_quality_reports', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'forecast_run_id']);
        });
        Schema::dropIfExists('forecast_actuals');
        Schema::dropIfExists('forecast_values');
        Schema::dropIfExists('model_evaluations');
        Schema::table('training_jobs', function (Blueprint $table) {
            $table->dropForeign(['model_registry_id']);
        });
        Schema::dropIfExists('model_registry');
        Schema::dropIfExists('training_jobs');
        Schema::dropIfExists('forecast_runs');
        Schema::dropIfExists('forecast_features');
        Schema::dropIfExists('forecasting_series');
    }
};
