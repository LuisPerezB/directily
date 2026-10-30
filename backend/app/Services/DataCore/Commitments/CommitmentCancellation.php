<?php

declare(strict_types=1);

namespace App\Services\DataCore\Commitments;

use App\Exceptions\DomainStateException;
use App\Models\CommercialCommitment;
use App\Services\Audit\AuditLogger;
use App\Support\Privacy\CustomerIdentity;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Cancelación de un compromiso (pedido/contrato/cotización) comunicada por API: agrega una REVISIÓN con la fecha de
 * cancelación y la fecha desde la que Predictily lo conoce. No toca las líneas ni las cantidades cumplidas.
 */
final class CommitmentCancellation
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function cancel(CommercialCommitment $commitment, ?CarbonInterface $cancelledAt, ?CarbonInterface $knownAt): CommercialCommitment
    {
        return DB::transaction(function () use ($commitment, $cancelledAt, $knownAt): CommercialCommitment {
            $locked = CommercialCommitment::query()->lockForUpdate()->with('items')->findOrFail($commitment->id);
            if ($locked->status === 'cancelled') {
                throw new DomainStateException('El compromiso ya está cancelado.', 'already_cancelled');
            }
            if ($locked->status === 'fulfilled') {
                throw new DomainStateException('Un compromiso cumplido no puede cancelarse.', 'invalid_state', ['status' => $locked->status]);
            }
            $at = $cancelledAt ?? now();
            $known = $knownAt ?? now();
            $locked->forceFill([
                'status' => 'cancelled', 'cancelled_at' => $at, 'known_at' => $known,
                'source_hash' => hash('sha256', $locked->source_hash.'|cancel|'.$at->toIso8601String()),
            ])->save();

            $skus = DB::table('products')->where('tenant_id', $locked->tenant_id)->whereIn('id', $locked->items->pluck('product_id'))->pluck('sku', 'id');
            $customerCode = $locked->customer_id !== null ? DB::table('customers')->where('tenant_id', $locked->tenant_id)->where('id', $locked->customer_id)->value('external_code') : null;
            $revision = (int) DB::table('commercial_commitment_revisions')->where('tenant_id', $locked->tenant_id)->where('commitment_id', $locked->id)->max('revision') + 1;
            DB::table('commercial_commitment_revisions')->insert([
                'tenant_id' => $locked->tenant_id, 'commitment_id' => $locked->id, 'import_job_id' => null, 'revision' => $revision, 'status' => 'cancelled',
                'known_at' => $known->utc()->format('Y-m-d H:i:sP'), 'cancelled_at' => $at->utc()->format('Y-m-d H:i:sP'), 'source_hash' => $locked->source_hash,
                'recorded_at' => now()->format('Y-m-d H:i:sP'),
                'snapshot' => json_encode([
                    'header' => [
                        'commitment_type' => $locked->commitment_type, 'external_id' => $locked->external_id, 'status' => 'cancelled',
                        'issued_at' => $locked->issued_at->utc()->format('Y-m-d H:i:sP'), 'known_at' => $known->utc()->format('Y-m-d H:i:sP'),
                        'cancelled_at' => $at->utc()->format('Y-m-d H:i:sP'), 'currency' => $locked->currency,
                        'customer_code' => $customerCode !== null ? CustomerIdentity::fromCode((string) $customerCode)['external_code'] : null,
                        'source' => 'api_cancel',
                    ],
                    'lines' => $locked->items->map(fn ($i) => [
                        'line_number' => $i->line_number, 'product_sku' => $skus[$i->product_id] ?? null, 'quantity' => (float) $i->quantity,
                        'quantity_fulfilled' => (float) $i->quantity_fulfilled, 'unit' => $i->unit, 'unit_price' => $i->unit_price,
                        'delivery_date' => $i->delivery_date?->toDateString(), 'source_quantity' => $i->source_quantity, 'source_unit' => $i->source_unit,
                        'conversion_factor' => $i->conversion_factor,
                    ])->all(),
                ], JSON_UNESCAPED_UNICODE),
            ]);
            $this->audit->log('commitment.cancelled', $locked, ['commitment_type' => $locked->commitment_type, 'external_id' => $locked->external_id]);

            return $locked->refresh();
        });
    }
}
