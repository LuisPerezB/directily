<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabla `external_indicator_observations`. Ver docs/03-modelo-datos.md.
 * Generado a partir del esquema; responsable: backend-core.
 */
class ExternalIndicatorObservation extends Model
{
    public const UPDATED_AT = null;

    /** Campos protegidos contra asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'period_date' => 'date:Y-m-d',
            'value' => 'float',
            'published_at' => 'datetime',
            'ingested_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function indicator(): BelongsTo
    {
        return $this->belongsTo(ExternalIndicator::class, 'indicator_id');
    }
}
