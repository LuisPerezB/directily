<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabla `decision_recommendations` (V2.5 · W5, ADR-V25-13, §11): recomendación del Decision Engine con el contrato completo de §11.
 * Distinta de `recommendations` (etapa 1: reposición por serie), que se conserva. Contrato del arquitecto (activación de W5): los
 * paquetes agregan relaciones, scopes y lógica en sus servicios, no cambian la tabla ni el significado de los casts. Las transiciones
 * de `status` y `agenda_status` solo las hace el workflow de W5-B (cada una con su `decision_events`).
 */
class DecisionRecommendation extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['suggested', 'assigned', 'reviewed', 'approved', 'rejected', 'in_progress', 'executed', 'measured', 'closed', 'expired'];

    public const AGENDA_STATUSES = ['pendiente', 'contactado', 'interesado', 'pedido_recibido', 'pospuesto', 'descartado'];

    public const ATTRIBUTION_LABELS = ['potential_unproven', 'incremental_estimated', 'causally_attributable'];

    // >>> W5-B decision engine (constantes, relaciones y scopes; no cambian la tabla ni los casts)
    /** Tipos de recomendación del Decision Engine (§11). `scenario` solo entra por RecommendationSink::propose(). */
    public const KINDS = ['contact', 'recovery', 'customer_risk', 'replenishment', 'inventory_mismatch', 'budget_constraint', 'scenario'];

    public const ENTITY_TYPES = ['customer_product', 'customer', 'product_branch', 'product'];

    /** Estados en los que la recomendación aún no tiene una acción en curso: pueden vencer o ser reemplazadas por una más nueva. */
    public const PRE_ACTION_STATUSES = ['suggested', 'assigned', 'reviewed', 'approved'];

    /** Estados finales: ya no admiten transiciones. */
    public const TERMINAL_STATUSES = ['rejected', 'closed', 'expired'];

    /** Tipos que participan en el experimento de contacto (la unidad es el cliente). */
    public const EXPERIMENT_KINDS = ['contact', 'recovery'];

    /**
     * «En curso» (decisión del arquitecto, V25-AGD / ADR-V25-13): hay trabajo humano sobre la oportunidad. Lo es cuando el seguimiento de la agenda está en
     * contactado, interesado o pospuesto, o cuando el workflow está en assigned, reviewed, approved o in_progress (y no terminó). Mientras un alcance tiene una
     * en curso, el Decision Engine no crea una sucesora ni la expira: la refresca con un comentario de linaje y su `expires_at` manda.
     */
    public const IN_FLIGHT_AGENDA = ['contactado', 'interesado', 'pospuesto'];

    public const IN_FLIGHT_STATUSES = ['assigned', 'reviewed', 'approved', 'in_progress'];

    /** Estados del workflow desde los que una recomendación todavía puede estar en curso (los demás son finales o ya ejecutados). */
    public const LIVE_STATUSES = ['suggested', 'assigned', 'reviewed', 'approved', 'in_progress'];

    protected $table = 'decision_recommendations';

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'as_of' => 'date:Y-m-d',
            'horizon_days' => 'integer',
            'model_versions' => 'array',
            'probability' => 'float',
            'interval' => 'array',
            'assumptions' => 'array',
            'constraints' => 'array',
            'availability' => 'array',
            'action_cost' => 'float',
            'expected_gross_value' => 'float',
            'expected_contribution' => 'float',
            'incremental_estimate' => 'float',
            'incremental_evidence' => 'array',
            'score' => 'float',
            'uncertainty' => 'array',
            'explanation' => 'array',
            'priority' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(DecisionEvent::class, 'recommendation_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id')->withTrashed();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id')->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /** Recomendaciones que pueden entregarse a una persona: las del grupo de control quedan para medición y nunca se asignan (ADR-V25-14). */
    public function scopeActionable(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('experiment_group')->orWhere('experiment_group', '<>', 'control'));
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', self::TERMINAL_STATUSES);
    }

    /** Recomendaciones en curso (ver {@see self::IN_FLIGHT_AGENDA}). */
    public function scopeInFlight(Builder $query): Builder
    {
        return $query->whereIn('status', self::LIVE_STATUSES)
            ->where(fn (Builder $q) => $q->whereIn('status', self::IN_FLIGHT_STATUSES)->orWhereIn('agenda_status', self::IN_FLIGHT_AGENDA));
    }
    // <<< W5-B decision engine
}
