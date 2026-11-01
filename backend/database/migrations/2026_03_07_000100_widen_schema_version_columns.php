<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * V2.5 · W2-B (ADR-V25-24): `schema_version` guarda la versión del contrato canónico con que se escribió el registro
 * (`<entidad>@<año>.<mes>.<correlativo>`, p. ej. `inventory_snapshot@2026.03.1` = 28 caracteres). La migración
 * 2026_03_03_000100 creó la columna como varchar(20) en `sales`, `sales_document_revisions` e `inventory_snapshots`: la versión de
 * `inventory_snapshot` (y cualquier entidad de nombre largo) no cabría y la escritura fallaría. Se amplía a varchar(40).
 *
 * Aditiva y de metadatos en PostgreSQL (ampliar varchar no reescribe la tabla). Reversible mientras no haya datos largos: `down`
 * ABORTA (sin cambiar nada) si alguna fila ya guarda una versión de más de 20 caracteres, porque estrechar la columna truncaría el
 * contrato con que se escribió el registro. Para revertir de verdad hay que decidir antes qué hacer con esos valores.
 */
return new class extends Migration
{
    private const TABLES = ['sales', 'sales_document_revisions', 'inventory_snapshots'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN schema_version TYPE varchar(40)");
        }
    }

    public function down(): void
    {
        $long = [];
        foreach (self::TABLES as $table) {
            $count = (int) DB::selectOne("SELECT COUNT(*) AS n FROM {$table} WHERE length(schema_version) > 20")->n;
            if ($count > 0) {
                $long[] = "{$table}: {$count}";
            }
        }
        if ($long !== []) {
            throw new RuntimeException('No se puede revertir 2026_03_07_000100: hay valores de schema_version de más de 20 caracteres ('.implode(', ', $long).'); estrechar la columna los truncaría. Decida antes qué hacer con ellos.');
        }
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN schema_version TYPE varchar(20)");
        }
    }
};
