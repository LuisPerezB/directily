<?php

declare(strict_types=1);

namespace App\Services\Radar;

use App\Models\CoreRun;
use App\Models\Customer;
use App\Models\CustomerActivity;
use App\Models\CustomerPrediction;
use App\Models\CustomerProductRanking;
use App\Models\DecisionEvent;
use App\Models\DecisionRecommendation;
use App\Services\Analytics\Support\SalesQuery;
use App\Services\Customer\CustomerEnvelope;
use App\Services\Customer\CustomerRunService;
use App\Services\Customer\CustomerS05Presenter;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ficha comercial de un comprador (V2.5 · W5-A; V25-AGD-010 a -013): demanda esperada por horizonte, productos habituales y probables, y la ficha con
 * compras, frecuencia, SKU habituales, evolución mensual, ventana futura y seguimiento. Lee lo que el Customer Core persistió y las ventas vigentes; no
 * recalcula ninguna predicción.
 *
 * Privacidad: solo el código del comprador, su tipo, segmento y zona; el nombre sigue cifrado y no sale por aquí. Los documentos de venta se muestran por tipo,
 * número, fecha e importes (referencias del ERP, no datos personales).
 * Honestidad: lo que no se estima es `null` (nunca cero inventado); los meses sin compras SÍ salen con cero porque ahí el cero es un hecho de las ventas.
 */
final class CustomerCommercialProfile
{
    /** Meses de evolución y días de historia para los productos habituales. */
    public const EVOLUTION_MONTHS = 12;

    public const HABITUAL_DAYS = 365;

    public function __construct(
        private readonly CustomerRunService $runs,
        private readonly OpportunityPresenter $presenter,
        private readonly TenantContext $context,
    ) {}

    /** Estado de la predicción del comprador (misma semántica que la ficha del Customer Core). */
    private function status(Customer $customer, ?CoreRun $run, bool $hasActivity, bool $hasPredictions): array
    {
        if ($run === null) {
            return ['no_prediction_run', 'Todavía no hay una predicción de comportamiento de compra.'];
        }
        if ($customer->is_generic) {
            return ['no_individual_prediction', 'Código genérico (consumidor final): no representa un comprador recurrente; su demanda queda en el residual de la reconciliación.'];
        }
        if (! $hasActivity) {
            return ['no_individual_prediction', 'Sin historial de compras suficiente a la fecha: no se inventa una predicción individual.'];
        }
        if (! $hasPredictions) {
            return ['no_candidate_relations', 'El comprador no tiene relaciones comprador × producto activas dentro de la recencia máxima.'];
        }

        return ['predicted', null];
    }

    /** @return array<string, mixed> */
    private function customerBlock(Customer $customer): array
    {
        $segment = $customer->segment;

        return [
            'id' => (int) $customer->id, 'code' => $customer->external_code, 'type' => $customer->customer_type, 'is_generic' => (bool) $customer->is_generic,
            'zone' => $customer->commercial_zone, 'segment' => $segment ? ['id' => (int) $segment->id, 'code' => $segment->code, 'name' => $segment->name] : null,
        ];
    }

    public function load(int $id): Customer
    {
        return Customer::query()->with('segment:id,code,name')->findOrFail($id);
    }

