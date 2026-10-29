<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 2 · P3 Datos e integración (docs/architecture/v2/P3-datos.md). Aditivo y reversible.
 *
 * - source_syncs: cada sincronización de una fuente SQL (modo, ventana `since`, marca de agua, filas,
 *   importación asociada y estado). Una sola sincronización activa por fuente y entidad.
 * - commercial_commitment_revisions: versiones de un compromiso (cotización/pedido/contrato) con el
 *   momento en que la fuente conocía esa versión, para reconstruir el estado a un corte.
 * - commercial_commitment_items: cantidad/unidad de ORIGEN y factor aplicado (ninguna conversión silenciosa).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_syncs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('data_source_id');
            $table->unsignedBigInteger('source_mapping_id')->nullable();
            $table->unsignedBigInteger('import_job_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('entity', 30);
            $table->unsignedInteger('mapping_version')->nullable();
            $table->string('mode', 12)->comment('full, incremental');
            $table->string('status', 20)->default('queued')->comment('queued, running, completed, needs_review, failed');
            $table->string('triggered_by', 12)->default('user')->comment('user, schedule');
            $table->timestampTz('since')->nullable()->comment('Ventana incremental aplicada al parámetro :since (NULL = carga completa)');
            $table->timestampTz('watermark')->nullable()->comment('Marca de agua alcanzada: máx. source_recorded_at extraído o, si no se mapea, el inicio de la sincronización');
            $table->unsignedInteger('rows_extracted')->default(0);
            $table->unsignedInteger('pages')->default(0);
            $table->jsonb('stats')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'data_source_id', 'entity', 'created_at']);
            $table->foreign(['tenant_id', 'data_source_id'])->references(['tenant_id', 'id'])->on('data_sources')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'source_mapping_id'])->references(['tenant_id', 'id'])->on('source_mappings')->nullOnDelete();
            $table->foreign(['tenant_id', 'import_job_id'])->references(['tenant_id', 'id'])->on('import_jobs')->nullOnDelete();
        });
        DB::statement("CREATE UNIQUE INDEX source_syncs_one_active ON source_syncs (tenant_id, data_source_id, entity) WHERE status IN ('queued', 'running')");

        Schema::create('commercial_commitment_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('commitment_id');
            $table->unsignedBigInteger('import_job_id')->nullable();
            $table->unsignedInteger('revision');
            $table->string('status', 25);
            $table->timestampTz('known_at')->nullable()->comment('Cuándo la fuente conocía esta versión; NULL = desconocido');
            $table->timestampTz('cancelled_at')->nullable();
            $table->char('source_hash', 64);
            $table->jsonb('snapshot')->comment('Cabecera y líneas de esta versión');
            $table->timestampTz('recorded_at')->useCurrent()->comment('Ingestión en Predictily');
            $table->unique(['commitment_id', 'revision']);
            $table->index(['tenant_id', 'commitment_id', 'known_at']);
            $table->foreign(['tenant_id', 'commitment_id'])->references(['tenant_id', 'id'])->on('commercial_commitments')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'import_job_id'])->references(['tenant_id', 'id'])->on('import_jobs')->nullOnDelete();
        });

        Schema::table('commercial_commitment_items', function (Blueprint $table) {
            $table->decimal('source_quantity', 14, 4)->nullable()->comment('Cantidad tal como vino de la fuente, antes de convertir');
            $table->string('source_unit', 20)->nullable();
            $table->decimal('conversion_factor', 20, 10)->nullable()->comment('Factor declarado aplicado (NULL = sin conversión)');
        });
    }

    public function down(): void
    {
        Schema::table('commercial_commitment_items', fn (Blueprint $t) => $t->dropColumn(['source_quantity', 'source_unit', 'conversion_factor']));
        Schema::dropIfExists('commercial_commitment_revisions');
        Schema::dropIfExists('source_syncs');
    }
};
