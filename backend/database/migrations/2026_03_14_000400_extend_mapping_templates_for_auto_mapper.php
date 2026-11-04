<?php

use App\Support\Database\CompositeForeignKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * V2.5 · W6-C · Auto Mapper (§4; ADR-V25-10). Extiende `mapping_templates` (contrato 2026_03_14_000100, que NO se modifica) con lo
 * mínimo que la plantilla necesita para ser reutilizable y auditable:
 *
 * - `source_spec`: estructura de origen a la que apunta `field_map` (tablas con sus alias, uniones y columna de marca de agua) y el dialecto;
 *   la consulta SELECT que se ejecuta se genera de aquí (nunca texto libre). El contrato dejó `field_map` como «campo canónico → expresión de
 *   origen»: la expresión es `alias.columna` y el alias se resuelve en `source_spec`.
 * - `data_source_id`: fuente de la que nació una plantilla de empresa (NULL en las públicas). FK compuesta (tenant_id, data_source_id).
 * - `forked_from_id`: plantilla pública de la que se bifurcó una de empresa.
 * - `created_by`, `drift` (informe de deriva que invalidó la plantilla) e `invalidated_at`.
 *
 * Integridad que el contrato no podía dar: en PostgreSQL los NULL son distintos en un UNIQUE, así que (tenant_id NULL, vendor NULL) permitía
 * versiones duplicadas de plantillas públicas o sin proveedor. Se agregan índices únicos parciales con COALESCE, y a lo sumo UNA plantilla
 * aprobada por (empresa o plataforma, sistema, proveedor, entidad, fuente). Aditivo y reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mapping_templates', function (Blueprint $table) {
            $table->unsignedBigInteger('data_source_id')->nullable()->comment('Fuente de la que nació una plantilla de empresa; NULL en las públicas');
            $table->unsignedBigInteger('forked_from_id')->nullable()->comment('Plantilla pública de la que se bifurcó esta');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('source_spec')->default('{}')->comment('Dialecto, tablas con alias, uniones y marca de agua a las que apunta field_map');
            $table->jsonb('drift')->nullable()->comment('Informe de deriva que invalidó la plantilla (agregadas, quitadas, cambio de tipo)');
            $table->timestampTz('invalidated_at')->nullable();
            $table->foreign('forked_from_id')->references('id')->on('mapping_templates')->nullOnDelete();
            $table->foreign(['tenant_id', 'data_source_id'])->references(['tenant_id', 'id'])->on('data_sources')->nullOnDelete();
        });

        DB::statement("ALTER TABLE mapping_templates ADD CONSTRAINT mapping_templates_status_check CHECK (status IN ('proposed', 'approved', 'retired'))");
        // Una plantilla ligada a una fuente solo existe en una empresa; las públicas no apuntan a ninguna fuente.
        DB::statement('ALTER TABLE mapping_templates ADD CONSTRAINT mapping_templates_source_requires_tenant CHECK (data_source_id IS NULL OR tenant_id IS NOT NULL)');
        // Versiones únicas por clave aunque tenant_id o vendor sean NULL (el UNIQUE del contrato no las cubre).
        DB::statement("CREATE UNIQUE INDEX mapping_templates_public_version_unique ON mapping_templates (system_kind, COALESCE(vendor, ''), entity, version) WHERE tenant_id IS NULL");
        DB::statement("CREATE UNIQUE INDEX mapping_templates_tenant_version_unique ON mapping_templates (tenant_id, system_kind, COALESCE(vendor, ''), entity, version) WHERE tenant_id IS NOT NULL");
        // A lo sumo una aprobada por clave y fuente: aprobar una versión retira la anterior.
        DB::statement("CREATE UNIQUE INDEX mapping_templates_one_approved ON mapping_templates (COALESCE(tenant_id, 0), system_kind, COALESCE(vendor, ''), entity, COALESCE(data_source_id, 0)) WHERE status = 'approved'");
        DB::statement('CREATE INDEX mapping_templates_tenant_status_index ON mapping_templates (tenant_id, status, entity)');

        // FK compuesta (tenant_id, data_source_id) ON DELETE SET NULL: anular solo la referencia, nunca tenant_id (2026_03_12_000400).
        CompositeForeignKeys::setNullOnlyReferences();
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS mapping_templates_tenant_status_index');
        DB::statement('DROP INDEX IF EXISTS mapping_templates_one_approved');
        DB::statement('DROP INDEX IF EXISTS mapping_templates_tenant_version_unique');
        DB::statement('DROP INDEX IF EXISTS mapping_templates_public_version_unique');
        DB::statement('ALTER TABLE mapping_templates DROP CONSTRAINT IF EXISTS mapping_templates_source_requires_tenant');
        DB::statement('ALTER TABLE mapping_templates DROP CONSTRAINT IF EXISTS mapping_templates_status_check');
        Schema::table('mapping_templates', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'data_source_id']);
            $table->dropForeign(['forked_from_id']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['data_source_id', 'forked_from_id', 'source_spec', 'drift', 'invalidated_at']);
        });
    }
};
