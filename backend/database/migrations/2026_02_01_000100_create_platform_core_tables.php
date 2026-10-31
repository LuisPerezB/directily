<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 2 · P0 Plataforma (docs/architecture/PREDICTILY_V2_GAP_ANALYSIS.md, ADR-V2-02/03/04/05/07).
 *
 * - core_runs: ejecuciones de los cores nuevos (customer | supply | cost | scenario) con los campos de
 *   la envoltura común de resultados. La demanda conserva forecast_runs (compatibilidad etapa 1).
 * - data_snapshots: huella reproducible por componente (ventas, precios, promociones as-of, costos,
 *   externos, calendario, configuración, reglas de elegibilidad).
 * - data_coverage: hasta qué fecha está completa cada fuente; fuera de la cobertura un dato es
 *   AUSENTE, no cero.
 * - Registro de modelos con estados candidate/validated/production/retired/failed y columna role.
 * Todo aditivo y reversible; los valores de estado legados se mapean (ver up/down).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('core', 20)->comment('demand, customer, supply, cost, scenario');
            $table->date('as_of')->comment('Fecha de corte de la información');
            $table->char('hash', 64)->comment('SHA-256 sobre los hashes de componentes, en orden');
            $table->jsonb('components')->default('{}')->comment('{sales, returns, inventory, prices, costs, promotions, externals, calendar, config, eligibility, ...}: hash');
            $table->jsonb('row_counts')->default('{}');
            $table->jsonb('coverage')->default('{}')->comment('Cobertura de cada fuente usada al corte');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['tenant_id', 'core', 'hash']);
            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('core_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('core', 20)->comment('customer, supply, cost, scenario');
            $table->string('operation', 20)->default('predict')->comment('train, predict, plan, evaluate, simulate');
            $table->unsignedInteger('version')->comment('Correlativo por empresa y core');
            $table->string('run_type', 20)->default('production')->comment('production, historical');
            $table->string('status', 20)->default('queued')->comment('Estado técnico: queued, running, completed, failed, cancelled');
            $table->string('result_type', 30)->nullable()->comment('forecast, conditional_simulation, causal_estimate, optimized_plan, evaluation');
            $table->string('quality_status', 30)->nullable()->comment('Aptitud del resultado: ok, degraded, insufficient_data, not_configured, insufficient_evidence');
            $table->string('uncertainty_source', 30)->nullable()->comment('empirical_holdout, conformal, model, unknown, none');
            $table->date('as_of')->nullable();
            $table->jsonb('horizons')->default('[]');
            $table->date('horizon_start')->nullable();
            $table->date('horizon_end')->nullable();
            $table->jsonb('target_definition')->nullable();
            $table->jsonb('options')->default('{}');
            $table->char('config_version', 64)->nullable();
            $table->jsonb('effective_config')->nullable();
            $table->unsignedBigInteger('data_snapshot_id')->nullable();
            $table->jsonb('model_versions')->nullable();
            $table->jsonb('evaluation_manifest')->nullable()->comment('Fechas de entrenamiento/selección/calibración/prueba y maduración de etiquetas');
            $table->jsonb('assumptions')->default('[]');
            $table->jsonb('limitations')->default('[]');
            $table->jsonb('summary')->nullable();
            $table->boolean('is_current')->default(false);
            $table->string('triggered_by', 20)->default('user');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('error_message')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'core', 'version']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'core', 'status', 'created_at']);
            $table->foreign(['tenant_id', 'data_snapshot_id'])->references(['tenant_id', 'id'])->on('data_snapshots')->nullOnDelete();
        });
        DB::statement('CREATE UNIQUE INDEX core_runs_one_current_per_core ON core_runs (tenant_id, core, operation) WHERE is_current');

        Schema::create('data_coverage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('entity', 30)->comment('sales, inventory, purchases, costs, promotions, customers, commitments, calendar, externals');
            $table->unsignedBigInteger('data_source_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable()->comment('NULL = toda la empresa');
            $table->date('complete_from')->nullable();
            $table->date('complete_through')->nullable()->comment('Último día con datos completos en la fuente');
            $table->string('basis', 20)->default('declared')->comment('declared (usuario/conector), inferred (heurística documentada)');
            $table->timestampTz('last_ingested_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'data_source_id'])->references(['tenant_id', 'id'])->on('data_sources')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches')->cascadeOnDelete();
        });
        DB::statement('CREATE UNIQUE INDEX data_coverage_natural_key ON data_coverage (tenant_id, entity, COALESCE(data_source_id, 0), COALESCE(branch_id, 0))');

        Schema::table('forecast_runs', function (Blueprint $table) {
            $table->char('config_version', 64)->nullable();
            $table->jsonb('effective_config')->nullable();
            $table->unsignedBigInteger('data_snapshot_id')->nullable();
            $table->jsonb('evaluation_manifest')->nullable();
            $table->foreign(['tenant_id', 'data_snapshot_id'])->references(['tenant_id', 'id'])->on('data_snapshots')->nullOnDelete();
        });

        Schema::table('training_jobs', function (Blueprint $table) {
            $table->unsignedBigInteger('core_run_id')->nullable();
            $table->foreign(['tenant_id', 'core_run_id'])->references(['tenant_id', 'id'])->on('core_runs')->cascadeOnDelete();
        });

        Schema::table('model_registry', function (Blueprint $table) {
            $table->string('core', 20)->default('demand');
            $table->string('role', 20)->default('primary')->comment('primary, fallback');
            $table->unsignedBigInteger('core_run_id')->nullable();
            $table->timestampTz('validated_at')->nullable();
            $table->jsonb('artifact_compat')->nullable()->comment('Versión de esquema de entrada, dependencias y feature set requeridos para servir el artefacto');
            $table->foreign('core_run_id')->references('id')->on('core_runs')->nullOnDelete();
            $table->index(['tenant_id', 'core', 'status']);
        });
        DB::statement("UPDATE model_registry SET role = 'fallback', status = 'production' WHERE status = 'fallback'");
        DB::statement("UPDATE model_registry SET status = 'retired' WHERE status = 'archived'");
        DB::statement("UPDATE model_registry SET status = 'validated' WHERE status = 'rejected'");
        DB::statement("COMMENT ON COLUMN model_registry.status IS 'candidate, validated, production, retired, failed (role: primary|fallback)'");

        Schema::table('model_evaluations', function (Blueprint $table) {
            $table->string('core', 20)->default('demand');
            $table->unsignedBigInteger('core_run_id')->nullable();
            $table->jsonb('metrics')->nullable()->comment('Métricas específicas del core: mase, rmsse, pinball_*, brier, log_loss, pr_auc, precision_at_k, ...');
            $table->foreign(['tenant_id', 'core_run_id'])->references(['tenant_id', 'id'])->on('core_runs')->cascadeOnDelete();
        });
        DB::statement('ALTER TABLE model_evaluations ALTER COLUMN forecast_run_id DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DELETE FROM model_evaluations WHERE forecast_run_id IS NULL');
        DB::statement('ALTER TABLE model_evaluations ALTER COLUMN forecast_run_id SET NOT NULL');
        Schema::table('model_evaluations', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'core_run_id']);
            $table->dropColumn(['core', 'core_run_id', 'metrics']);
        });

        DB::statement("UPDATE model_registry SET status = 'fallback' WHERE role = 'fallback' AND status = 'production'");
        DB::statement("UPDATE model_registry SET status = 'archived' WHERE status = 'retired'");
        DB::statement("UPDATE model_registry SET status = 'rejected' WHERE status = 'validated'");
        Schema::table('model_registry', function (Blueprint $table) {
            $table->dropForeign(['core_run_id']);
            $table->dropIndex(['tenant_id', 'core', 'status']);
            $table->dropColumn(['core', 'role', 'core_run_id', 'validated_at', 'artifact_compat']);
        });

        Schema::table('training_jobs', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'core_run_id']);
            $table->dropColumn('core_run_id');
        });

        Schema::table('forecast_runs', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'data_snapshot_id']);
            $table->dropColumn(['config_version', 'effective_config', 'data_snapshot_id', 'evaluation_manifest']);
        });

        Schema::dropIfExists('data_coverage');
        Schema::dropIfExists('core_runs');
        Schema::dropIfExists('data_snapshots');
    }
};
