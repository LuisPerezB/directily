<?php

declare(strict_types=1);

namespace App\Services\Outcomes;

use App\Models\DecisionEvent;
use App\Models\DecisionOutcome;
use App\Models\DecisionRecommendation;
use App\Models\Tenant;
use App\Services\Decision\DecisionSettings;
use App\Services\Decision\UnitEconomicsLoader;
use App\Services\Decision\Workflow;
use App\Services\Forecasting\Support\CoreServices;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Medición de outcomes (V2.5 · W5-C; ADR-V25-14; §12): une recomendación → acción registrada → evento observable posterior, con clasificación honesta.
 *
 * REGLAS (todas probadas en tests/Feature/Outcomes):
 *  1. Ventana FIJA por recomendación: desde = as_of + 1, hasta = as_of + horizonte. Se mide solo cuando la ventana cerró MÁS un margen de espera configurable
 *     (`settling_lag_days`); antes no se escribe nada (la recomendación sigue «prevista»).
 *  2. Lectura AS-OF: se lee lo que Predictily conocía al cierre del día `hasta + margen` (vista `sales_line_versions`, W1). Un documento ingerido después no entra en
 *     esa medición (se cuenta aparte en `source.late_lines_not_counted`); uno anterior al corte sí, aunque su fecha de venta sea más vieja.
 *  3. SE MIDE TODO: ejecutadas, rechazadas, caducadas, no contactadas y el grupo de control (`action_kind` dice qué pasó). Sin los no tratados la comparación se sesga.
 *  4. Clasificación (ver OutcomeVocabulary): `observed` (lo ocurrido), `temporally_associated` (compra POSTERIOR a una acción registrada, sin contrafactual),
 *     `incremental_estimated` (SOLO si el experimento tiene control y su ITT alcanza el tamaño mínimo por grupo, con intervalo). `causally_attributable` NUNCA se
 *     produce aquí. `forecast` no se persiste: es el estado de lo aún no medible.
 *  5. Contribución, no ingresos brutos: la economía unitaria de W5-B (`UnitEconomicsLoader`) al corte; sin costo confiable la contribución es NULL con su motivo.
 *  6. IDEMPOTENTE: una recomendación con resultado no se vuelve a medir (el resultado es inmutable; la huella de las ventas leídas queda en la evidencia). Las filas
 *     de las recomendaciones se bloquean durante la medición de su grupo, de modo que dos ejecuciones simultáneas no duplican.
 *  7. `executed → measured` solo por `Workflow::systemTransition` (nunca un UPDATE directo).
 *
 * No escribe en el ERP, no envía mensajes y no modifica `decision_recommendations` salvo esa transición.
 */
final class OutcomeMeasurementService
{
    /** Espacio de nombres del candado consultivo por empresa (una medición a la vez por empresa, entre procesos). */
    private const LOCK_NAMESPACE = 5301;

    /** Código estable del error de un grupo (la causa queda en el registro de la aplicación, no en la salida). */
    public const ERROR_GROUP_FAILED = 'outcomes_group_failed';

    /** @var array<string, list<array<string, mixed>>> unidades ya contadas por «experimento|horizonte» durante esta ejecución */
    private array $pooled = [];

    public function __construct(
        private readonly TenantContext $context,
        private readonly Workflow $workflow,
        private readonly UnitEconomicsLoader $economics,
        private readonly SalesObservationReader $sales,
        private readonly ObservationCalculator $calculator,
        private readonly ActionResolver $actions,
        private readonly ExperimentResolver $experiments,
    ) {}

