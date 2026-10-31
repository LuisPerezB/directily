<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Supply;

use App\Http\Resources\Supply\SupplyPlanLineResource;
use App\Http\Resources\Supply\SupplyPlanShortfallResource;
use App\Http\Resources\Supply\SupplyRunResource;
use App\Models\Branch;
use App\Models\CoreRun;
use App\Models\ForecastRun;
use App\Models\SupplyPlan;
use App\Models\SupplyPlanLine;
use App\Models\SupplyPlanShortfall;
use App\Services\Supply\SupplyRunService;
use App\Support\Results\JsonMap;
use App\Support\Tenancy\TenantRule;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Planes de reposición con restricciones (presupuesto, capacidades, lotes, mínimos, plazos). Asíncronos: crean core_run +
 * job en la cola `forecasting`. Son SUGERENCIAS: no emiten pedidos ni comprometen fondos.
 */
class PlanController extends SupplyController
{
    /** Claves de configuración que una solicitud puede sobrescribir (las demás se rechazan). */
    private const CONFIG_KEYS = [
        'holding_cost_rate_annual' => ['numeric', 'min:0', 'max:5'],
        'shortage_penalty_multiplier' => ['numeric', 'gt:1', 'max:100'],
        'safety_stock_weight' => ['numeric', 'min:0', 'lt:1'],
        'solver_time_limit_seconds' => ['integer', 'min:1', 'max:300'],
        'solver_mip_gap' => ['numeric', 'min:0', 'max:0.2'],
        'use_implicit_offers' => ['boolean'],
        'diagnose_unmet' => ['boolean'],
    ];

    /** Líneas / faltantes que acompaña el detalle del plan como vista previa; el resto se pagina en `/lines` y `/shortfalls`. */
    private const PREVIEW_LIMIT = 20;

    /** Bloques pesados del detalle que solo se devuelven si se piden con `?include=` (lista blanca; separados por comas). */
    private const INCLUDES = ['balance'];

    /** Ordenamientos permitidos de las líneas (lista blanca: nunca se interpola la entrada de la persona). */
    private const LINE_SORTS = [
        'order_date' => 'supply_plan_lines.order_date', 'expected_receipt_date' => 'supply_plan_lines.expected_receipt_date',
        'product' => 'products.name', 'branch' => 'branches.name', 'supplier' => 'suppliers.name',
        'quantity' => 'supply_plan_lines.quantity', 'unit_cost' => 'supply_plan_lines.unit_cost', 'line_cost' => 'supply_plan_lines.line_cost',
    ];

    /** Ordenamientos permitidos de la demanda no atendida. */
    private const SHORTFALL_SORTS = [
        'period_start' => 'supply_plan_shortfalls.period_start', 'product' => 'products.name', 'branch' => 'branches.name',
        'demand' => 'supply_plan_shortfalls.demand', 'unmet' => 'supply_plan_shortfalls.unmet', 'reason' => 'supply_plan_shortfalls.reason',
    ];

