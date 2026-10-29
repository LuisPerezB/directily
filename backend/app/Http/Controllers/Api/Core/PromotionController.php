<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Core;

use App\Http\Controllers\Controller;
use App\Models\Promotion;
use App\Models\PromotionRevision;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRule;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Promociones: el motor las usa como covariables, a futuro solo desde `known_at` y con la
 * versión vigente en cada corte. Alta, edición y baja (lógica) quedan en `promotion_revisions`.
 */
final class PromotionController extends Controller
{
    public function __construct(private readonly TenantContext $tenants) {}

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
        $attributes = $this->validated($request);
        $promotion = DB::transaction(fn () => Promotion::query()->create($attributes))->refresh();

        return response()->json(['data' => $this->present($promotion)], 201);
    }

    public function update(Request $request, Promotion $promotion): JsonResponse
    {
        $attributes = $this->validated($request, $promotion);
        DB::transaction(fn () => $promotion->fill($attributes)->save());

        return response()->json(['data' => $this->present($promotion->refresh())]);
    }

    public function destroy(Promotion $promotion): Response
    {
        DB::transaction(fn () => $promotion->delete());

        return response()->noContent();
    }

    /** Historial de versiones (también de promociones dadas de baja). */
    public function revisions(Promotion $promotion): JsonResponse
    {
        return response()->json(['data' => $promotion->revisions()->get()->map(fn (PromotionRevision $r) => [
            'revision' => $r->revision, 'name' => $r->name, 'promo_type' => $r->promo_type, 'discount_pct' => $r->discount_pct,
            'starts_on' => $r->starts_on?->toDateString(), 'ends_on' => $r->ends_on?->toDateString(),
            'branch_id' => $r->branch_id, 'product_id' => $r->product_id, 'category_id' => $r->category_id,
            'known_at' => $r->known_at?->toIso8601String(), 'deleted' => $r->deleted, 'source' => $r->source,
            'recorded_at' => $r->recorded_at?->toIso8601String(),
        ])]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Promotion $promotion = null): array
    {
        $req = $promotion === null ? 'required' : 'sometimes';
        $timezone = $this->tenants->require()->timezone ?: 'America/Guayaquil';

        $data = $request->validate([
            'name' => [$req, 'string', 'max:150'],
            'promo_type' => ['sometimes', Rule::in(['percentage', 'fixed_amount', 'bundle', 'other'])],
            'discount_pct' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'], // fracción: 0.15 = 15 %
            'starts_on' => [$req, 'date'],
            'ends_on' => [$req, 'date', 'after_or_equal:starts_on'],
            'branch_id' => ['sometimes', 'nullable', 'integer', TenantRule::exists('branches')],
            'product_id' => ['sometimes', 'nullable', 'integer', TenantRule::exists('products')],
            'category_id' => ['sometimes', 'nullable', 'integer', TenantRule::exists('product_categories')],
            // Cuándo la conocía el negocio (p. ej. fecha de aprobación de un histórico). Por defecto: el alta.
            'known_at' => [$promotion === null ? 'nullable' : 'sometimes', 'date', function (string $attribute, mixed $value, Closure $fail) use ($timezone): void {
                try {
                    $future = Promotion::parseKnownAt((string) $value, $timezone)->isFuture();
                } catch (Throwable) {
                    return; // la regla `date` ya informa el formato inválido
                }
                if ($future) {
                    $fail('La fecha de registro de la promoción (known_at) no puede ser futura.');
                }
            }],
        ]);
        if (array_key_exists('known_at', $data)) {
            if ($data['known_at'] === null) {
                unset($data['known_at']);
            } else {
                $data['known_at'] = Promotion::parseKnownAt((string) $data['known_at'], $timezone);
            }
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function present(Promotion $p): array
    {
        return [
            'id' => $p->id, 'name' => $p->name, 'promo_type' => $p->promo_type, 'discount_pct' => $p->discount_pct,
            'starts_on' => $p->starts_on?->toDateString(), 'ends_on' => $p->ends_on?->toDateString(),
            'branch_id' => $p->branch_id, 'product_id' => $p->product_id, 'category_id' => $p->category_id,
            'known_at' => $p->known_at?->toIso8601String(),
        ];
    }
}