    // ── Demanda ───────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Demanda esperada del comprador en un horizonte: suma, sobre sus relaciones comprador × producto, de max(esperanza, comprometido) con el desglose
     * comprometido / incremental (V25-AGD-012). Coincide con la suma de GET /customer/predictions?customer_id=.
     *
     * @return array<string, mixed>
     */
    public function demand(Customer $customer, ?CoreRun $run, int $horizon): array
    {
        $tenant = $this->context->require();
        $block = ['customer' => $this->customerBlock($customer), 'horizon_days' => $horizon];
        if ($run === null) {
            return ['data' => $block + $this->emptyDemand('no_prediction_run', 'Todavía no hay una predicción de comportamiento de compra.'), 'envelope' => null];
        }
        $base = fn () => CustomerPrediction::query()->where('core_run_id', $run->id)->where('customer_id', $customer->id)->where('horizon_days', $horizon);
        $agg = $base()->toBase()->selectRaw(
            'COUNT(*) AS pairs, SUM(expected_quantity) AS expected_quantity, SUM(committed_quantity) AS committed_quantity, SUM(incremental_quantity) AS incremental_quantity, '
            .'SUM(total_view_quantity) AS total_view_quantity, SUM(expected_amount) AS expected_amount, COUNT(expected_units) AS s05_pairs, SUM(expected_units) AS expected_units, '
            .'SUM(expected_orders) AS expected_orders, MAX(currency) AS currency'
        )->first();
        $pairs = (int) ($agg->pairs ?? 0);
        $hasActivity = CustomerActivity::query()->where('core_run_id', $run->id)->where('customer_id', $customer->id)->exists();
        [$status, $fallback] = $this->status($customer, $run, $hasActivity, $pairs > 0);
        $envelope = CustomerEnvelope::forRun($run, $tenant, $horizon);

        if ($pairs === 0) {
            return ['data' => $block + $this->emptyDemand($status, $fallback), 'envelope' => $envelope];
        }
        $products = $base()->join('products', fn ($j) => $j->on('products.id', '=', 'customer_predictions.product_id')->on('products.tenant_id', '=', 'customer_predictions.tenant_id'))
            ->toBase()->orderByDesc('customer_predictions.total_view_quantity')->orderBy('customer_predictions.product_id')
            ->get(['customer_predictions.product_id', 'products.sku', 'products.name', 'products.unit', 'customer_predictions.expected_quantity', 'customer_predictions.committed_quantity',
                'customer_predictions.incremental_quantity', 'customer_predictions.total_view_quantity', 'customer_predictions.expected_units', 'customer_predictions.expected_orders']);
        $s05Pairs = (int) $agg->s05_pairs;
        $num = fn ($v) => $v === null ? null : round((float) $v, 4);

        return ['data' => $block + [
            'status' => $status, 'fallback' => $fallback, 'as_of' => $run->as_of?->toDateString(), 'pairs' => $pairs,
            'total_view_quantity' => $num($agg->total_view_quantity),
            'breakdown' => ['expected_quantity' => $num($agg->expected_quantity), 'committed_quantity' => $num($agg->committed_quantity), 'incremental_quantity' => $num($agg->incremental_quantity)],
            's05' => [
                'pairs_estimated' => $s05Pairs, 'pairs_without_estimate' => $pairs - $s05Pairs,
                'expected_orders' => $s05Pairs > 0 ? $num($agg->expected_orders) : null, 'expected_units' => $s05Pairs > 0 ? $num($agg->expected_units) : null,
            ],
            'expected_amount' => ['value' => $num($agg->expected_amount), 'currency' => $agg->currency ?? ($tenant->currency ?: 'USD'), 'basis' => 'supuesto: esperanza × último precio neto de cada relación; no es una venta confirmada'],
            'products' => $products->map(fn ($p) => [
                'product' => ['id' => (int) $p->product_id, 'sku' => $p->sku, 'name' => $p->name, 'unit' => $p->unit],
                'total_view_quantity' => $num($p->total_view_quantity), 'expected_quantity' => $num($p->expected_quantity), 'committed_quantity' => $num($p->committed_quantity),
                'incremental_quantity' => $num($p->incremental_quantity), 'expected_units' => $num($p->expected_units), 'expected_orders' => $num($p->expected_orders),
            ])->all(),
            'definition' => 'Σ por producto de max(esperanza, comprometido): la esperanza no se suma al comprometido (CustomerEnvelope::DEFINITIONS.committed_quantity).',
        ], 'envelope' => $envelope];
    }

    /** @return array<string, mixed> */
    private function emptyDemand(string $status, ?string $fallback): array
    {
        return [
            'status' => $status, 'fallback' => $fallback, 'as_of' => null, 'pairs' => 0, 'total_view_quantity' => null,
            'breakdown' => ['expected_quantity' => null, 'committed_quantity' => null, 'incremental_quantity' => null],
            's05' => ['pairs_estimated' => 0, 'pairs_without_estimate' => 0, 'expected_orders' => null, 'expected_units' => null],
            'expected_amount' => null, 'products' => [],
        ];
    }

    // ── Productos ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Productos del comprador: habituales (compras históricas) y probables (ranking del Customer Core con su fuente y la ventana). Cada lista dice su base.
     *
     * @return array<string, mixed>
     */
    public function products(Customer $customer, ?CoreRun $run, int $horizon, ?string $kind, int $limit = 10): array
    {
        $out = [];
        if ($kind === null || $kind === 'habitual') {
            $out['habitual'] = $this->habitual($customer, $this->referenceDate($run), $limit);
        }
        if ($kind === null || $kind === 'probable') {
            $out['probable'] = $this->probable($customer, $run, $horizon, $limit);
        }

        return $out;
    }

