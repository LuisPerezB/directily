<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * V2.5 · W4-A · política explícita para los modelos LEGADOS (ADR-V25-07), «legado aprobado por migración».
 *
 * Los modelos que ya existen cuando se activa la aprobación separada (los que el pipeline propio entrenó y promovió, en estado
 * `production`, `validated` o `retired`, sin `published_*` ni `approved_by`) NO dejan de servirse ni de poder revertirse: son el
 * comportamiento vigente de la demo, del rollback y de los E2E. En vez de dejarlos sin historia, esta migración escribe en
 * `model_registry_events` UNA fila de línea base por modelo (de nulo al estado actual, actor `system`) con la marca
 * `evidence.legacy_policy = "legacy_approved_by_migration"`. Reglas (App\Services\Models\ModelServingPolicy):
 *  - Un modelo publicado (CLI / almacén de objetos: cualquier `published_*`) NUNCA es legado y exige aprobación.
 *  - Candidatos, rechazados y fallidos no se tocan (no se sirven).
 *  - Idempotente: solo modelos de empresa sin ningún evento. Los modelos que el motor registre DESPUÉS de esta migración sin pasar por
 *    el servicio se tratan igual (política dinámica) y reciben su línea base al primer cambio de estado.
 * Reversible: `down()` borra solo las filas escritas por esta migración (marca en `evidence.baseline_origin`).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO model_registry_events (tenant_id, model_registry_id, from_status, to_status, actor_user_id, actor_kind, reason, evidence, created_at)
            SELECT m.tenant_id, m.id, NULL, m.status, NULL, 'system',
                   'Línea base: modelo anterior a la aprobación separada, legado aprobado por migración (se sirve y se revierte como hasta ahora).',
                   jsonb_build_object('legacy_policy', 'legacy_approved_by_migration', 'serving_basis', 'legacy_pipeline', 'baseline_origin', 'migration_2026_03_12_000300'),
                   now()
            FROM model_registry m
            WHERE m.tenant_id IS NOT NULL
              AND m.status IN ('production', 'validated', 'retired')
              AND m.approved_by IS NULL AND m.approved_at IS NULL
              AND m.published_by IS NULL AND m.published_at IS NULL AND m.published_object_key IS NULL AND m.published_env IS NULL
              AND NOT EXISTS (SELECT 1 FROM model_registry_events e WHERE e.model_registry_id = m.id)
        SQL);
    }

    public function down(): void
    {
        DB::statement("DELETE FROM model_registry_events WHERE evidence->>'baseline_origin' = 'migration_2026_03_12_000300'");
    }
};
