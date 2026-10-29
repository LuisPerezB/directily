<?php

declare(strict_types=1);

namespace App\Services\Promotions;

use Illuminate\Support\Facades\DB;

/**
 * Registra versiones de una promoción en `promotion_revisions` (solo inserción).
 *
 * La versión copia la fila tal como quedó guardada en `promotions` (incluidos valores por
 * defecto de la BD), de modo que la historia refleja exactamente lo que vio el motor. Debe
 * llamarse en la misma transacción que la escritura: el bloqueo de fila de esa escritura
 * serializa la numeración de revisiones de la promoción.
 */
final class PromotionRevisionRecorder
{
    /** Campos cuya modificación crea una revisión (lo que el motor necesita para reconstruirla). */
    public const TRACKED = ['name', 'promo_type', 'discount_pct', 'starts_on', 'ends_on', 'branch_id', 'product_id', 'category_id', 'known_at'];

    public function record(int $tenantId, int $promotionId, string $source = 'api'): void
    {
        DB::insert(<<<'SQL'
            INSERT INTO promotion_revisions (tenant_id, promotion_id, revision, name, promo_type, discount_pct, starts_on,
                ends_on, branch_id, product_id, category_id, known_at, deleted, source, recorded_at)
            SELECT p.tenant_id, p.id,
                   COALESCE((SELECT MAX(r.revision) FROM promotion_revisions r
                             WHERE r.tenant_id = p.tenant_id AND r.promotion_id = p.id), 0) + 1,
                   p.name, p.promo_type, p.discount_pct, p.starts_on, p.ends_on, p.branch_id, p.product_id,
                   p.category_id, p.known_at, p.deleted_at IS NOT NULL, ?, ?
            FROM promotions p
            WHERE p.tenant_id = ? AND p.id = ?
            SQL, [$source, now(), $tenantId, $promotionId]);
    }
}