    public function store(Request $request, SupplyRunService $service): JsonResponse
    {
        $rules = [
            'objective' => ['sometimes', Rule::in(['min_cost', 'max_margin'])],
            'coverage_mode' => ['sometimes', Rule::in(['soft', 'hard'])],
            'forecast_run_id' => ['nullable', 'integer', TenantRule::exists('forecast_runs')],
            'horizon_days' => ['sometimes', 'integer', 'min:14', 'max:365'],
            'period_days' => ['sometimes', 'integer', Rule::in([1, 7, 14, 30])],
            'undated_receipt_policy' => ['sometimes', Rule::in(['lead_time_end', 'observed_lead_time', 'exclude'])],
            'overdue_receipt_policy' => ['sometimes', Rule::in(['lead_time_end', 'observed_lead_time', 'exclude'])],
            'priorities' => ['sometimes', 'array', 'max:5000'],
            'priorities.*' => ['numeric', 'gt:0', 'max:100'],
            'shortage_penalties' => ['sometimes', 'array', 'max:5000'],
            'shortage_penalties.*' => ['numeric', 'gt:0'],
            'config' => ['sometimes', 'array'],
        ];
        foreach (self::CONFIG_KEYS as $key => $rule) {
            $rules["config.$key"] = array_merge(['sometimes'], $rule);
        }
        $data = $request->validate($rules);
        if (isset($data['forecast_run_id'])) {
            $fr = ForecastRun::query()->findOrFail((int) $data['forecast_run_id']);
            if ($fr->status !== ForecastRun::STATUS_COMPLETED) {
                abort(422, 'El pronóstico indicado no está completado.');
            }
        }
        $unknown = array_diff(array_keys((array) $request->input('config', [])), array_keys(self::CONFIG_KEYS));
        if ($unknown !== []) {
            abort(422, 'Claves de configuración no permitidas: '.implode(', ', $unknown));
        }
        foreach (['priorities', 'shortage_penalties'] as $map) {
            foreach (array_keys((array) ($data[$map] ?? [])) as $productId) {
                if (! is_numeric($productId)) {
                    abort(422, "Las claves de {$map} deben ser identificadores de producto.");
                }
            }
        }
        $options = array_filter($data, fn ($v) => $v !== null);
        $run = $service->request(
            tenant: $this->tenant(),
            user: $request->user(),
            kind: 'plan',
            options: $options,
            triggeredBy: $request->user()?->currentAccessToken() instanceof PersonalAccessToken ? 'api' : 'user',
        );

        return (new SupplyRunResource($run))->response()->setStatusCode(202);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $runs = CoreRun::query()->where('core', 'supply')->where('operation', 'plan')->with('trainingJobs')
            ->when($request->query('status'), fn ($q, $st) => $q->where('status', (string) $st))
            ->latest('id')->paginate($this->perPage($request, 20));

        return SupplyRunResource::collection($runs);
    }

