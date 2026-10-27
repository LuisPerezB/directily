<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Núcleo multi-tenant: planes, empresas (tenants), membresías y consumo.
 * Ver docs/03-modelo-datos.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique()->comment('trial, basico, profesional, empresarial');
            $table->string('name', 100);
            $table->text('description')->nullable();
            // limits: {max_branches, max_active_products, max_import_rows_per_month,
            //          max_forecast_runs_per_day, max_users, horizons:[7,30,90],
            //          features:{scenarios, api_access, external_indicators, custom_models}}
            $table->jsonb('limits');
            $table->decimal('monthly_price_usd', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->comment('Identificador público usado en el header X-Tenant-ID');
            $table->string('name', 150);
            $table->string('legal_name', 200)->nullable();
            $table->string('tax_id', 20)->nullable()->comment('RUC de la empresa (dato de persona jurídica)');
            $table->string('sector', 50)->default('otro')->comment('ferreteria, distribuidora, repuestos_automotrices, repuestos_agricolas, retail_multisucursal, importadora, consumo_recurrente, otro');
            $table->char('country', 2)->default('EC');
            $table->char('currency', 3)->default('USD');
            $table->string('timezone', 64)->default('America/Guayaquil');
            $table->string('locale', 10)->default('es-EC');
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->jsonb('plan_overrides')->nullable()->comment('Límites específicos que sobrescriben el plan');
            // settings: {default_horizon_days:30, service_level:0.95, review_period_days:7,
            //            default_lead_time_days:7, forecast_schedule:'daily'|'weekly'|'manual',
            //            stockout_risk_threshold:0.3}
            $table->jsonb('settings')->default('{}');
            $table->boolean('allow_shared_training')->default(false)->comment('Consentimiento explícito para usar datos en modelos compartidos (Nivel B/C entre empresas)');
            $table->timestampTz('shared_training_consented_at')->nullable();
            $table->foreignId('shared_training_consented_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('data_retention_months')->default(60);
            $table->string('status', 20)->default('active')->comment('active, suspended, pending_deletion');
            $table->timestampTz('onboarding_completed_at')->nullable();
            $table->timestampTz('deletion_requested_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index('status');
        });

        Schema::create('tenant_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20)->comment('owner, admin, analyst, viewer');
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'user_id']);
            $table->index('user_id'); // "mis empresas" al iniciar sesión
        });

        Schema::create('tenant_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->char('period', 7)->comment('YYYY-MM');
            $table->string('metric', 50)->comment('import_rows, forecast_runs, api_requests, active_products');
            $table->unsignedBigInteger('value')->default(0);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'period', 'metric']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_usage');
        Schema::dropIfExists('tenant_users');
        Schema::dropIfExists('tenants');
        Schema::dropIfExists('plans');
    }
};
