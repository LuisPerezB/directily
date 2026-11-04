<?php

declare(strict_types=1);

namespace App\Services\Radar;

use App\Models\CoreRun;
use App\Models\Customer;
use App\Models\DecisionRecommendation;
use App\Models\Product;
use App\Services\Auth\Commercial\CommercialScopeContext;
use App\Services\Customer\CustomerRunService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Lado CONSUMIDOR del Revenue Radar y la Agenda Comercial (V2.5 · W5-A; ADR-V25-12; V25-AGD-001 a -009): lista, resume y agrupa las oportunidades que el
 * Decision Engine ya materializó en `decision_recommendations` (kind contact, recovery y customer_risk). Solo lee, a través del modelo (aislado por empresa);
 * no recalcula probabilidades, cantidades ni valores: lo que muestra es lo que se propuso (con la predicción de origen que lo respalda).
 *
 * Qué ve cada lista:
 * - Las oportunidades del grupo de CONTROL de un experimento no aparecen nunca (se reservan para medición y no se asignan a vendedores). Cuántas se retienen solo
 *   lo ve quien puede aprobar y nunca con un filtro de entidad (un conteo por cliente o producto delataría su pertenencia al grupo de control).
 * - A lo sumo UNA oportunidad accionable por alcance (tipo, cliente, producto, horizonte): si por un dato inconsistente hubiera dos, la lista de trabajo muestra la que está
 *   en curso (con seguimiento o trabajo humano) y, si ninguna lo está, la más reciente. El Decision Engine ya garantiza esto al proponer; aquí es una defensa.
 * - Lista de trabajo por defecto (sin `state` ni `status`): vigentes (ni vencidas, ni medidas, ni cerradas, ni rechazadas) y sin estado final de agenda
 *   (ni pedido recibido ni descartado). Con `state` se ve exactamente ese estado heredado (NULL = pendiente); con `status` exactamente ese estado de workflow.
 * - La agenda de una fecha agrega: ya vigente a esa fecha, sin vencer, y las pospuestas solo reaparecen desde su `snoozed_until`.
 *
 * Alcance comercial (V2.5 · W6-A; ADR-V25-15; antes TODO(W6)): TODA consulta de esta clase pasa por `base()`/`find()`, que aplican el `CommercialScope` de la persona de la petición
 * (lo fija `ResolveTenant`). Un vendedor ve solo las oportunidades asignadas a él y las SIN asignar de clientes de su cartera (clientes, zonas o segmentos de
 * `tenant_users.commercial_scope`), y la lista queda vacía sin cartera ni asignaciones; un gerente o servicio con alcance ve su cartera y a su equipo; sin rol comercial (owner/admin/
 * analyst/viewer) se ve toda la empresa. El resumen, los conteos por estado, el valor potencial y la agenda se calculan sobre lo VISIBLE (un vendedor nunca recibe totales que
 * incluyan clientes ajenos). `seller_id` sigue siendo solo un filtro: se compone con el alcance (no lo amplía). Los comandos programados no tienen persona y operan sobre toda la empresa.
 */
final class PredictiveSalesAgenda
{
    public function __construct(
        private readonly OpportunityPresenter $presenter,
        private readonly CustomerRunService $runs,
        private readonly TenantContext $context,
        private readonly CommercialScopeContext $commercial,
    ) {}

