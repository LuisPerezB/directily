<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\DataCore;

use App\Http\Controllers\Api\DataCore\Concerns\ImportsCanonicalRows;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\UnitConversion;
use App\Services\Audit\AuditLogger;
use App\Services\Imports\ImportEntity;
use App\Services\DataCore\Units\UnitNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Conversiones de unidades declaradas. Sin una conversión declarada, una unidad distinta es un ERROR (nunca se asume). */
final class UnitConversionController extends Controller
{
    use ImportsCanonicalRows;

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['product_id' => ['sometimes', 'integer']]);
        $query = UnitConversion::query()->with('product:id,sku,name')->orderBy('from_unit')->orderBy('to_unit');
        if ($request->filled('product_id')) {
            $query->where(fn ($q) => $q->where('product_id', $request->integer('product_id'))->orWhereNull('product_id'));
        }

        return response()->json(['data' => $query->get()->map(fn (UnitConversion $c) => $this->present($c))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_sku' => ['sometimes', 'nullable', 'string', 'max:100'], 'from_unit' => ['required', 'string', 'max:20'], 'to_unit' => ['required', 'string', 'max:20'],
            'factor' => ['required', 'numeric', 'gt:0'], 'valid_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'source' => ['sometimes', 'nullable', 'string', 'max:30'],
        ]);
        $headers = ['product_sku', 'from_unit', 'to_unit', 'factor', 'valid_from', 'source'];
        $this->importRows(ImportEntity::UnitConversions, $headers, [array_map(fn (string $h) => $data[$h] ?? null, $headers)]);
        $product = isset($data['product_sku']) ? Product::query()->whereRaw('upper(sku) = ?', [mb_strtoupper($data['product_sku'])])->first() : null;
        $conversion = UnitConversion::query()->with('product:id,sku,name')
            ->where('product_id', $product?->id)->where('from_unit', UnitNormalizer::normalize($data['from_unit']))->where('to_unit', UnitNormalizer::normalize($data['to_unit']))
            ->where('valid_from', $data['valid_from'] ?? null)->firstOrFail();

        return response()->json(['data' => $this->present($conversion)], 201);
    }

    public function destroy(UnitConversion $unitConversion): Response
    {
        $this->audit->log('unit_conversion.deleted', $unitConversion, ['from' => $unitConversion->from_unit, 'to' => $unitConversion->to_unit]);
        $unitConversion->delete();

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function present(UnitConversion $c): array
    {
        return [
            'id' => $c->id, 'product' => $c->product ? ['id' => $c->product->id, 'sku' => $c->product->sku, 'name' => $c->product->name] : null,
            'from_unit' => $c->from_unit, 'to_unit' => $c->to_unit, 'factor' => $c->factor, 'valid_from' => $c->valid_from?->toDateString(), 'source' => $c->source,
        ];
    }
}