    /**
     * Mide las recomendaciones de la empresa que ya se pueden medir y aún no tienen resultado.
     *
     * @return array<string, mixed> informe de la ejecución
     */
    public function measure(Tenant $tenant, ?CarbonImmutable $now = null, bool $dryRun = false, ?int $lagOverride = null): array
    {
        return $this->context->runAs($tenant, function () use ($tenant, $now, $dryRun, $lagOverride): array {
            $settings = OutcomeSettings::forTenant($tenant, $lagOverride);
            $decision = DecisionSettings::forTenant($tenant);
            $today = ($now ?? CarbonImmutable::now())->setTimezone($settings->timezone())->startOfDay();
            $report = [
                'tenant_id' => (int) $tenant->getKey(), 'dry_run' => $dryRun, 'today' => $today->toDateString(), 'settling_lag_days' => $settings->lagDays(),
                'groups' => 0, 'examined' => 0, 'measured' => 0, 'by_classification' => [], 'by_action_kind' => [], 'by_event' => [], 'transitioned_to_measured' => 0,
                'finalized_late_execution' => 0, 'already_measured_concurrently' => 0, 'pending_window_open' => 0, 'pending_settling' => 0, 'experiments' => [], 'errors' => [],
            ];
            $this->pooled = [];
            $this->economics->flush();
            try {
                foreach ($this->eligibleGroups($settings, $today) as $group) {
                    $report['groups']++;
                    try {
                        $this->measureGroup($tenant, $settings, $decision, $today, $group['as_of'], $group['horizon_days'], $dryRun, $report);
                    } catch (Throwable $e) {
                        $this->pooled = [];   // la transacción del grupo se revirtió: lo acumulado en memoria no se persistió
                        // El detalle (puede traer SQL o datos) va al registro de la aplicación; el informe y la consola llevan solo un código estable.
                        Log::error('Outcomes: grupo con error', ['tenant_id' => $tenant->getKey(), 'as_of' => $group['as_of'], 'horizon_days' => $group['horizon_days'], 'exception' => $e::class, 'error' => $e->getMessage()]);
                        $report['errors'][] = ['code' => self::ERROR_GROUP_FAILED, 'as_of' => $group['as_of'], 'horizon_days' => $group['horizon_days']];
                    }
                }
                $report['finalized_late_execution'] = $this->finalizeLateExecutions($dryRun);
                $report = $this->countPending($report, $settings, $today);
            } finally {
                $this->economics->flush();
                $this->pooled = [];
            }
            if (! $dryRun && ($report['measured'] > 0 || $report['finalized_late_execution'] > 0)) {
                CoreServices::audit('outcomes.measured', $tenant, [
                    'measured' => $report['measured'], 'by_classification' => $report['by_classification'], 'by_action_kind' => $report['by_action_kind'],
                    'finalized_late_execution' => $report['finalized_late_execution'], 'settling_lag_days' => $settings->lagDays(), 'method_version' => $settings->methodVersion(),
                ]);
            }

            return $report;
        });
    }

    /** @return list<array{as_of: string, horizon_days: int}> grupos (corte, horizonte) con recomendaciones medibles, del más antiguo al más reciente */
    private function eligibleGroups(OutcomeSettings $settings, CarbonImmutable $today): array
    {
        return DecisionRecommendation::query()->whereNotExists($this->hasOutcome())
            ->whereRaw('(as_of + (horizon_days + ?::int)) < ?::date', [$settings->lagDays(), $today->toDateString()])
            ->selectRaw('as_of, horizon_days')->groupBy('as_of', 'horizon_days')->orderBy('as_of')->orderBy('horizon_days')
            ->toBase()->get()->map(fn ($r) => ['as_of' => substr((string) $r->as_of, 0, 10), 'horizon_days' => (int) $r->horizon_days])->all();
    }

    private function hasOutcome(): Closure
    {
        return fn ($q) => $q->selectRaw('1')->from('decision_outcomes as o')->whereColumn('o.recommendation_id', 'decision_recommendations.id')->whereColumn('o.tenant_id', 'decision_recommendations.tenant_id');
    }

