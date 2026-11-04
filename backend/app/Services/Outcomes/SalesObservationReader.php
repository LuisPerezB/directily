<?php

declare(strict_types=1);

namespace App\Services\Outcomes;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Lectura AS-OF de las ventas para medir outcomes (V2.5 · W5-C; decisión del arquitecto; ADR-V25-23): solo lo que Predictily CONOCÍA al cierre del día de corte
 * (`cutoff`, fecha local de la empresa). Es la regla de W1 sobre la vista `sales_line_versions`:
 *
 *   una versión es visible al corte C si  max(sale_date, día_local(known_from)) ≤ C  y  (known_until es NULL o día_local(known_until) > C)
 *
 * Por eso un documento que llegó tarde NO se ve antes de haberse ingerido, una anulación posterior no reescribe lo ya medido y la medición es reproducible.
 * Solo cuentan documentos completados (factura, nota de venta u otro). Las COMPRAS son las de la ventana (`desde … hasta`); las devoluciones (`is_return`) y las notas
 * de crédito se leen hasta el corte de conocimiento —aunque su fecha sea posterior al cierre de la ventana— para restarlas de esas compras.
 * Nunca devuelve datos personales: solo identificadores de venta, línea, cliente (id), producto y sucursal.
 */
final class SalesObservationReader
{
    private const DOCUMENT_TYPES = "('invoice','sales_note','other','credit_note')";

    /**
     * Líneas de los clientes indicados en la ventana, conocidas al corte.
     *
     * @param  list<int>  $customerIds
     * @return array<int, list<object>> líneas por cliente
     */
    public function forCustomers(Tenant $tenant, string $from, string $to, string $cutoff, array $customerIds): array
    {
        $byCustomer = [];
        foreach (array_chunk(array_values(array_unique($customerIds)), 1000) as $chunk) {
            [$sql, $bindings] = $this->base($tenant, $from, $to, $cutoff);
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $rows = DB::select($this->select()." {$sql} AND v.customer_id IN ({$in}) ORDER BY v.customer_id, issued_at, v.sale_id, v.line_number", [...$bindings, ...$chunk]);
            foreach ($rows as $row) {
                $row->is_return = (bool) $row->is_return || $row->document_type === 'credit_note';
                $byCustomer[(int) $row->customer_id][] = $row;
            }
        }

        return $byCustomer;
    }

    /**
     * Resumen SQL de un producto (y sucursal, si la recomendación la fija) en la ventana: unidades, importes, devoluciones, líneas, las posteriores a la acción y
     * la huella de las líneas leídas. Se agrega en la base: un producto popular puede tener miles de líneas.
     *
     * @return array<string, mixed>
     */
    public function productSummary(Tenant $tenant, string $from, string $to, string $cutoff, int $productId, ?int $branchId, ?string $actionAt): array
    {
        [$sql, $bindings] = $this->base($tenant, $from, $to, $cutoff);
        $ret = '(v.is_return OR v.document_type = \'credit_note\')';
        $branch = $branchId === null ? '' : ' AND v.branch_id = ?';
        $row = DB::selectOne(
            "SELECT COALESCE(SUM(v.quantity) FILTER (WHERE NOT {$ret}), 0) AS gross_units,
                    COALESCE(SUM(v.net_amount) FILTER (WHERE NOT {$ret}), 0) AS gross_net,
                    COALESCE(SUM(v.quantity) FILTER (WHERE {$ret}), 0) AS ret_units,
                    COALESCE(SUM(v.net_amount) FILTER (WHERE {$ret}), 0) AS ret_net,
                    COUNT(*) FILTER (WHERE NOT {$ret}) AS purchase_lines,
                    COUNT(*) AS lines,
                    COUNT(*) FILTER (WHERE NOT {$ret} AND ?::timestamptz IS NOT NULL AND COALESCE(s.issued_at, r.issued_at) > ?::timestamptz) AS lines_after_action,
                    encode(sha256(convert_to(COALESCE(string_agg(v.sale_id || ':' || v.revision || ':' || v.line_number || ':' || v.quantity || ':' || v.net_amount || ':' || v.is_return::text,
                        ',' ORDER BY v.sale_id, v.line_number, v.revision), ''), 'UTF8')), 'hex') AS fingerprint
             {$this->fromJoins()} {$sql} AND v.product_id = ?{$branch}",
            [$actionAt, $actionAt, ...$bindings, $productId, ...($branchId === null ? [] : [$branchId])],
        );

        return [
            'gross_units' => (float) $row->gross_units, 'gross_net_amount' => (float) $row->gross_net, 'returned_units' => (float) $row->ret_units,
            'returned_net_amount' => (float) $row->ret_net, 'purchase_lines' => (int) $row->purchase_lines, 'lines' => (int) $row->lines,
            'lines_after_action' => (int) $row->lines_after_action, 'fingerprint' => (string) $row->fingerprint,
        ];
    }

