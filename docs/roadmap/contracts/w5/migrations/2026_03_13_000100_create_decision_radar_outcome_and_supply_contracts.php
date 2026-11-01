<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * V2.5 · W5 Flujo empresarial P1 (arquitecto; ADR-V25-12, -13 y -14; §10, §11, §12).
 *
 * - Decision Engine (ADR-V25-13): `decision_recommendations` con el contrato de §11 (corte, horizonte, entidad, fuente y
 *   huella, versiones de modelos, probabilidad e intervalo, supuestos, restricciones, disponibilidad, costo de la acción,
 *   valor bruto esperado, contribución estimada, incremental SOLO con evidencia, etiqueta de atribución, score con versión
 *   de fórmula, incertidumbre, explicación verificable, política, prioridad, dueño, estado, expiración y siguiente paso).
 *   Es distinta de `recommendations` (etapa 1: reposición por serie), que se conserva. Clave de idempotencia por empresa.
 * - Workflow: `decision_events` audita cada transición (suggested → assigned → reviewed → approved | rejected →
 *   in_progress → executed → measured → closed, + expired) y el feedback de la agenda; idempotente por clave.
 * - Revenue Radar y Agenda (ADR-V25-12): capa sobre lo anterior; `agenda_status` conserva los estados heredados
 *   (pendiente, contactado, interesado, pedido_recibido, pospuesto, descartado) mapeados al workflow sin perder historial.
 * - Experimentos (ADR-V25-14): `experiment_assignments` registra la asignación (tratamiento/control) AL GENERAR la
 *   recomendación, con semilla y política, para análisis por intención de tratamiento.
 * - Outcomes (ADR-V25-14): `decision_outcomes` une recomendación → acción → evento observable posterior y clasifica el
 *   resultado (forecast, observed, temporally_associated, incremental_estimated, causally_attributable); contribución, no
 *   ingresos brutos; incluye rechazadas, no contactadas y caducadas.
 * - Supply (§10): perecibilidad en `products` y `inventory_lots` con vencimiento para FEFO.
 * Todo aditivo y reversible.
 */
