<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Core;

use App\Http\Controllers\Controller;
use App\Http\Requests\Core\ProductRequest;
use App\Http\Resources\Core\ProductResource;
use App\Models\Product;
use App\Services\Audit\AuditLogger;
use App\Services\Catalog\ProductStatsService;
use App\Services\Plans\PlanLimitService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

final class ProductController extends Controller
{
    /** Ordenamientos permitidos (lista blanca; nunca se interpola la entrada del usuario). */
    private const SORTS = [
        'name' => ['products.name', 'asc'], '-name' => ['products.name', 'desc'],
        'sku' => ['products.sku', 'asc'], '-sku' => ['products.sku', 'desc'],
        'units_90d' => ['units_90d', 'asc'], '-units_90d' => ['units_90d', 'desc'],
        'revenue_90d' => ['revenue_90d', 'asc'], '-revenue_90d' => ['revenue_90d', 'desc'],
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly ProductStatsService $stats,
        private readonly PlanLimitService $plans,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $tenant = $this->context->require();
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'category_id' => ['sometimes', 'nullable', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', Rule::in(array_keys(self::SORTS))],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        [$column, $direction] = self::SORTS[$filters['sort'] ?? 'name'];
        $byStats = in_array($column, ['units_90d', 'revenue_90d'], true);

        $query = Product::query()->with('category:id,name');
        if (! empty($filters['search'])) {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['search']).'%';
            $query->where(fn ($q) => $q->where('products.name', 'ilike', $term)->orWhere('products.sku', 'ilike', $term));
        }
        if (! empty($filters['category_id'])) {
            $query->where('products.category_id', (int) $filters['category_id']);
        }
        if (array_key_exists('is_active', $filters)) {
            $query->where('products.is_active', (bool) $filters['is_active']);
        }

        if ($byStats) {
            $query->leftJoinSub($this->stats->subquery($tenant), 'st', 'st.product_id', '=', 'products.id')
                ->select('products.*')
                ->selectRaw('COALESCE(st.units_90d, 0) AS units_90d, COALESCE(st.revenue_90d, 0) AS revenue_90d, st.last_sale_date')
                ->orderByRaw(($column === 'units_90d' ? 'COALESCE(st.units_90d, 0)' : 'COALESCE(st.revenue_90d, 0)').' '.($direction === 'desc' ? 'DESC' : 'ASC'))
                ->orderBy('products.id');
            $page = $query->paginate((int) ($filters['per_page'] ?? 25));

            return ProductResource::collection($page);
        }

        $page = $query->orderBy($column, $direction)->orderBy('products.id')->paginate((int) ($filters['per_page'] ?? 25));
        $stats = $this->stats->forProducts($tenant, $page->getCollection()->pluck('id')->all());
        $page->getCollection()->each(fn (Product $p) => $this->attachStats($p, $stats[$p->id] ?? null));

        return ProductResource::collection($page);
    }

    public function show(Product $product): ProductResource
    {
        $product->load('category:id,name');
        $stats = $this->stats->forProducts($this->context->require(), [$product->id]);
        $this->attachStats($product, $stats[$product->id] ?? null);

        return new ProductResource($product);
    }

    /** @param array{units_90d: float, revenue_90d: float, last_sale_date: ?string}|null $stats */
    private function attachStats(Product $product, ?array $stats): void
    {
        $product->setRawAttributes(array_merge($product->getAttributes(), [
            'units_90d' => $stats['units_90d'] ?? 0,
            'revenue_90d' => $stats['revenue_90d'] ?? 0,
            'last_sale_date' => $stats['last_sale_date'] ?? null,
        ]), true);
    }

    public function store(ProductRequest $request): JsonResponse
    {
        $data = $request->validated();
        if ($data['is_active'] ?? true) {
            $this->plans->assertCan($this->context->require(), 'max_active_products');
        }
        $product = Product::query()->create($data)->refresh();
        $this->audit->log('product.created', $product, ['sku' => $product->sku]);
        $product->load('category:id,name');

        return (new ProductResource($product))->response()->setStatusCode(201);
    }

    public function update(ProductRequest $request, Product $product): ProductResource
    {
        $data = $request->validated();
        if (($data['is_active'] ?? false) && ! $product->is_active) {
            $this->plans->assertCan($this->context->require(), 'max_active_products');
        }
        $product->fill($data)->save();
        $this->audit->log('product.updated', $product, ['fields' => array_keys($data)]);
        $product->load('category:id,name');

        return new ProductResource($product);
    }
}
