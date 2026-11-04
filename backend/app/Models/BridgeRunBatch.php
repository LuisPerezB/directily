<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Tabla `bridge_run_batches`: lote CONFIRMADO en una posición de una extracción (dispositivo, entidad, corrida). La usa el servidor para validar la corrida completa. */
class BridgeRunBatch extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['batch_index' => 'integer', 'row_count' => 'integer', 'created_at' => 'datetime'];
    }
}