    private function referenceDate(?CoreRun $run): string
    {
        $tz = $this->context->require()->timezone ?: 'America/Guayaquil';

        return $run?->as_of?->toDateString() ?? CarbonImmutable::now($tz)->toDateString();
    }

    /** @return array<string, mixed> */
    private function habitual(Customer $customer, string $reference, int $limit): array
    {
        $tid = (int) $this->context->require()->getKey();
        $from = CarbonImmutable::parse($reference)->subDays(self::HABITUAL_DAYS)->toDateString();
        $rows = SalesQuery::validItems($tid)->where('s.customer_id', $customer->id)->whereBetween('si.sale_date', [$from, $reference])
            ->join('products as p', fn ($j) => $j->on('p.id', '=', 'si.product_id')->on('p.tenant_id', '=', 'si.tenant_id'))
            ->selectRaw('si.product_id, p.sku, p.name, p.unit, COUNT(DISTINCT si.sale_date) AS purchase_days, SUM(si.quantity) AS units, SUM(si.net_amount) AS net_amount, '
                .'MIN(si.sale_date) AS first_purchase, MAX(si.sale_date) AS last_purchase')
            ->groupBy('si.product_id', 'p.sku', 'p.name', 'p.unit')
            ->orderByRaw('COUNT(DISTINCT si.sale_date) DESC')->orderByRaw('SUM(si.quantity) DESC')->orderBy('si.product_id')->limit($limit)->get();

        return [
            'basis' => 'purchase_history', 'window' => ['from' => $from, 'to' => $reference, 'days' => self::HABITUAL_DAYS],
            'definition' => 'Productos con más días de compra del comprador en los últimos '.self::HABITUAL_DAYS.' días (ventas válidas, sin devoluciones).',
            'data' => $rows->map(fn ($r) => [
                'product' => ['id' => (int) $r->product_id, 'sku' => $r->sku, 'name' => $r->name, 'unit' => $r->unit],
                'purchase_days' => (int) $r->purchase_days, 'units' => round((float) $r->units, 4), 'avg_units_per_purchase_day' => round((float) $r->units / max(1, (int) $r->purchase_days), 4),
                'net_amount' => round((float) $r->net_amount, 2), 'first_purchase_date' => substr((string) $r->first_purchase, 0, 10), 'last_purchase_date' => substr((string) $r->last_purchase, 0, 10),
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function probable(Customer $customer, ?CoreRun $run, int $horizon, int $limit): array
    {
        if ($run === null) {
            return ['basis' => 'customer_core_ranking', 'status' => 'no_prediction_run', 'ranking_source' => null, 'horizon_days' => $horizon, 'data' => []];
        }
        if (! in_array($horizon, array_map('intval', (array) $run->horizons), true)) {
            // La corrida se abstuvo de ese horizonte: se dice, no se muestra un ranking vacío como si no hubiera relaciones.
            return ['basis' => 'customer_core_ranking', 'status' => 'horizon_not_available', 'ranking_source' => null, 'horizon_days' => $horizon, 'data' => []];
        }
        $ranked = CustomerProductRanking::query()->where('core_run_id', $run->id)->where('customer_id', $customer->id)->where('horizon_days', $horizon)
            ->with('product:id,sku,name,unit')->orderBy('rank')->limit($limit)->get();
        $predictions = $ranked->isEmpty() ? collect() : CustomerPrediction::query()
            ->select(['id', 'product_id', 'expected_orders', 'quantity_per_order', 'expected_units', 'window_p10_days', 'window_p50_days', 'window_p90_days', 'window_status', 'segment'])
            ->where('core_run_id', $run->id)->where('customer_id', $customer->id)->where('horizon_days', $horizon)->whereIn('product_id', $ranked->pluck('product_id')->all())
            ->get()->keyBy('product_id');

        return [
            'basis' => 'customer_core_ranking', 'status' => $ranked->isEmpty() ? 'no_candidate_relations' : 'predicted', 'horizon_days' => $horizon,
            'ranking_source' => $run->model_versions[(string) $horizon]['ranking_source'] ?? null, 'as_of' => $run->as_of?->toDateString(),
            'definition' => CustomerEnvelope::DEFINITIONS['ranking_source'],
            'data' => $ranked->map(fn (CustomerProductRanking $r) => [
                'rank' => (int) $r->rank, 'ranking_source' => $r->ranking_source, 'ranking_score' => $r->ranking_score,
                'product' => ['id' => (int) $r->product_id, 'sku' => $r->product?->sku, 'name' => $r->product?->name, 'unit' => $r->product?->unit],
                'purchase_probability' => $r->purchase_probability, 'expected_quantity' => $r->expected_quantity,
                'expected_amount' => ['value' => $r->expected_amount, 'currency' => $r->currency, 'basis' => 'supuesto, no venta confirmada'],
            ] + ($predictions->has($r->product_id) ? CustomerS05Presenter::fields($predictions->get($r->product_id)) : CustomerS05Presenter::fields((object) []))
            )->all(),
        ];
    }

    // ── Ficha ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Ficha comercial: compras recientes con importes, frecuencia, SKU habituales, evolución mensual, ventana futura de recompra y línea de seguimiento.
     *
     * @return array<string, mixed>
     */
    public function overview(Customer $customer, ?CoreRun $run, int $horizon): array
    {
        $tenant = $this->context->require();
        $tid = (int) $tenant->getKey();
        $reference = $this->referenceDate($run);
        $activity = $run === null ? null : CustomerActivity::query()->where('core_run_id', $run->id)->where('customer_id', $customer->id)->first();
        $hasPredictions = $run !== null && CustomerPrediction::query()->where('core_run_id', $run->id)->where('customer_id', $customer->id)->exists();
        [$status, $fallback] = $this->status($customer, $run, $activity !== null, $hasPredictions);

        $probable = $this->probable($customer, $run, $horizon, 5);

        return ['data' => [
            'customer' => $this->customerBlock($customer),
            'status' => $status, 'fallback' => $fallback, 'as_of' => $run?->as_of?->toDateString(), 'reference_date' => $reference, 'horizon_days' => $horizon,
            'purchases' => $this->purchases($tid, $customer, $reference),
            'frequency' => $activity ? [
                'source' => 'customer_activity', 'last_purchase_date' => $activity->last_purchase_date?->toDateString(), 'days_since_last_purchase' => $activity->days_since_last_purchase,
                'n_purchase_days' => (int) $activity->n_purchase_days, 'purchase_frequency_per_30d' => $activity->purchase_frequency_per_30d,
                'median_interval_days' => $activity->median_interval_days, 'mean_interval_days' => $activity->mean_interval_days,
                'inactivity' => ['status' => $activity->inactivity_status, 'risk' => $activity->inactivity_risk, 'definition' => CustomerEnvelope::DEFINITIONS['inactivity']],
                'behavior_change' => ['status' => $activity->behavior_change, 'frequency_ratio' => $activity->frequency_ratio, 'spend_ratio' => $activity->spend_ratio],
            ] : null,
            'habitual_products' => $this->habitual($customer, $reference, 10),
            'evolution' => $this->evolution($tid, $customer, $reference),
            'next_purchase_window' => [
                'horizon_days' => $horizon, 'ranking_source' => $probable['ranking_source'] ?? null, 'status' => $probable['status'],
                'note' => 'Cuantiles del tiempo hasta la siguiente compra contados desde el corte; nunca un día exacto. Sin estado estimado u overdue no hay cifras.',
                'products' => array_map(fn (array $p) => ['product' => $p['product'], 'purchase_probability' => $p['purchase_probability'], 'expected_orders' => $p['expected_orders'], 'window' => $p['window']], $probable['data']),
            ],
            'follow_up' => $this->followUp($customer),
        ], 'envelope' => $run === null ? null : CustomerEnvelope::forRun($run, $tenant, $horizon)];
    }

    /** @return array<string, mixed> */
    private function purchases(int $tid, Customer $customer, string $reference): array
    {
        $base = fn () => SalesQuery::validItems($tid)->where('s.customer_id', $customer->id);
        $recent = $base()->selectRaw('s.id, s.sale_date, s.document_type, s.document_number, s.currency, COUNT(*) AS lines, SUM(si.quantity) AS units, SUM(si.net_amount) AS net_amount')
            ->groupBy('s.id', 's.sale_date', 's.document_type', 's.document_number', 's.currency')->orderByDesc('s.sale_date')->orderByDesc('s.id')->limit(10)->get();
        $totals = $base()->selectRaw('COUNT(DISTINCT s.id) AS documents, MIN(s.sale_date) AS first_purchase, MAX(s.sale_date) AS last_purchase')->first();
        $from12 = CarbonImmutable::parse($reference)->subMonths(12)->toDateString();
        $year = $base()->whereBetween('si.sale_date', [$from12, $reference])->selectRaw('COUNT(DISTINCT s.id) AS documents, SUM(si.net_amount) AS net_amount')->first();

        return [
            'recent' => $recent->map(fn ($r) => [
                'date' => substr((string) $r->sale_date, 0, 10), 'document_type' => $r->document_type, 'document_number' => $r->document_number,
                'lines' => (int) $r->lines, 'units' => round((float) $r->units, 4), 'net_amount' => round((float) $r->net_amount, 2), 'currency' => $r->currency,
            ])->all(),
            'totals' => [
                'documents' => (int) ($totals->documents ?? 0), 'first_purchase_date' => $totals?->first_purchase ? substr((string) $totals->first_purchase, 0, 10) : null,
                'last_purchase_date' => $totals?->last_purchase ? substr((string) $totals->last_purchase, 0, 10) : null,
                'documents_last_12_months' => (int) ($year->documents ?? 0), 'net_amount_last_12_months' => round((float) ($year->net_amount ?? 0), 2),
            ],
            'definition' => 'Ventas válidas del comprador (documentos completados, sin devoluciones), importes netos sin impuestos.',
        ];
    }

    /** @return array<string, mixed> serie mensual (los meses sin compras salen con cero: ahí el cero es un hecho de las ventas) */
    private function evolution(int $tid, Customer $customer, string $reference): array
    {
        $end = CarbonImmutable::parse($reference)->startOfMonth();
        $start = $end->subMonths(self::EVOLUTION_MONTHS - 1);
        $rows = SalesQuery::validItems($tid)->where('s.customer_id', $customer->id)->whereBetween('si.sale_date', [$start->toDateString(), CarbonImmutable::parse($reference)->toDateString()])
            ->selectRaw("to_char(si.sale_date, 'YYYY-MM') AS month, COUNT(DISTINCT s.id) AS documents, COUNT(DISTINCT si.sale_date) AS purchase_days, SUM(si.quantity) AS units, SUM(si.net_amount) AS net_amount")
            ->groupByRaw("to_char(si.sale_date, 'YYYY-MM')")->get()->keyBy('month');
        $series = [];
        for ($i = 0; $i < self::EVOLUTION_MONTHS; $i++) {
            $m = $start->addMonths($i)->format('Y-m');
            $r = $rows->get($m);
            $series[] = [
                'month' => $m, 'documents' => (int) ($r->documents ?? 0), 'purchase_days' => (int) ($r->purchase_days ?? 0),
                'units' => round((float) ($r->units ?? 0), 4), 'net_amount' => round((float) ($r->net_amount ?? 0), 2),
            ];
        }

        return ['granularity' => 'month', 'months' => self::EVOLUTION_MONTHS, 'series' => $series];
    }

    /** @return array<string, mixed> oportunidades del comprador y su línea de seguimiento (estados heredados y eventos) */
    private function followUp(Customer $customer): array
    {
        $recs = DecisionRecommendation::query()->whereIn('kind', RadarPolicy::KINDS)->where('customer_id', $customer->id)
            ->where(fn (Builder $w) => $w->whereNull('experiment_group')->orWhere('experiment_group', '<>', 'control'))
            ->orderByDesc('id')->limit(20)->get();
        $events = $recs->isEmpty() ? collect() : DecisionEvent::query()->whereIn('recommendation_id', $recs->pluck('id')->all())->where('event', '<>', 'comment')->orderByDesc('id')->limit(50)->get();

        return [
            'opportunities' => $this->presenter->present($recs),
            'events' => $events->map(fn (DecisionEvent $e) => ['recommendation_id' => (int) $e->recommendation_id] + $this->presenter->event($e))->values()->all(),
            'definition' => 'Hasta 20 oportunidades más recientes del comprador y los últimos 50 eventos de seguimiento (inmutables; las del grupo de control no se muestran).',
        ];
    }
}
