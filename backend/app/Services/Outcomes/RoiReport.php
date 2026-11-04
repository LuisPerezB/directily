<?php

declare(strict_types=1);

namespace App\Services\Outcomes;

use App\Enums\TenantRole;
use App\Models\CustomerSegment;
use App\Models\Tenant;
use App\Services\Decision\ExperimentRedaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Informe de ROI y outcomes HONESTO de una empresa (V2.5 · W5-C; ADR-V25-14; §12). Solo lee lo ya medido (`decision_outcomes`) y las recomendaciones; no calcula
 * nada nuevo sobre ventas ni escribe.
 *
 * Qué separa, siempre (nunca se suman entre sí):
 *  - OPORTUNIDAD POTENCIAL (`potential`): contribución esperada de las recomendaciones identificadas, por tipo y horizonte (no se suman tipos ni horizontes: contarían dos
 *    veces la misma venta). Atribución no demostrada; no es ingreso recuperado.
 *  - OBSERVACIÓN (`observed`, `associated`): lo que pasó después, con contribución conocida (no ingresos brutos), SIN DOBLE CONTEO: varias recomendaciones pueden observar la
 *    misma línea de venta (horizontes de 7 y 30 días, contacto y recuperación), así que los totales se deduplican por línea de venta distinta; la suma por recomendación
 *    queda aparte (`per_recommendation`) como detalle, no aditivo. `associated` suma SOLO lo posterior a la acción registrada (neto de las devoluciones posteriores).
 *  - INCREMENTO ESTIMADO (`experiments`, `roi`): solo con un experimento de control analizado por intención de tratamiento, con tamaños e intervalo. Sin él,
 *    `roi.status = potential_unproven` y no hay cifra de ROI.
 *
 * Quien no puede aprobar (`decisions.approve`) NO recibe información del control ni del experimento (ExperimentRedaction de W5-B): ni conteos del control, ni muestras, ni
 * desglose por grupo; el ROI le muestra solo su estado.
 *
 * Alcance y costo: el periodo (por el corte `as_of`) es por omisión de 180 días y tiene un máximo de 730; las filas se leen por bloques con paginación por clave.
 */
final class RoiReport
{
    public const METHOD_ID = 'outcomes-roi-v1';

    public const GROUPS = ['kind', 'horizon', 'segment', 'owner'];

    private const CHUNK = 5000;

    /** @var array<string, string> memoria de «fin de ventana» por (corte, horizonte) */
    private array $windowEnds = [];

    public function __construct(private readonly ExperimentResolver $experiments) {}

    /**
     * Periodo efectivo (por el corte `as_of`): `hasta` por omisión es hoy; `desde` por omisión son 180 días antes de `hasta`; el rango no puede superar el máximo.
     *
     * @return array{from: string, to: string, defaulted: bool, days: int}
     *
     * @throws ValidationException 422 si el rango es inválido o supera el máximo
     */
    public static function period(?string $from, ?string $to, CarbonImmutable $today): array
    {
        $max = max(1, (int) config('outcomes.report_max_days', 730));
        $default = max(1, (int) config('outcomes.report_default_days', 180));
        $end = $to === null ? $today : CarbonImmutable::parse($to)->startOfDay();
        $start = $from === null ? $end->subDays($default) : CarbonImmutable::parse($from)->startOfDay();
        if ($start->greaterThan($end)) {
            throw ValidationException::withMessages(['from' => 'El periodo es inválido: «desde» es posterior a «hasta».']);
        }
        $days = (int) $start->diffInDays($end);
        if ($days > $max) {
            throw ValidationException::withMessages(['to' => "El periodo no puede superar {$max} días (se pidieron {$days}): acote «from» y «to»."]);
        }

        return ['from' => $start->toDateString(), 'to' => $end->toDateString(), 'defaulted' => $from === null || $to === null, 'days' => $days];
    }

