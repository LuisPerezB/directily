<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabla `model_registry_events` (V2.5 · W4, ADR-V25-07): una fila por transición del ciclo de vida de un modelo
 * (de, a, actor, tipo de actor, motivo y evidencia). Solo inserción: una transición registrada no se edita ni se borra.
 */
class ModelRegistryEvent extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const ACTOR_USER = 'user';

    public const ACTOR_SYSTEM = 'system';

    public const ACTOR_CLI = 'cli';

    public const ACTOR_KINDS = [self::ACTOR_USER, self::ACTOR_SYSTEM, self::ACTOR_CLI];

    protected $table = 'model_registry_events';

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(ModelRegistry::class, 'model_registry_id');
    }
}
