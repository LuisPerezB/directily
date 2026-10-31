<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * V2.5 · Contrato temporal de ventas (arquitecto; docs/architecture/adr/V25-ADRS.md, ADR-V25-23).
 *
 * Problema (auditoría 31-oct-2026, defectos HIGH de datos/demanda/cliente/conectores): el motor leía las
 * ventas por fecha de evento y con el estado ACTUAL del documento, así que una venta cargada tarde o una
 * anulación posterior reescribían los cortes pasados (fuga temporal en backtests y features); y un reintento
 * con una versión anterior sobrescribía la nueva.
 *
 * Contrato:
 * - Cada versión de un documento tiene una ventana de conocimiento [known_from, known_until).
 *   · Versión vigente: `sales.version_known_from` (NULL = inicio del día local de `sale_date`, regla de
 *     carga histórica) y `known_until` abierta.
 *   · Versiones reemplazadas: `sales_document_revisions` (cabecera) + `sale_item_revisions` (líneas), con
 *     `known_until` = `known_from` de la versión que la reemplazó.
 * - `sales.ingestion_mode`:
 *   · `backfill`: carga histórica declarada (onboarding). Se ASUME que el documento era conocido desde el
 *     inicio de su día de negocio (supuesto documentado; sin historial de estados de la fuente, el estado
 *     final se asume desde ese día). Una re-carga backfill colapsa versiones (known_until = known_from).
 *   · `incremental`: carga rutinaria/sincronización/API. La versión es conocida desde que Predictily la
 *     recibió (`version_known_from` = hora de ingestión); un documento que llega tarde NO es visible en
 *     cortes anteriores a su llegada.
 *   La columna tiene DEFAULT 'backfill' solo por compatibilidad de filas existentes y fixtures; todo camino
 *   de escritura (importador, POST /sales/batch, sincronización SQL, demo) debe fijarlo explícitamente.
 * - Visibilidad al corte C (fecha local de la empresa): una versión es visible si
 *   known_from < medianoche local de (C + 1) y (known_until IS NULL o known_until >= medianoche local de (C + 1)).
 * - Vista `sales_line_versions`: todas las versiones de líneas con su ventana de conocimiento (fuente única
 *   de lectura as-of para Demand y Customer Core en el motor).
 * - `data_coverage_gaps`: huecos internos de una fuente (caída de integración, migración, origen ausente):
 *   los días cubiertos son `missing_source`, nunca ceros.
 * Todo aditivo y reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('ingestion_mode', 20)->default('backfill')
                ->comment('backfill (carga histórica: conocido desde el día de negocio) | incremental (conocido desde la ingestión)');
            $table->timestampTz('version_known_from')->nullable()
                ->comment('Desde cuándo Predictily conoce la versión vigente; NULL = inicio del día local de sale_date (backfill)');
            $table->unsignedInteger('current_revision')->default(0)->comment('Número de la versión vigente (0 = primera)');
            $table->timestampTz('first_seen_at')->nullable()->comment('Primera ingestión del documento en Predictily (inmutable)');
        });
        DB::statement('UPDATE sales SET first_seen_at = created_at WHERE first_seen_at IS NULL');

        Schema::create('sales_document_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('sale_id');
            $table->unsignedInteger('revision')->comment('Versión reemplazada (0, 1, ...)');
            $table->string('document_type', 20);
            $table->string('status', 20);
            $table->timestampTz('issued_at');
            $table->date('sale_date');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('channel', 30)->nullable();
            $table->char('currency', 3);
            $table->decimal('subtotal', 14, 4);
            $table->decimal('discount_total', 14, 4);
            $table->decimal('tax_total', 14, 4);
            $table->decimal('total', 14, 4);
            $table->char('source_hash', 64);
            $table->timestampTz('source_recorded_at')->nullable();
            $table->string('ingestion_mode', 20);
            $table->timestampTz('known_from')->nullable()->comment('NULL = inicio del día local de sale_date (backfill)');
            $table->timestampTz('known_until')->comment('known_from de la versión que la reemplazó');
            $table->unsignedBigInteger('import_job_id')->nullable()->comment('Import que tenía esta versión');
            $table->unsignedBigInteger('superseded_by_import_job_id')->nullable();
            $table->unsignedBigInteger('data_source_id')->nullable();
            $table->string('change_kind', 30)->comment('status_changed, lines_changed, header_changed, mixed');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['sale_id', 'revision']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'sale_id']);
            $table->foreign(['tenant_id', 'sale_id'])->references(['tenant_id', 'id'])->on('sales')->cascadeOnDelete();
        });

        Schema::create('sale_item_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('sale_id');
            $table->unsignedInteger('revision');
            $table->unsignedInteger('line_number');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('branch_id');
            $table->date('sale_date');
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_price', 14, 4);
            $table->decimal('discount_amount', 14, 4)->default(0);
            $table->decimal('net_amount', 14, 4);
            $table->decimal('tax_amount', 14, 4)->default(0);
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->boolean('is_return')->default(false);
            $table->unsignedBigInteger('promotion_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['sale_id', 'revision', 'line_number']);
            $table->index(['tenant_id', 'sale_date', 'branch_id', 'product_id'], 'sale_item_revisions_tenant_date_idx');
            $table->foreign(['tenant_id', 'sale_id'])->references(['tenant_id', 'id'])->on('sales')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->restrictOnDelete();
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches')->restrictOnDelete();
        });

        Schema::create('data_coverage_gaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('entity', 30)->comment('sales, inventory, purchases, costs, promotions, customers, commitments');
            $table->unsignedBigInteger('data_source_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable()->comment('NULL = toda la empresa');
            $table->date('gap_from');
            $table->date('gap_to');
            $table->string('reason', 30)->default('unknown')->comment('integration_outage, source_missing, migration, unknown');
            $table->string('basis', 20)->default('declared')->comment('declared (usuario/conector) | inferred (regla documentada)');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'entity', 'gap_from']);
            $table->foreign(['tenant_id', 'data_source_id'])->references(['tenant_id', 'id'])->on('data_sources')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches')->cascadeOnDelete();
        });
        DB::statement('ALTER TABLE data_coverage_gaps ADD CONSTRAINT data_coverage_gaps_range CHECK (gap_to >= gap_from)');

        DB::statement(<<<'SQL'
            CREATE VIEW sales_line_versions AS
            SELECT si.tenant_id, si.sale_id, s.current_revision AS revision, true AS is_current,
                   s.document_type, s.status, s.customer_id, s.channel, s.ingestion_mode,
                   si.line_number, si.branch_id, si.product_id, si.sale_date, si.quantity, si.unit_price,
                   si.discount_amount, si.net_amount, si.tax_amount, si.unit_cost, si.is_return, si.promotion_id,
                   COALESCE(s.version_known_from, (si.sale_date::timestamp AT TIME ZONE t.timezone)) AS known_from,
                   NULL::timestamptz AS known_until
            FROM sale_items si
            JOIN sales s ON s.id = si.sale_id AND s.tenant_id = si.tenant_id
            JOIN tenants t ON t.id = si.tenant_id
            UNION ALL
            SELECT sir.tenant_id, sir.sale_id, r.revision, false AS is_current,
                   r.document_type, r.status, r.customer_id, r.channel, r.ingestion_mode,
                   sir.line_number, sir.branch_id, sir.product_id, sir.sale_date, sir.quantity, sir.unit_price,
                   sir.discount_amount, sir.net_amount, sir.tax_amount, sir.unit_cost, sir.is_return, sir.promotion_id,
                   COALESCE(r.known_from, (sir.sale_date::timestamp AT TIME ZONE t.timezone)) AS known_from,
                   r.known_until
            FROM sale_item_revisions sir
            JOIN sales_document_revisions r ON r.tenant_id = sir.tenant_id AND r.sale_id = sir.sale_id AND r.revision = sir.revision
            JOIN tenants t ON t.id = sir.tenant_id
            SQL);
        DB::statement("COMMENT ON VIEW sales_line_versions IS 'Versiones de líneas de venta con ventana de conocimiento [known_from, known_until). Lectura as-of: known_from < medianoche local (C+1) y (known_until IS NULL o known_until >= medianoche local (C+1)).'");
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS sales_line_versions');
        Schema::dropIfExists('data_coverage_gaps');
        Schema::dropIfExists('sale_item_revisions');
        Schema::dropIfExists('sales_document_revisions');
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['ingestion_mode', 'version_known_from', 'current_revision', 'first_seen_at']);
        });
    }
};
