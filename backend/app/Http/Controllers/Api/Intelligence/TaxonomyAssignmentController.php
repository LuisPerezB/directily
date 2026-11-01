<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Intelligence;

use App\Models\ProductTaxonomyAssignment;
use App\Services\Intelligence\TaxonomyService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Asignación producto → nodo de taxonomía con procedencia manual | llm_proposed | verified (V2.1 §24.2; T13; ADR-V25-25). Una propuesta del LLM
 * (que este paquete no genera) queda pendiente de revisión y NUNCA cuenta como verdad hasta que un usuario la verifica. El historial se conserva
 * por vigencia (valid_from / valid_to). El SKU canónico no se sustituye.
 */
final class TaxonomyAssignmentController extends IntelligenceController
{
    public function __construct(private readonly TaxonomyService $taxonomy) {}

    /** GET /intelligence/taxonomy/assignments — data.view */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'product_id' => ['sometimes', 'integer'],
            'taxonomy_node_id' => ['sometimes', 'integer'],
            'provenance' => ['sometimes', Rule::in(ProductTaxonomyAssignment::PROVENANCES)],
            'pending_review' => ['sometimes', 'boolean'],
            'usable' => ['sometimes', 'boolean'],
            'as_of' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);
        $query = ProductTaxonomyAssignment::query()->with('node')->orderBy('product_id')->orderByDesc('valid_from')->orderByDesc('id');
        foreach (['product_id', 'taxonomy_node_id', 'provenance'] as $column) {
            if (isset($f[$column])) {
                $query->where($column, $f[$column]);
            }
        }
        if (! empty($f['pending_review'])) {
            $query->where('provenance', ProductTaxonomyAssignment::PROVENANCE_LLM_PROPOSED);
        }
        if (! empty($f['usable'])) {
            $query->usable();
        }
        if (isset($f['as_of'])) {
            $query->validOn(CarbonImmutable::parse($f['as_of']));
        }

        return $this->paginated($query->paginate((int) ($f['per_page'] ?? 50)), fn (ProductTaxonomyAssignment $a) => $this->taxonomy->presentAssignment($a));
    }

    /** POST /intelligence/taxonomy/assignments — catalog.manage; asignación MANUAL (la propuesta de un LLM no entra por la API pública) */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'min:1'],
            'taxonomy_node_id' => ['required', 'integer', 'min:1'],
            'confidence' => ['sometimes', 'nullable', 'numeric', 'between:0,1'],
            'is_primary' => ['sometimes', 'boolean'],
            'valid_from' => ['sometimes', 'nullable', 'date'],
            'valid_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:valid_from'],
        ]);
        $assignment = $this->taxonomy->assign($request->user(), $data);

        return response()->json(['data' => $this->taxonomy->presentAssignment($assignment->load('node'))], 201);
    }

    /** PATCH /intelligence/taxonomy/assignments/{assignment} — catalog.manage (cierre o ajuste de vigencia, confianza) */
    public function update(Request $request, ProductTaxonomyAssignment $assignment): JsonResponse
    {
        $data = $request->validate([
            'valid_from' => ['sometimes', 'nullable', 'date'],
            'valid_to' => ['sometimes', 'nullable', 'date'],
            'confidence' => ['sometimes', 'nullable', 'numeric', 'between:0,1'],
        ]);

        return response()->json(['data' => $this->taxonomy->presentAssignment($this->taxonomy->updateAssignment($assignment, $data)->load('node'))]);
    }

    /** POST /intelligence/taxonomy/assignments/{assignment}/verify — catalog.manage; una propuesta pasa a `verified` */
    public function verify(Request $request, ProductTaxonomyAssignment $assignment): JsonResponse
    {
        return response()->json(['data' => $this->taxonomy->presentAssignment($this->taxonomy->verify($request->user(), $assignment)->load('node'))]);
    }

    /** DELETE /intelligence/taxonomy/assignments/{assignment} — catalog.manage; descarta una propuesta pendiente (las vigentes se cierran, no se borran) */
    public function destroy(ProductTaxonomyAssignment $assignment): JsonResponse
    {
        $this->taxonomy->rejectProposal($assignment);

        return response()->json(['data' => ['deleted' => true]]);
    }
}