    /** @param array<string, mixed> $report */
    private function measureGroup(Tenant $tenant, OutcomeSettings $settings, DecisionSettings $decision, CarbonImmutable $today, string $asOf, int $horizon, bool $dryRun, array &$report): void
    {
        // Los contadores del grupo se acumulan aparte y se suman al informe solo si el grupo se midió completo (si la transacción se revierte, no cuentan).
        $group = ['examined' => 0, 'measured' => 0, 'by_classification' => [], 'by_action_kind' => [], 'by_event' => [], 'transitioned_to_measured' => 0, 'already_measured_concurrently' => 0, 'experiments' => []];
        $work = function () use ($tenant, $settings, $decision, $today, $asOf, $horizon, $dryRun, &$group): void {
            $window = OutcomeWindow::for($asOf, $horizon, $settings->lagDays());
            if (! $dryRun) {
                // Una medición a la vez por empresa (entre procesos): el análisis acumulado de un experimento depende de lo que midieron los grupos anteriores, y dos
                // procesos a la vez podrían contar dos veces al mismo cliente. El candado se suelta con la transacción. Lo acumulado en memoria se relee de la base.
                DB::select('SELECT pg_advisory_xact_lock(?::int, ?::int)', [self::LOCK_NAMESPACE, (int) $tenant->getKey()]);
                $this->pooled = [];
            }
            $recs = $this->eligible($asOf, $horizon, ! $dryRun);
            if ($recs->isEmpty()) {
                return;
            }
            $group['examined'] += $recs->count();
            $from = $window->from()->toDateString();
            $to = $window->to()->toDateString();
            $cutoff = $window->cutoff();

            // ── Lo que pasó con cada recomendación (auditoría leída con la fila bloqueada) ──
            $events = collect();
            foreach (array_chunk($recs->keys()->all(), 5000) as $chunk) {
                $events = $events->concat(DecisionEvent::query()->whereIn('recommendation_id', $chunk)->orderBy('id')->get());
            }
            $events = $events->groupBy('recommendation_id');
            /** @var array<int, ActionRecord> $actions */
            $windowEnd = CarbonImmutable::parse($to.' 23:59:59.999999', $settings->timezone());
            $actions = $recs->map(fn (DecisionRecommendation $r) => $this->actions->resolve($r, $events->get($r->id, collect()), $windowEnd))->all();

            // ── Ventas conocidas al corte (y, aparte, las que llegaron después) ──
            $customerIds = $recs->filter(fn (DecisionRecommendation $r) => OutcomeVocabulary::isCustomerKind((string) $r->kind) && $r->customer_id !== null)
                ->map(fn (DecisionRecommendation $r) => (int) $r->customer_id)->unique()->values()->all();
            $lines = $customerIds === [] ? [] : $this->sales->forCustomers($tenant, $from, $to, $cutoff->toDateString(), $customerIds);
            $late = $customerIds === [] ? ['new' => [], 'changed' => []] : $this->afterCutoff($tenant, $from, $to, $today, $customerIds, $lines);
            $productIds = $recs->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->all();
            foreach ($lines as $customerLines) {
                foreach ($customerLines as $l) {
                    $productIds[] = (int) $l->product_id;
                }
            }
            $this->economics->prime($tenant, $decision, $cutoff, array_values(array_unique($productIds)));

            // ── Experimento: unidades (clientes) de esta cohorte y análisis acumulado ──
            $exp = $this->experiments->resolve($recs);
            $cohorts = $this->cohorts($tenant, $settings, $decision, $recs, $exp, $actions, $lines, $cutoff, $horizon, $to);
            foreach ($cohorts as $key => $cohort) {
                $group['experiments'][ExperimentResolver::cohortKey((string) $key, $asOf, $horizon)] = ['status' => $cohort['estimate']['status'], 'sample' => $cohort['estimate']['sample']];
            }

            foreach ($recs as $rec) {
                $action = $actions[$rec->id];
                $attributes = OutcomeVocabulary::isCustomerKind((string) $rec->kind)
                    ? $this->customerOutcome($tenant, $decision, $settings, $rec, $action, $window, $lines, $late, $exp[$rec->id] ?? null, $cohorts, $today)
                    : $this->productOutcome($tenant, $decision, $settings, $rec, $action, $window, $today);
                $outcomeId = null;
                if (! $dryRun) {
                    // INSERT … ON CONFLICT DO NOTHING contra el índice único (empresa, recomendación): si otro proceso ya midió esta recomendación no se escribe nada y se cuenta como
                    // «ya medida», sin abortar la transacción del grupo. NO se usa un punto de guardado por fila: cada subtransacción que escribe toma un candado sobre su propio XID y
                    // unos miles en una sola transacción agotan la tabla de candados de PostgreSQL («out of shared memory»; lo encontró la prueba E1 de la revisión con 66 000 filas).
                    if ($this->insertOutcome($tenant, $attributes) === 0) {
                        $group['already_measured_concurrently']++;

                        continue;
                    }
                    $outcomeId = $rec->status === 'executed' ? (int) DB::table('decision_outcomes')->where('tenant_id', $tenant->getKey())->where('recommendation_id', $rec->id)->value('id') : 0;
                }
                $group['measured']++;
                foreach ([['by_classification', $attributes['classification']], ['by_action_kind', $attributes['action_kind']], ['by_event', $attributes['observed_event']]] as [$bucket, $value]) {
                    $group[$bucket][$value] = ($group[$bucket][$value] ?? 0) + 1;
                }
                if ($outcomeId !== null && $rec->status === 'executed') {
                    $moved = $this->workflow->systemTransition((int) $rec->id, 'measured', 'outcome-measurement', 'Resultado medido en la ventana de observación.', [
                        'outcome_id' => $outcomeId, 'classification' => $attributes['classification'], 'window_to' => $to, 'knowledge_cutoff' => $cutoff->toDateString(),
                    ]);
                    $group['transitioned_to_measured'] += $moved === null ? 0 : 1;
                }
            }
        };
        $dryRun ? $work() : DB::transaction($work);

        foreach (['examined', 'measured', 'transitioned_to_measured', 'already_measured_concurrently'] as $counter) {
            $report[$counter] += $group[$counter];
        }
        foreach (['by_classification', 'by_action_kind', 'by_event'] as $bucket) {
            foreach ($group[$bucket] as $name => $n) {
                $report[$bucket][$name] = ($report[$bucket][$name] ?? 0) + $n;
            }
        }
        $report['experiments'] += $group['experiments'];
    }