    // ── Consultas base ────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /** Filtros de entidad comunes a todas las listas (sin reglas de estado ni de control). */
    private function base(RadarFilters $f): Builder
    {
        $q = DecisionRecommendation::query()->whereIn('kind', $f->kind !== null ? [$f->kind] : RadarPolicy::KINDS);
        if ($f->customerId !== null) {
            $q->where('customer_id', $f->customerId);
        }
        if ($f->productId !== null) {
            $q->where('product_id', $f->productId);
        }
        if ($f->horizon !== null) {
            $q->where('horizon_days', $f->horizon);
        }
        if ($f->sellerId !== null) {
            $q->where('assigned_user_id', $f->sellerId);
        }
        if ($f->asOf !== null) {
            $q->where('as_of', $f->asOf);
        }
        if ($f->segmentId !== null || $f->zone !== null) {
            $q->whereIn('customer_id', Customer::query()
                ->when($f->segmentId !== null, fn (Builder $c) => $c->where('segment_id', $f->segmentId))
                ->when($f->zone !== null, fn (Builder $c) => $c->where('commercial_zone', $f->zone))
                ->select('id'));
        }
        if ($f->categoryId !== null) {
            $q->whereIn('product_id', Product::query()->where('category_id', $f->categoryId)->select('id'));
        }
        if ($f->modelSegment !== null) {
            $tenantId = (int) $this->context->require()->getKey();
            $q->whereExists(function ($s) use ($f, $tenantId): void {
                $s->selectRaw('1')->from('customer_predictions as cp')->where('cp.tenant_id', $tenantId)
                    ->whereColumn('cp.core_run_id', 'decision_recommendations.source_core_run_id')
                    ->whereColumn('cp.customer_id', 'decision_recommendations.customer_id')
                    ->whereColumn('cp.product_id', 'decision_recommendations.product_id')
                    ->whereColumn('cp.horizon_days', 'decision_recommendations.horizon_days')
                    ->where('cp.segment', $f->modelSegment);
            });
        }
        // W6-A: alcance comercial de la persona (cartera y asignaciones). Sin restricción no hace nada.
        $this->commercial->current()->restrictRecommendations($q);

        return $q;
    }

    private function withoutControl(Builder $q): Builder
    {
        return $q->where(fn (Builder $w) => $w->whereNull('experiment_group')->orWhere('experiment_group', '<>', 'control'));
    }

    /** Estado heredado efectivo (NULL = pendiente). */
    private function whereState(Builder $q, string $state): void
    {
        $state === AgendaStatusMap::PENDIENTE
            ? $q->where(fn (Builder $w) => $w->whereNull('agenda_status')->orWhere('agenda_status', AgendaStatusMap::PENDIENTE))
            : $q->where('agenda_status', $state);
    }

    /** Lista de trabajo: control fuera + reglas de estado descritas en la clase. */
    public function worklist(RadarFilters $f): Builder
    {
        $q = $this->withoutControl($this->base($f));
        if ($f->status !== null) {
            $q->where('status', $f->status);
        } else {
            $q->whereNotIn('status', AgendaStatusMap::TERMINAL_WORKFLOW_STATUSES);
        }
        if ($f->state !== null) {
            $this->whereState($q, $f->state);
        } elseif ($f->status === null) {
            $q->where('status', '<>', 'rejected')
                ->where(fn (Builder $w) => $w->whereNull('agenda_status')->orWhereNotIn('agenda_status', AgendaStatusMap::CLOSED_STATES));
            $this->onePerScope($q);   // solo en la lista por defecto: con un `state` o `status` explícito se ve exactamente lo pedido
        }

        return $q;
    }

