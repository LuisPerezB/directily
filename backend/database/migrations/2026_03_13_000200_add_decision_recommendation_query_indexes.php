<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * V2.5 · W5-B (revisión conjunta de W5-A y W5-B): índices de las consultas reales del Decision Engine y del Revenue Radar sobre `decision_recommendations`
 * (el contrato de 2026_03_13_000100 solo trae (tenant, status, priority), (tenant, customer) y la clave de idempotencia). Aditivo y reversible; sin cambios de datos.
 * Se verificó con EXPLAIN sobre cientos de miles de filas sintéticas (docs/decision/DECISION_ENGINE.md § 15).
 *
 * - `decision_recommendations_scope_idx` (tenant, kind, customer, product, horizonte): el ALCANCE de una oportunidad. Lo usan la búsqueda de la que está en curso, el
 *   reemplazo de las no trabajadas y la defensa de «una accionable por alcance» del Radar.
 * - `decision_recommendations_group_idx` (tenant, kind, corte, horizonte): el GRUPO de prioridad. Lo usa el reordenamiento por posición del score al proponer.
 * - `decision_recommendations_work_idx` (tenant, estado, tipo, score descendente): la lista de trabajo por defecto (orden por score, estado y tipo).
 * - `decision_recommendations_expiry_idx` (tenant, vencimiento) parcial sobre los estados sin acción en curso: el barrido horario de vencimientos.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX decision_recommendations_scope_idx ON decision_recommendations (tenant_id, kind, customer_id, product_id, horizon_days)');
        DB::statement('CREATE INDEX decision_recommendations_group_idx ON decision_recommendations (tenant_id, kind, as_of, horizon_days)');
        DB::statement('CREATE INDEX decision_recommendations_work_idx ON decision_recommendations (tenant_id, status, kind, score DESC NULLS LAST)');
        DB::statement("CREATE INDEX decision_recommendations_expiry_idx ON decision_recommendations (tenant_id, expires_at) WHERE status IN ('suggested', 'assigned', 'reviewed', 'approved')");
    }

    public function down(): void
    {
        foreach (['expiry', 'work', 'group', 'scope'] as $name) {
            DB::statement("DROP INDEX IF EXISTS decision_recommendations_{$name}_idx");
        }
    }
};
