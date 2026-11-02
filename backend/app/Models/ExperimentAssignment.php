<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabla `experiment_assignments` (V2.5 · W5, ADR-V25-14): asignación tratamiento/control registrada AL GENERAR la recomendación (con
 * semilla y política) para el análisis por intención de tratamiento. Una por unidad y experimento.
 */
class ExperimentAssignment extends Model
{
    use BelongsToTenant;

    public const CREATED_AT = 'assigned_at';

    public const UPDATED_AT = null;

    protected $table = 'experiment_assignments';

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'policy' => 'array',
            'assigned_at' => 'datetime',
        ];
    }
}
