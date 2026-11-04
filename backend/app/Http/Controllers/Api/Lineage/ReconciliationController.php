<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Lineage;

use App\Http\Controllers\Controller;
use App\Models\ReconciliationReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Informes de reconciliación de la empresa activa (fuente → Bronze → Silver → Gold): por carga (`import`, `sync`) y por ventana
 * (`window`). Solo lectura (`data.view`). Las capas no disponibles se reportan con su razón: nunca se inventan cifras.
 */
final class ReconciliationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'scope' => ['sometimes', 'string', Rule::in(ReconciliationReport::SCOPES)],
            'entity' => ['sometimes', 'string', 'regex:/^[a-z_]{2,30}\z/'],
            'status' => ['sometimes', 'string', Rule::in(ReconciliationReport::STATUSES)],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);
        $query = ReconciliationReport::query()->orderByDesc('created_at')->orderByDesc('id');
        foreach (['scope', 'entity', 'status'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        // `from`/`to` devuelven los informes cuya ventana se solapa con ese rango.
        if (isset($filters['from'])) {
            $query->where('window_to', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->where('window_from', '<=', $filters['to']);
        }
        $page = $query->paginate((int) ($filters['per_page'] ?? 50));

        return response()->json([
            'data' => $page->getCollection()->map(fn (ReconciliationReport $r) => $r->toApi())->values(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(ReconciliationReport $report): JsonResponse
    {
        return response()->json(['data' => $report->toApi()]);
    }
}
