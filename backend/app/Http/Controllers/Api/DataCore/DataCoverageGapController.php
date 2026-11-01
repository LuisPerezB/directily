<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\DataCore;

use App\Http\Controllers\Controller;
use App\Http\Requests\DataCore\StoreCoverageGapRequest;
use App\Models\DataCoverageGap;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Huecos internos de cobertura de una fuente (ADR-V25-23): caídas de integración, migraciones u orígenes ausentes. Los días
 * cubiertos por un hueco son `missing_source`, nunca ceros observados. GET: `data.view`; POST/DELETE: `imports.manage`
 * (la misma habilidad que declara la cobertura con PUT /data-coverage). Aislado por empresa (BelongsToTenant).
 */
final class DataCoverageGapController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'entity' => ['sometimes', 'string', Rule::in(DataCoverageGap::ENTITIES)],
            'data_source_id' => ['sometimes', 'integer'],
            'branch_id' => ['sometimes', 'integer'],
            'basis' => ['sometimes', 'string', Rule::in(DataCoverageGap::BASES)],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);
        $query = DataCoverageGap::query()->orderBy('gap_from')->orderBy('id');
        foreach (['entity', 'data_source_id', 'branch_id', 'basis'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        // `from`/`to` devuelven los huecos que se solapan con ese rango.
        if (isset($filters['from'])) {
            $query->where('gap_to', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->where('gap_from', '<=', $filters['to']);
        }
        $page = $query->paginate((int) ($filters['per_page'] ?? 50));

        return response()->json([
            'data' => $page->getCollection()->map(fn (DataCoverageGap $g) => $g->toApi())->values(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function store(StoreCoverageGapRequest $request): JsonResponse
    {
        $data = $request->validated();
        $gap = new DataCoverageGap;
        $gap->forceFill([
            'entity' => $data['entity'],
            'data_source_id' => $data['data_source_id'] ?? null,
            'branch_id' => $data['branch_id'] ?? null,
            'gap_from' => $data['gap_from'],
            'gap_to' => $data['gap_to'],
            'reason' => $data['reason'] ?? 'unknown',
            'basis' => 'declared',
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()?->id,
        ])->save();
        $this->audit->log('data_coverage.gap_declared', $gap, [
            'entity' => $gap->entity, 'gap_from' => $data['gap_from'], 'gap_to' => $data['gap_to'],
            'data_source_id' => $gap->data_source_id, 'branch_id' => $gap->branch_id, 'reason' => $gap->reason,
        ]);

        return response()->json(['data' => $gap->refresh()->toApi()], 201);
    }

    public function destroy(DataCoverageGap $gap): Response
    {
        $this->audit->log('data_coverage.gap_deleted', $gap, [
            'entity' => $gap->entity, 'gap_from' => $gap->gap_from?->toDateString(), 'gap_to' => $gap->gap_to?->toDateString(), 'basis' => $gap->basis,
        ]);
        $gap->delete();

        return response()->noContent();
    }
}
