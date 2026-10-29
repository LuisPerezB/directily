<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Intención comercial (cotización), compromiso (pedido/contrato) y su cumplimiento. No es una venta realizada.
 * Identidad: (tenant, commitment_type, external_id). `known_at` NULL = la fuente no declaró cuándo conocía esta versión.
 */
class CommercialCommitment extends Model
{
    use BelongsToTenant;

    public const TYPES = ['quote', 'order', 'contract'];

    public const STATUSES = ['open', 'confirmed', 'partially_fulfilled', 'fulfilled', 'cancelled', 'expired'];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'known_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'valid_from' => 'date:Y-m-d',
            'valid_to' => 'date:Y-m-d',
            'expected_delivery_date' => 'date:Y-m-d',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(CommercialCommitmentItem::class, 'commitment_id')->orderBy('line_number');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(CommercialCommitmentRevision::class, 'commitment_id')->orderBy('revision');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}