    /**
     * Detalle del plan: envoltura común + cabecera con totales y fecha de corte, restricciones, alternativas e infactibilidad.
     * Las líneas y la demanda no atendida NO se devuelven completas (un plan real tiene miles): `lines` / `shortfalls` son una
     * vista previa de las primeras PREVIEW_LIMIT con su resumen (`lines_summary`, `shortfalls_summary`); el listado completo
     * y paginado está en `GET /supply/plans/{id}/lines` y `/shortfalls`. El `balance` del motor (por nodo y período; cientos de KB en un
     * plan real) tampoco va por defecto: `balance` es `null` = «no solicitado» y solo trae datos con `?include=balance`.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $includes = $this->includes($request);
        $run = $this->planRun($id);
        $envelope = $this->envelope($run, ['result_type' => 'optimized_plan']);
        $run_payload = (new SupplyRunResource($run))->resolve($request);
        $plan = SupplyPlan::query()->where('core_run_id', $run->id)->first();
        if ($plan === null) {
            return response()->json(['data' => [
                'run' => $run_payload, 'plan' => null, 'lines' => [], 'shortfalls' => [], 'branches' => [], 'balance' => in_array('balance', $includes, true) ? [] : null,
                'lines_summary' => $this->summary(0, 0, 0) + ['actionable_now' => 0], 'shortfalls_summary' => $this->summary(0, 0, 0) + ['unmet_units' => 0.0, 'demand_units' => 0.0],
            ], 'envelope' => $envelope]);
        }
        $filtered = $this->linesQuery($plan, [
            'actionable_now' => $request->boolean('actionable_now'),
            'product_id' => $request->filled('product_id') ? (int) $request->input('product_id') : null,
            'supplier_id' => $request->filled('supplier_id') ? (int) $request->input('supplier_id') : null,
        ]);
        $matched = (clone $filtered)->count();
        $lines = $this->sortLines($filtered, null)->limit(self::PREVIEW_LIMIT)->get();
        $linesAgg = $plan->lines()->toBase()->selectRaw('COUNT(*) AS total, COUNT(*) FILTER (WHERE actionable_now) AS actionable_now')->first();
        $shortfallAgg = $plan->shortfalls()->toBase()->selectRaw('COUNT(*) AS total, COALESCE(SUM(unmet), 0) AS unmet, COALESCE(SUM(demand), 0) AS demand')->first();
        $shortfalls = $this->sortShortfalls($this->shortfallsQuery($plan, []), null)->limit(self::PREVIEW_LIMIT)->get();
        $details = (array) $plan->details;

        return response()->json([
            'data' => [
                'run' => $run_payload,
                'plan' => [
                    'id' => $plan->id, 'objective' => $plan->objective, 'coverage_mode' => $plan->coverage_mode,
                    'solver_status' => $plan->solver_status, 'mip_gap' => $plan->mip_gap, 'objective_value' => $plan->objective_value,
                    'solve_seconds' => $plan->solve_seconds, 'currency' => $plan->currency,
                    'total_purchase_cost' => $plan->total_purchase_cost, 'total_holding_cost' => $plan->total_holding_cost,
                    'total_shortage_penalty' => $plan->total_shortage_penalty, 'unmet_units_total' => $plan->unmet_units_total,
                    'demand_units_total' => $plan->demand_units_total, 'forecast_run_id' => $plan->forecast_run_id,
                    'is_optimal' => $plan->solver_status === 'optimal',
                    'note' => 'Sugerencias de reposición: Predictily no envía pedidos ni compromete fondos.',
                ] + $this->cutoff($run),
                'lines' => SupplyPlanLineResource::collection($lines)->resolve($request),
                'lines_summary' => $this->summary((int) $linesAgg->total, $lines->count(), $matched) + ['actionable_now' => (int) $linesAgg->actionable_now],
                'shortfalls' => SupplyPlanShortfallResource::collection($shortfalls)->resolve($request),
                'shortfalls_summary' => $this->summary((int) $shortfallAgg->total, $shortfalls->count(), (int) $shortfallAgg->total)
                    + ['unmet_units' => (float) $shortfallAgg->unmet, 'demand_units' => (float) $shortfallAgg->demand],
                'branches' => $this->planBranches($plan),
                'constraints' => $details['constraints'] ?? [],
                // Mapas del motor (objeto JSON aunque estén vacíos); null = el bloque no aplica a este plan.
                'solver' => JsonMap::of($details['solver'] ?? null),
                'diagnosis' => JsonMap::of($details['diagnosis'] ?? null),
                'infeasibility' => JsonMap::of($details['infeasibility'] ?? null),
                'alternative_plan' => JsonMap::of($details['alternative_plan'] ?? null),
                'offers' => $details['offers'] ?? [],
                'comparison_coverage' => JsonMap::of($details['report']['comparison_coverage'] ?? null),
                'offer_diagnostics' => $details['report']['offer_diagnostics'] ?? [],
                'excluded_nodes' => $details['report']['excluded_nodes'] ?? [],
                'production' => JsonMap::of($details['report']['production'] ?? ['status' => 'not_configured']),
                'receipts_summary' => JsonMap::of($details['report']['receipts_summary'] ?? null),
                // null = no solicitado (por defecto); con `?include=balance`, la lista por nodo y período del motor ([] si el plan no la trae).
                'balance' => in_array('balance', $includes, true) ? ($details['balance'] ?? []) : null,
                'periods' => $details['periods'] ?? [],
            ],
            'envelope' => $envelope,
        ]);
    }

    /**
     * Líneas del plan, paginadas en el servidor: `?page&per_page&branch_id&product_id&supplier_id&search&actionable_now&sort`.
     * Orden por lista blanca (`order_date`, `expected_receipt_date`, `product`, `branch`, `supplier`, `quantity`, `unit_cost`,
     * `line_cost`; prefijo `-` para descendente); sin `sort`, primero lo emitible en la fecha de corte y luego por fecha de emisión.
     */
    public function lines(Request $request, int $id): AnonymousResourceCollection
    {
        $run = $this->planRun($id);
        $sorts = $this->sortRule(self::LINE_SORTS);
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'branch_id' => ['sometimes', 'nullable', 'integer'], 'product_id' => ['sometimes', 'nullable', 'integer'], 'supplier_id' => ['sometimes', 'nullable', 'integer'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'], 'sort' => ['sometimes', 'nullable', $sorts],
        ]);
        $filters['actionable_now'] = $request->boolean('actionable_now');
        $plan = SupplyPlan::query()->where('core_run_id', $run->id)->first();
        $query = $plan === null ? SupplyPlanLine::query()->whereRaw('1 = 0') : $this->linesQuery($plan, $filters);

