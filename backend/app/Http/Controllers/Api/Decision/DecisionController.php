<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Decision;

use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Decision\DecisionIndexRequest;
use App\Http\Requests\Decision\DecisionTransitionRequest;
use App\Http\Resources\Decision\DecisionRecommendationResource;
use App\Models\DecisionRecommendation;
use App\Services\Auth\Commercial\CommercialScopeContext;
use App\Services\Decision\DecisionSettings;
use App\Services\Decision\Workflow;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Recomendaciones del Decision Engine (V2.5 · W5-B; ADR-V25-13). Predictily propone y audita; NO escribe en el ERP ni envía mensajes: «ejecutar» es
 * registrar que una persona hizo la acción fuera del sistema.
 *
 * - GET  /decisions               lista con filtros y paginado (`decisions.view`). Las del grupo de control no se listan (salvo `include_control` de quien aprueba).
 * - GET  /decisions/summary       conteos por estado, tipo y etiqueta de atribución (`decisions.view`).
 * - GET  /decisions/{id}          detalle con contrato completo, línea de tiempo y transiciones disponibles (`decisions.view`).
 * - POST /decisions/{id}/transition   transición con `idempotency_key`; la habilidad concreta y la segregación de funciones las decide el workflow.
 * Una recomendación de otra empresa responde 404.
 */
final class DecisionController extends Controller
{
    private const SORTS = [
        '-score' => ['score', 'desc'], 'score' => ['score', 'asc'], 'priority' => ['priority', 'asc'], '-priority' => ['priority', 'desc'],
        '-created_at' => ['created_at', 'desc'], 'created_at' => ['created_at', 'asc'], 'expires_at' => ['expires_at', 'asc'], '-expires_at' => ['expires_at', 'desc'],
        '-as_of' => ['as_of', 'desc'],
    ];

    public function __construct(private readonly Workflow $workflow, private readonly TenantContext $context, private readonly CommercialScopeContext $commercial) {}

    public function index(DecisionIndexRequest $request): AnonymousResourceCollection
    {
        $this->bindContext($request);
        $role = $this->role($request);
        $query = Workflow::withApprover(Workflow::withVersion(DecisionRecommendation::query()))->with(['customer:id,external_code', 'product:id,sku,name,unit', 'branch:id,code,name']);
        if (! ($role->can('decisions.approve') && $request->boolean('include_control'))) {
            $query->actionable();
        }
        $this->commercial->current()->restrictRecommendations($query);   // W6-A: un vendedor solo ve lo asignado a él y lo sin asignar de su cartera
        $this->filter($query, $request);
        [$column, $direction] = self::SORTS[$request->query('sort', '-score')] ?? ['score', 'desc'];
        $column === 'score' || $column === 'expires_at'
            ? $query->orderByRaw("{$column} {$direction} NULLS LAST")
            : $query->orderBy($column, $direction);

        return DecisionRecommendationResource::collection($query->orderBy('id')->paginate(max(1, min(100, (int) $request->integer('per_page', 25)))));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->bindContext($request);

        return response()->json(['data' => $this->detail($request, $id)]);
    }