    /**
     * Defensa: a lo sumo una oportunidad accionable por alcance. Se oculta la fila si existe otra VIGENTE del mismo alcance (tipo, entidad, horizonte, cliente, producto,
     * sucursal) que esté en curso y ella no, o que, estando ambas en el mismo punto, sea más reciente (id mayor).
     */
    private function onePerScope(Builder $q): void
    {
        // COALESCE: sin seguimiento (`agenda_status` NULL) «en curso» es FALSO, no desconocido; con NULL, `NOT en_curso` sería NULL y la defensa nunca ocultaría nada.
        $flight = fn (string $t): string => "({$t}.status IN ('assigned','reviewed','approved','in_progress') OR COALESCE({$t}.agenda_status IN ('contactado','interesado','pospuesto'), false))";
        $live = "'".implode("','", DecisionRecommendation::LIVE_STATUSES)."'";
        $q->whereNotExists(function ($s) use ($flight, $live): void {
            $s->selectRaw('1')->from('decision_recommendations as o')
                ->whereColumn('o.tenant_id', 'decision_recommendations.tenant_id')->whereColumn('o.kind', 'decision_recommendations.kind')
                ->whereColumn('o.entity_type', 'decision_recommendations.entity_type')->whereColumn('o.horizon_days', 'decision_recommendations.horizon_days')
                ->whereRaw('o.customer_id IS NOT DISTINCT FROM decision_recommendations.customer_id')
                ->whereRaw('o.product_id IS NOT DISTINCT FROM decision_recommendations.product_id')
                ->whereRaw('o.branch_id IS NOT DISTINCT FROM decision_recommendations.branch_id')
                ->whereRaw("o.status IN ({$live}) AND o.experiment_group IS DISTINCT FROM 'control' AND (o.agenda_status IS NULL OR o.agenda_status NOT IN ('pedido_recibido','descartado'))")
                ->whereRaw('o.id <> decision_recommendations.id')
                ->whereRaw('(('.$flight('o').' AND NOT '.$flight('decision_recommendations').') OR ('.$flight('o').' = '.$flight('decision_recommendations').' AND o.id > decision_recommendations.id))');
        });
    }

    /** Igual que la lista de trabajo pero sin restringir el estado heredado (para el desglose por estado del resumen). */
    private function allStates(RadarFilters $f): Builder
    {
        $q = $this->withoutControl($this->base($f->withoutState()));

        return $f->status !== null ? $q->where('status', $f->status) : $q->whereNotIn('status', AgendaStatusMap::TERMINAL_WORKFLOW_STATUSES);
    }

    private function order(Builder $q, RadarFilters $f): Builder
    {
        $column = RadarFilters::ORDER[$f->orderBy ?? 'score'] ?? 'score';
        $direction = ($f->direction ?? ($column === 'priority' ? 'asc' : 'desc')) === 'asc' ? 'asc' : 'desc';
        $q->orderByRaw("{$column} {$direction} NULLS LAST");
        if ($column !== 'score') {
            $q->orderByRaw('score DESC NULLS LAST');
        }
        if ($column !== 'expected_gross_value') {
            $q->orderByRaw('expected_gross_value DESC NULLS LAST');
        }

        return $q->orderBy('id');
    }

    // ── Oportunidades ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /** Página de oportunidades presentadas (orden determinista: el pedido, luego puntaje, valor bruto e id). */
    public function opportunities(RadarFilters $f, int $perPage, int $page): LengthAwarePaginator
    {
        $paginator = $this->order($this->worklist($f), $f)->paginate($perPage, ['*'], 'page', $page);
        $paginator->setCollection(collect($this->presenter->present(collect($paginator->items()), false, $f->privileged)));

        return $paginator;
    }

    /** Detalle con explicación, supuestos, restricciones e historial completo de seguimiento. Las del grupo de control no son visibles (404). */
    public function detail(int $id, bool $privileged = false): ?array
    {
        $rec = $this->find($id);

        return $rec === null ? null : $this->presenter->present(collect([$rec]), true, $privileged)[0];
    }

    public function find(int $id): ?DecisionRecommendation
    {
        $q = $this->withoutControl(DecisionRecommendation::query()->whereIn('kind', RadarPolicy::KINDS));
        $this->commercial->current()->restrictRecommendations($q);   // W6-A: una oportunidad fuera del alcance no existe para esta persona (404)

        return $q->find($id);
    }

