<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Intelligence;

use App\Models\TaxonomyNode;
use App\Services\Intelligence\Exceptions\PublicCatalogReadOnlyException;
use App\Services\Intelligence\TaxonomyService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Nodos de la taxonomía de la empresa: sector → industria → categoría → subcategoría, con versión y vínculo opcional a `product_categories`
 * (V2.1 §24.2; ADR-V25-25). Los nodos públicos (tenant_id NULL) se leen pero no se escriben. El SKU canónico no se sustituye.
 */
final class TaxonomyNodeController extends IntelligenceController
{
    public function __construct(private readonly TaxonomyService $taxonomy) {}

    /** GET /intelligence/taxonomy/nodes — data.view */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'level' => ['sometimes', Rule::in(TaxonomyNode::LEVELS)],
            'parent_id' => ['sometimes', 'integer'],
            'code' => ['sometimes', 'string', 'max:80'],
            'scope' => ['sometimes', Rule::in(['tenant', 'public'])],
            'current_only' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);
        $query = TaxonomyNode::query()->orderBy('level')->orderBy('code')->orderByDesc('version');
        foreach (['level', 'parent_id', 'code'] as $column) {
            if (isset($f[$column])) {
                $query->where($column, $f[$column]);
            }
        }
        if (($f['scope'] ?? null) === 'public') {
            $query->whereNull('tenant_id');
        } elseif (($f['scope'] ?? null) === 'tenant') {
            $query->whereNotNull('tenant_id');
        }
        if (! empty($f['current_only'])) {
            $today = CarbonImmutable::now()->toDateString();
            $query->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $today));
        }

        return $this->paginated($query->paginate((int) ($f['per_page'] ?? 50)), fn (TaxonomyNode $n) => $this->taxonomy->presentNode($n));
    }

    /** GET /intelligence/taxonomy/nodes/{node} — data.view */
    public function show(TaxonomyNode $node): JsonResponse
    {
        return response()->json(['data' => $this->taxonomy->presentNode($node)]);
    }

    /** POST /intelligence/taxonomy/nodes — catalog.manage */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'level' => ['required', Rule::in(TaxonomyNode::LEVELS)],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.\-]*$/'],
            'name' => ['required', 'string', 'min:2', 'max:200'],
            'valid_from' => ['sometimes', 'nullable', 'date'],
            'valid_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:valid_from'],
            'erp_category_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'attributes' => ['sometimes', 'nullable', 'array'],
        ]);
        $data['attributes'] = $data['attributes'] ?? [];

        return response()->json(['data' => $this->taxonomy->presentNode($this->taxonomy->createNode($data))], 201);
    }

    /** PATCH /intelligence/taxonomy/nodes/{node} — catalog.manage (nombre, vigencia, vínculo con la categoría del ERP, atributos) */
    public function update(Request $request, TaxonomyNode $node): JsonResponse
    {
        $this->assertOwn($node);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:200'],
            'valid_from' => ['sometimes', 'nullable', 'date'],
            'valid_to' => ['sometimes', 'nullable', 'date'],
            'erp_category_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'attributes' => ['sometimes', 'array'],
        ]);

        return response()->json(['data' => $this->taxonomy->presentNode($this->taxonomy->updateNode($node, $data))]);
    }

    /** DELETE /intelligence/taxonomy/nodes/{node} — catalog.manage; solo sin hijos ni productos asignados */
    public function destroy(TaxonomyNode $node): JsonResponse
    {
        $this->assertOwn($node);
        $this->taxonomy->deleteNode($node);

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** POST /intelligence/taxonomy/nodes/{node}/versions — catalog.manage; nueva versión del mismo código (cierra la anterior) */
    public function newVersion(Request $request, TaxonomyNode $node): JsonResponse
    {
        $this->assertOwn($node);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:200'],
            'valid_from' => ['sometimes', 'date'],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'erp_category_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'attributes' => ['sometimes', 'array'],
        ]);

        return response()->json(['data' => $this->taxonomy->presentNode($this->taxonomy->newVersion($node, $data))], 201);
    }

    private function assertOwn(TaxonomyNode $node): void
    {
        if ($node->isPublic()) {
            throw new PublicCatalogReadOnlyException;
        }
    }
}
