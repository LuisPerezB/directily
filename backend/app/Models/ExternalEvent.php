<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenantOrPublic;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evento o alerta externo con procedencia (V2.1 §24.6; ADR-V25-25). Estados PROPOSED → VERIFIED | REJECTED y EXPIRED al vencer su vigencia.
 * Sin `published_at` el evento no puede verificarse para uso predictivo (INVALID como feature). `tenant_id` NULL = evento público (lectura).
 */
class ExternalEvent extends Model
{
    use BelongsToTenantOrPublic;

    public const STATUS_PROPOSED = 'PROPOSED';

    public const STATUS_VERIFIED = 'VERIFIED';

    public const STATUS_REJECTED = 'REJECTED';

    public const STATUS_EXPIRED = 'EXPIRED';

    public const STATUSES = [self::STATUS_PROPOSED, self::STATUS_VERIFIED, self::STATUS_REJECTED, self::STATUS_EXPIRED];

    public const CORROBORATIONS = ['single_source', 'corroborated', 'official'];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'available_at' => 'datetime',
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'extraction' => 'array',
            'affects' => 'array',
            'is_informative_alert' => 'boolean',
            'verified_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    /** Vigencia vencida: `effective_to` anterior al día local indicado. */
    public function isPastValidity(CarbonInterface $today): bool
    {
        return $this->effective_to !== null && $this->effective_to->toDateString() < $today->toDateString();
    }
}
