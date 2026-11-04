<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Decision;

use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Decision\OutcomeIndexRequest;
use App\Http\Requests\Decision\RoiRequest;
use App\Http\Resources\Decision\DecisionOutcomeResource;
use App\Models\DecisionOutcome;
use App\Services\Outcomes\RoiReport;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Outcomes y ROI honestos (V2.5 · W5-C; ADR-V25-14). SOLO LECTURA (`decisions.view`): los resultados los escribe `predictily:outcomes-measure`; esta API no mide, no
 * edita y no mueve el workflow. Aislada por empresa (modelos con global scope; el informe filtra explícitamente por la empresa activa).
 *
 * - GET /decisions/outcomes  lista paginada con filtros (tipo, clasificación, acción, evento, horizonte, experimento, cliente, producto, periodo). Los resultados del grupo
 *   de control no se listan salvo a quien aprueba (`decisions.approve`).
 * - GET /decisions/roi       ROI honesto con su método: embudo, oportunidad potencial vs observación vs incremento estimado, costos, experimentos (ITT) y límites. Periodo por
 *   omisión de 180 días (por el corte `as_of`) y máximo de 730 (422 si se supera). Quien no puede aprobar no recibe información del control ni del experimento.
 */
final class OutcomeController extends Controller
{
    private const SORTS = [
        '-measured_at' => ['measured_at', 'desc'], 'measured_at' => ['measured_at', 'asc'], '-window_to' => ['window_to', 'desc'], 'window_to' => ['window_to', 'asc'],
        '-observed_net_amount' => ['observed_net_amount', 'desc'], '-contribution' => ['contribution', 'desc'], '-id' => ['id', 'desc'], 'id' => ['id', 'asc'],
    ];

    public function __construct(private readonly TenantContext $context, private readonly RoiReport $report) {}

    public function index(OutcomeIndexRequest $request): AnonymousResourceCollection
    {
        $role = $this->role($request);
        $canApprove = $role->can('decisions.approve');
        $lists = $request->lists();
        $query = DecisionOutcome::query()->with(['recommendation' => fn ($q) => $q->with(['customer:id,external_code', 'product:id,sku,name'])]);

        $rec = function (callable $constraint) use ($query): void {
            $query->whereHas('recommendation', fn (Builder $q) => $constraint($q));
        };
        if (! $canApprove) {
            $rec(fn (Builder $q) => $q->actionable());   // el grupo de control no se revela a quien contacta
        }
        if ($request->filled('kind')) {
            $rec(fn (Builder $q) => $q->whereIn('kind', $lists['kinds'] ?: ['__none__']));
        }
        foreach (['horizon_days', 'customer_id', 'product_id', 'assigned_user_id'] as $field) {
            if ($request->filled($field)) {
                $rec(fn (Builder $q) => $q->where($field, (int) $request->query($field)));
            }
        }
        if ($request->filled('from')) {
            $rec(fn (Builder $q) => $q->where('as_of', '>=', (string) $request->query('from')));
        }
        if ($request->filled('to')) {
            $rec(fn (Builder $q) => $q->where('as_of', '<=', (string) $request->query('to')));
        }
        if ($request->filled('experiment_group')) {
            // Filtrar por grupo revelaría quién quedó en cada uno: solo quien aprueba puede; para los demás el filtro no devuelve nada.
            $rec(fn (Builder $q) => $canApprove ? $q->where('experiment_group', (string) $request->query('experiment_group')) : $q->whereRaw('1 = 0'));
        }
        if ($request->filled('classification')) {
            $query->whereIn('classification', $lists['classifications'] ?: ['__none__']);
        }
        if ($request->filled('action_kind')) {
            $query->whereIn('action_kind', $lists['actions'] ?: ['__none__']);
        }
        if ($request->filled('observed_event')) {
            $query->whereIn('observed_event', $lists['events'] ?: ['__none__']);
        }
        if ($request->filled('recommendation_id')) {
            $query->where('recommendation_id', (int) $request->query('recommendation_id'));
        }
        if ($request->filled('experiment_key')) {
            // Filtrar por experimento revelaría quién pertenece a uno: solo quien aprueba puede; para los demás no devuelve nada.
            $canApprove
                ? $query->whereRaw("evidence->'experiment'->>'key' = ?", [(string) $request->query('experiment_key')])
                : $query->whereRaw('1 = 0');
        }
        if ($request->filled('contribution_known')) {
            $request->boolean('contribution_known') ? $query->whereNotNull('contribution') : $query->whereNull('contribution');
        }
        [$column, $direction] = self::SORTS[$request->query('sort', '-measured_at')] ?? ['measured_at', 'desc'];
        $column === 'contribution' || $column === 'observed_net_amount'
            ? $query->orderByRaw("{$column} {$direction} NULLS LAST")
            : $query->orderBy($column, $direction);

        return DecisionOutcomeResource::collection($query->orderBy('id')->paginate(max(1, min(100, (int) $request->integer('per_page', 25)))));
    }

    public function roi(RoiRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->report->build($this->context->require(), $request->filters(), CarbonImmutable::now(), $this->role($request))]);
    }

    private function role(Request $request): TenantRole
    {
        return $request->attributes->get('tenant_role');
    }
}