    /**
     * Días de la ventana en que el producto estuvo sin existencias disponibles (existencias − reservado ≤ 0 por sucursal con piso en cero, sumado sobre las sucursales con
     * foto ese día) y la existencia disponible de la última foto de la ventana. Solo fotos cargadas Y no corregidas después del corte. Sin ninguna foto: `snapshot_days = 0`
     * (desconocido, no «sin stock»).
     *
     * @return array{snapshot_days: int, stockout_days: int, last_available: float|null, last_snapshot_date: string|null, knowledge: string}
     */
    public function inventoryWindow(Tenant $tenant, string $from, string $to, string $cutoff, int $productId, ?int $branchId): array
    {
        $zone = $tenant->timezone ?: 'America/Guayaquil';
        $branch = $branchId === null ? '' : ' AND branch_id = ?';
        // Conocimiento: `updated_at` es el último momento en que una carga creó o corrigió la foto (el importador hace upsert y lo actualiza; `created_at` no cambia). Una foto
        // cargada o corregida DESPUÉS del corte se trata como desconocida (el valor original ya no existe): ni se lee el corregido ni se supone el anterior.
        // Disponible = existencias − reservado con piso en cero POR SUCURSAL antes de sumar (como InventoryAvailabilityLoader): un saldo negativo no compensa el stock de otra.
        $rows = DB::select(
            "SELECT snapshot_date, SUM(GREATEST(on_hand - reserved, 0)) AS available
             FROM inventory_snapshots
             WHERE tenant_id = ? AND product_id = ?{$branch} AND snapshot_date BETWEEN ?::date AND ?::date
               AND CAST(updated_at AT TIME ZONE CAST(? AS text) AS date) <= ?::date
             GROUP BY snapshot_date ORDER BY snapshot_date",
            [$tenant->getKey(), $productId, ...($branchId === null ? [] : [$branchId]), $from, $to, $zone, $cutoff],
        );
        $last = $rows === [] ? null : end($rows);

        return [
            'snapshot_days' => count($rows),
            'stockout_days' => count(array_filter($rows, fn ($r) => (float) $r->available <= 0.0)),
            'last_available' => $last === null ? null : (float) $last->available,
            'last_snapshot_date' => $last === null ? null : (string) $last->snapshot_date,
            'knowledge' => 'updated_at <= corte de conocimiento (fotos cargadas o corregidas después se tratan como desconocidas)',
        ];
    }

    private function select(): string
    {
        return 'SELECT v.sale_id, v.revision, v.line_number, v.customer_id, v.product_id, v.branch_id, v.sale_date, v.quantity, v.net_amount, v.is_return, v.document_type,
                COALESCE(s.issued_at, r.issued_at) AS issued_at '.$this->fromJoins();
    }

    private function fromJoins(): string
    {
        return 'FROM sales_line_versions v
                LEFT JOIN sales s ON v.is_current AND s.tenant_id = v.tenant_id AND s.id = v.sale_id
                LEFT JOIN sales_document_revisions r ON NOT v.is_current AND r.tenant_id = v.tenant_id AND r.sale_id = v.sale_id AND r.revision = v.revision';
    }

    /** @return array{0: string, 1: list<mixed>} condiciones comunes (sin cliente/producto) y sus parámetros */
    private function base(Tenant $tenant, string $from, string $to, string $cutoff): array
    {
        $zone = $tenant->timezone ?: 'America/Guayaquil';

        return [
            "WHERE v.tenant_id = ? AND v.status = 'completed' AND v.document_type IN ".self::DOCUMENT_TYPES.'
               AND v.sale_date >= ?::date AND (v.sale_date <= ?::date OR v.is_return OR v.document_type = \'credit_note\')
               AND GREATEST(v.sale_date, CAST(v.known_from AT TIME ZONE CAST(? AS text) AS date)) <= ?::date
               AND (v.known_until IS NULL OR (CAST(v.known_until AT TIME ZONE CAST(? AS text) AS date) - 1) >= ?::date)',
            [$tenant->getKey(), $from, $to, $zone, $cutoff, $zone, $cutoff],
        ];
    }
}
