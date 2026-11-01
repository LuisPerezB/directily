<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Intelligence;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

/** Base de los controladores de Factor Intelligence: respuesta paginada uniforme `{data, meta}`. */
abstract class IntelligenceController extends Controller
{
    /** @param callable(mixed): array<string, mixed> $present */
    protected function paginated(LengthAwarePaginator $page, callable $present): JsonResponse
    {
        return response()->json([
            'data' => $page->getCollection()->map($present)->values()->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }
}
