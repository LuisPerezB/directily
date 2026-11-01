<?php

declare(strict_types=1);

namespace App\Services\Conversation\Tools;

use App\Models\Product;
use App\Services\Analytics\DemandEnvelope;
use App\Services\Analytics\HorizonSupport;
use App\Services\Analytics\ProductForecastQuery;
use App\Services\Analytics\RunResolver;
use App\Support\Tenancy\TenantRule;
use Illuminate\Validation\Rule;

/** Pronóstico de uno o varios productos (GET /forecasts/products) con intervalo, tendencia, modelo y WAPE de backtest. */
final class ProductForecastTool extends ReadTool
{
    public function __construct(
        private readonly ProductForecastQuery $products,
        private readonly RunResolver $runs,
        private readonly DemandEnvelope $envelopes,
        private readonly HorizonSupport $support,
    ) {}

    public function name(): string
    {
        return 'product_forecast';
    }

    public function description(): string
    {
        return 'Pronóstico de demanda ya calculado de un producto (busque por SKU o por parte del nombre) o de los productos de mayor demanda si no se indica: '
            .'unidades e ingresos pronosticados, intervalo del 80 %, cambio frente al período previo, tendencia, modelo y error de backtesting.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'product' => ['type' => 'string', 'maxLength' => 80, 'description' => 'SKU o parte del nombre del producto.'],
                'product_id' => ['type' => 'integer', 'description' => 'Identificador del producto (opcional).'],
                'horizon' => $this->horizonSchema(array_map('intval', (array) config('engine.horizons'))),
                'branch_id' => ['type' => 'integer', 'description' => 'Identificador de sucursal (opcional).'],
                'limit' => $this->limitSchema(),
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'product' => ['sometimes', 'string', 'min:1', 'max:80'],
            'product_id' => ['sometimes', 'integer', TenantRule::exists('products')],
            'horizon' => ['sometimes', 'integer', Rule::in(array_map('intval', (array) config('engine.horizons')))],
            'branch_id' => ['sometimes', 'integer', TenantRule::exists('branches')],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('conversation.limits.max_rows_per_tool', 10)],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $horizon = $this->horizon($arguments, $context->tenant, array_map('intval', (array) config('engine.horizons')));
        $branchId = isset($arguments['branch_id']) ? (int) $arguments['branch_id'] : null;
        $run = $this->runs->resolveCompleted(null);
        if ($run === null) {
            return ToolResult::noData('Aún no hay un pronóstico generado para esta empresa.');
        }

        $productId = isset($arguments['product_id']) ? (int) $arguments['product_id'] : null;
        $search = isset($arguments['product']) ? trim((string) $arguments['product']) : null;
        if ($productId !== null) {
            $search = (string) Product::query()->whereKey($productId)->value('sku');
        }
        $limit = $this->limit($arguments);
        $page = $this->products->products($run, $horizon, ['branch_id' => $branchId, 'search' => $search], '-forecast_units', $productId !== null ? 100 : $limit, 1);
        $rows = collect($page->items());
        if ($productId !== null) {
            $rows = $rows->filter(fn (array $r) => (int) $r['product']['id'] === $productId);
        }
        $rows = $rows->take($limit)->values()->all();

        $envelope = $this->envelopes->for($run, $horizon, ['entity_keys' => $branchId !== null ? ['branch_id', 'product_id'] : ['product_id']]);
        $data = [
            'horizon' => $horizon,
            'branch_id' => $branchId,
            'products' => $rows,
            'total_matches' => $productId !== null ? count($rows) : $page->total(),
            'horizon_support' => $this->support->forHorizon($run, $horizon),
        ];
        if ($rows === []) {
            return ToolResult::noData('No hay pronóstico para un producto de su empresa que coincida con la búsqueda.', $envelope, $data);
        }

        return ToolResult::ok($data, $envelope);
    }
}
