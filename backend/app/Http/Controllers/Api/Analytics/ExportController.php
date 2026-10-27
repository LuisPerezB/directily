<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Analytics;

use App\Http\Requests\Analytics\AccuracyRequest;
use App\Http\Requests\Analytics\ForecastListRequest;
use App\Http\Requests\Analytics\RecommendationIndexRequest;
use App\Http\Requests\Analytics\SalesSummaryExportRequest;
use App\Models\ForecastRun;
use App\Models\ModelEvaluation;
use App\Models\Recommendation;
use App\Services\Analytics\HistoryQuery;
use App\Services\Analytics\InventoryQuery;
use App\Services\Analytics\ProductForecastQuery;
use App\Services\Analytics\RunResolver;
use App\Services\Analytics\Support\ForecastNode;
use App\Services\Analytics\Support\Granularity;
use App\Services\Analytics\Support\ModelCatalog;
use App\Services\Analytics\Support\Num;
use App\Services\Analytics\Support\SalesQuery;
use App\Services\Exports\CsvExporter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Exportaciones CSV (requieren la habilidad data.export; quedan auditadas). */
class ExportController extends AnalyticsController
{
    public function __construct(
        private readonly RunResolver $runs,
        private readonly CsvExporter $csv,
    ) {}

    public function forecasts(ForecastListRequest $request, ProductForecastQuery $products): StreamedResponse
    {
        $run = $this->requireRun($request->intOrNull('run_id'));
        $horizon = $request->horizon($this->defaultHorizon());
        $branchId = $request->intOrNull('branch_id');
        $categoryId = $request->intOrNull('category_id');
        $granularity = $request->validated('granularity');
        $delimiter = (string) ($request->validated('delimiter') ?? ',');
        $meta = ['type' => 'forecasts', 'run_id' => $run->id, 'horizon' => $horizon, 'granularity' => $granularity];

        if ($granularity === null) {
            $query = $products->productsQuery($run, $horizon, [
                'branch_id' => $branchId, 'category_id' => $categoryId, 'search' => $request->validated('search'),
            ], (string) ($request->validated('sort') ?? '-forecast_units'));

            return $this->csv->stream(
                "pronostico_productos_v{$run->version}_{$horizon}d.csv",
                ['sku', 'producto', 'categoria', 'unidades_periodo_anterior', 'unidades_pronosticadas', 'variacion_pct', 'tendencia', 'ingresos_estimados', 'version_pronostico', 'fecha_corte'],
                function () use ($query, $run) {
                    foreach ($query->cursor() as $r) {
                        $change = $r->change_pct !== null ? (float) $r->change_pct : null;
                        yield [
                            $r->sku, $r->name, $r->category_name, Num::units($r->history_units), Num::units($r->forecast_units),
                            $change !== null ? round($change * 100, 2) : null,
                            ProductForecastQuery::trend($change, (float) $r->history_units, (float) $r->forecast_units),
                            Num::money($r->forecast_revenue), $run->version, $run->cutoff_date?->toDateString(),
                        ];
                    }
                },
                $delimiter,
                $meta,
            );
        }

        $level = $branchId !== null ? ForecastNode::LEVEL_SERIES : ForecastNode::LEVEL_PRODUCT;
        $trunc = Granularity::truncSql((string) $granularity, 'fv.target_date');
        $tenantId = (int) $run->tenant_id;
        $query = DB::table('forecast_values as fv')
            ->join('products as p', function ($join) use ($tenantId) {
                $join->on('p.id', '=', 'fv.product_id')->where('p.tenant_id', '=', $tenantId);
            })
            ->leftJoin('product_categories as c', function ($join) use ($tenantId) {
                $join->on('c.id', '=', 'p.category_id')->where('c.tenant_id', '=', $tenantId);
            })
            ->where('fv.tenant_id', $tenantId)
            ->where('fv.forecast_run_id', $run->id)
            ->where('fv.level', $level)
            ->where('fv.granularity', 'day')
            ->where('fv.horizon_step', '<=', $horizon)
            ->when($branchId !== null, fn ($q) => $q->where('fv.branch_id', $branchId))
            ->when($categoryId !== null, fn ($q) => $q->where('p.category_id', $categoryId))
            ->selectRaw("p.sku, p.name, c.name AS category_name, {$trunc} AS period, SUM(fv.yhat) AS yhat, SUM(fv.revenue_yhat) AS revenue,
                         MIN(fv.model_family) AS model_family, COUNT(*) AS days")
            ->groupBy('p.sku', 'p.name', 'c.name', DB::raw('4'))
            ->orderBy('p.sku')->orderByRaw('4');

        $daily = $granularity === Granularity::DAY;

        return $this->csv->stream(
            "pronostico_{$granularity}_v{$run->version}_{$horizon}d.csv",
            ['sku', 'producto', 'categoria', 'periodo', 'dias_en_periodo', 'unidades_pronosticadas', 'ingresos_estimados', 'modelo', 'version_pronostico'],
            function () use ($query, $run, $daily) {
                foreach ($query->cursor() as $r) {
                    yield [
                        $r->sku, $r->name, $r->category_name, substr((string) $r->period, 0, 10), $daily ? 1 : (int) $r->days,
                        Num::units($r->yhat), Num::money($r->revenue), ModelCatalog::label($r->model_family), $run->version,
                    ];
                }
            },
            $delimiter,
            $meta,
        );
    }

    public function recommendations(RecommendationIndexRequest $request, InventoryQuery $inventory): StreamedResponse
    {
        $run = $this->requireRun($request->intOrNull('run_id'));
        $filters = $request->filters();

        return $this->csv->stream(
            "recomendaciones_abastecimiento_v{$run->version}.csv",
            ['sku', 'producto', 'sucursal', 'tipo', 'riesgo', 'prob_quiebre', 'dias_cobertura', 'fecha_quiebre_estimada', 'disponible', 'plazo_dias', 'demanda_en_plazo', 'stock_seguridad', 'punto_reorden', 'cantidad_sugerida', 'costo_unitario', 'inversion_estimada', 'estado', 'fecha_inventario'],
            function () use ($inventory, $run, $filters) {
                $query = $inventory->filtered($run, $filters)
                    ->with(['product:id,sku,name', 'branch:id,name'])
                    ->orderByRaw('stockout_probability DESC NULLS LAST')->orderBy('id');
                foreach ($query->lazy(500) as $r) {
                    /** @var Recommendation $r */
                    yield [
                        $r->product?->sku, $r->product?->name, $r->branch?->name, $r->type, $r->risk_level,
                        Num::ratio($r->stockout_probability), Num::units($r->days_of_cover), $r->stockout_date_estimate?->toDateString(),
                        Num::units($r->available), $r->lead_time_days, Num::units($r->demand_lead_time), Num::units($r->safety_stock),
                        Num::units($r->reorder_point), Num::units($r->suggested_qty), Num::money($r->unit_cost),
                        Num::money($r->estimated_investment), $r->status, $r->inventory_snapshot_date?->toDateString(),
                    ];
                }
            },
            (string) ($request->validated('delimiter') ?? ','),
            ['type' => 'recommendations', 'run_id' => $run->id],
        );
    }

    public function accuracy(AccuracyRequest $request): StreamedResponse
    {
        $run = $this->requireRun($request->intOrNull('run_id'));

        return $this->csv->stream(
            "precision_modelos_v{$run->version}.csv",
            ['tipo_evaluacion', 'nivel', 'clave', 'horizonte_dias', 'modelo', 'es_referencia', 'es_seleccionado', 'wape', 'mae', 'rmse', 'sesgo', 'cobertura_80', 'cobertura_95', 'observaciones', 'ventanas'],
            function () use ($run, $request) {
                $query = ModelEvaluation::query()->where('forecast_run_id', $run->id)
                    ->when($request->validated('evaluation_type'), fn ($q, $v) => $q->where('evaluation_type', $v))
                    ->when($request->validated('level'), fn ($q, $v) => $q->where('level', $v))
                    ->orderBy('id');
                foreach ($query->lazyById(1000) as $e) {
                    yield [
                        $e->evaluation_type, $e->level, $e->level_key, $e->horizon_days, ModelCatalog::label($e->model_family),
                        (bool) $e->is_baseline, (bool) $e->is_selected, Num::ratio($e->wape), Num::units($e->mae), Num::units($e->rmse),
                        Num::ratio($e->bias), Num::ratio($e->coverage_80), Num::ratio($e->coverage_95), (int) $e->n_obs, (int) $e->n_windows,
                    ];
                }
            },
            (string) ($request->validated('delimiter') ?? ','),
            ['type' => 'accuracy', 'run_id' => $run->id],
        );
    }

    public function salesSummary(SalesSummaryExportRequest $request, HistoryQuery $history): StreamedResponse
    {
        $tenantId = (int) $this->tenant()->getKey();
        $filters = $request->nodeFilters();
        $node = ForecastNode::fromFilters($filters['branch_id'], $filters['category_id'], $filters['product_id']);
        $granularity = (string) ($request->validated('granularity') ?? Granularity::DAY);
        $last = SalesQuery::lastSaleDate($tenantId);
        $to = CarbonImmutable::parse($request->validated('date_to') ?? $last ?? now()->toDateString());
        $from = CarbonImmutable::parse($request->validated('date_from') ?? $to->subDays(89)->toDateString());
        if ($from->diffInDays($to) > 1830) {
            abort(response()->json(['message' => 'El rango máximo de exportación es de 5 años.', 'errors' => ['date_from' => ['Rango demasiado amplio.']]], 422));
        }

        return $this->csv->stream(
            "resumen_ventas_{$granularity}_{$from->toDateString()}_{$to->toDateString()}.csv",
            ['periodo', 'unidades', 'ingresos_netos', 'quiebre_stock', 'proporcion_sin_stock'],
            function () use ($history, $tenantId, $node, $granularity, $from, $to) {
                foreach ($history->series($tenantId, $node, $granularity, $from, $to) as $p) {
                    yield [$p['date'], $p['units'], $p['revenue'], $p['stockout'], $p['stockout_share']];
                }
            },
            (string) ($request->validated('delimiter') ?? ','),
            ['type' => 'sales_summary', 'granularity' => $granularity, 'node' => $node->key],
        );
    }

    private function requireRun(?int $runId): ForecastRun
    {
        return $this->runs->resolveCompleted($runId)
            ?? throw new NotFoundHttpException('Aún no hay un pronóstico generado para exportar.');
    }
}