    public function transition(DecisionTransitionRequest $request, int $id): JsonResponse
    {
        $this->bindContext($request);
        $result = $this->workflow->transition($id, (string) $request->validated('to_status'), $request->user(), $this->role($request), (string) $request->validated('idempotency_key'), $request->params());

        return response()->json([
            'data' => $this->detail($request, $result->recommendation->id),
            'meta' => ['idempotent_replay' => $result->replayed, 'event_id' => $result->event->id],
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $role = $this->role($request);
        $settings = DecisionSettings::forTenant($this->context->require());
        $base = function (): Builder {
            $query = DecisionRecommendation::query()->actionable();
            $this->commercial->current()->restrictRecommendations($query);   // W6-A: los conteos son de lo visible para esta persona

            return $query;
        };
        $count = fn (string $column) => $base()->selectRaw("{$column} AS k, COUNT(*) AS n")->groupBy($column)->pluck('n', 'k')->map(fn ($n) => (int) $n)->all();
        $now = CarbonImmutable::now();
        $awaiting = $base()->where('status', 'reviewed')->tap(fn (Builder $q) => $this->requiresApprovalFilter($q, $settings, true))->count();

        $data = [
            'by_status' => (object) $count('status'),
            'by_kind' => (object) $count('kind'),
            'by_attribution_label' => (object) $count('attribution_label'),
            'awaiting_assignment' => $base()->where('status', 'suggested')->count(),
            'awaiting_approval' => $awaiting,
            'expiring_within_24h' => $base()->whereIn('status', DecisionRecommendation::PRE_ACTION_STATUSES)->where('expires_at', '<=', $now->addDay())->where('expires_at', '>', $now)->count(),
            'attribution_note' => 'Las recomendaciones son oportunidades potenciales con atribución no demostrada; no son ingresos recuperados.',
        ];
        if ($role->can('decisions.approve')) {
            $control = DecisionRecommendation::query()->where('experiment_group', 'control');
            $this->commercial->current()->restrictRecommendations($control);
            $data['control_group_units'] = $control->count();
        }

        return response()->json(['data' => $data]);
    }

    /** @return array<string, mixed> */
    private function detail(Request $request, int $id): array
    {
        $role = $this->role($request);
        $query = Workflow::withApprover(Workflow::withVersion(DecisionRecommendation::query()))
            ->with(['customer:id,external_code', 'product:id,sku,name,unit', 'branch:id,code,name', 'events' => fn ($q) => $q->orderBy('id')]);
        $this->commercial->current()->restrictRecommendations($query);   // W6-A
        $rec = $query->find($id) ?? throw new NotFoundHttpException('Recomendación no encontrada.');
        // Las de control no se muestran a quien no aprueba: no se revela quién queda fuera del contacto.
        if ($rec->experiment_group === 'control' && ! $role->can('decisions.approve')) {
            throw new NotFoundHttpException('Recomendación no encontrada.');
        }

        return (new DecisionRecommendationResource($rec))->resolve($request);
    }

    private function filter(Builder $query, DecisionIndexRequest $request): void
    {
        if ($request->filled('status')) {
            $query->whereIn('status', $request->csv('status', DecisionRecommendation::STATUSES));   // un valor desconocido no filtra «todo»: no devuelve nada
        }
        if ($request->filled('kind')) {
            $query->whereIn('kind', $request->csv('kind', DecisionRecommendation::KINDS));
        }
        foreach (['priority', 'horizon_days', 'customer_id', 'product_id', 'branch_id', 'assigned_user_id', 'attribution_label'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->query($field));
            }
        }
        if ($request->boolean('mine')) {
            $query->where('assigned_user_id', $request->user()->getKey());
        }
        if ($request->filled('as_of')) {
            $query->whereDate('as_of', (string) $request->query('as_of'));
        }
        if ($request->filled('expiring_before')) {
            $query->whereIn('status', DecisionRecommendation::PRE_ACTION_STATUSES)->where('expires_at', '<=', CarbonImmutable::parse((string) $request->query('expiring_before')));
        }
        if ($request->filled('requires_approval')) {
            $this->requiresApprovalFilter($query, DecisionSettings::forTenant($this->context->require()), $request->boolean('requires_approval'));
        }
    }

    /** Misma regla que Workflow::requiresApproval, en SQL: tipo sensible, restricción «sobre presupuesto» o costo sobre el umbral de la empresa. */
    private function requiresApprovalFilter(Builder $query, DecisionSettings $settings, bool $wanted): void
    {
        $kinds = (array) $settings->all()['approval_required_kinds'];
        $threshold = $settings->approvalCostThreshold();
        $query->where(function (Builder $q) use ($kinds, $threshold, $wanted): void {
            $sensitive = function (Builder $s) use ($kinds, $threshold): void {
                $s->whereIn('kind', $kinds ?: ['__none__'])->orWhereRaw('constraints @> ?::jsonb', ['[{"code":"over_budget"}]']);
                if ($threshold !== null) {
                    $s->orWhereRaw('COALESCE(action_cost >= ?, false)', [$threshold]);
                }
            };
            $wanted ? $q->where($sensitive) : $q->whereNot($sensitive);
        });
    }

    private function role(Request $request): TenantRole
    {
        return $request->attributes->get('tenant_role');
    }

    /** El contexto va en la petición de la aplicación Y en la inyectada: el recurso paginado se serializa después con la primera. */
    private function bindContext(Request $request): void
    {
        $context = [
            'user' => $request->user(), 'role' => $this->role($request), 'settings' => DecisionSettings::forTenant($this->context->require()), 'workflow' => $this->workflow,
        ];
        $request->attributes->set('decision_ctx', $context);
        request()->attributes->set('decision_ctx', $context);
    }
}
