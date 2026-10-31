<?php

declare(strict_types=1);

namespace App\Services\DataCore\Commitments;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Estado de los compromisos comerciales a un CORTE (E2-23). Regla (la misma que debe usar cualquier consumidor):
 *  1. Versiones visibles = revisiones con `effective_known_at ≤ corte`, donde `effective_known_at = COALESCE(known_at, recorded_at)`:
 *     la ingestión en Predictily (`recorded_at`, el momento en que se registró esa revisión) es una COTA SUPERIOR del conocimiento.
 *     Con `known_at` declarado manda éste (`known_basis = declared`); sin él, la ingestión (`known_basis = ingestion`). Así un histórico
 *     importado hoy sin `known_at` NO se usa en cortes pasados, pero sí cuenta en el corte de hoy. Si no hay ninguna versión visible,
 *     el compromiso NO existe a ese corte.
 *  2. Se toma la última versión visible (cabecera, líneas y cantidades cumplidas tal como se conocían).
 *  3. Cancelado al corte si `cancelled_at ≤ corte`. Estado cancelado SIN fecha ⇒ `cancellation_date_unknown`: pendiente 0.
 *  4. Pendiente por línea = max(0, cantidad − cumplida); 0 si está cancelado.
 *  5. Un compromiso no es una venta ni se suma a la demanda probabilística: es una vista separada con su estado.
 */
final class CommitmentAsOf
{
    /**
     * @param  array{type?: string, product_id?: int, status?: string}  $filters
     * @return list<array<string, mixed>>
     */
    public function view(int $tenantId, CarbonInterface $asOf, array $filters = [], int $limit = 500): array
    {
        $rows = DB::select(
            'SELECT r.commitment_id, r.revision, r.status, r.known_at, r.recorded_at, COALESCE(r.known_at, r.recorded_at) AS effective_known_at, r.cancelled_at, r.snapshot, c.commitment_type, c.external_id, c.customer_id, c.branch_id, c.data_source_id '
            .'FROM commercial_commitment_revisions r '
            .'JOIN (SELECT commitment_id, MAX(revision) AS revision FROM commercial_commitment_revisions '
            .'      WHERE tenant_id = ? AND COALESCE(known_at, recorded_at) <= ? GROUP BY commitment_id) m '
            .'  ON m.commitment_id = r.commitment_id AND m.revision = r.revision '
            .'JOIN commercial_commitments c ON c.tenant_id = r.tenant_id AND c.id = r.commitment_id '
            .'WHERE r.tenant_id = ? '.(isset($filters['type']) ? 'AND c.commitment_type = ? ' : '').'ORDER BY r.commitment_id LIMIT '.max(1, $limit),
            array_values(array_filter([$tenantId, $asOf->copy()->utc()->format('Y-m-d H:i:sP'), $tenantId, $filters['type'] ?? null], fn ($v) => $v !== null)),
        );

        $result = [];
        foreach ($rows as $row) {
            $snapshot = json_decode((string) $row->snapshot, true) ?: [];
            $cancelledAt = $row->cancelled_at !== null ? CarbonImmutable::parse((string) $row->cancelled_at) : null;
            $flags = [];
            $cancelled = false;
            if ($cancelledAt !== null && $cancelledAt->lessThanOrEqualTo($asOf)) {
                $cancelled = true;
            } elseif ($row->status === 'cancelled' && $cancelledAt === null) {
                $cancelled = true;
                $flags[] = 'cancellation_date_unknown';
            }
            $lines = [];
            $pendingTotal = 0.0;
            foreach ((array) ($snapshot['lines'] ?? []) as $line) {
                $pending = $cancelled ? 0.0 : max(0.0, (float) $line['quantity'] - (float) $line['quantity_fulfilled']);
                $pendingTotal += $pending;
                $lines[] = [
                    'line_number' => $line['line_number'], 'product_sku' => $line['product_sku'], 'quantity' => (float) $line['quantity'],
                    'quantity_fulfilled' => (float) $line['quantity_fulfilled'], 'pending' => round($pending, 4), 'unit' => $line['unit'] ?? null,
                    'delivery_date' => $line['delivery_date'] ?? null,
                ];
            }
            if (isset($filters['product_id'])) {
                $sku = DB::table('products')->where('tenant_id', $tenantId)->where('id', $filters['product_id'])->value('sku');
                if (! in_array($sku, array_column($lines, 'product_sku'), true)) {
                    continue;
                }
            }
            $status = $cancelled ? 'cancelled' : (string) ($snapshot['header']['status'] ?? $row->status);
            if (isset($filters['status']) && $filters['status'] !== $status) {
                continue;
            }
            $result[] = [
                'commitment_id' => (int) $row->commitment_id, 'commitment_type' => $row->commitment_type, 'external_id' => $row->external_id,
                'customer_id' => $row->customer_id !== null ? (int) $row->customer_id : null, 'branch_id' => $row->branch_id !== null ? (int) $row->branch_id : null,
                'revision' => (int) $row->revision, 'status_at_cutoff' => $status,
                'known_at' => CarbonImmutable::parse((string) $row->effective_known_at)->toIso8601String(),
                'known_basis' => $row->known_at !== null ? 'declared' : 'ingestion',
                'ingested_at' => CarbonImmutable::parse((string) $row->recorded_at)->toIso8601String(),
                'cancelled_at' => $cancelledAt?->toIso8601String(), 'pending_total' => round($pendingTotal, 4), 'flags' => $flags, 'lines' => $lines,
            ];
        }

        return $result;
    }
}
