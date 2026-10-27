<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Core;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveTenant;
use App\Http\Requests\Core\SalesBatchRequest;
use App\Models\PersonalAccessToken;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\Audit\AuditLogger;
use App\Services\Sales\SalesBatchService;
use App\Services\Sales\SalesQuery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SaleController extends Controller
{
    public function __construct(private readonly TenantContext $context, private readonly AuditLogger $audit)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
            'branch_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', Rule::in(['completed', 'voided', 'pending'])],
            'document_type' => ['sometimes', Rule::in(['invoice', 'sales_note', 'credit_note', 'other'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = Sale::query()->with('branch:id,code,name')->withCount('items')
            ->orderByDesc('sale_date')->orderByDesc('id');
        if (isset($filters['date_from'])) {
            $query->where('sale_date', '>=', $filters['date_from']);
        }
        if (isset($filters['date_to'])) {
            $query->where('sale_date', '<=', $filters['date_to']);
        }
        foreach (['branch_id', 'status', 'document_type'] as $f) {
            if (isset($filters[$f])) {
                $query->where($f, $filters[$f]);
            }
        }
        if (! empty($filters['search'])) {
            $query->where('document_number', 'ilike', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['search']).'%');
        }
        $page = $query->paginate((int) ($filters['per_page'] ?? 25));

        return response()->json([
            'data' => $page->getCollection()->map(fn (Sale $s) => $this->present($s) + ['items_count' => (int) $s->items_count]),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(Sale $sale): JsonResponse
    {
        $sale->load(['branch:id,code,name', 'items.product:id,sku,name']);

        return response()->json(['data' => $this->present($sale) + [
            'subtotal' => $sale->subtotal, 'discount_total' => $sale->discount_total, 'tax_total' => $sale->tax_total,
            'channel' => $sale->channel, 'currency' => $sale->currency,
            'items' => $sale->items->map(fn (SaleItem $i) => [
                'line_number' => $i->line_number,
                'product' => $i->product ? ['id' => $i->product->id, 'sku' => $i->product->sku, 'name' => $i->product->name] : null,
                'quantity' => $i->quantity, 'unit_price' => $i->unit_price, 'discount_amount' => $i->discount_amount,
                'net_amount' => $i->net_amount, 'tax_amount' => $i->tax_amount, 'unit_cost' => $i->unit_cost, 'is_return' => $i->is_return,
            ]),
        ]]);
    }

    /** Resumen agregado en SQL (excluye anuladas y devoluciones; devoluciones aparte). */
    public function summary(Request $request): JsonResponse
    {
        $tenant = $this->context->require();
        $filters = $request->validate([
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
            'branch_id' => ['sometimes', 'integer'],
            'category_id' => ['sometimes', 'integer'],
            'product_id' => ['sometimes', 'integer'],
            'granularity' => ['sometimes', Rule::in(['day', 'week', 'month'])],
        ]);
        $granularity = $filters['granularity'] ?? 'day';
        $to = $filters['date_to'] ?? now($tenant->timezone)->toDateString();
        $from = $filters['date_from'] ?? now($tenant->timezone)->subDays(89)->toDateString();
        $period = match ($granularity) {
            'week' => "to_char(date_trunc('week', si.sale_date), 'YYYY-MM-DD')",
            'month' => "to_char(date_trunc('month', si.sale_date), 'YYYY-MM-DD')",
            default => "to_char(si.sale_date, 'YYYY-MM-DD')",
        };
        $apply = function ($q) use ($filters, $from, $to) {
            $q->whereBetween('si.sale_date', [$from, $to]);
            if (isset($filters['branch_id'])) {
                $q->where('si.branch_id', (int) $filters['branch_id']);
            }
            if (isset($filters['product_id'])) {
                $q->where('si.product_id', (int) $filters['product_id']);
            }
            if (isset($filters['category_id'])) {
                $q->join('products as p', 'p.id', '=', 'si.product_id')->where('p.category_id', (int) $filters['category_id']);
            }

            return $q;
        };

        $rows = $apply(SalesQuery::validItems($tenant->id))
            ->selectRaw("{$period} AS period, SUM(si.quantity) AS units, SUM(si.net_amount) AS revenue, COUNT(DISTINCT si.sale_id) AS documents")
            ->groupByRaw($period)->orderByRaw($period)->get();
        $returns = (float) $apply(SalesQuery::returnItems($tenant->id))->sum('si.quantity');

        $series = $rows->map(fn ($r) => ['period' => $r->period, 'units' => round((float) $r->units, 4), 'revenue' => round((float) $r->revenue, 2), 'documents' => (int) $r->documents]);

        return response()->json(['data' => [
            'date_from' => $from, 'date_to' => $to, 'granularity' => $granularity,
            'series' => $series,
            'totals' => [
                'units' => round($series->sum('units'), 4),
                'revenue' => round($series->sum('revenue'), 2),
                'documents' => (int) $apply(SalesQuery::validItems($tenant->id))->distinct()->count('si.sale_id'),
                'returns_units' => round($returns, 4),
            ],
        ]]);
    }

    public function batch(SalesBatchRequest $request, SalesBatchService $service): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();
        $dataSourceId = null;
        if ($token instanceof PersonalAccessToken && $token->exists && str_starts_with((string) $token->name, 'datasource:')) {
            $dataSourceId = (int) substr((string) $token->name, strlen('datasource:'));
        }
        $result = $service->ingest($this->context->require(), $request->validated('documents'), $dataSourceId, $request->validated('options', []));
        $this->audit->log('sales.batch', null, ['documents' => count($request->validated('documents')), 'created' => $result['created'], 'updated' => $result['updated'], 'rejected' => $result['rejected']]);
        if ($dataSourceId !== null) {
            \Illuminate\Support\Facades\DB::table('data_sources')->where('tenant_id', $this->context->id())->where('id', $dataSourceId)
                ->update(['last_synced_at' => now(), 'status' => 'active', 'last_error' => null]);
        }

        return response()->json(['data' => $result], $result['errors'] !== [] && ($result['created'] + $result['updated'] + $result['unchanged']) === 0 ? 422 : 200);
    }

    /** @return array<string, mixed> */
    private function present(Sale $s): array
    {
        return [
            'id' => $s->id,
            'document_type' => $s->document_type,
            'document_number' => $s->document_number,
            'status' => $s->status,
            'issued_at' => $s->issued_at?->toIso8601String(),
            'sale_date' => $s->sale_date?->toDateString(),
            'branch' => $s->branch ? ['id' => $s->branch->id, 'code' => $s->branch->code, 'name' => $s->branch->name] : null,
            'total' => $s->total,
        ];
    }
}
