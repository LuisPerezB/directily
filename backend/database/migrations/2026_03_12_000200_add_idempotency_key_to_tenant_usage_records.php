<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * V2.5 · W4-A · medición de uso idempotente (complemento de 2026_03_12_000100, que no se modifica).
 *
 * `tenant_usage_records` registra una fila por (empresa, fuente, referencia, métrica): una ejecución del motor (`forecast_run`/`core_run` +
 * id) o la foto diaria de almacenamiento (`bronze`/`artifacts` + fecha). Reintentar un job o repetir `predictily:usage-snapshot` debe
 * REEMPLAZAR la fila, nunca duplicar el consumo (y con él el costo): un índice único lo garantiza aun con dos procesos a la vez. Las
 * filas sin referencia (`source_ref` nulo) no se ven afectadas (los nulos no colisionan en un índice único).
 * Aditivo y reversible; depura antes los duplicados que hubiera (se conserva la fila más reciente).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DELETE FROM tenant_usage_records a USING tenant_usage_records b WHERE a.source_ref IS NOT NULL AND a.tenant_id = b.tenant_id '
            .'AND a.source = b.source AND a.source_ref = b.source_ref AND a.metric = b.metric AND a.id < b.id');
        DB::statement('CREATE UNIQUE INDEX tenant_usage_records_source_unique ON tenant_usage_records (tenant_id, source, source_ref, metric)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS tenant_usage_records_source_unique');
    }
};
