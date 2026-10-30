<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\DataCore;

use App\Http\Controllers\Api\DataCore\Concerns\ImportsCanonicalRows;
use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Services\Imports\ImportEntity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Almacenes por sucursal (consulta y alta/actualización por el importador). */
final class WarehouseController extends Controller
{
    use ImportsCanonicalRows;

    public function index(): JsonResponse
    {
        return response()->json(['data' => Warehouse::query()->with('branch:id,code,name')->orderBy('code')->get()->map(fn (Warehouse $w) => $this->present($w))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50'], 'name' => ['required', 'string', 'max:150'], 'branch_code' => ['required', 'string', 'max:50'],
            'storage_capacity' => ['sometimes', 'nullable', 'numeric', 'min:0'], 'capacity_unit' => ['sometimes', 'nullable', 'string', 'max:20'], 'is_active' => ['sometimes', 'boolean'],
        ]);
        $headers = ['code', 'name', 'branch_code', 'storage_capacity', 'capacity_unit', 'is_active'];
        $this->importRows(ImportEntity::Warehouses, $headers, [array_map(fn (string $h) => $data[$h] ?? null, $headers)]);
        $warehouse = Warehouse::query()->with('branch:id,code,name')->whereRaw('upper(code) = ?', [mb_strtoupper($data['code'])])->firstOrFail();

        return response()->json(['data' => $this->present($warehouse)], 201);
    }

    /** @return array<string, mixed> */
    private function present(Warehouse $w): array
    {
        return [
            'id' => $w->id, 'code' => $w->code, 'name' => $w->name, 'branch' => $w->branch ? ['id' => $w->branch->id, 'code' => $w->branch->code, 'name' => $w->branch->name] : null,
            'storage_capacity' => $w->storage_capacity, 'capacity_unit' => $w->capacity_unit, 'is_active' => $w->is_active,
        ];
    }
}
