<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fuentes de datos, trabajos de importación, errores y reportes de calidad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30)->comment('file_upload, rest_api, connector');
            $table->string('connector_key', 60)->nullable()->comment('Clave del conector registrado (p. ej. generic_csv, generic_rest)');
            $table->string('name', 150);
            $table->jsonb('config')->default('{}')->comment('Configuración NO secreta (mapeos por defecto, formato, etc.)');
            $table->text('credentials_encrypted')->nullable()->comment('Credenciales cifradas con APP_KEY; nunca se devuelven por API');
            $table->string('status', 20)->default('active')->comment('active, inactive, error');
            $table->timestampTz('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'type']);
        });

        Schema::create('import_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('data_source_id')->nullable();
            $table->string('entity_type', 30)->comment('sales, products, inventory, lead_times, promotions, customers, suppliers');
            $table->string('original_filename', 255);
            $table->string('stored_path', 500)->comment('Ruta en el disco privado; tenants/{tenant_id}/imports/...');
            $table->char('file_hash', 64)->comment('SHA-256 del archivo original (trazabilidad)');
            $table->unsignedBigInteger('file_size');
            $table->string('file_format', 10)->comment('csv, xlsx');
            $table->string('status', 20)->default('uploaded')->comment('uploaded, awaiting_mapping, validating, validated, importing, completed, failed, rejected');
            $table->jsonb('detected_columns')->nullable();
            $table->jsonb('suggested_mapping')->nullable();
            $table->jsonb('column_mapping')->nullable()->comment('{campo_predictily: columna_origen}');
            $table->jsonb('options')->nullable()->comment('date_format, decimal_separator, default_branch_code, create_missing_products, create_missing_branches, skip_invalid_rows');
            $table->jsonb('preview')->nullable()->comment('Primeras filas normalizadas para vista previa');
            $table->jsonb('stats')->nullable()->comment('rows_total, rows_valid, rows_error, rows_warning, duplicates_in_file, documents_new, documents_updated, documents_unchanged, returns, voided, ...');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->text('error_message')->nullable();
            $table->timestampTz('validated_at')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'file_hash']); // detectar re-subida del mismo archivo
            $table->foreign(['tenant_id', 'data_source_id'])->references(['tenant_id', 'id'])->on('data_sources')->nullOnDelete();
        });

        Schema::create('import_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('import_job_id');
            $table->unsignedInteger('row_number')->comment('Fila en el archivo original (1 = primera fila de datos)');
            $table->string('column', 100)->nullable();
            $table->string('error_code', 50)->comment('required, invalid_date, invalid_number, negative_quantity, duplicate_in_file, unknown_branch, unknown_product, currency_mismatch, ...');
            $table->string('severity', 10)->default('error')->comment('error, warning');
            $table->text('message');
            $table->string('raw_value', 255)->nullable()->comment('Valor truncado; no almacenar datos personales completos');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['import_job_id', 'severity', 'row_number']);
            $table->foreign(['tenant_id', 'import_job_id'])->references(['tenant_id', 'id'])->on('import_jobs')->cascadeOnDelete();
        });

        Schema::create('data_quality_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('import_job_id')->nullable();
            $table->unsignedBigInteger('forecast_run_id')->nullable()->comment('Sin FK: forecast_runs se crea después');
            $table->string('scope', 30)->comment('import, forecasting_readiness');
            $table->decimal('score', 5, 2)->nullable()->comment('0-100, indicador orientativo');
            $table->jsonb('summary')->comment('Métricas agregadas del reporte');
            $table->jsonb('issues')->default('[]')->comment('[{code, severity, message, count, sample}]');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['tenant_id', 'scope', 'created_at']);
            $table->foreign(['tenant_id', 'import_job_id'])->references(['tenant_id', 'id'])->on('import_jobs')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_quality_reports');
        Schema::dropIfExists('import_errors');
        Schema::dropIfExists('import_jobs');
        Schema::dropIfExists('data_sources');
    }
};