return new class extends Migration
{
    private const STATUSES = ['suggested', 'assigned', 'reviewed', 'approved', 'rejected', 'in_progress', 'executed', 'measured', 'closed', 'expired'];

    private const AGENDA = ['pendiente', 'contactado', 'interesado', 'pedido_recibido', 'pospuesto', 'descartado'];

    public function up(): void
    {
        Schema::create('decision_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30)->comment('contact, recovery, customer_risk, replenishment, inventory_mismatch, budget_constraint, scenario');
            $table->date('as_of');
            $table->smallInteger('horizon_days');
            $table->string('entity_type', 30)->comment('customer_product, customer, product_branch, product');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('source_core_run_id')->nullable();
            $table->unsignedBigInteger('source_forecast_run_id')->nullable();
            $table->unsignedBigInteger('data_snapshot_id')->nullable();
            $table->jsonb('model_versions')->default('{}');
            $table->decimal('probability', 8, 6)->nullable();
            $table->jsonb('interval')->default('{}')->comment('Intervalos/cuantiles de las magnitudes usadas');
            $table->jsonb('assumptions')->default('[]');
            $table->jsonb('constraints')->default('[]');
            $table->jsonb('availability')->default('{}')->comment('Stock disponible, en tránsito y comprometido al corte');
            $table->decimal('action_cost', 16, 4)->nullable();
            $table->decimal('expected_gross_value', 16, 4)->nullable();
            $table->decimal('expected_contribution', 16, 4)->nullable()->comment('NULL si el costo no es confiable');
            $table->decimal('incremental_estimate', 16, 4)->nullable()->comment('Solo con evidencia de uplift');
            $table->jsonb('incremental_evidence')->nullable();
            $table->string('attribution_label', 30)->default('potential_unproven')->comment('potential_unproven, incremental_estimated, causally_attributable');
            $table->decimal('score', 16, 6)->nullable();
            $table->string('score_formula_version', 64);
            $table->jsonb('uncertainty')->default('{}');
            $table->jsonb('explanation')->default('[]')->comment('Factores verificables (sin PII)');
            $table->string('policy', 60)->nullable();
            $table->smallInteger('priority')->default(3);
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('suggested');
            $table->string('agenda_status', 20)->nullable()->comment('Estado heredado de la agenda V2.2');
            $table->string('experiment_group', 12)->nullable()->comment('treatment, control o NULL sin experimento');
            $table->string('proposed_by_kind', 10)->default('system')->comment('system, user');
            $table->foreignId('proposed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at')->nullable();
            $table->text('next_step')->nullable();
            $table->char('idempotency_key', 64)->comment('SHA-256 de (kind, as_of, horizonte, entidad, fuente)');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'status', 'priority']);
            $table->index(['tenant_id', 'customer_id']);
            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'source_core_run_id'])->references(['tenant_id', 'id'])->on('core_runs')->nullOnDelete();
            $table->foreign(['tenant_id', 'source_forecast_run_id'])->references(['tenant_id', 'id'])->on('forecast_runs')->nullOnDelete();
        });
        DB::statement("ALTER TABLE decision_recommendations ADD CONSTRAINT decision_recommendations_status_check CHECK (status IN ('".implode("', '", self::STATUSES)."'))");
        DB::statement("ALTER TABLE decision_recommendations ADD CONSTRAINT decision_recommendations_agenda_check CHECK (agenda_status IS NULL OR agenda_status IN ('".implode("', '", self::AGENDA)."'))");
        DB::statement("ALTER TABLE decision_recommendations ADD CONSTRAINT decision_recommendations_attribution_check CHECK (attribution_label IN ('potential_unproven', 'incremental_estimated', 'causally_attributable'))");

        Schema::create('decision_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('recommendation_id');
            $table->string('event', 20)->comment('transition, feedback, assignment, comment');
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->string('agenda_status', 20)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_kind', 10)->comment('user, system');
            $table->text('reason')->nullable();
            $table->jsonb('payload')->default('{}');
            $table->char('idempotency_key', 64);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'recommendation_id', 'created_at']);
            $table->foreign(['tenant_id', 'recommendation_id'])->references(['tenant_id', 'id'])->on('decision_recommendations')->cascadeOnDelete();
        });

        Schema::create('experiment_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('experiment_key', 80);
            $table->string('unit_type', 20)->comment('customer, customer_product');
            $table->string('unit_key', 80)->comment('customer:<id> o customer:<id>|product:<id>');
            $table->string('group', 12)->comment('treatment, control');
            $table->jsonb('policy')->default('{}')->comment('Fracción de control, semilla, estratos');
            $table->timestampTz('assigned_at')->useCurrent();
            $table->unique(['tenant_id', 'experiment_key', 'unit_type', 'unit_key']);
        });
        DB::statement("ALTER TABLE experiment_assignments ADD CONSTRAINT experiment_assignments_group_check CHECK (\"group\" IN ('treatment', 'control'))");

        Schema::create('decision_outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('recommendation_id');
            $table->string('action_kind', 30)->nullable()->comment('contacted, not_contacted, rejected, expired, ordered, ...');
            $table->timestampTz('action_at')->nullable();
            $table->date('window_from');
            $table->date('window_to');
            $table->string('observed_event', 30)->comment('purchase, no_purchase, stockout, overstock, ...');
            $table->string('observed_ref', 80)->nullable()->comment('Referencia sin PII (p. ej. sale:<id>)');
            $table->decimal('observed_units', 16, 4)->nullable();
            $table->decimal('observed_net_amount', 16, 4)->nullable();
            $table->decimal('contribution', 16, 4)->nullable()->comment('NULL si el costo no es confiable');
            $table->decimal('contact_cost', 16, 4)->nullable();
            $table->string('classification', 30)->comment('forecast, observed, temporally_associated, incremental_estimated, causally_attributable');
            $table->string('method', 20)->default('none')->comment('itt, quasi_experiment, none');
            $table->jsonb('evidence')->default('{}');
            $table->timestampTz('measured_at')->useCurrent();
            $table->index(['tenant_id', 'recommendation_id']);
            $table->foreign(['tenant_id', 'recommendation_id'])->references(['tenant_id', 'id'])->on('decision_recommendations')->cascadeOnDelete();
        });
        DB::statement("ALTER TABLE decision_outcomes ADD CONSTRAINT decision_outcomes_classification_check CHECK (classification IN ('forecast', 'observed', 'temporally_associated', 'incremental_estimated', 'causally_attributable'))");

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_perishable')->default(false);
            $table->unsignedInteger('shelf_life_days')->nullable()->comment('Vida útil declarada; NULL = no declarada');
        });

        Schema::create('inventory_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('product_id');
            $table->string('lot_code', 60);
            $table->date('snapshot_date');
            $table->date('expiry_date')->nullable();
            $table->decimal('quantity', 16, 4);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'branch_id', 'product_id', 'lot_code', 'snapshot_date'], 'inventory_lots_unique');
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_lots');
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['is_perishable', 'shelf_life_days']);
        });
        Schema::dropIfExists('decision_outcomes');
        Schema::dropIfExists('experiment_assignments');
        Schema::dropIfExists('decision_events');
        Schema::dropIfExists('decision_recommendations');
    }
};