    // ── Resumen ───────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Conteos y valor potencial POR HORIZONTE (cada horizonte es una vista solapada de la misma relación: nada se suma entre horizontes). Siempre rotulado «oportunidad
     * potencial, atribución no demostrada».
     *
     * - `value` suma SOLO el valor pronosticado de las oportunidades a nivel de relación cliente × producto (unidades esperadas × último precio neto). El valor de las de
     *   nivel de cliente (recuperación y riesgo: caída OBSERVADA del gasto neto) va aparte, en `customer_level.observed_spend_decline`, con su propia base: es otra cosa
     *   (observada, no pronosticada) y NUNCA se suma al valor pronosticado.
     * - `by_state` e `inventory` también son por horizonte; el total general cuenta relaciones DISTINTAS (tipo, cliente, producto), no filas por horizonte.
     * - `control_withheld` (cuántas se retienen en control) solo se informa a quien puede aprobar y nunca con un filtro de entidad.
     *
     * @return array<string, mixed>
     */
    public function summary(RadarFilters $f): array
    {
        $tenant = $this->context->require();
        $currency = $tenant->currency ?: 'USD';
        $work = fn (): \Illuminate\Database\Query\Builder => $this->worklist($f)->toBase()->reorder();
        $pair = "CASE WHEN entity_type = '".OpportunityPresenter::PAIR_ENTITY."' THEN 1 ELSE 0 END";
        $rows = $work()
            ->selectRaw("horizon_days, kind, ({$pair}) AS pair, COUNT(*) AS n, SUM(expected_gross_value) AS gross, COUNT(expected_gross_value) AS gross_n, "
                .'SUM(expected_contribution) AS contribution, COUNT(expected_contribution) AS contribution_n')
            ->groupBy('horizon_days', 'kind')->groupByRaw($pair)->orderBy('horizon_days')->orderBy('kind')->get();
        $customers = $work()->selectRaw('horizon_days, COUNT(DISTINCT customer_id) AS customers')->groupBy('horizon_days')->pluck('customers', 'horizon_days');
        $customersCustomerLevel = $work()->where('entity_type', '<>', OpportunityPresenter::PAIR_ENTITY)->selectRaw('horizon_days, COUNT(DISTINCT customer_id) AS customers')
            ->groupBy('horizon_days')->pluck('customers', 'horizon_days');

        $blank = fn (): array => ['opportunities' => 0, 'by_kind' => [], 'gross' => 0.0, 'gross_n' => 0, 'contribution' => 0.0, 'contribution_n' => 0, 'pair_n' => 0, 'cl_n' => 0, 'cl_value' => 0.0, 'cl_value_n' => 0];
        $byHorizon = [];
        foreach ($rows as $r) {
            $b = $byHorizon[(string) $r->horizon_days] ??= $blank();
            $b['opportunities'] += (int) $r->n;
            $b['by_kind'][$r->kind] = ($b['by_kind'][$r->kind] ?? 0) + (int) $r->n;
            if ((int) $r->pair === 1) {
                $b['pair_n'] += (int) $r->n;
                $b['gross'] += (float) $r->gross;
                $b['gross_n'] += (int) $r->gross_n;
                $b['contribution'] += (float) $r->contribution;
                $b['contribution_n'] += (int) $r->contribution_n;
            } else {
                $b['cl_n'] += (int) $r->n;
                $b['cl_value'] += (float) $r->gross;
                $b['cl_value_n'] += (int) $r->gross_n;
            }
            $byHorizon[(string) $r->horizon_days] = $b;
        }

        $states = [];
        $this->allStates($f)->toBase()->reorder()->selectRaw("horizon_days, COALESCE(agenda_status, 'pendiente') AS state, COUNT(*) AS n")
            ->groupBy('horizon_days')->groupBy(DB::raw("COALESCE(agenda_status, 'pendiente')"))->get()
            ->each(function ($r) use (&$states): void {
                $states[(string) $r->horizon_days][$r->state] = (int) $r->n;
            });
        // Disponibilidad al corte según lo que registró el Decision Engine: `below_expected` = el motor limitó el valor a lo disponible (stock_below_expected_units / out_of_stock).
        $inventory = [];
        $work()->selectRaw("horizon_days, CASE WHEN product_id IS NULL THEN 'not_applicable' "
            ."WHEN availability->>'status' = 'known' AND jsonb_path_exists(constraints, '\$[*] ? (@.code == \"stock_below_expected_units\" || @.code == \"out_of_stock\")') THEN 'below_expected' "
            ."WHEN availability->>'status' = 'known' THEN 'known' ELSE 'unknown' END AS inv, COUNT(*) AS n")
            ->groupBy('horizon_days')->groupBy(DB::raw('2'))->get()->each(function ($r) use (&$inventory): void {
                $inventory[(string) $r->horizon_days][$r->inv] = (int) $r->n;
            });

        $horizons = [];
        foreach ($byHorizon as $h => $b) {
            $horizons[$h] = [
                'opportunities' => $b['opportunities'],
                'customers' => (int) ($customers[(int) $h] ?? 0),
                'by_kind' => (object) $b['by_kind'],
                'by_state' => array_replace(array_fill_keys(AgendaStatusMap::STATES, 0), $states[$h] ?? []),
                'inventory' => array_replace(['known' => 0, 'unknown' => 0, 'below_expected' => 0, 'not_applicable' => 0], $inventory[$h] ?? []),
                'value' => [
                    'currency' => $currency,
                    'scope' => 'customer_product',
                    'opportunities' => $b['pair_n'],
                    'attribution' => ['label' => 'potential_unproven', 'text' => RadarPolicy::ATTRIBUTION_TEXT],
                    'gross_value' => $b['gross_n'] > 0 ? round($b['gross'], 2) : null,
                    'gross_value_opportunities' => $b['gross_n'],
                    'contribution' => $b['contribution_n'] > 0 ? round($b['contribution'], 2) : null,
                    'contribution_opportunities' => $b['contribution_n'],
                    'contribution_unavailable' => $b['pair_n'] - $b['contribution_n'],
                    'basis_code' => 'forecast_units_x_last_net_price',
                    'basis' => 'pronóstico de las relaciones cliente × producto (unidades esperadas × último precio neto); contribución solo con costo confiable; no es venta confirmada ni causada por el contacto',
                ],
                'customer_level' => [
                    'opportunities' => $b['cl_n'],
                    'customers' => (int) ($customersCustomerLevel[(int) $h] ?? 0),
                    'currency' => $currency,
                    'observed_spend_decline' => $b['cl_value_n'] > 0 ? round($b['cl_value'], 2) : null,
                    'observed_spend_decline_opportunities' => $b['cl_value_n'],
                    'basis_code' => 'observed_spend_decline',
                    'basis' => 'importe OBSERVADO (caída del gasto neto del cliente entre dos ventanas de comportamiento); no es un pronóstico ni una contribución y no se suma a «value»',
                ],
            ];
        }
        ksort($horizons, SORT_NUMERIC);

        $totals = $work()->selectRaw('COUNT(DISTINCT (kind, customer_id, COALESCE(product_id, 0))) AS n, COUNT(DISTINCT customer_id) AS customers, MAX(as_of) AS as_of, MAX(source_core_run_id) AS run_id')->first();
        $data = [
            'as_of' => $totals?->as_of !== null ? substr((string) $totals->as_of, 0, 10) : null,
            'totals' => ['opportunities' => (int) ($totals->n ?? 0), 'customers' => (int) ($totals->customers ?? 0), 'unit' => 'relaciones distintas (tipo, cliente, producto) sin repetir por horizonte'],
            'by_horizon' => (object) $horizons,
            'label' => RadarPolicy::ATTRIBUTION_TEXT,
            'notes' => [
                'Una misma relación cliente × producto puede estar en varios horizontes; por eso el valor, los estados y el inventario se informan por horizonte y nada se suma entre ellos.',
                'El valor pronosticado («value») es solo el de las relaciones cliente × producto; la caída observada del gasto de recuperación y riesgo a nivel de cliente va aparte («customer_level») y no se suma.',
            ],
            'run_id' => $totals?->run_id !== null ? (int) $totals->run_id : null,
        ];
        if ($f->privileged && ! $f->hasEntityFilter()) {
            $data['control_withheld'] = $this->base($f->withoutState())->where('experiment_group', 'control')
                ->when($f->status !== null, fn (Builder $q) => $q->where('status', $f->status), fn (Builder $q) => $q->whereNotIn('status', AgendaStatusMap::TERMINAL_WORKFLOW_STATUSES))->count();
            $data['notes'][] = 'Las oportunidades del grupo de control de un experimento no se listan ni se asignan: «control_withheld» solo se informa a quien puede aprobar y sin filtros de entidad.';
        }

        return $data;
    }

