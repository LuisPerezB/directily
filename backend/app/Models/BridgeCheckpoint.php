<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabla `bridge_checkpoints`: marca de agua confirmada y cursor opaco de la extracción en curso, por dispositivo y entidad. Solo el
 * servidor los avanza, y solo tras recibir (y registrar en Bronze) el lote al que corresponden.
 */
class BridgeCheckpoint extends Model
{
    use BelongsToTenant;

    public const CREATED_AT = null;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'watermark' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(BridgeDevice::class, 'bridge_device_id');
    }
}
