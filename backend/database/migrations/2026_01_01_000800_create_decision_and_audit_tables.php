<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recomendaciones de abastecimiento, alertas, escenarios guardados y auditoría.
 * Predictily NUNCA ejecuta pedidos ni compromete fondos automáticamente:
 * las recomendaciones son sugerencias con supuestos explícitos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('forecast_run_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('product_id');
            $table->string('type', 20)->comment('reorder, stockout_risk, overstock, no_action');
            $table->date('inventory_snapshot_date')->nullable();
            $table->decimal('on_hand', 14, 4)->nullable();
            $table->decimal('reserved', 14, 4)->default(0);
            $table->decimal('in_transit', 14, 4)->default(0);
            $table->decimal('available', 14, 4)->nullable()->comment('on_hand - reserved + in_transit');
            $table->unsignedSmallInteger('lead_time_days');
            $table->string('lead_time_source', 20)->comment('product_branch, product, supplier, tenant_default');
            $table->unsignedSmallInteger('review_period_days');
            $table->decimal('service_level', 5, 4);
            $table->decimal('demand_lead_time', 14, 4)->comment('Demanda pronosticada durante plazo + revisión');
            $table->decimal('demand_lead_time_std', 14, 4)->nullable();
            $table->decimal('safety_stock', 14, 4);
            $table->decimal('reorder_point', 14, 4);
            $table->decimal('suggested_qty', 14, 4)->default(0);
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->decimal('estimated_investment', 16, 4)->nullable()->comment('suggested_qty * unit_cost');
            $table->decimal('days_of_cover', 10, 2)->nullable();
            $table->decimal('stockout_probability', 6, 4)->nullable()->comment('P(demanda en plazo > disponible), aproximación');
            $table->date('stockout_date_estimate')->nullable();
            $table->string('risk_level', 10)->comment('low, medium, high, critical');
            $table->string('status', 20)->default('open')->comment('open, acknowledged, dismissed, ordered');
            $table->jsonb('assumptions')->default('{}');
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->timestampsTz();
            $table->unique(['forecast_run_id', 'branch_id', 'product_id']);
            $table->index(['tenant_id', 'forecast_run_id', 'risk_level']);
            $table->foreign(['tenant_id', 'forecast_run_id'])->references(['tenant_id', 'id'])->on('forecast_runs')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
        });

        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30)->comment('stockout_risk, overstock, forecast_drift, data_quality, import_failed, run_failed, run_completed');
            $table->string('severity', 10)->default('info')->comment('info, warning, critical');
            $table->string('title', 200);
            $table->text('message');
            $table->jsonb('payload')->default('{}');
            $table->string('related_type', 50)->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->string('dedup_key', 150)->nullable()->comment('Evita alertas repetidas abiertas para el mismo hecho');
            $table->string('status', 20)->default('open')->comment('open, acknowledged, resolved');
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['tenant_id', 'dedup_key']);
        });

        Schema::create('scenarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('forecast_run_id')->nullable();
            $table->string('name', 150);
            $table->unsignedSmallInteger('horizon_days');
            $table->jsonb('filters')->default('{}');
            $table->jsonb('assumptions')->comment('Supuestos explícitos del usuario');
            $table->jsonb('results')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'created_at']);
            $table->foreign(['tenant_id', 'forecast_run_id'])->references(['tenant_id', 'id'])->on('forecast_runs')->nullOnDelete();
        });

        // Bitácora de solo inserción. No guardar datos personales en metadata.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 80)->comment('auth.login, auth.failed, import.confirmed, forecast.requested, user.role_changed, data.exported, ...');
            $table->string('auditable_type', 80)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['tenant_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('scenarios');
        Schema::dropIfExists('alerts');
        Schema::dropIfExists('recommendations');
    }
};