    /**
     * Escribe el resultado de una recomendación. Devuelve 0 si ya existía (otro proceso ganó la carrera: ON CONFLICT DO NOTHING), 1 si lo escribió.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function insertOutcome(Tenant $tenant, array $attributes): int
    {
        $row = $attributes + ['tenant_id' => (int) $tenant->getKey(), 'measured_at' => CarbonImmutable::now()->toIso8601String()];
        $row['evidence'] = json_encode($attributes['evidence'], JSON_THROW_ON_ERROR);
        $row['action_at'] = $attributes['action_at']?->toIso8601String();

        return DB::table('decision_outcomes')->insertOrIgnore([$row]);
    }

    /**
     * Recomendaciones del grupo sin resultado. En la medición real se bloquean sus filas (en orden de id, para no cruzarse con otro proceso) y DESPUÉS, en otra
     * consulta, se descartan las que otro proceso ya midió: con READ COMMITTED una fila solo bloqueada no se reevalúa, así que la comprobación debe ser posterior al bloqueo.
     *
     * @return Collection<int, DecisionRecommendation> por id
     */
    private function eligible(string $asOf, int $horizon, bool $lock): Collection
    {
        $ids = DecisionRecommendation::query()->where('as_of', $asOf)->where('horizon_days', $horizon)->whereNotExists($this->hasOutcome())->orderBy('id')->pluck('id')->all();
        $recs = collect();
        foreach (array_chunk($ids, 5000) as $chunk) {
            $query = DecisionRecommendation::query()->whereIn('id', $chunk)->orderBy('id');
            $recs = $recs->concat($lock ? $query->lockForUpdate()->get() : $query->get());
        }
        if ($lock && $ids !== []) {
            $done = [];
            foreach (array_chunk($ids, 5000) as $chunk) {
                array_push($done, ...DecisionOutcome::query()->whereIn('recommendation_id', $chunk)->pluck('recommendation_id')->map(fn ($id) => (int) $id)->all());
            }
            $done = array_flip($done);
            $recs = $recs->reject(fn (DecisionRecommendation $r) => isset($done[(int) $r->id]));
        }

        return $recs->keyBy('id');
    }

    /**
     * Lo que cambió DESPUÉS del corte de la medición, solo para informarlo (no reescribe nada): líneas nuevas que el sistema conoce hoy y no se conocían al corte
     * (`new`: documentos y devoluciones que llegaron tarde) y líneas que sí se leyeron pero hoy están revisadas o ya no están (`changed`: una anulación o
     * corrección posterior).
     *
     * @param  list<int>  $customerIds
     * @param  array<int, list<object>>  $known
     * @return array{new: array<int, list<object>>, changed: array<int, list<object>>}
     */
    private function afterCutoff(Tenant $tenant, string $from, string $to, CarbonImmutable $today, array $customerIds, array $known): array
    {
        $knownRevision = [];
        foreach ($known as $customerLines) {
            foreach ($customerLines as $l) {
                $knownRevision[$l->sale_id.':'.$l->line_number] = (int) $l->revision;
            }
        }
        $new = [];
        $changed = [];
        $seenToday = [];
        foreach ($this->sales->forCustomers($tenant, $from, $to, $today->toDateString(), $customerIds) as $customerId => $customerLines) {
            foreach ($customerLines as $l) {
                $key = $l->sale_id.':'.$l->line_number;
                $seenToday[$key] = true;
                if (! isset($knownRevision[$key])) {
                    $new[$customerId][] = $l;
                } elseif ($knownRevision[$key] !== (int) $l->revision) {
                    $changed[$customerId][] = $l;
                }
            }
        }
        foreach ($known as $customerId => $customerLines) {
            foreach ($customerLines as $l) {
                if (! isset($seenToday[$l->sale_id.':'.$l->line_number])) {
                    $changed[$customerId][] = $l;
                }
            }
        }

        return ['new' => $new, 'changed' => $changed];
    }

    // ───────────────────────── experimento (ITT) ─────────────────────────