    /**
     * @param  array{kind?: list<string>|null, horizon_days?: int|null, from?: string|null, to?: string|null, experiment_key?: string|null, group_by?: string|null}  $filters
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function build(Tenant $tenant, array $filters, CarbonImmutable $now, ?TenantRole $role = null): array
    {
        $settings = OutcomeSettings::forTenant($tenant);
        $today = $now->setTimezone($settings->timezone())->startOfDay();
        $seeExperiment = ExperimentRedaction::canSee($role);
        $groupBy = in_array($filters['group_by'] ?? null, self::GROUPS, true) ? $filters['group_by'] : 'kind';
        $tenantId = (int) $tenant->getKey();
        $period = self::period($filters['from'] ?? null, $filters['to'] ?? null, $today);
        $filters['from'] = $period['from'];
        $filters['to'] = $period['to'];
        $this->windowEnds = [];

        $total = $this->accumulator();
        $groups = [];
        foreach ($this->rows($tenantId, $filters, $seeExperiment) as $row) {
            $this->add($total, $row, $today, $settings);
            $key = $this->groupKey($row, $groupBy);
            $groups[$key] ??= $this->accumulator();
            $this->add($groups[$key], $row, $today, $settings);
        }

        // El análisis se calcula siempre (el estado del ROI depende de él); quien no aprueba solo recibe ese estado, nunca el detalle por grupos.
        $experiments = ! $seeExperiment && ! empty($filters['experiment_key']) ? [] : $this->experimentList($tenantId, $settings, $filters);
        $usage = $this->usage($tenantId, $settings, $filters);
        $report = [
            'method' => $this->method($settings),
            'filters' => [
                'kind' => $filters['kind'] ?? null, 'horizon_days' => $filters['horizon_days'] ?? null, 'from' => $period['from'], 'to' => $period['to'], 'period_days' => $period['days'],
                'period_defaulted' => $period['defaulted'], 'experiment_key' => $seeExperiment ? ($filters['experiment_key'] ?? null) : null, 'group_by' => $groupBy,
                'period_basis' => 'as_of de la recomendación', 'period_default_days' => (int) config('outcomes.report_default_days', 180), 'period_max_days' => (int) config('outcomes.report_max_days', 730),
            ],
            'currency' => $settings->currency(),
            'today' => $today->toDateString(),
            'data_origin' => $this->dataOrigin($tenant),
            'summary' => $this->present($total, $seeExperiment),
            'experiments' => $experiments,
            'costs' => ['contact' => $this->contactCosts($total), 'platform_usage' => $usage],
            'roi' => $this->roi($experiments, $usage, $settings, $seeExperiment) + $this->originWarning($tenant),
            'breakdown' => ['group_by' => $groupBy, 'rows' => $this->breakdown($tenantId, $groups, $groupBy, $seeExperiment)],
            'limitations' => self::limitations($settings, $seeExperiment),
        ];
        if (! $seeExperiment) {
            unset($report['experiments']);
        }

        return $report;
    }

    /**
     * Origen de los datos de la empresa (`tenants.settings.data_origin`: real, demo o simulated). Las empresas de demostración lo declaran (`demo`); sin declarar NO se supone
     * real y el ROI lleva un aviso de «origen no verificado». Los resultados económicos de datos de demostración o simulados nunca se presentan como reales (V25-OUT-023).
     *
     * @return array{value: string|null, declared: bool, note: string}
     */
    private function dataOrigin(Tenant $tenant): array
    {
        $value = $tenant->effectiveSettings()['data_origin'] ?? null;
        $value = is_string($value) && in_array($value, ['real', 'demo', 'simulated'], true) ? $value : null;

        return [
            'value' => $value, 'declared' => $value !== null,
            'note' => match ($value) {
                'real' => 'Datos declarados como reales.',
                'demo', 'simulated' => 'Datos de demostración o simulados: los importes de este informe NO son resultados reales.',
                default => 'Origen de los datos no verificado (tenants.settings.data_origin sin declarar): no se supone que sean reales.',
            },
        ];
    }

    /** @return array<string, mixed> */
    private function originWarning(Tenant $tenant): array
    {
        $origin = $this->dataOrigin($tenant);

        return $origin['value'] === 'real' ? [] : ['data_origin_warning' => $origin['note']];
    }

    // ───────────────────────── filas ─────────────────────────

