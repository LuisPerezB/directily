<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Customer;

use App\Http\Requests\Customer\CustomerReconciliationRequest;
use App\Models\CoreRun;
use App\Models\CustomerReconciliation;
use App\Services\Customer\CustomerEnvelope;
use App\Services\Customer\CustomerRunService;
use Illuminate\Http\JsonResponse;

/**
 * Reconciliación por producto: demanda agregada vigente = compradores modelados + comprometido fuera del modelo + residual.
 * Las dos vistas (agregada y por comprador) NO se suman; si Σ compradores > agregado se informa la inconsistencia sin alterar
 * las probabilidades individuales. Todas las cantidades están en la unidad del producto.
 */
class CustomerReconciliationController extends CustomerController
{
    public const POLICY = 'agregado = compradores modelados (Σ max(esperanza, comprometido)) + comprometido fuera del modelo + residual (anónimos, '
        .'compradores nuevos y población no modelada). El comprometido no se suma a la esperanza. Si compradores > agregado: status «inconsistent», '
        .'residual 0 y exceso informado; las probabilidades individuales no se modifican.';

    public function index(CustomerReconciliationRequest $request, CustomerRunService $service): JsonResponse
    {
        $tenant = $this->tenant();
        $horizon = $this->horizon($request);
        $run = $request->filled('run_id')
            ? CoreRun::query()->where('core', 'customer')->where('operation', 'predict')->where('status', 'completed')->findOrFail((int) $request->integer('run_id'))
            : $service->latestPrediction();
        if ($run === null) {
            return response()->json(['data' => [], 'summary' => (object) [], 'policy' => self::POLICY,
                'meta' => ['current_page' => 1, 'per_page' => 25, 'total' => 0, 'last_page' => 1],
                'envelope' => CustomerEnvelope::missing($tenant, 'forecast', 'Aún no hay una predicción de comportamiento de compra completada.')]);
        }
        $base = CustomerReconciliation::query()->where('core_run_id', $run->id)->where('horizon_days', $horizon)
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', (int) $request->integer('product_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', (string) $request->query('status')));
        $summary = (clone $base)->reorder()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
        $page = (clone $base)->with('product:id,sku,name,unit')->orderByRaw("case status when 'inconsistent' then 0 else 1 end")
            ->orderBy('product_id')->paginate($this->perPage($request));
        $rows = collect($page->items())->map(fn (CustomerReconciliation $r) => [
            'product' => ['id' => (int) $r->product_id, 'sku' => $r->product?->sku, 'name' => $r->product?->name, 'unit' => $r->product?->unit],
            'horizon_days' => (int) $r->horizon_days, 'status' => $r->status, 'severity' => $r->severity,
            'aggregate_quantity' => $r->aggregate_quantity, 'aggregate_days' => $r->aggregate_days,
            'buyers_total_quantity' => $r->buyers_total_quantity, 'buyers_committed_quantity' => $r->buyers_committed_quantity,
            'buyers_incremental_quantity' => $r->buyers_incremental_quantity, 'buyers_expected_quantity' => $r->buyers_expected_quantity,
            'n_buyers' => (int) $r->n_buyers, 'committed_outside_model' => $r->committed_outside_model,
            'residual_quantity' => $r->residual_quantity, 'residual_share' => $r->residual_share,
            'excess_quantity' => $r->excess_quantity, 'excess_ratio' => $r->excess_ratio,
        ])->all();

        return response()->json([
            'data' => $rows,
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
            'summary' => ['horizon_days' => $horizon, 'status_counts' => $summary],
            'policy' => self::POLICY,
            'envelope' => CustomerEnvelope::forRun($run, $tenant, $horizon, ['entity_keys' => ['product_id'], 'result_type' => 'forecast']),
        ]);
    }
}
