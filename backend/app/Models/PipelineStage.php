<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabla `pipeline_stages` (ADR-V26-01 · F1): qué pasó con cada etapa del ciclo diario de una empresa en un día de negocio.
 * La escribe App\Services\Pipeline\DailyPipeline; una fila por (empresa, día, etapa).
 */
class PipelineStage extends Model
{
    use BelongsToTenant;

    public const CUSTOMER_PREDICT = 'customer_predict';

    public const RADAR = 'radar';

    public const DECISIONS = 'decisions';

    public const OUTCOMES = 'outcomes';

    public const WAITING = 'waiting';

    public const RUNNING = 'running';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const BLOCKED = 'blocked';

    public const SKIPPED = 'skipped';

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'business_date' => 'date:Y-m-d',
            'detail' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