    /** @return \Generator<int, object> bloques de hasta CHUNK filas, paginados por clave (id de la recomendación) */
    private function rows(int $tenantId, array $filters, bool $seeExperiment): \Generator
    {
        // Reemplazadas por una más nueva: se leen UNA vez (un recorrido de los eventos de vencimiento), no una consulta por recomendación ni por bloque.
        $superseded = array_flip(DB::table('decision_events')->where('tenant_id', $tenantId)->where('event', 'transition')->where('to_status', 'expired')
            ->whereRaw("payload->>'reason_code' = 'superseded'")->distinct()->pluck('recommendation_id')->map(fn ($id) => (int) $id)->all());
        $after = 0;
        do {
            $query = DB::table('decision_recommendations as r')
                ->leftJoin('decision_outcomes as o', fn ($j) => $j->on('o.recommendation_id', '=', 'r.id')->on('o.tenant_id', '=', 'r.tenant_id'))
                ->leftJoin('customers as c', fn ($j) => $j->on('c.id', '=', 'r.customer_id')->on('c.tenant_id', '=', 'r.tenant_id'))
                // `jsonb_to_record` descomprime la evidencia UNA vez por fila y extrae solo los bloques que el informe usa (extraer cada campo por separado la descomprime cada vez).
                ->leftJoin(DB::raw('LATERAL jsonb_to_record(o.evidence) AS x(contribution jsonb, after_action jsonb, lines jsonb, unit_costs jsonb, lines_truncated text, experiment jsonb)'), DB::raw('true'), '=', DB::raw('true'))
                ->where('r.tenant_id', $tenantId)->where('r.id', '>', $after)
                ->selectRaw("r.id AS recommendation_id, r.kind, r.horizon_days, r.as_of, r.status, r.assigned_user_id, r.customer_id, r.experiment_group, r.expected_contribution, r.expected_gross_value,
                    c.segment_id, o.id AS outcome_id, o.action_kind, o.classification, o.observed_event, o.observed_units, o.observed_net_amount, o.contribution, o.contact_cost,
                    x.contribution->>'status' AS contribution_status,
                    x.after_action->>'units' AS aa_units, x.after_action->>'net_amount' AS aa_amount, x.after_action->>'contribution' AS aa_contribution,
                    x.after_action->>'contribution_status' AS aa_status,
                    x.lines AS lines, x.unit_costs AS unit_costs, x.lines_truncated AS lines_truncated,
                    false AS superseded")
                ->orderBy('r.id')->limit(self::CHUNK);
            $this->applyFilters($query, $filters);
            if (! $seeExperiment) {
                // Quien no aprueba no recibe información del control ni del experimento: las filas de control no entran en los conteos ni en los desgloses, y filtrar por
                // experimento (revelaría quién pertenece a uno) no devuelve nada.
                $query->where(fn ($q) => $q->whereNull('r.experiment_group')->orWhere('r.experiment_group', '<>', 'control'));
                if (! empty($filters['experiment_key'])) {
                    $query->whereRaw('1 = 0');
                }
            }
            $rows = $query->get();
            foreach ($rows as $row) {
                $after = (int) $row->recommendation_id;
                $row->superseded = isset($superseded[$after]);

                yield $row;
            }
        } while (count($rows) === self::CHUNK);
    }

    private function applyFilters($query, array $filters, bool $kind = true): void
    {
        if ($kind && ! empty($filters['kind'])) {
            $query->whereIn('r.kind', $filters['kind']);
        }
        if (! empty($filters['horizon_days'])) {
            $query->where('r.horizon_days', (int) $filters['horizon_days']);
        }
        if (! empty($filters['from'])) {
            $query->where('r.as_of', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('r.as_of', '<=', $filters['to']);
        }
        if (! empty($filters['experiment_key'])) {
            $query->whereRaw("x.experiment->>'key' = ?", [$filters['experiment_key']]);
        }
    }

    // ───────────────────────── acumulación ─────────────────────────

    /** @return array<string, mixed> */
    private function accumulator(): array
    {
        return [
            'recommendations' => 0, 'superseded' => 0, 'identified' => 0, 'control' => 0, 'actionable' => 0, 'measured' => 0, 'measured_actionable' => 0,
            'pending' => ['window_open' => 0, 'settling' => 0, 'measurable_not_yet_measured' => 0],
            'by_classification' => array_fill_keys(OutcomeVocabulary::CLASSIFICATIONS, 0), 'by_action_kind' => array_fill_keys(OutcomeVocabulary::ACTION_KINDS, 0),
            'attended' => 0, 'responded' => 0, 'purchases' => 0,
            // Suma por recomendación (detalle, NO aditiva) y deduplicación por línea de venta distinta.
            'per_recommendation' => ['rows' => 0, 'units' => 0.0, 'net_amount' => 0.0, 'contribution' => 0.0, 'contribution_rows' => 0, 'contribution_unknown_rows' => 0],
            'associated_per_recommendation' => ['rows' => 0, 'units' => 0.0, 'net_amount' => 0.0, 'contribution' => 0.0, 'contribution_rows' => 0, 'contribution_unknown_rows' => 0],
            'whole' => $this->lineSet(), 'after' => $this->lineSet(),
            'control_baseline' => ['units' => 0, 'purchased' => 0],
            'supply' => ['stockout' => 0, 'no_sell_through' => 0, 'product_sales' => 0],
            'potential' => [], 'contact' => ['total' => 0.0, 'actions' => 0, 'actions_cost_unknown' => 0],
        ];
    }

    /** @return array<string, mixed> */
    private function lineSet(): array
    {
        return ['seen' => [], 'pairs' => [], 'costs' => [], 'fallback_customers' => [], 'rows' => 0, 'rows_without_detail' => 0, 'fallback_units' => 0.0, 'fallback_amount' => 0.0, 'fallback_contribution' => 0.0, 'fallback_unknown' => 0];
    }

    /** @param array<string, mixed> $a */
    private function add(array &$a, object $r, CarbonImmutable $today, OutcomeSettings $settings): void
    {
        $a['recommendations']++;
        if ($r->superseded) {
            $a['superseded']++;   // reemplazada por una más nueva: no es una oportunidad distinta, no entra en los totales

            return;
        }
        $a['identified']++;
        $control = $r->experiment_group === 'control';
        $customerKind = OutcomeVocabulary::isCustomerKind((string) $r->kind);
        if ($control) {
            $a['control']++;
        } else {
            $a['actionable']++;
            $cell = $r->kind.'|'.$r->horizon_days;
            $a['potential'][$cell] ??= ['kind' => $r->kind, 'horizon_days' => (int) $r->horizon_days, 'recommendations' => 0, 'expected_contribution' => 0.0, 'recommendations_without_reliable_cost' => 0, 'expected_gross_value' => 0.0];
            $a['potential'][$cell]['recommendations']++;
            if ($r->expected_contribution === null) {
                $a['potential'][$cell]['recommendations_without_reliable_cost']++;
            } else {
                $a['potential'][$cell]['expected_contribution'] += (float) $r->expected_contribution;
            }
            $a['potential'][$cell]['expected_gross_value'] += (float) ($r->expected_gross_value ?? 0);
        }
        if ($r->outcome_id === null) {
            $bucket = $this->pendingBucket((string) $r->as_of, (int) $r->horizon_days, $today, $settings);
            $a['pending'][$bucket]++;
            $a['by_classification'][OutcomeVocabulary::FORECAST]++;   // previsto: virtual, no se persiste

            return;
        }
        $a['measured']++;
        $a['by_classification'][$r->classification]++;
        $a['by_action_kind'][$r->action_kind]++;
        if ($control) {
            $a['control_baseline']['units']++;
            $a['control_baseline']['purchased'] += $r->observed_event === OutcomeVocabulary::PURCHASE ? 1 : 0;

            return;
        }
        $a['measured_actionable']++;
        if (! $customerKind) {
            $a['supply'][$r->observed_event] = ($a['supply'][$r->observed_event] ?? 0) + 1;
            if (in_array($r->action_kind, OutcomeVocabulary::ACTED, true)) {
                $a['attended']++;
            }

            return;
        }
        $acted = in_array($r->action_kind, OutcomeVocabulary::ACTED, true);
        $purchased = $r->observed_event === OutcomeVocabulary::PURCHASE;
        $associated = $r->classification === OutcomeVocabulary::TEMPORALLY_ASSOCIATED;
        if ($acted) {
            $a['attended']++;
            $a['contact']['actions']++;
            if ($r->contact_cost === null) {
                $a['contact']['actions_cost_unknown']++;
            } else {
                $a['contact']['total'] += (float) $r->contact_cost;
            }
            if ($associated) {
                $a['responded']++;
            }
        }
        if ($purchased) {
            $a['purchases']++;
            $this->addPerRecommendation($a['per_recommendation'], (float) $r->observed_units, (float) $r->observed_net_amount, $r->contribution);
            $this->addLines($a['whole'], $r, false, (float) $r->observed_units, (float) $r->observed_net_amount, $r->contribution);
        }
        if ($associated) {
            // Solo lo posterior a la acción (y neto de las devoluciones posteriores): lo anterior es observación, no asociación.
            $this->addPerRecommendation($a['associated_per_recommendation'], (float) $r->aa_units, (float) $r->aa_amount, $r->aa_status === 'known' ? $r->aa_contribution : null);
            $this->addLines($a['after'], $r, true, (float) $r->aa_units, (float) $r->aa_amount, $r->aa_status === 'known' ? $r->aa_contribution : null);
        }
    }

    private function pendingBucket(string $asOf, int $horizon, CarbonImmutable $today, OutcomeSettings $settings): string
    {
        $key = substr($asOf, 0, 10).'|'.$horizon;
        $this->windowEnds[$key] ??= OutcomeWindow::for($asOf, $horizon, $settings->lagDays())->to()->toDateString();
        $to = $this->windowEnds[$key];
        $cutoff = $this->windowEnds[$key.'|c'] ??= OutcomeWindow::for($asOf, $horizon, $settings->lagDays())->cutoff()->toDateString();
        $todayDate = $today->toDateString();

        return $todayDate <= $to ? 'window_open' : ($todayDate <= $cutoff ? 'settling' : 'measurable_not_yet_measured');
    }

    /**
     * @param  array<string, mixed>  $bucket
     */
    private function addPerRecommendation(array &$bucket, float $units, float $amount, mixed $contribution): void
    {
        $bucket['rows']++;
        $bucket['units'] += $units;
        $bucket['net_amount'] += $amount;
        if ($contribution === null) {
            $bucket['contribution_unknown_rows']++;

            return;
        }
        $bucket['contribution'] += (float) $contribution;
        $bucket['contribution_rows']++;
    }

    /**
     * Registra las líneas de venta de un resultado en el conjunto DEDUPLICADO: cada línea (cliente, producto, venta, línea) cuenta una sola vez aunque varias recomendaciones
     * la observen. Con `$afterOnly` solo las posteriores a la acción. Si el resultado no trae el detalle de líneas (anterior a este formato, o truncado) se suma por
     * recomendación y se declara (`rows_without_line_detail`).
     *
     * @param  array<string, mixed>  $set
     */
    private function addLines(array &$set, object $r, bool $afterOnly, float $units, float $amount, mixed $contribution): void
    {
        $set['rows']++;
        $lines = $r->lines === null ? null : json_decode((string) $r->lines, true);
        if (! is_array($lines) || $r->lines_truncated === 'true') {
            $set['rows_without_detail']++;
            $set['fallback_units'] += $units;
            $set['fallback_amount'] += $amount;
            if ($contribution === null) {
                $set['fallback_unknown']++;
            } else {
                $set['fallback_contribution'] += (float) $contribution;
            }
            $set['fallback_customers'][(int) $r->customer_id] = true;

            return;
        }
        $costs = $r->unit_costs === null ? [] : (array) json_decode((string) $r->unit_costs, true);
        foreach ($lines as $l) {
            [$sale, $line, $product, $u, $a, $ret, $after] = $l;
            if ($afterOnly && (int) $after !== 1) {
                continue;
            }
            $key = $r->customer_id.'|'.$product.'|'.$sale.'|'.$line;
            if (isset($set['seen'][$key])) {
                continue;
            }
            $set['seen'][$key] = true;
            $pair = $r->customer_id.'|'.$product;
            $p = $set['pairs'][$pair] ?? ['g_u' => 0.0, 'g_a' => 0.0, 'r_u' => 0.0, 'r_a' => 0.0];
            if ((int) $ret === 1) {
                $p['r_u'] += (float) $u;
                $p['r_a'] += (float) $a;
            } else {
                $p['g_u'] += (float) $u;
                $p['g_a'] += (float) $a;
            }
            $set['pairs'][$pair] = $p;
            $set['costs'][(int) $product] = array_key_exists((string) $product, $costs) ? $costs[(string) $product] : ($set['costs'][(int) $product] ?? null);
        }
    }

    /**
     * Totales deduplicados de un conjunto de líneas: neto por (cliente, producto) como en cada resultado (compras menos devoluciones, sin bajar de cero) y contribución con el
     * costo confiable de cada producto (NULL = desconocida).
     *
     * @param  array<string, mixed>  $set
     * @return array<string, mixed>
     */
    private function dedup(array $set): array
    {
        $units = $set['fallback_units'];
        $amount = $set['fallback_amount'];
        $contribution = $set['fallback_contribution'];
        $unknownPairs = $set['fallback_unknown'];
        $purchasing = [];
        foreach ($set['pairs'] as $pair => $p) {
            $netUnits = max(0.0, $p['g_u'] - $p['r_u']);
            if ($netUnits <= 0) {
                continue;
            }
            [$customer, $product] = explode('|', (string) $pair);
            $netAmount = max(0.0, $p['g_a'] - $p['r_a']);
            $units += $netUnits;
            $amount += $netAmount;
            $purchasing[$customer] = true;
            $cost = $set['costs'][(int) $product] ?? null;
            if ($cost === null) {
                $unknownPairs++;
            } else {
                $contribution += $netAmount - $netUnits * (float) $cost;
            }
        }
        $customers = array_unique(array_map('intval', array_merge(array_keys($purchasing), array_keys($set['fallback_customers']))));

        return [
            'customers' => count($customers),
            'units' => round($units, 4), 'net_amount' => round($amount, 4), 'contribution_known' => round($contribution, 4), 'pairs_with_unknown_contribution' => $unknownPairs,
            'distinct_lines' => count($set['seen']), 'rows' => $set['rows'], 'rows_without_line_detail' => $set['rows_without_detail'],
        ];
    }

    private function groupKey(object $r, string $groupBy): string
    {
        return match ($groupBy) {
            'horizon' => (string) $r->horizon_days,
            'segment' => $r->segment_id === null ? 'none' : (string) $r->segment_id,
            'owner' => $r->assigned_user_id === null ? 'unassigned' : (string) $r->assigned_user_id,
            default => (string) $r->kind,
        };
    }

    // ───────────────────────── presentación ─────────────────────────

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    private function present(array $a, bool $seeExperiment): array
    {
        $rate = fn (int $n, int $d): ?float => $d > 0 ? round($n / $d, 4) : null;
        $money = fn (float $v): float => round($v, 4);
        $whole = $this->dedup($a['whole']);
        $after = $this->dedup($a['after']);
        $perRec = fn (array $b): array => [
            'rows' => $b['rows'], 'units' => $money($b['units']), 'net_amount' => $money($b['net_amount']), 'contribution_known' => $money($b['contribution']),
            'rows_with_known_contribution' => $b['contribution_rows'], 'rows_with_unknown_contribution' => $b['contribution_unknown_rows'],
            'note' => 'Suma por recomendación, solo para el detalle: NO es aditiva (varias recomendaciones pueden observar la misma venta).',
        ];

        $funnel = [
            'recommendations' => $a['recommendations'], 'superseded_not_counted' => $a['superseded'], 'identified' => $a['identified'],
            'actionable' => $a['actionable'], 'measured' => $a['measured'], 'measured_actionable' => $a['measured_actionable'],
            'forecast_pending' => $a['pending'] + ['total' => array_sum($a['pending'])],
            'attended' => $a['attended'],
            'action_rate' => $rate($a['attended'], $a['measured_actionable']),
            'responded_after_action' => $a['responded'],
            'response_rate' => $rate($a['responded'], $a['attended']),
            'notes' => [
                'action_rate' => 'Atendidas / medidas accionables (excluye el grupo de control, retenido a propósito).',
                'response_rate' => 'Atendidas con una compra posterior a la acción / atendidas. Es asociación temporal, NO efecto de la acción: se atiende a los casos más fáciles.',
            ],
        ];
        $byAction = $a['by_action_kind'];
        if ($seeExperiment) {
            $funnel['control_held_out'] = $a['control'];
        } else {
            unset($byAction[OutcomeVocabulary::CONTROL]);
        }
        $potentialCells = array_values($a['potential']);
        usort($potentialCells, fn (array $x, array $y) => [$x['kind'], $x['horizon_days']] <=> [$y['kind'], $y['horizon_days']]);
        $out = [
            'funnel' => $funnel,
            'by_classification' => $a['by_classification'],
            'by_action_kind' => $byAction,
            'potential' => [
                'label' => 'Oportunidad potencial, atribución no demostrada',
                'cells' => array_map(fn (array $c) => ['expected_contribution' => $money($c['expected_contribution']), 'expected_gross_value' => $money($c['expected_gross_value'])] + $c, $potentialCells),
                'note' => 'Lo esperado por las recomendaciones accionables, POR TIPO Y HORIZONTE: no se suman tipos ni horizontes (contarían dos veces la misma venta). No es ingreso recuperado ni beneficio atribuible.',
            ],
            'observed' => [
                'label' => 'Observado (compras de clientes en la ventana, sin doble conteo)',
                'customers' => $whole['customers'], 'purchase_lines' => $whole['distinct_lines'], 'units' => $whole['units'], 'net_amount' => $whole['net_amount'],
                'contribution_known' => $whole['contribution_known'], 'pairs_with_unknown_contribution' => $whole['pairs_with_unknown_contribution'],
                'recommendations_with_purchase' => $a['purchases'], 'rows_without_line_detail' => $whole['rows_without_line_detail'],
                'per_recommendation' => $perRec($a['per_recommendation']),
                'note' => 'Lo ocurrido, con o sin acción, deduplicado por línea de venta (cliente × producto × venta × línea). Contribución solo donde el costo es confiable; el resto es NULL, no cero.',
            ],
            'associated' => [
                'label' => 'Asociado temporalmente (SOLO lo posterior a la acción registrada; sin contrafactual)',
                'recommendations' => $a['associated_per_recommendation']['rows'], 'customers' => $after['customers'], 'units' => $after['units'], 'net_amount' => $after['net_amount'],
                'contribution_known' => $after['contribution_known'], 'pairs_with_unknown_contribution' => $after['pairs_with_unknown_contribution'], 'rows_without_line_detail' => $after['rows_without_line_detail'],
                'per_recommendation' => $perRec($a['associated_per_recommendation']),
                'note' => 'NO es beneficio atribuible. Las compras anteriores a la acción dentro de la ventana NO se cuentan aquí: quedan en «observado».',
            ],
            'supply' => $a['supply'] + ['note' => 'Reposición, inventario-demanda, presupuesto y escenarios se miden por producto (quiebres y «sin salida» observados); no tienen grupo de control ni se asocian a la acción.'],
        ];
        if ($seeExperiment) {
            $out['control_baseline'] = $a['control_baseline'] + ['purchase_rate' => $rate($a['control_baseline']['purchased'], $a['control_baseline']['units'])];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    private function contactCosts(array $a): array
    {
        return [
            'total' => round($a['contact']['total'], 4), 'actions' => $a['contact']['actions'], 'actions_cost_unknown' => $a['contact']['actions_cost_unknown'],
            'note' => 'Costo de la acción de la recomendación por cada recomendación atendida (las acciones registradas después de cerrar la ventana no cuentan). Un solo contacto que cubre varias recomendaciones del mismo cliente se cuenta una vez por recomendación (cota conservadora). Acción sin costo configurado = desconocido, no cero.',
        ];
    }

    /**
     * Experimentos con unidades analizadas, SIN el filtro de tipo (la unidad es el cliente, no la recomendación): análisis ITT, costos de contacto de las propias unidades
     * de tratamiento del análisis y unidades descartadas por grupo.
     *
     * @return list<array<string, mixed>>
     */
    private function experimentList(int $tenantId, OutcomeSettings $settings, array $filters): array
    {
        $acted = "o.action_kind IN ('contacted','executed')";
        $inTreatment = "x.experiment->>'in_itt' = 'true' AND r.experiment_group = 'treatment' AND x.experiment->>'in_contribution_population' = 'true'";
        $status = "x.experiment->>'itt_status'";
        $dropped = fn (string $cond, string $group) => "COUNT(DISTINCT r.customer_id) FILTER (WHERE ({$cond}) AND r.experiment_group = '{$group}')";
        $repeat = "{$status} = 'repeat_customer'";
        $unverified = "{$status} LIKE 'unverified_assignment%'";
        $conflict = "{$status} = 'conflicting_groups'";
        $query = DB::table('decision_outcomes as o')
            ->join('decision_recommendations as r', fn ($j) => $j->on('r.id', '=', 'o.recommendation_id')->on('r.tenant_id', '=', 'o.tenant_id'))
            ->join(DB::raw('LATERAL jsonb_to_record(o.evidence) AS x(experiment jsonb)'), DB::raw('true'), '=', DB::raw('true'))
            ->where('o.tenant_id', $tenantId)->whereRaw("x.experiment IS NOT NULL AND jsonb_typeof(x.experiment) = 'object'")
            ->selectRaw("COALESCE(x.experiment->>'key', '') AS exp_key, r.horizon_days,
                COUNT(*) FILTER (WHERE {$inTreatment} AND {$acted}) AS actions,
                COALESCE(SUM(o.contact_cost) FILTER (WHERE {$inTreatment} AND {$acted}), 0) AS cost,
                COUNT(*) FILTER (WHERE {$inTreatment} AND {$acted} AND o.contact_cost IS NULL) AS cost_unknown,
                {$dropped($repeat, 'treatment')} AS repeat_t, {$dropped($repeat, 'control')} AS repeat_c,
                {$dropped($unverified, 'treatment')} AS unverified_t, {$dropped($unverified, 'control')} AS unverified_c,
                {$dropped($conflict, 'treatment')} AS conflict_t, {$dropped($conflict, 'control')} AS conflict_c")
            ->groupByRaw("COALESCE(x.experiment->>'key', ''), r.horizon_days")->orderByRaw('1, 2');
        if (! empty($filters['horizon_days'])) {
            $query->where('r.horizon_days', (int) $filters['horizon_days']);
        }
        if (! empty($filters['from'])) {
            $query->where('r.as_of', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('r.as_of', '<=', $filters['to']);
        }
        if (! empty($filters['experiment_key'])) {
            $query->whereRaw("x.experiment->>'key' = ?", [$filters['experiment_key']]);
        }

        $list = [];
        foreach ($query->get() as $e) {
            $drop = [
                'repeat_customer' => ['treatment' => (int) $e->repeat_t, 'control' => (int) $e->repeat_c],
                'unverified_assignment' => ['treatment' => (int) $e->unverified_t, 'control' => (int) $e->unverified_c],
                'conflicting_groups' => ['treatment' => (int) $e->conflict_t, 'control' => (int) $e->conflict_c],
            ];
            if ($e->exp_key === '') {
                $list[] = ['experiment_key' => null, 'horizon_days' => (int) $e->horizon_days, 'status' => 'not_analysed', 'dropped_units' => $drop,
                    'note' => 'Unidades con grupo asignado que no se pudieron atribuir a un experimento (clave no registrada o ambigua): no entran al análisis.'];

                continue;
            }
            $units = $this->experiments->countedUnits($tenantId, (string) $e->exp_key, (int) $e->horizon_days, $filters['from'] ?? null, $filters['to'] ?? null);
            $list[] = ['experiment_key' => (string) $e->exp_key, 'horizon_days' => (int) $e->horizon_days] + IttAnalysis::estimate($units, $settings, (string) $e->exp_key, (int) $e->horizon_days) + [
                'contact_cost' => ['total' => round((float) $e->cost, 4), 'actions' => (int) $e->actions, 'actions_cost_unknown' => (int) $e->cost_unknown, 'basis' => 'unidades de tratamiento del propio análisis, sin filtro de tipo'],
                'dropped_units' => $drop,
            ];
        }

        return $list;
    }

    /**
     * Uso de plataforma (`tenant_usage_records`, W4): cantidades medidas; solo se monetiza con precios configurados por la empresa (`outcomes.usage_prices`).
     *
     * @return array<string, mixed>
     */
    private function usage(int $tenantId, OutcomeSettings $settings, array $filters): array
    {
        $rows = DB::table('tenant_usage_records')->where('tenant_id', $tenantId)
            ->when(! empty($filters['from']), fn ($q) => $q->where('period', '>=', $filters['from']))
            ->when(! empty($filters['to']), fn ($q) => $q->where('period', '<=', $filters['to']))
            ->selectRaw('metric, unit, SUM(quantity) AS quantity')->groupBy('metric', 'unit')->orderBy('metric')->get();
        $prices = $settings->usagePrices();
        $metrics = [];
        $cost = 0.0;
        $priced = 0;
        foreach ($rows as $r) {
            $price = $prices[$r->metric] ?? null;
            $line = $price === null ? null : round((float) $r->quantity * $price, 4);
            $metrics[] = ['metric' => (string) $r->metric, 'unit' => (string) $r->unit, 'quantity' => round((float) $r->quantity, 4), 'unit_price' => $price, 'cost' => $line];
            if ($line !== null) {
                $cost += $line;
                $priced++;
            }
        }

        return [
            'source' => 'tenant_usage_records', 'metrics' => $metrics,
            'total_cost' => $priced > 0 ? round($cost, 4) : null,
            'unpriced_metrics' => array_values(array_map(fn (array $m) => $m['metric'], array_filter($metrics, fn (array $m) => $m['cost'] === null))),
            'reason' => $priced === 0 ? ($metrics === [] ? 'no_usage_records' : 'no_price_model') : null,
            'note' => 'Uso de la plataforma completo del periodo, no atribuible a estas decisiones. Sin precios configurados no se convierte en dinero ni se inventa un costo.',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $experiments
     * @param  array<string, mixed>  $usage
     * @return array<string, mixed>
     */
    private function roi(array $experiments, array $usage, OutcomeSettings $settings, bool $seeExperiment): array
    {
        $base = ['proven' => false, 'causal_claims' => 0, 'currency' => $settings->currency()];
        $analysed = array_values(array_filter($experiments, fn (array $e) => $e['experiment_key'] !== null));
        $estimated = array_values(array_filter($analysed, fn (array $e) => $e['status'] === 'estimated'));
        $status = match (true) {
            $estimated === [] => 'potential_unproven',
            count($estimated) > 1 => 'multiple_experiments',
            $estimated[0]['incremental'] === null => 'incremental_conversion_only',
            default => 'incremental_estimated',
        };
        $labels = [
            'potential_unproven' => 'Oportunidad potencial, atribución no demostrada',
            'multiple_experiments' => 'Varios experimentos u horizontes: no son aditivos',
            'incremental_conversion_only' => 'Incremento de conversión estimado; contribución no estimable',
            'incremental_estimated' => 'Incremento de contribución estimado por intención de tratamiento (no causalidad demostrada)',
        ];
        if (! $seeExperiment) {
            // Sin información del control ni del experimento: solo el estado. El detalle (muestras, tasas, incremento) es de quien puede aprobar.
            return $base + ['status' => $status, 'label' => $labels[$status], 'value' => null,
                'message' => 'El detalle del análisis por grupos solo está disponible para quien puede aprobar (decisions.approve).'];
        }
        if ($status === 'potential_unproven') {
            $statuses = array_values(array_unique(array_map(fn (array $e) => $e['status'], $analysed)));

            return $base + [
                'status' => $status, 'label' => $labels[$status], 'value' => null,
                'reasons' => $statuses === [] ? ['no_control_group'] : $statuses,
                'message' => 'Sin un grupo de control analizado por intención de tratamiento (con muestra suficiente) no hay base para afirmar un beneficio: el ROI no se calcula ni se muestra como probado.',
                'associated_note' => 'La contribución asociada temporalmente a acciones (summary.associated) NO es beneficio atribuible.',
            ];
        }
        if ($status === 'multiple_experiments') {
            return $base + [
                'status' => $status, 'label' => $labels[$status], 'value' => null, 'reasons' => ['experiments_not_additive'],
                'message' => 'Hay más de un experimento u horizonte con estimación; los clientes se solapan entre horizontes y sumarlos contaría dos veces. Filtre por experiment_key y horizon_days.',
                'experiments' => array_map(fn (array $e) => $e['experiment_key'].'|'.$e['horizon_days'], $estimated),
            ];
        }
        $e = $estimated[0];
        $cost = $e['contact_cost'];
        $roi = $base + [
            'status' => $status, 'label' => $labels[$status],
            'experiment_key' => $e['experiment_key'], 'horizon_days' => $e['horizon_days'], 'confidence_level' => $e['confidence_level'],
            'conversion' => $e['conversion'], 'sample' => $e['sample'], 'sample_ratio' => $e['sample_ratio'],
            'incremental_contribution' => $e['incremental'], 'contact_cost' => $e['contact_cost'],
            'net_incremental_contribution' => null, 'roi_ratio' => null, 'net_after_platform_usage' => null, 'reasons' => [],
        ];
        if (($e['sample_ratio']['status'] ?? null) === 'mismatch') {
            $roi['reasons'][] = 'sample_ratio_mismatch';
        }
        if (($e['contribution']['tail_diagnostic']['heavy_tailed'] ?? false) === true) {
            $roi['reasons'][] = 'heavy_tailed_contribution';
        }
        if ($status === 'incremental_conversion_only') {
            $roi['reasons'][] = $e['contribution']['reason'] ?? 'contribution_not_estimable';
            $roi['message'] = 'Hay estimación de la tasa de conversión, pero no de la contribución (costo no confiable, pocos compradores por grupo, varianza nula o población insuficiente): no se publica una cifra monetaria.';

            return $roi;
        }
        if ($cost['actions_cost_unknown'] > 0) {
            $roi['reasons'][] = 'contact_cost_unknown';
            $roi['message'] = 'Hay acciones de contacto sin costo configurado: el neto no se calcula (un costo desconocido no es cero).';

            return $roi;
        }
        $inc = $e['incremental'];
        $net = ['estimate' => round($inc['contribution'] - $cost['total'], 2), 'ci_low' => round($inc['ci_low'] - $cost['total'], 2), 'ci_high' => round($inc['ci_high'] - $cost['total'], 2)];
        $net['significant'] = $net['ci_low'] > 0 || $net['ci_high'] < 0;
        $roi['net_incremental_contribution'] = $net;
        if ($cost['total'] > 0) {
            $roi['roi_ratio'] = ['estimate' => round($net['estimate'] / $cost['total'], 4), 'ci_low' => round($net['ci_low'] / $cost['total'], 4), 'ci_high' => round($net['ci_high'] / $cost['total'], 4)];
        } else {
            $roi['reasons'][] = 'no_contact_cost_registered';
        }
        if ($usage['total_cost'] !== null) {
            $roi['net_after_platform_usage'] = ['estimate' => round($net['estimate'] - $usage['total_cost'], 2), 'ci_low' => round($net['ci_low'] - $usage['total_cost'], 2), 'ci_high' => round($net['ci_high'] - $usage['total_cost'], 2),
                'platform_usage_cost' => $usage['total_cost']];
        } else {
            $roi['reasons'][] = 'platform_usage_not_monetized';
        }
        $roi['message'] = $net['significant']
            ? 'El intervalo (bootstrap percentil) del incremento neto excluye 0. Es una estimación por intención de tratamiento, no causalidad demostrada.'
            : 'El intervalo (bootstrap percentil) del incremento neto incluye 0: no se puede afirmar un beneficio distinto de cero con esta muestra.';

        return $roi;
    }

    /**
     * @param  array<string, array<string, mixed>>  $groups
     * @return list<array<string, mixed>>
     */
    private function breakdown(int $tenantId, array $groups, string $groupBy, bool $seeExperiment): array
    {
        $segments = $groupBy === 'segment'
            ? CustomerSegment::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereIn('id', array_values(array_filter(array_keys($groups), 'is_numeric')))->get(['id', 'code', 'name'])->keyBy('id')
            : collect();
        ksort($groups, SORT_NATURAL);
        $rows = [];
        foreach ($groups as $key => $a) {
            $label = match ($groupBy) {
                'segment' => $key === 'none' ? 'Sin segmento' : ($segments->get((int) $key)?->name ?? 'Segmento '.$key),
                'owner' => $key === 'unassigned' ? 'Sin responsable' : 'Usuario '.$key,
                'horizon' => $key.' días',
                default => $key,
            };
            $rows[] = ['key' => (string) $key, 'label' => $label] + $this->present($a, $seeExperiment) + ['contact_cost' => $this->contactCosts($a)];
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function method(OutcomeSettings $settings): array
    {
        return [
            'id' => self::METHOD_ID,
            'version' => $settings->methodVersion(),
            'summary' => 'Cada recomendación se mide en su ventana fija (as_of + 1 … as_of + horizonte) una vez cerrada más un margen de espera, leyendo las ventas tal como se conocían a ese corte. '
                .'La contribución usa el costo unitario confiable (W5-B); sin él es NULL. El incremento solo se estima con un experimento de control analizado por intención de tratamiento.',
            'classification' => OutcomeVocabulary::LABELS,
            'settings' => $settings->toArray(),
            'estimators' => [
                'conversion' => 'Diferencia de tasas tratamiento − control, intervalo de Newcombe (Wilson híbrido)',
                'contribution' => 'Diferencia de medias por unidad (con ceros), intervalo percentil bootstrap con semilla registrada (Welch solo como diagnóstico)',
            ],
            'causally_attributable' => 'No se produce en esta versión (diseño pre-registrado DISEÑADO; ver OUTCOME_MEASUREMENT.md).',
        ];
    }

    /**
     * Los límites del método. Los que hablan del experimento (grupos, control, análisis por intención de tratamiento) solo se entregan a quien puede aprobar.
     *
     * @return list<string>
     */
    public static function limitations(OutcomeSettings $settings, bool $seeExperiment = true): array
    {
        $general = [
            'Sin una comparación con un grupo de control no hay beneficio atribuible: solo oportunidad potencial y observación. Una compra posterior a una acción es asociación temporal, no causa.',
            'Sesgo de selección: atendidas vs. no atendidas NO son comparables (se atiende a los casos más fáciles).',
            'Los importes observados y asociados se deduplican por línea de venta; la suma por recomendación (per_recommendation) y la oportunidad potencial NO son aditivas entre tipos, horizontes ni ventanas solapadas.',
            "Documentos de venta que llegan después del cierre de ventana + {$settings->lagDays()} día(s) no entran en la medición (se cuentan aparte, en evidence.source.late_lines_not_counted). El margen es provisional: a aprobar en el piloto.",
            'La contribución exige costo unitario confiable (W5-B). Donde no lo hay es NULL con su motivo.',
            'El uso de la plataforma (tenant_usage_records) son cantidades; solo se convierte en dinero con los precios que la empresa configure. Sin precios no se inventa un costo.',
            'Todos los umbrales (margen de espera, tamaño mínimo por grupo, compradores mínimos, nivel de confianza) son provisionales: a aprobar en el piloto. Esta versión no contiene resultados de clientes reales: requiere piloto (BLOQUEADO).',
        ];
        $experiment = [
            'Análisis por intención de tratamiento: se compara el grupo ASIGNADO, se haya contactado o no. Con incumplimiento (asignados que no se contactaron) el efecto del contacto efectivo es mayor que el estimado.',
            'Un control con acción registrada se informa (contaminación) pero sigue en su grupo; contactos de un vendedor que alcancen a clientes de control no se detectan salvo que queden registrados.',
            'Un cliente entra una sola vez por experimento y horizonte (con su primera cohorte) para que las unidades sean independientes; las cohortes siguientes del mismo cliente no suman al análisis. Las unidades descartadas por grupo se informan (dropped_units) y el cociente de muestras observado se compara con la fracción de control registrada (sample_ratio).',
            'En el ITT las unidades sin costo confiable salen del análisis de contribución (no del de conversión).',
            'El intervalo monetario es un bootstrap percentil con semilla registrada y exige un mínimo de compradores por grupo; la cobertura real de un intervalo percentil con contribución cero-inflada y de cola pesada es inferior a la nominal con muestras pequeñas (simulación en OUTCOME_MEASUREMENT.md).',
        ];

        return $seeExperiment ? array_merge(array_slice($general, 0, 1), $experiment, array_slice($general, 1)) : $general;
    }
}
