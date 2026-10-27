<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Core;

use App\Http\Controllers\Controller;
use App\Models\Promotion;
use App\Support\Tenancy\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Promociones: el motor las usa como covariables conocidas a futuro. */
final class PromotionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['active_on' => ['sometimes', 'date'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $query = Promotion::query()->orderByDesc('starts_on');
        if ($request->filled('active_on')) {
            $query->where('starts_on', '<=', $request->date('active_on'))->where('ends_on', '>=', $request->date('active_on'));
        }
        $page = $query->paginate((int) $request->input('per_page', 25));

        return response()->json([
            'data' => $page->getCollection()->map(fn (Promotion $p) => $this->present($p)),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(Promotion $promotion): JsonResponse
    {
        return response()->json(['data' => $this->present($promotion)]);
    }

    public function store(Request $request): JsonResponse
    {
        $promotion = Promotion::query()->create($this->validated($request))->refresh();

        return response()->json(['data' => $this->present($promotion)], 201);
    }

    public function update(Request $request, Promotion $promotion): JsonResponse
    {
        $promotion->fill($this->validated($request, $promotion))->save();

        return response()->json(['data' => $this->present($promotion)]);
    }

    public function destroy(Promotion $promotion): Response
    {
        $promotion->delete();

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Promotion $promotion = null): array
    {
        $req = $promotion === null ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$req, 'string', 'max:150'],
            'promo_type' => ['sometimes', Rule::in(['percentage', 'fixed_amount', 'bundle', 'other'])],
            'discount_pct' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'], // fracción: 0.15 = 15 %
            'starts_on' => [$req, 'date'],
            'ends_on' => [$req, 'date', 'after_or_equal:starts_on'],
            'branch_id' => ['sometimes', 'nullable', 'integer', TenantRule::exists('branches')],
            'product_id' => ['sometimes', 'nullable', 'integer', TenantRule::exists('products')],
            'category_id' => ['sometimes', 'nullable', 'integer', TenantRule::exists('product_categories')],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(Promotion $p): array
    {
        return [
            'id' => $p->id, 'name' => $p->name, 'promo_type' => $p->promo_type, 'discount_pct' => $p->discount_pct,
            'starts_on' => $p->starts_on?->toDateString(), 'ends_on' => $p->ends_on?->toDateString(),
            'branch_id' => $p->branch_id, 'product_id' => $p->product_id, 'category_id' => $p->category_id,
        ];
    }
}
