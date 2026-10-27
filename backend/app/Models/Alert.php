<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabla `alerts`. Ver docs/03-modelo-datos.md.
 * Generado a partir del esquema; responsable: backend-analytics.
 */
class Alert extends Model
{
    use BelongsToTenant;

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'acknowledged_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public const STATUS_OPEN = 'open';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public const STATUS_RESOLVED = 'resolved';

    /**
     * Crea una alerta abierta salvo que ya exista otra abierta con el mismo dedup_key.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function raise(array $attributes): self
    {
        $dedupKey = $attributes['dedup_key'] ?? null;
        if ($dedupKey !== null) {
            $existing = static::query()
                ->where('dedup_key', $dedupKey)
                ->where('status', self::STATUS_OPEN)
                ->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        return static::query()->create($attributes + ['status' => self::STATUS_OPEN, 'payload' => []]);
    }
}
