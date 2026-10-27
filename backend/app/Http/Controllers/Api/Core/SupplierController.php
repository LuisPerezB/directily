<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Core;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Support\Tenancy\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SupplierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['search' => ['sometimes', 'nullable', 'string', 'max:100'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $query = Supplier::query()->orderBy('name');
        if ($search = $request->input('search')) {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(fn ($q) => $q->where('name', 'ilike', $term)->orWhere('code', 'ilike', $term));
        }
        $page = $query->paginate((int) $request->input('per_page', 25));

        return response()->json([
            'data' => $page->getCollection()->map(fn (Supplier $s) => $this->present($s)),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $supplier = Supplier::query()->create($this->validated($request))->refresh();

        return response()->json(['data' => $this->present($supplier)], 201);
    }

    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        $supplier->fill($this->validated($request, $supplier))->save();

        return response()->json(['data' => $this->present($supplier)]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Supplier $supplier = null): array
    {
        $req = $supplier === null ? 'required' : 'sometimes';

        return $request->validate([
            'code' => [$req, 'string', 'max:50', TenantRule::unique('suppliers', 'code', $supplier?->id)],
            'name' => [$req, 'string', 'max:200'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'regex:/^\d{10}(\d{3})?$/'],
            'country' => ['sometimes', 'string', 'size:2'],
            'default_lead_time_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(Supplier $s): array
    {
        return [
            'id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'tax_id' => $s->tax_id, 'country' => $s->country,
            'default_lead_time_days' => $s->default_lead_time_days, 'is_active' => (bool) $s->is_active,
        ];
    }
}
