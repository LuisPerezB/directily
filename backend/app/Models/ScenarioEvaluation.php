<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabla `scenario_evaluations`. Evaluación de escenarios: estrategias comparadas sobre la misma base, con entradas clasificadas.
 * Etapa 2 · P4 (docs/architecture/v2/P4-decisiones.md).
 */
class ScenarioEvaluation extends Model
{
    use BelongsToTenant;

    protected $table = 'scenario_evaluations';

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['request' => 'array', 'results' => 'array'];
    }

    public function coreRun(): BelongsTo
    {
        return $this->belongsTo(CoreRun::class, 'core_run_id');
    }
}