        return SupplyPlanLineResource::collection($this->sortLines($query, $filters['sort'] ?? null)->paginate($this->perPage($request)));
    }

    /**
     * Demanda no atendida del plan, paginada en el servidor: `?page&per_page&branch_id&product_id&search&reason&sort`.
     * Orden por lista blanca (`period_start`, `product`, `branch`, `demand`, `unmet`, `reason`; prefijo `-` para descendente).
     */
    public function shortfalls(Request $request, int $id): AnonymousResourceCollection
    {
        $run = $this->planRun($id);
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'branch_id' => ['sometimes', 'nullable', 'integer'], 'product_id' => ['sometimes', 'nullable', 'integer'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'], 'reason' => ['sometimes', 'nullable', 'string', 'max:40'],
            'sort' => ['sometimes', 'nullable', $this->sortRule(self::SHORTFALL_SORTS)],
        ]);
        $plan = SupplyPlan::query()->where('core_run_id', $run->id)->first();
        $query = $plan === null ? SupplyPlanShortfall::query()->whereRaw('1 = 0') : $this->shortfallsQuery($plan, $filters);

        return SupplyPlanShortfallResource::collection($this->sortShortfalls($query, $filters['sort'] ?? null)->paginate($this->perPage($request)));
    }

    /**
     * Valores de `?include=` (separados por comas, sin repetir). Cualquier valor fuera de la lista blanca `INCLUDES` responde 422.
     *
     * @return list<string>
     */
    private function includes(Request $request): array
    {
        $request->validate(['include' => ['sometimes', 'nullable', 'string', 'max:100']]);
        $values = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $request->query('include', ''))), fn (string $v) => $v !== '')));
        $unknown = array_values(array_diff($values, self::INCLUDES));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['include' => 'Valores de include no permitidos: '.implode(', ', $unknown).'. Permitidos: '.implode(', ', self::INCLUDES).'.']);
        }

        return $values;
    }

    /** Corrida de plan de la empresa activa (404 si no existe o es de otra empresa). */
    private function planRun(int $id): CoreRun
    {
        return CoreRun::query()->where('core', 'supply')->where('operation', 'plan')->with('trainingJobs')->findOrFail($id);
    }

    /**
     * Fecha de corte del plan (`as_of`) frente a HOY en la zona horaria de la empresa. `actionable_now` de una línea significa
     * «su fecha de emisión es la fecha de corte»; solo equivale a «emitir hoy» cuando `as_of_age_days` es 0.
     *
     * @return array{as_of: ?string, today: string, timezone: string, as_of_age_days: ?int}
     */
    private function cutoff(CoreRun $run): array
    {
        $timezone = $this->tenant()->timezone ?: 'America/Guayaquil';
        $today = CarbonImmutable::now($timezone)->toDateString();
        $asOf = $run->as_of?->toDateString();
        $age = $asOf === null ? null : -((int) (new DateTimeImmutable($today))->diff(new DateTimeImmutable($asOf))->format('%r%a'));

        return ['as_of' => $asOf, 'today' => $today, 'timezone' => $timezone, 'as_of_age_days' => $age];
    }

    /** @return array{total: int, shown: int, matched: int, truncated: bool} */
    private function summary(int $total, int $shown, int $matched): array
    {
        return ['total' => $total, 'shown' => $shown, 'matched' => $matched, 'truncated' => $matched > $shown];
    }

    /** Sucursales con líneas o faltantes en el plan (para el filtro de la tabla). */
    private function planBranches(SupplyPlan $plan): array
    {
        $ids = $plan->lines()->toBase()->distinct()->pluck('supply_plan_lines.branch_id')
            ->merge($plan->shortfalls()->toBase()->distinct()->pluck('supply_plan_shortfalls.branch_id'))->unique()->values()->all();

        return Branch::query()->withTrashed()->whereIn('id', $ids)->orderBy('name')->get(['id', 'code', 'name'])
            ->map(fn (Branch $b) => ['id' => $b->id, 'code' => $b->code, 'name' => $b->name])->values()->all();
    }

    /** @param  array<string, string>  $sorts */
    private function sortRule(array $sorts): In
    {
        $keys = array_keys($sorts);

        return Rule::in(array_merge($keys, array_map(fn (string $k) => '-'.$k, $keys)));
    }

    /** @param  array<string, mixed>  $f */
    private function linesQuery(SupplyPlan $plan, array $f): Builder
    {
        return SupplyPlanLine::query()->select('supply_plan_lines.*')
            ->leftJoin('products', fn ($j) => $j->on('products.id', '=', 'supply_plan_lines.product_id')->on('products.tenant_id', '=', 'supply_plan_lines.tenant_id'))
            ->leftJoin('branches', fn ($j) => $j->on('branches.id', '=', 'supply_plan_lines.branch_id')->on('branches.tenant_id', '=', 'supply_plan_lines.tenant_id'))
            ->leftJoin('suppliers', fn ($j) => $j->on('suppliers.id', '=', 'supply_plan_lines.supplier_id')->on('suppliers.tenant_id', '=', 'supply_plan_lines.tenant_id'))
            ->where('supply_plan_lines.supply_plan_id', $plan->id)
            ->with(['product:id,sku,name', 'branch:id,code,name', 'supplier:id,code,name'])
            ->when(! empty($f['actionable_now']), fn ($q) => $q->where('supply_plan_lines.actionable_now', true))
            ->when(! empty($f['branch_id']), fn ($q) => $q->where('supply_plan_lines.branch_id', (int) $f['branch_id']))
            ->when(! empty($f['product_id']), fn ($q) => $q->where('supply_plan_lines.product_id', (int) $f['product_id']))
            ->when(! empty($f['supplier_id']), fn ($q) => $q->where('supply_plan_lines.supplier_id', (int) $f['supplier_id']))
            ->when(! empty($f['search']), fn ($q) => $this->searchProduct($q, (string) $f['search']));
    }

    /** @param  array<string, mixed>  $f */
    private function shortfallsQuery(SupplyPlan $plan, array $f): Builder
    {
        return SupplyPlanShortfall::query()->select('supply_plan_shortfalls.*')
            ->leftJoin('products', fn ($j) => $j->on('products.id', '=', 'supply_plan_shortfalls.product_id')->on('products.tenant_id', '=', 'supply_plan_shortfalls.tenant_id'))
            ->leftJoin('branches', fn ($j) => $j->on('branches.id', '=', 'supply_plan_shortfalls.branch_id')->on('branches.tenant_id', '=', 'supply_plan_shortfalls.tenant_id'))
            ->where('supply_plan_shortfalls.supply_plan_id', $plan->id)
            ->with(['product:id,sku,name', 'branch:id,code,name'])
            ->when(! empty($f['branch_id']), fn ($q) => $q->where('supply_plan_shortfalls.branch_id', (int) $f['branch_id']))
            ->when(! empty($f['product_id']), fn ($q) => $q->where('supply_plan_shortfalls.product_id', (int) $f['product_id']))
            ->when(! empty($f['reason']), fn ($q) => $q->where('supply_plan_shortfalls.reason', (string) $f['reason']))
            ->when(! empty($f['search']), fn ($q) => $this->searchProduct($q, (string) $f['search']));
    }

    /** Búsqueda por SKU o nombre de producto (sin distinguir mayúsculas; los comodines del texto se escapan). */
    private function searchProduct(Builder $query, string $search): Builder
    {
        $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($search)).'%';

        return $query->where(fn ($q) => $q->where('products.name', 'ilike', $term)->orWhere('products.sku', 'ilike', $term));
    }

    private function sortLines(Builder $query, ?string $sort): Builder
    {
        return $this->applySort($query, self::LINE_SORTS, $sort, 'supply_plan_lines')
            ?? $query->orderByDesc('supply_plan_lines.actionable_now')->orderBy('supply_plan_lines.order_date')->orderBy('supply_plan_lines.id');
    }

    private function sortShortfalls(Builder $query, ?string $sort): Builder
    {
        return $this->applySort($query, self::SHORTFALL_SORTS, $sort, 'supply_plan_shortfalls')
            ?? $query->orderBy('supply_plan_shortfalls.period_start')->orderBy('supply_plan_shortfalls.product_id')->orderBy('supply_plan_shortfalls.id');
    }

    /**
     * Ordena por una clave de la lista blanca (`clave` o `-clave`); NULL siempre al final. Devuelve null si no hay orden pedido.
     *
     * @param  array<string, string>  $sorts
     */
    private function applySort(Builder $query, array $sorts, ?string $sort, string $table): ?Builder
    {
        if ($sort === null || $sort === '') {
            return null;
        }
        $desc = str_starts_with($sort, '-');
        $column = $sorts[ltrim($sort, '-')] ?? null;
        if ($column === null) {
            return null;
        }

        return $query->orderByRaw($column.($desc ? ' DESC' : ' ASC').' NULLS LAST')->orderBy($table.'.id');
    }
}
