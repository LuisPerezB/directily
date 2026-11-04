<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabla `bridge_devices` (ADR-V25-10): un agente Predictily Bridge registrado, ligado a UNA fuente de datos de UNA empresa. El token del
 * dispositivo se guarda solo como SHA-256 (`token_hash`, oculto); la huella es el SHA-256 de su clave pública Ed25519 (la clave privada
 * nunca sale del dispositivo). Revocar (`revoked_at`) tiene efecto inmediato: cada petición vuelve a leer la fila.
 */
class BridgeDevice extends Model
{
    use BelongsToTenant;

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'registered_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class, 'data_source_id');
    }

    public function checkpoints(): HasMany
    {
        return $this->hasMany(BridgeCheckpoint::class, 'bridge_device_id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /** active | offline | revoked (informativo: «offline» = sin latido reciente). */
    public function status(): string
    {
        if ($this->isRevoked()) {
            return 'revoked';
        }
        $threshold = (int) config('bridge.offline_after_seconds', 900);

        return $this->last_seen_at !== null && $this->last_seen_at->diffInSeconds(now(), true) <= $threshold ? 'active' : 'offline';
    }
}