    // ── Agenda ────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Agenda de una fecha agrupada por CLIENTE (un cliente no se duplica por SKU). Pagina por cliente.
     *
     * @return array{paginator: LengthAwarePaginator, groups: list<array<string, mixed>>, date: string}
     */
    public function agenda(RadarFilters $f, ?string $date, int $perPage, int $page): array
    {
        $tenant = $this->context->require();
        $day = $date ?? CarbonImmutable::now($tenant->timezone ?: 'America/Guayaquil')->toDateString();
        // El inicio del día es el de la empresa, expresado en la zona de la aplicación (la base compara en ella): sin esto una oportunidad vencida al terminar el día local
        // anterior aparecería en la agenda (o una vigente desaparecería) según el desfase de la zona.
        $start = CarbonImmutable::parse($day, $tenant->timezone ?: 'America/Guayaquil')->startOfDay()->setTimezone((string) config('app.timezone', 'UTC'));
        $q = $this->worklist($f)
            ->where('as_of', '<=', $day)
            ->where(fn (Builder $w) => $w->whereNull('expires_at')->orWhere('expires_at', '>=', $start));
        if ($f->state === null) {
            // Las pospuestas reaparecen en su fecha de reactivación (último evento de feedback); con filtro de estado explícito se ven tal cual.
            $q->whereRaw("NOT (agenda_status = 'pospuesto' AND COALESCE((SELECT e.payload->>'snoozed_until' FROM decision_events e WHERE e.tenant_id = decision_recommendations.tenant_id "
                ."AND e.recommendation_id = decision_recommendations.id AND e.event = 'feedback' ORDER BY e.id DESC LIMIT 1), '0001-01-01') > ?)", [$day]);
        }

        $paginator = (clone $q)->toBase()->reorder()
            ->selectRaw('customer_id, MAX(score) AS best_score, MIN(priority) AS top_priority, COUNT(*) AS n')
            ->groupBy('customer_id')->orderByRaw('MAX(score) DESC NULLS LAST')->orderBy('customer_id')
            ->paginate($perPage, ['*'], 'page', $page);
        $customerIds = collect($paginator->items())->pluck('customer_id')->map(fn ($id) => (int) $id)->all();

        $byCustomer = collect();
        if ($customerIds !== []) {
            $recs = $this->order((clone $q)->whereIn('customer_id', $customerIds), $f)->get();
            $byCustomer = collect($this->presenter->present($recs, false, $f->privileged))->groupBy(fn (array $o) => $o['customer']['id']);
        }
        $groups = [];
        foreach ($paginator->items() as $g) {
            $opps = $byCustomer->get((int) $g->customer_id, collect())->values();
            $first = $opps->first();
            $groups[] = [
                'customer' => $first['customer'] ?? ['id' => (int) $g->customer_id],
                'opportunities_count' => (int) $g->n,
                'best_score' => $g->best_score !== null ? (float) $g->best_score : null,
                'top_priority' => (int) $g->top_priority,
                'states' => (object) $opps->countBy('state')->all(),
                'next_action' => $first['next_step'] ?? null,
                'opportunities' => $opps->all(),
            ];
        }

        return ['paginator' => $paginator, 'groups' => $groups, 'date' => $day];
    }

    /** Corrida de origen de las oportunidades (para la envoltura de las respuestas): la indicada o la predicción vigente. */
    public function envelopeRun(?int $runId = null): ?CoreRun
    {
        return $runId !== null ? CoreRun::query()->find($runId) : $this->runs->latestPrediction();
    }
}