    /**
     * Forma las unidades (clientes) del experimento en esta cohorte y calcula el análisis ACUMULADO (las ya contadas en cohortes anteriores más las nuevas).
     * Un cliente entra una sola vez por experimento y horizonte: con su primera cohorte medida.
     *
     * @param  Collection<int, DecisionRecommendation>  $recs
     * @param  array<int, array<string, mixed>>  $exp
     * @param  array<int, ActionRecord>  $actions
     * @param  array<int, list<object>>  $lines
     * @return array<string, array{estimate: array<string, mixed>, units: array<int, array<string, mixed>>, repeat: array<int, true>, conflict: array<int, true>}>
     */
    private function cohorts(Tenant $tenant, OutcomeSettings $settings, DecisionSettings $decision, Collection $recs, array $exp, array $actions, array $lines, CarbonImmutable $cutoff, int $horizon, string $windowTo): array
    {
        $byKey = [];
        foreach ($recs as $rec) {
            $e = $exp[$rec->id] ?? null;
            if ($e === null || ! $e['verified']) {
                continue;
            }
            $customerId = (int) $rec->customer_id;
            $u = $byKey[$e['key']][$customerId] ?? ['recs' => [], 'groups' => [], 'products' => [], 'any' => false, 'control_fraction' => $e['control_fraction'], 'contaminated' => false];
            $u['recs'][] = (int) $rec->id;
            $u['groups'][] = $e['group'];
            if ($rec->product_id === null) {
                $u['any'] = true;
            } else {
                $u['products'][(int) $rec->product_id] = (int) $rec->product_id;
            }
            $u['contaminated'] = $u['contaminated'] || ! empty($actions[$rec->id]->details['control_contaminated']);
            $byKey[$e['key']][$customerId] = $u;
        }

        $out = [];
        foreach ($byKey as $key => $units) {
            $poolKey = $key.'|'.$horizon;
            $this->pooled[$poolKey] ??= $this->experiments->countedUnits((int) $tenant->getKey(), (string) $key, $horizon);
            $counted = array_flip(array_map(fn (array $u) => $u['customer_id'], $this->pooled[$poolKey]));
            $new = [];
            $repeat = [];
            $conflict = [];
            ksort($units);
            foreach ($units as $customerId => $u) {
                $customerId = (int) $customerId;
                if (count(array_unique($u['groups'])) > 1) {
                    $conflict[$customerId] = true;

                    continue;
                }
                if (isset($counted[$customerId])) {
                    $repeat[$customerId] = true;

                    continue;
                }
                $scope = $u['any'] ? null : array_values($u['products']);
                $summary = $this->calculator->summarize($lines[$customerId] ?? [], $scope, null, $windowTo);
                $reliable = $scope !== null && array_reduce($scope, fn (bool $all, int $pid) => $all && $this->economics->forProduct($tenant, $decision, $cutoff, $pid)->costReliable, true);
                $contribution = $reliable ? $this->contribution($tenant, $decision, $cutoff, $summary['by_product'])['value'] : null;
                $new[$customerId] = [
                    'customer_id' => $customerId, 'group' => $u['groups'][0], 'converted' => $summary['net_units'] > 0 ? 1 : 0, 'contribution' => $contribution,
                    'in_population' => $reliable, 'control_fraction' => (float) $u['control_fraction'], 'contaminated' => $u['contaminated'],
                    'representative' => min($u['recs']), 'scope_products' => $scope,
                ];
            }
            $pool = array_merge($this->pooled[$poolKey], array_values($new));
            $this->pooled[$poolKey] = $pool;
            $out[(string) $key] = ['estimate' => IttAnalysis::estimate($pool, $settings, (string) $key, $horizon), 'units' => $new, 'repeat' => $repeat, 'conflict' => $conflict];
        }

        return $out;
    }

    // ───────────────────────── resultado de una recomendación con cliente ─────────────────────────

