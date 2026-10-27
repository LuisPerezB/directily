<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Core;

use App\Http\Controllers\Controller;
use App\Models\SupplierLeadTime;
use App\Support\Tenancy\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Plazos de abastecimiento. Prioridad de resolución en el motor:
 * producto+sucursal > producto > proveedor > tenants.settings.default_lead_time_days.
 */
final class LeadTimeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['product_id' => ['sometimes', 'integer'], 'supplier_id' => ['sometimes', 'integer'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $query = SupplierLeadTime::query()->with(['product:id,sku,name', 'supplier:id,code,name', 'branch:id,code,name'])->orderByDesc('id');
        foreach (['product_id', 'supplier_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, (int) $request->input($filter));
            }
        }
        $page = $query->paginate((int) $request->input('per_page', 50));

        return response()->json([
            'data' => $page->getCollection()->map(fn (SupplierLeadTime $l) => $this->present($l)),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['nullable', 'integer', 'required_without:supplier_id', TenantRule::exists('products')],
            'supplier_id' => ['nullable', 'integer', 'required_without:product_id', TenantRule::exists('suppliers')],
            'branch_id' => ['nullable', 'integer', TenantRule::exists('branches')],
            'lead_time_days' => ['required', 'integer', 'min:1', 'max:365'],
            'lead_time_std_days' => ['nullable', 'numeric', 'min:0', 'max:180'],
        ]);
        // Un único plazo vigente por combinación (producto, proveedor, sucursal).
        $leadTime = SupplierLeadTime::query()->updateOrCreate(
            ['product_id' => $data['product_id'] ?? null, 'supplier_id' => $data['supplier_id'] ?? null, 'branch_id' => $data['branch_id'] ?? null],
            ['lead_time_days' => $data['lead_time_days'], 'lead_time_std_days' => $data['lead_time_std_days'] ?? null, 'source' => 'manual', 'valid_from' => now()->toDateString()],
        );
        $leadTime->refresh()->load(['product:id,sku,name', 'supplier:id,code,name', 'branch:id,code,name']);

        return response()->json(['data' => $this->present($leadTime)], 201);
    }

    public function destroy(SupplierLeadTime $leadTime): Response
    {
        $leadTime->delete();

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function present(SupplierLeadTime $l): array
    {
        return [
            'id' => $l->id,
            'product_id' => $l->product_id,
            'supplier_id' => $l->supplier_id,
            'branch_id' => $l->branch_id,
            'product' => $l->product ? ['id' => $l->product->id, 'sku' => $l->product->sku, 'name' => $l->product->name] : null,
            'supplier' => $l->supplier ? ['id' => $l->supplier->id, 'code' => $l->supplier->code, 'name' => $l->supplier->name] : null,
            'branch' => $l->branch ? ['id' => $l->branch->id, 'code' => $l->branch->code, 'name' => $l->branch->name] : null,
            'lead_time_days' => $l->lead_time_days,
            'lead_time_std_days' => $l->lead_time_std_days,
            'source' => $l->source,
            'valid_from' => $l->valid_from?->toDateString(),
        ];
    }
}
