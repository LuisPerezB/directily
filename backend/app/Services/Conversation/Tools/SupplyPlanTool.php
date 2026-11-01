<?php

declare(strict_types=1);

namespace App\Services\Conversation\Tools;

use App\Models\SupplyPlan;
use App\Services\Supply\SupplyRunService;
use App\Support\Results\ResultEnvelope;
use Illuminate\Support\Facades\DB;

/**
 * Plan de abastecimiento vigente (GET /supply/plans/{id}), solo lectura: sugerencias con proveedor, cantidad, fechas de
 * emisión y recepción y costo; demanda no atendida y restricciones activas. No emite pedidos ni compromete fondos.
 */
final class SupplyPlanTool extends ReadTool
{
    public function __construct(private readonly SupplyRunService $runs) {}

    public function name(): string
    {
        return 'supply_plan';
    }

    public function description(): string
    {
        return 'Plan de abastecimiento vigente ya calculado por el optimizador (solo sugerencias, no pedidos): líneas con proveedor, cantidad, fecha sugerida de emisión, '
            .'recepción prevista y costo; demanda no atendida con su causa; y restricciones activas. Puede filtrarse por producto o por las líneas que se emiten en la '
            .'fecha de corte del plan (que no siempre es hoy: compárela con la fecha de corte de la envoltura).';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'product' => ['type' => 'string', 'maxLength' => 80, 'description' => 'SKU o parte del nombre del producto (opcional).'],
                'actionable_now' => ['type' => 'boolean', 'description' => 'Solo líneas cuya emisión sugerida es la fecha de corte.'],
                'limit' => $this->limitSchema(),
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'product' => ['sometimes', 'string', 'min:1', 'max:80'],
            'actionable_now' => ['sometimes', 'boolean'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('conversation.limits.max_rows_per_tool', 10)],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $run = $this->runs->latestPlan();
        if ($run === null) {
            return ToolResult::noData('Aún no hay un plan de abastecimiento calculado para esta empresa.');
        }
        $envelope = ResultEnvelope::fromCoreRun($run, [
            'entity_keys' => ['branch_id', 'product_id', 'supplier_id'],
            'unit' => 'product_unit',
            'currency' => $context->tenant->currency ?: 'USD',
            'result_type' => 'optimized_plan',
        ]);
        $plan = SupplyPlan::query()->where('core_run_id', $run->id)->first();
        if ($plan === null) {
            return ToolResult::noData('La corrida de abastecimiento no produjo un plan.', $envelope);
        }

        $limit = $this->limit($arguments);
        $product = isset($arguments['product']) ? trim((string) $arguments['product']) : null;
        $term = $product !== null && $product !== '' ? '%'.addcslashes($product, '%_\\').'%' : null;
        $lines = $plan->lines()->with(['product:id,sku,name', 'branch:id,code,name', 'supplier:id,code,name'])
            ->when($term !== null, fn ($q) => $q->whereHas('product', fn ($p) => $p->where(fn ($w) => $w->where('sku', 'ilike', $term)->orWhere('name', 'ilike', $term))))
            ->when((bool) ($arguments['actionable_now'] ?? false), fn ($q) => $q->where('actionable_now', true));
        $totalLines = (clone $lines)->count();
        $lines = $lines->orderBy('order_date')->orderBy('id')->limit($limit)->get();

        $shortfallBase = $plan->shortfalls();
        $byReason = (clone $shortfallBase)->select('reason', DB::raw('COUNT(*) AS n'), DB::raw('SUM(unmet) AS unmet'))->groupBy('reason')->get()
            ->map(fn ($r) => ['reason' => $r->reason, 'periods' => (int) $r->n, 'unmet' => round((float) $r->unmet, 4)])->values()->all();
        $topShortfalls = $shortfallBase->with(['product:id,sku,name', 'branch:id,code,name'])->orderByDesc('unmet')->orderBy('id')->limit($limit)->get();
        $details = (array) $plan->details;

        $data = [
            'plan' => [
                'objective' => $plan->objective, 'coverage_mode' => $plan->coverage_mode, 'solver_status' => $plan->solver_status,
                'is_optimal' => $plan->solver_status === 'optimal', 'mip_gap' => $plan->mip_gap, 'currency' => $plan->currency,
                'total_purchase_cost' => $plan->total_purchase_cost, 'total_holding_cost' => $plan->total_holding_cost,
                'total_shortage_penalty' => $plan->total_shortage_penalty, 'unmet_units_total' => $plan->unmet_units_total,
                'demand_units_total' => $plan->demand_units_total,
                'note' => 'Sugerencias de reposición: Predictily no envía pedidos ni compromete fondos.',
            ],
            'total_lines' => $totalLines,
            'lines' => $lines->map(fn ($l) => [
                'line_type' => $l->line_type,
                'branch' => $l->branch ? ['code' => $l->branch->code, 'name' => $l->branch->name] : null,
                'product' => $l->product ? ['sku' => $l->product->sku, 'name' => $l->product->name] : null,
                'supplier' => $l->supplier ? ['code' => $l->supplier->code, 'name' => $l->supplier->name] : null,
                'quantity' => $l->quantity, 'unit' => $l->unit, 'offer_quantity' => $l->offer_quantity, 'offer_unit' => $l->offer_unit,
                'order_date' => $l->order_date?->toDateString(), 'expected_receipt_date' => $l->expected_receipt_date?->toDateString(),
                'unit_cost' => $l->unit_cost, 'line_cost' => $l->line_cost, 'currency' => $l->currency,
                'actionable_now' => (bool) $l->actionable_now, 'justification' => $l->justification,
            ])->values()->all(),
            'shortfalls' => [
                'by_reason' => $byReason,
                'largest' => $topShortfalls->map(fn ($s) => [
                    'branch' => $s->branch ? ['code' => $s->branch->code, 'name' => $s->branch->name] : null,
                    'product' => $s->product ? ['sku' => $s->product->sku, 'name' => $s->product->name] : null,
                    'period_start' => $s->period_start?->toDateString(), 'period_end' => $s->period_end?->toDateString(),
                    'demand' => $s->demand, 'unmet' => $s->unmet, 'reason' => $s->reason,
                ])->values()->all(),
            ],
            'constraints' => array_slice((array) ($details['constraints'] ?? []), 0, 10),
            'infeasibility' => $details['infeasibility'] ?? null,
            'production_status' => $details['report']['production']['status'] ?? 'not_configured',
        ];

        return ToolResult::ok($data, $envelope);
    }
}