    /**
     * La clasificación de una FILA nunca es `incremental_estimated`: el incremento es una propiedad de la comparación tratamiento-control (el agregado), no de una compra ni de
     * un cliente concreto; estampar «incremental estimado» en cada unidad de tratamiento (compre o no, la hayan contactado o no) sería afirmar lo que solo el agregado sostiene.
     * La fila queda `observed` o `temporally_associated` y enlaza con el análisis por `evidence.experiment` (y `evidence.itt`, solo para quien aprueba).
     *
     * @param  array<int, list<object>>  $lines
     * @param  array{new: array<int, list<object>>, changed: array<int, list<object>>}  $late
     * @param  array<string, mixed>|null  $exp
     * @param  array<string, array<string, mixed>>  $cohorts
     * @return array<string, mixed>
     */
    private function customerOutcome(Tenant $tenant, DecisionSettings $decision, OutcomeSettings $settings, DecisionRecommendation $rec, ActionRecord $action, OutcomeWindow $window, array $lines, array $late, ?array $exp, array $cohorts, CarbonImmutable $today): array
    {
        $customerId = (int) $rec->customer_id;
        $scope = $rec->product_id !== null ? [(int) $rec->product_id] : null;
        $summary = $this->calculator->summarize($lines[$customerId] ?? [], $scope, $action->at, $window->to()->toDateString());
        $inScope = fn (object $l): bool => $scope === null || in_array((int) $l->product_id, $scope, true);
        $lateScope = array_values(array_filter($late['new'][$customerId] ?? [], $inScope));
        $changedScope = array_values(array_filter($late['changed'][$customerId] ?? [], $inScope));
        $contribution = $this->contribution($tenant, $decision, $window->cutoff(), $summary['by_product']);
        $purchased = $summary['net_units'] > 0;
        // Lo ÚNICO asociable a la acción es lo posterior a ella (neto de devoluciones posteriores); la ventana completa queda como observación.
        $afterAction = null;
        if ($summary['after_action'] !== null && $action->acted()) {
            $afterContribution = $this->contribution($tenant, $decision, $window->cutoff(), $summary['after_action']['by_product']);
            $afterAction = [
                'basis' => $summary['after_action']['basis'], 'units' => $summary['after_action']['net_units'], 'net_amount' => $summary['after_action']['net_amount'],
                'gross_units' => $summary['after_action']['gross_units'], 'returned_units' => $summary['after_action']['returned_units'], 'purchase_lines' => $summary['after_action']['purchase_lines'],
                'contribution' => $afterContribution['value'], 'contribution_status' => $afterContribution['status'], 'contribution_reasons' => $afterContribution['reasons'],
                'contribution_detail' => ['by_product' => $afterContribution['by_product'], 'unknown_products' => $afterContribution['unknown_products'] ?? []],
            ];
        }
        $associated = $afterAction !== null && $afterAction['units'] > 0;

        // Pertenencia al análisis ITT de esta cohorte (solo informa; no decide la clasificación de la fila).
        $experiment = null;
        $cohort = null;
        $unit = null;
        $inItt = false;
        if ($exp !== null) {
            $cohort = $exp['verified'] ? ($cohorts[(string) $exp['key']] ?? null) : null;
            $inItt = $cohort !== null && isset($cohort['units'][$customerId]);
            $unit = $inItt ? $cohort['units'][$customerId] : null;
            $status = match (true) {
                ! $exp['verified'] => 'unverified_assignment:'.$exp['reason'],
                $cohort === null => 'no_cohort',
                isset($cohort['conflict'][$customerId]) => 'conflicting_groups',
                isset($cohort['repeat'][$customerId]) => 'repeat_customer',
                default => $cohort['estimate']['status'],
            };
            $experiment = array_filter([
                'key' => $exp['key'], 'key_source' => $exp['key_source'] ?? null, 'group' => $exp['group'], 'unit_key' => $exp['unit_key'], 'verified' => $exp['verified'], 'algorithm' => $exp['algorithm'] ?? null,
                'control_fraction' => $exp['control_fraction'] ?? null, 'assigned_at' => $exp['assigned_at'] ?? null,
                'cohort' => $exp['key'] === null ? null : ExperimentResolver::cohortKey((string) $exp['key'], $window->asOf->toDateString(), $window->horizonDays),
                'itt_status' => $status, 'in_itt' => $inItt, 'itt_counted' => $inItt && (int) $unit['representative'] === (int) $rec->id,
                'in_contribution_population' => $inItt ? (bool) $unit['in_population'] : false,
            ], fn ($v) => $v !== null);
        }

        [$classification, $reason] = match (true) {
            $action->kind === OutcomeVocabulary::CONTROL => [OutcomeVocabulary::OBSERVED, 'control_group_baseline'],
            $associated => [OutcomeVocabulary::TEMPORALLY_ASSOCIATED, 'purchase_after_registered_action'],
            ! $purchased => [OutcomeVocabulary::OBSERVED, 'no_purchase_observed'],
            $action->acted() => [OutcomeVocabulary::OBSERVED, 'purchase_only_before_registered_action'],
            default => [OutcomeVocabulary::OBSERVED, 'purchase_without_registered_action'],
        };
        $evidence = $this->evidence($settings, $window, $today, $rec, $action, [
            'view' => 'sales_line_versions', 'fingerprint' => $summary['fingerprint'], 'lines_read' => $summary['lines_read'],
            'late_lines_not_counted' => count($lateScope),
            'late_units_not_counted' => round(array_sum(array_map(fn (object $l) => $l->is_return ? 0.0 : (float) $l->quantity, $lateScope)), 4),
            'late_returned_units_not_counted' => round(array_sum(array_map(fn (object $l) => $l->is_return ? (float) $l->quantity : 0.0, $lateScope)), 4),
            'lines_revised_or_removed_after_cutoff' => count($changedScope),
        ], [
            'type' => $rec->entity_type, 'customer_id' => $customerId, 'product_ids' => $scope ?? 'any',
        ], $classification, $reason);
        $evidence['observation'] = array_diff_key($summary, ['fingerprint' => 1, 'lines_read' => 1, 'first_ref' => 1, 'first_after_action_ref' => 1, 'after_action' => 1, 'lines' => 1, 'lines_truncated' => 1]);
        $evidence['after_action'] = $afterAction;
        // Costo unitario confiable al corte de cada producto visto (NULL = no confiable): el informe lo usa para deduplicar la contribución entre recomendaciones.
        $evidence['unit_costs'] = [];
        foreach (array_keys($summary['by_product']) as $productId) {
            $eco = $this->economics->forProduct($tenant, $decision, $window->cutoff(), (int) $productId);
            $evidence['unit_costs'][(int) $productId] = $eco->costReliable ? $eco->cost : null;
        }
        $evidence['lines'] = $summary['lines'];
        $evidence['lines_truncated'] = $summary['lines_truncated'];
        $evidence['contribution'] = $contribution;
        $evidence['experiment'] = $experiment;
        if ($inItt) {
            $evidence['itt'] = $cohort['estimate'];
            if ($experiment['itt_counted']) {
                $evidence['unit'] = array_intersect_key($unit, array_flip(['converted', 'contribution', 'in_population', 'control_fraction', 'contaminated', 'scope_products']));
            }
        }
        $ref = $purchased ? ($summary['first_after_action_ref'] ?? $summary['first_ref']) : null;

        return [
            'recommendation_id' => (int) $rec->id, 'action_kind' => $action->kind, 'action_at' => $action->at, 'window_from' => $window->from()->toDateString(),
            'window_to' => $window->to()->toDateString(), 'observed_event' => $purchased ? OutcomeVocabulary::PURCHASE : OutcomeVocabulary::NO_PURCHASE,
            'observed_ref' => $ref === null ? null : 'sale:'.$ref, 'observed_units' => $summary['net_units'], 'observed_net_amount' => $summary['net_amount'],
            'contribution' => $contribution['value'],
            // Costo de contacto: solo si hubo acción. Acción con costo en la recomendación = ese costo; acción sin costo configurado = NULL (desconocido, no cero).
            'contact_cost' => $action->acted() ? $rec->action_cost : 0.0,
            'classification' => $classification, 'method' => 'none', 'evidence' => $evidence,
        ];
    }

