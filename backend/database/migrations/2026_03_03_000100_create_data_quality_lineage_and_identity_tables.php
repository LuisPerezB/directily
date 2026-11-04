<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * V2.5 · Data Core P0 (arquitecto; ADR-V25-04, ADR-V25-05 y ADR-V25-24).
 *
 * - SourceSystem: `data_sources` gana tipo de sistema, proveedor, zona horaria de origen y versión/huella del
 *   esquema de origen (deriva de esquema = cambio de huella).
 * - Bronze: `bronze_objects`, objetos inmutables en el ObjectStore (clave generada por el servidor con prefijo
 *   de la empresa), SHA-256, procedencia, versión de esquema y retención. El contenido nunca se modifica; solo
 *   cambian estado y retención.
 * - Linaje Silver: `bronze_object_id` y `source_row` en ventas (versión vigente y revisiones) e inventario;
 *   `schema_version` = versión del contrato canónico con que se escribió el registro.
 * - Data Quality Engine: `data_quality_reports` (métricas por dataset y resultado de gates por módulo),
 *   `data_quality_issues` (una fila por hallazgo con clase AUTO_FIX_SAFE / REVIEW_REQUIRED / QUARANTINED /
 *   WARNING / BLOCKER, valor original acotado y puntero al registro Bronze) y `quarantine_records` (registros
 *   retenidos fuera de Silver, revisables y reprocesables).
 * - Reconciliación: `reconciliation_reports` (conteos y totales por capa fuente → Bronze → Silver → Gold por
 *   ventana, discrepancias y estado).
 * - Identidades: `product_aliases` y `customer_aliases` (código de origen → identidad canónica con vigencia;
 *   cambio de código, fusión, división y alias).
 * Todo aditivo y reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            $table->string('system_kind', 20)->nullable()->comment('erp, pos, crm, spreadsheet, api, bridge, other');
            $table->string('vendor', 80)->nullable()->comment('Producto de origen declarado (p. ej. Contífico, Odoo); sin verificar salvo prueba');
            $table->string('source_timezone', 64)->nullable()->comment('Zona horaria de las marcas de tiempo del origen; NULL = la de la empresa');
            $table->unsignedInteger('schema_version')->default(1)->comment('Versión del esquema de origen observado; sube cuando cambia la huella');
            $table->char('schema_fingerprint', 64)->nullable()->comment('SHA-256 de columnas y tipos observados del origen');
        });

        Schema::create('bronze_objects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('data_source_id')->nullable();
            $table->string('kind', 20)->comment('file_upload, sql_extract, api_batch, bridge_batch');
            $table->string('entity', 30)->comment('sales, products, inventory, customers, commitments, ...');
            $table->string('storage_driver', 20)->comment('local, s3');
            $table->string('object_key', 500)->comment('Clave generada por el servidor: tenants/<uuid>/bronze/<entity>/<yyyy>/<mm>/<sha256>.<ext>');
            $table->char('sha256', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->string('content_type', 120)->nullable();
            $table->char('schema_fingerprint', 64)->nullable();
            $table->unsignedInteger('schema_version')->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->string('original_name', 255)->nullable()->comment('Nombre de archivo declarado (no se usa para rutas)');
            $table->foreignId('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('received_from_device', 100)->nullable()->comment('Identificador del dispositivo Bridge, si aplica');
            $table->timestampTz('received_at')->useCurrent();
            $table->date('retention_until')->nullable();
            $table->string('status', 20)->default('stored')->comment('stored, processed, quarantined, purged');
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'sha256', 'entity']);
            $table->index(['tenant_id', 'data_source_id', 'received_at']);
            $table->foreign(['tenant_id', 'data_source_id'])->references(['tenant_id', 'id'])->on('data_sources')->nullOnDelete();
        });

        Schema::table('import_jobs', function (Blueprint $table) {
            $table->unsignedBigInteger('bronze_object_id')->nullable();
            $table->foreign(['tenant_id', 'bronze_object_id'])->references(['tenant_id', 'id'])->on('bronze_objects')->nullOnDelete();
        });
        Schema::table('source_syncs', function (Blueprint $table) {
            $table->unsignedBigInteger('bronze_object_id')->nullable();
            $table->foreign(['tenant_id', 'bronze_object_id'])->references(['tenant_id', 'id'])->on('bronze_objects')->nullOnDelete();
        });
        foreach (['sales', 'sales_document_revisions', 'inventory_snapshots'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('bronze_object_id')->nullable();
                $table->unsignedInteger('source_row')->nullable()->comment('Fila/registro de origen dentro del objeto Bronze');
                $table->string('schema_version', 20)->nullable()->comment('Versión del contrato canónico con que se escribió');
                $table->foreign(['tenant_id', 'bronze_object_id'])->references(['tenant_id', 'id'])->on('bronze_objects')->nullOnDelete();
            });
        }

        // La tabla existe desde la etapa 1 (scope, score, summary, issues JSON): se amplía sin romper su contrato.
        Schema::table('data_quality_reports', function (Blueprint $table) {
            $table->string('dataset', 30)->nullable()->comment('sales, inventory, customers, products, commitments, ...');
            $table->unsignedBigInteger('source_sync_id')->nullable();
            $table->unsignedBigInteger('bronze_object_id')->nullable();
            $table->unsignedBigInteger('core_run_id')->nullable();
            $table->date('as_of')->nullable();
            $table->jsonb('metrics')->nullable()->comment('V2.5: cobertura, % inválido, integridad de claves, historia por SKU/cliente, ceros, variabilidad, estabilidad de IDs, presencia de costo/inventario/precio, % cierres/quiebres, frescura');
            $table->jsonb('issue_counts')->nullable()->comment('{clase DQ: n}');
            $table->jsonb('gate_results')->nullable()->comment('{módulo: {outcome: PASS|WARN|DEGRADE|BLOCK, reasons: [...], disabled_features: [...]}}');
            $table->string('rules_version', 64)->nullable()->comment('Versión/huella de las reglas y umbrales aplicados');
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'dataset', 'created_at']);
            $table->foreign(['tenant_id', 'source_sync_id'])->references(['tenant_id', 'id'])->on('source_syncs')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'bronze_object_id'])->references(['tenant_id', 'id'])->on('bronze_objects')->nullOnDelete();
            $table->foreign(['tenant_id', 'core_run_id'])->references(['tenant_id', 'id'])->on('core_runs')->cascadeOnDelete();
        });

        Schema::create('data_quality_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('report_id');
            $table->string('dq_class', 20)->comment('AUTO_FIX_SAFE, REVIEW_REQUIRED, QUARANTINED, WARNING, BLOCKER');
            $table->string('code', 60);
            $table->string('entity', 30);
            $table->string('record_ref', 200)->nullable()->comment('Clave del documento/registro (sin PII)');
            $table->unsignedInteger('source_row')->nullable();
            $table->string('field', 60)->nullable();
            $table->text('original_value')->nullable()->comment('Valor original (acotado); el registro íntegro queda en Bronze');
            $table->text('proposed_value')->nullable();
            $table->boolean('applied')->default(false)->comment('true solo para AUTO_FIX_SAFE aplicados');
            $table->text('reason');
            $table->string('status', 20)->default('open')->comment('open, accepted, rejected, reprocessed');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'report_id']);
            $table->index(['tenant_id', 'dq_class', 'status']);
            $table->foreign(['tenant_id', 'report_id'])->references(['tenant_id', 'id'])->on('data_quality_reports')->cascadeOnDelete();
        });
        DB::statement("ALTER TABLE data_quality_issues ADD CONSTRAINT data_quality_issues_class CHECK (dq_class IN ('AUTO_FIX_SAFE','REVIEW_REQUIRED','QUARANTINED','WARNING','BLOCKER'))");

        Schema::create('quarantine_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('bronze_object_id')->nullable();
            $table->unsignedBigInteger('import_job_id')->nullable();
            $table->unsignedBigInteger('source_sync_id')->nullable();
            $table->string('entity', 30);
            $table->string('record_key', 200)->comment('Clave natural del registro retenido (p. ej. tipo|número de documento)');
            $table->jsonb('payload')->comment('Registro normalizado retenido, minimizado (sin campos sensibles innecesarios)');
            $table->jsonb('issue_codes')->default('[]');
            $table->string('status', 20)->default('pending')->comment('pending, released, discarded');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'entity', 'status']);
            $table->foreign(['tenant_id', 'bronze_object_id'])->references(['tenant_id', 'id'])->on('bronze_objects')->nullOnDelete();
            $table->foreign(['tenant_id', 'import_job_id'])->references(['tenant_id', 'id'])->on('import_jobs')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'source_sync_id'])->references(['tenant_id', 'id'])->on('source_syncs')->cascadeOnDelete();
        });

        Schema::create('reconciliation_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 20)->comment('import, sync, window');
            $table->string('entity', 30)->default('sales');
            $table->unsignedBigInteger('data_source_id')->nullable();
            $table->unsignedBigInteger('bronze_object_id')->nullable();
            $table->unsignedBigInteger('import_job_id')->nullable();
            $table->unsignedBigInteger('source_sync_id')->nullable();
            $table->date('window_from')->nullable();
            $table->date('window_to')->nullable();
            $table->jsonb('layers')->default('{}')->comment('{source|bronze|silver|gold: {rows, documents, lines, units, net_amount, voided, credit_notes, duplicates, quarantined}}');
            $table->jsonb('discrepancies')->default('[]')->comment('[{from, to, metric, expected, observed, explanation}]');
            $table->string('status', 20)->comment('balanced, explained, unexplained');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'entity', 'created_at']);
            $table->foreign(['tenant_id', 'data_source_id'])->references(['tenant_id', 'id'])->on('data_sources')->nullOnDelete();
            $table->foreign(['tenant_id', 'bronze_object_id'])->references(['tenant_id', 'id'])->on('bronze_objects')->nullOnDelete();
            $table->foreign(['tenant_id', 'import_job_id'])->references(['tenant_id', 'id'])->on('import_jobs')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'source_sync_id'])->references(['tenant_id', 'id'])->on('source_syncs')->cascadeOnDelete();
        });

        foreach (['product' => 'products', 'customer' => 'customers'] as $singular => $plural) {
            Schema::create("{$singular}_aliases", function (Blueprint $table) use ($singular, $plural) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger("{$singular}_id")->comment('Identidad canónica');
                $table->unsignedBigInteger('data_source_id')->nullable()->comment('NULL = cualquier fuente');
                $table->string('source_code', 100)->comment('Código en el origen (SKU, cédula/RUC normalizado o código interno)');
                $table->string('kind', 20)->default('alias')->comment('alias, code_change, merge, split');
                $table->date('valid_from')->nullable();
                $table->date('valid_to')->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestampsTz();
                $table->unique(['tenant_id', 'id']);
                $table->index(['tenant_id', "{$singular}_id"]);
                $table->foreign(['tenant_id', "{$singular}_id"])->references(['tenant_id', 'id'])->on($plural)->cascadeOnDelete();
                $table->foreign(['tenant_id', 'data_source_id'])->references(['tenant_id', 'id'])->on('data_sources')->cascadeOnDelete();
            });
            DB::statement("CREATE UNIQUE INDEX {$singular}_aliases_natural_key ON {$singular}_aliases (tenant_id, COALESCE(data_source_id, 0), source_code, COALESCE(valid_from, DATE '1900-01-01'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_aliases');
        Schema::dropIfExists('product_aliases');
        Schema::dropIfExists('reconciliation_reports');
        Schema::dropIfExists('quarantine_records');
        Schema::dropIfExists('data_quality_issues');
        Schema::table('data_quality_reports', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'source_sync_id']);
            $table->dropForeign(['tenant_id', 'bronze_object_id']);
            $table->dropForeign(['tenant_id', 'core_run_id']);
            $table->dropIndex(['tenant_id', 'dataset', 'created_at']);
            $table->dropUnique(['tenant_id', 'id']);
            $table->dropColumn(['dataset', 'source_sync_id', 'bronze_object_id', 'core_run_id', 'as_of', 'metrics', 'issue_counts', 'gate_results', 'rules_version']);
        });
        foreach (['inventory_snapshots', 'sales_document_revisions', 'sales'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropForeign(['tenant_id', 'bronze_object_id']);
                $table->dropColumn(['bronze_object_id', 'source_row', 'schema_version']);
            });
        }
        Schema::table('source_syncs', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'bronze_object_id']);
            $table->dropColumn('bronze_object_id');
        });
        Schema::table('import_jobs', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'bronze_object_id']);
            $table->dropColumn('bronze_object_id');
        });
        Schema::dropIfExists('bronze_objects');
        Schema::table('data_sources', function (Blueprint $table) {
            $table->dropColumn(['system_kind', 'vendor', 'source_timezone', 'schema_version', 'schema_fingerprint']);
        });
    }
};
