<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Facades\DB;

/**
 * Disponibilidad persistida de un producto a la fecha de corte (V2.5 · W5-B), sumada sobre sucursales con foto de inventario vigente.
 *
 * - Disponible = existencias − reservado (nunca negativo por sucursal); el tránsito se informa APARTE y no se cuenta como disponible
 *   (§10: separado de pedidos confirmados y compras en tránsito).
 * - Se usa la última foto de cada sucursal con fecha ≤ corte y no más vieja que `inventory_max_age_days`. Sin foto vigente, la disponibilidad es
 *   DESCONOCIDA (`status = unknown`), nunca cero. Lee lo ya cargado; no recalcula nada.
 */
#[Scoped]
final class InventoryAvailabilityLoader
{
    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    public function flush(): void
    {
        $this->cache = [];
    }

    /** @param list<int> $productIds */
    public function prime(Tenant $tenant, DecisionSettings $settings, CarbonImmutable $asOf, array $productIds): void
    {
        $missing = array_values(array_filter(array_unique($productIds), fn (int $id) => ! isset($this->cache[$this->key($tenant, $asOf, $id)])));
        foreach (array_chunk($missing, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $oldest = $asOf->subDays($settings->inventoryMaxAgeDays())->toDateString();
            $rows = DB::select(
                "SELECT s.product_id, COUNT(*) AS branches, SUM(s.on_hand) AS on_hand, SUM(s.reserved) AS reserved, SUM(s.in_transit) AS in_transit,
                        SUM(GREATEST(s.on_hand - s.reserved, 0)) AS available, MIN(s.snapshot_date) AS oldest, MAX(s.snapshot_date) AS newest
                 FROM (
                    SELECT DISTINCT ON (branch_id, product_id) branch_id, product_id, snapshot_date, on_hand, reserved, in_transit
                    FROM inventory_snapshots
                    WHERE tenant_id = ? AND product_id IN ({$in}) AND snapshot_date <= ?::date AND snapshot_date >= ?::date
                    ORDER BY branch_id, product_id, snapshot_date DESC
                 ) s
                 GROUP BY s.product_id",
                [$tenant->getKey(), ...$chunk, $asOf->toDateString(), $oldest],
            );
            $byProduct = collect($rows)->mapWithKeys(fn ($r) => [(int) $r->product_id => $r]);
            foreach ($chunk as $productId) {
                $r = $byProduct->get($productId);
                $this->cache[$this->key($tenant, $asOf, $productId)] = $r === null
                    ? ['status' => 'unknown', 'reason' => 'no_recent_inventory_snapshot', 'max_age_days' => $settings->inventoryMaxAgeDays(), 'source' => 'inventory_snapshots']
                    : [
                        'status' => 'known', 'source' => 'inventory_snapshots', 'branches_counted' => (int) $r->branches,
                        'on_hand' => (float) $r->on_hand, 'reserved' => (float) $r->reserved, 'available' => (float) $r->available,
                        'in_transit' => (float) $r->in_transit, 'in_transit_counted_as_available' => false,
                        'snapshot_from' => (string) $r->oldest, 'snapshot_to' => (string) $r->newest,
                    ];
            }
        }
    }

    /** @return array<string, mixed> */
    public function forProduct(Tenant $tenant, DecisionSettings $settings, CarbonImmutable $asOf, int $productId): array
    {
        $this->prime($tenant, $settings, $asOf, [$productId]);

        return $this->cache[$this->key($tenant, $asOf, $productId)];
    }

    private function key(Tenant $tenant, CarbonImmutable $asOf, int $productId): string
    {
        return $tenant->getKey().'|'.$asOf->toDateString().'|'.$productId;
    }
}