    // ───────────────────────── resultado de una recomendación por producto ─────────────────────────

    /**
     * Reposición, inventario-demanda, presupuesto y escenarios: se miden por el producto (y la sucursal, si la recomendación la fija): ventas conocidas al corte y
     * quiebres de stock en la ventana. No hay cliente ni grupo de control, y NO se afirma asociación: una compra al proveedor no «causa» las ventas posteriores del producto
     * (las vende cualquier cliente), así que el resultado es siempre `observed`; las líneas posteriores a la acción solo se informan.
     *
     * @return array<string, mixed>
     */
    private function productOutcome(Tenant $tenant, DecisionSettings $decision, OutcomeSettings $settings, DecisionRecommendation $rec, ActionRecord $action, OutcomeWindow $window, CarbonImmutable $today): array
    {
        $from = $window->from()->toDateString();
        $to = $window->to()->toDateString();
        $cutoff = $window->cutoff();
        $productId = $rec->product_id === null ? null : (int) $rec->product_id;
        $branchId = $rec->entity_type === 'product_branch' && $rec->branch_id !== null ? (int) $rec->branch_id : null;
        $summary = null;
        $inventory = null;
        if ($productId !== null) {
            $summary = $this->sales->productSummary($tenant, $from, $to, $cutoff->toDateString(), $productId, $branchId, $action->at?->toIso8601String());
            $inventory = $this->sales->inventoryWindow($tenant, $from, $to, $cutoff->toDateString(), $productId, $branchId);
        }
        $netUnits = $summary === null ? 0.0 : max(0.0, $summary['gross_units'] - $summary['returned_units']);
        $netAmount = $summary === null || $netUnits <= 0 ? 0.0 : max(0.0, $summary['gross_net_amount'] - $summary['returned_net_amount']);
        $byProduct = $productId === null || $netUnits <= 0 ? [] : [$productId => ['gross_units' => $summary['gross_units'], 'returned_units' => $summary['returned_units'], 'net_units' => round($netUnits, 4), 'net_amount' => round($netAmount, 4)]];
        $contribution = $productId === null
            ? ['status' => 'unknown', 'value' => null, 'method' => null, 'by_product' => [], 'reasons' => ['no_product_scope']]
            : $this->contribution($tenant, $decision, $cutoff, $byProduct);

        $availability = (array) $rec->availability;
        $event = match (true) {
            $inventory !== null && $inventory['stockout_days'] > 0 => OutcomeVocabulary::STOCKOUT,
            // «La disponibilidad no bajó»: al cierre de la ventana hay al menos lo que había al generar. Es una observación de existencias, no un diagnóstico de exceso ni de causa.
            $rec->kind === 'inventory_mismatch' && $inventory !== null && $inventory['last_available'] !== null && ($availability['status'] ?? null) === 'known'
                && isset($availability['available']) && (float) $availability['available'] > 0 && $inventory['last_available'] >= (float) $availability['available'] => OutcomeVocabulary::NO_SELL_THROUGH,
            default => OutcomeVocabulary::PRODUCT_SALES,
        };
        $classification = OutcomeVocabulary::OBSERVED;
        $reason = 'product_level_observation_no_association_claim';

        $evidence = $this->evidence($settings, $window, $today, $rec, $action, [
            'view' => 'sales_line_versions', 'fingerprint' => $summary['fingerprint'] ?? null, 'lines_read' => $summary['lines'] ?? 0,
        ], ['type' => $rec->entity_type, 'product_ids' => $productId === null ? [] : [$productId], 'branch_id' => $branchId], $classification, $reason);
        $evidence['observation'] = [
            'net_units' => round($netUnits, 4), 'net_amount' => round($netAmount, 4), 'gross_units' => $summary['gross_units'] ?? 0.0, 'returned_units' => $summary['returned_units'] ?? 0.0,
            'purchase_lines' => $summary['purchase_lines'] ?? 0, 'purchase_lines_after_action' => $summary['lines_after_action'] ?? 0,
            'caveat' => 'Ventas del producto de todos los clientes: lo posterior a la acción registrada se informa pero NO se asocia a ella.',
        ];
        $evidence['inventory'] = $inventory === null ? null : $inventory + ['available_at_generation' => ($availability['status'] ?? null) === 'known' ? ($availability['available'] ?? null) : null];
        $evidence['contribution'] = $contribution;
        $evidence['experiment'] = null;

        return [
            'recommendation_id' => (int) $rec->id, 'action_kind' => $action->kind, 'action_at' => $action->at, 'window_from' => $from, 'window_to' => $to,
            'observed_event' => $event, 'observed_ref' => null, 'observed_units' => round($netUnits, 4), 'observed_net_amount' => round($netAmount, 4),
            'contribution' => $contribution['value'],
            // La «acción» de reposición es una compra al proveedor, no un contacto: su inversión no es costo de contacto (queda en la recomendación).
            'contact_cost' => null, 'classification' => $classification, 'method' => 'none', 'evidence' => $evidence,
        ];
    }

