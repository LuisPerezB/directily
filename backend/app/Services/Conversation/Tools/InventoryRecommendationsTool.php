<?php

declare(strict_types=1);

namespace App\Services\Conversation\Tools;

use App\Http\Resources\Analytics\RecommendationResource;
use App\Services\Analytics\DemandEnvelope;
use App\Services\Analytics\InventoryQuery;
use App\Services\Analytics\RunResolver;
use App\Support\Tenancy\TenantRule;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Recomendaciones de inventario (GET /inventory/recommendations): sugerencias, no pedidos. */
final class InventoryRecommendationsTool extends ReadTool
{
    private const RISKS = ['low', 'medium', 'high', 'critical'];

    public function __construct(
        private readonly InventoryQuery $inventory,
        private readonly RunResolver $runs,
        private readonly DemandEnvelope $envelopes,
    ) {}

    public function name(): string
    {
        return 'inventory_recommendations';
    }

    public function description(): string
    {
        return 'Recomendaciones de inventario ya calculadas (sugerencias; Predictily no emite pedidos): productos con riesgo de quiebre de stock, '
            .'días de cobertura, probabilidad de quiebre, punto de reorden, cantidad sugerida e inversión estimada. Se pueden filtrar por nivel de riesgo.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'risk_level' => ['type' => 'string', 'enum' => self::RISKS, 'description' => 'Nivel de riesgo a filtrar (opcional).'],
                'product' => ['type' => 'string', 'maxLength' => 80, 'description' => 'SKU o parte del nombre del producto (opcional).'],
                'branch_id' => ['type' => 'integer', 'description' => 'Identificador de sucursal (opcional).'],
                'limit' => $this->limitSchema(),
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'risk_level' => ['sometimes', 'string', Rule::in(self::RISKS)],
            'product' => ['sometimes', 'string', 'min:1', 'max:80'],
            'branch_id' => ['sometimes', 'integer', TenantRule::exists('branches')],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('conversation.limits.max_rows_per_tool', 10)],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $run = $this->runs->resolveCompleted(null);
        if ($run === null) {
            return ToolResult::noData('Aún no hay un pronóstico generado; las recomendaciones de inventario se calculan junto con él.');
        }
        $branchId = isset($arguments['branch_id']) ? (int) $arguments['branch_id'] : null;
        $filters = [
            'branch_id' => $branchId,
            'risk_level' => isset($arguments['risk_level']) ? [(string) $arguments['risk_level']] : null,
            'search' => $arguments['product'] ?? null,
        ];
        $page = $this->inventory->recommendations($run, $filters, '-stockout_probability', $this->limit($arguments), 1);
        $rows = RecommendationResource::collection($page->getCollection())->resolve(Request::create('/'));

        $data = [
            'recommendations' => array_map(fn (array $r) => array_diff_key($r, ['assumptions' => true]), $rows),
            'total_matches' => $page->total(),
            'risk_counts' => $this->inventory->riskCounts($run, $branchId),
            'purchase_investment' => $this->inventory->purchaseInvestment($run, $branchId),
            'disclaimer' => 'Sugerencias calculadas con el pronóstico, el inventario y los plazos de abastecimiento registrados. Predictily no realiza pedidos.',
        ];
        $envelope = $this->envelopes->for($run, null, ['entity_keys' => ['branch_id', 'product_id']]);
        if ($rows === []) {
            return ToolResult::noData('No hay recomendaciones de inventario que coincidan con el filtro.', $envelope, $data);
        }

        return ToolResult::ok($data, $envelope);
    }
}