    // ───────────────────────── piezas comunes ─────────────────────────

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $scope
     * @return array<string, mixed>
     */
    private function evidence(OutcomeSettings $settings, OutcomeWindow $window, CarbonImmutable $today, DecisionRecommendation $rec, ActionRecord $action, array $source, array $scope, string $classification, string $reason): array
    {
        return [
            'method_version' => $settings->methodVersion(),
            'window' => $window->toArray() + ['measured_on' => $today->toDateString()],
            'source' => ['reading' => 'as_of_knowledge_cutoff', 'knowledge_cutoff' => $window->cutoff()->toDateString()] + $source,
            'recommendation' => [
                'kind' => $rec->kind, 'status_at_measurement' => (string) $rec->status, 'attribution_label_at_generation' => $rec->attribution_label,
                'superseded' => ($action->details['expiry_reason'] ?? null) === 'superseded', 'horizon_days' => (int) $rec->horizon_days,
            ],
            'action' => $action->toArray(),
            'scope' => $scope,
            'classification_reason' => $reason,
            'classification_note' => OutcomeVocabulary::NOTES[$classification],
        ];
    }

    /**
     * Contribución = importe neto − unidades netas × costo unitario confiable al corte (economía de W5-B). Sin costo confiable en algún producto comprado: NULL con el motivo.
     *
     * @param  array<int, array<string, float>>  $byProduct
     * @return array<string, mixed>
     */
    private function contribution(Tenant $tenant, DecisionSettings $decision, CarbonImmutable $cutoff, array $byProduct): array
    {
        $method = 'net_amount - net_units x unit_cost (costo unitario confiable de W5-B, lectura as-of al corte)';
        $known = [];
        $unknown = [];
        $reasons = [];
        $total = 0.0;
        foreach ($byProduct as $productId => $p) {
            if ($p['net_units'] <= 0) {
                continue;
            }
            $eco = $this->economics->forProduct($tenant, $decision, $cutoff, (int) $productId);
            if (! $eco->costReliable || $eco->cost === null) {
                $unknown[] = ['product_id' => (int) $productId, 'reasons' => $eco->costReasons];
                array_push($reasons, ...$eco->costReasons);

                continue;
            }
            $value = round($p['net_amount'] - $p['net_units'] * $eco->cost, 4);
            $total += $value;
            $known[] = ['product_id' => (int) $productId, 'net_units' => $p['net_units'], 'net_amount' => $p['net_amount'], 'unit_cost' => $eco->cost, 'cost_source' => $eco->costSource, 'contribution' => $value];
        }
        if ($known === [] && $unknown === []) {
            return ['status' => 'not_applicable_no_purchase', 'value' => 0.0, 'method' => null, 'by_product' => [], 'reasons' => []];
        }
        if ($unknown !== []) {
            return ['status' => 'unknown', 'value' => null, 'method' => $method, 'by_product' => $known, 'unknown_products' => $unknown, 'reasons' => array_values(array_unique($reasons))];
        }

        return ['status' => 'known', 'value' => round($total, 4), 'method' => $method, 'by_product' => $known, 'reasons' => []];
    }

    /** Recomendaciones ejecutadas cuyo resultado ya existía (la ejecución se registró DESPUÉS de medir): se cierran como medidas, con la marca. */
    private function finalizeLateExecutions(bool $dryRun): int
    {
        $ids = DecisionRecommendation::query()->where('status', 'executed')->whereExists($this->hasOutcome())->orderBy('id')->pluck('id');
        if ($dryRun) {
            return $ids->count();
        }
        $done = 0;
        foreach ($ids as $id) {
            $outcomeId = DecisionOutcome::query()->where('recommendation_id', $id)->orderBy('id')->value('id');
            $moved = $this->workflow->systemTransition((int) $id, 'measured', 'outcome-measurement', 'Resultado ya medido: la ejecución se registró después de la medición.', [
                'outcome_id' => $outcomeId === null ? null : (int) $outcomeId, 'late_execution' => true,
            ]);
            $done += $moved === null || $moved->replayed ? 0 : 1;
        }

        return $done;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function countPending(array $report, OutcomeSettings $settings, CarbonImmutable $today): array
    {
        $base = fn () => DecisionRecommendation::query()->whereNotExists($this->hasOutcome());
        $report['pending_window_open'] = $base()->whereRaw('(as_of + horizon_days) >= ?::date', [$today->toDateString()])->count();
        $report['pending_settling'] = $base()->whereRaw('(as_of + horizon_days) < ?::date', [$today->toDateString()])
            ->whereRaw('(as_of + (horizon_days + ?::int)) >= ?::date', [$settings->lagDays(), $today->toDateString()])->count();

        return $report;
    }
}
