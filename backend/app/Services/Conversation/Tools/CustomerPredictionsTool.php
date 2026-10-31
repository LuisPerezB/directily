<?php

declare(strict_types=1);

namespace App\Services\Conversation\Tools;

use App\Http\Resources\Customer\CustomerPredictionResource;
use App\Models\CustomerPrediction;
use App\Services\Customer\CustomerEnvelope;
use App\Services\Customer\CustomerRunService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Predicciones de compra por comprador × producto (GET /customer/predictions). Los resultados son esperanzas probabilísticas,
 * no ventas confirmadas. Solo expone el código externo del comprador (nunca nombre, correo ni cédula/RUC).
 */
final class CustomerPredictionsTool extends ReadTool
{
    public function __construct(private readonly CustomerRunService $runs) {}

    public function isEnabled(): bool
    {
        return (bool) config('conversation.privacy.customer_tools_enabled', true);
    }

    public function name(): string
    {
        return 'customer_predictions';
    }

    public function description(): string
    {
        return 'Predicciones de compra ya calculadas por comprador y producto: probabilidad de comprar en 7, 30 o 90 días, cantidad total si compra, '
            .'cantidad esperada y monto esperado (supuesto; no es una venta confirmada). Busque por código del comprador y/o producto.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'customer_code' => ['type' => 'string', 'maxLength' => 50, 'description' => 'Código externo del comprador tal como figura en la empresa.'],
                'product' => ['type' => 'string', 'maxLength' => 80, 'description' => 'SKU o parte del nombre del producto (opcional).'],
                'horizon' => $this->horizonSchema(CustomerRunService::HORIZONS),
                'min_probability' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1, 'description' => 'Probabilidad mínima (0 a 1).'],
                'limit' => $this->limitSchema(),
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'customer_code' => ['sometimes', 'string', 'min:1', 'max:50'],
            'product' => ['sometimes', 'string', 'min:1', 'max:80'],
            'horizon' => ['sometimes', 'integer', Rule::in(CustomerRunService::HORIZONS)],
            'min_probability' => ['sometimes', 'numeric', 'between:0,1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('conversation.limits.max_rows_per_tool', 10)],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $tenant = $context->tenant;
        $horizon = $this->horizon($arguments, $tenant, CustomerRunService::HORIZONS);
        $run = $this->runs->latestPrediction();
        if ($run === null) {
            return ToolResult::noData(
                'Aún no hay una predicción de comportamiento de compra completada.',
                CustomerEnvelope::missing($tenant, 'forecast', 'Aún no hay una predicción de comportamiento de compra completada.'),
            );
        }
        $envelope = CustomerEnvelope::forRun($run, $tenant, $horizon);
        if (! in_array($horizon, array_map('intval', (array) $run->horizons), true)) {
            return ToolResult::noData("La corrida vigente no tiene predicciones a {$horizon} días (abstención por datos insuficientes o modelo ausente).", $envelope, [
                'available_horizons' => $run->horizons,
            ]);
        }

        $code = isset($arguments['customer_code']) ? trim((string) $arguments['customer_code']) : null;
        $product = isset($arguments['product']) ? trim((string) $arguments['product']) : null;
        $query = CustomerPrediction::query()
            ->where('core_run_id', $run->id)->where('horizon_days', $horizon)
            ->when($code !== null && $code !== '', fn ($q) => $q->whereHas('customer', fn ($c) => $c->where('external_code', $code)))
            ->when($product !== null && $product !== '', function ($q) use ($product) {
                $term = '%'.addcslashes($product, '%_\\').'%';
                $q->whereHas('product', fn ($p) => $p->where(fn ($w) => $w->where('sku', 'ilike', $term)->orWhere('name', 'ilike', $term)));
            })
            ->when(isset($arguments['min_probability']), fn ($q) => $q->where('purchase_probability', '>=', (float) $arguments['min_probability']));

        $total = (clone $query)->count();
        $rows = $query->with(['customer:id,external_code', 'product:id,sku,name,unit'])
            ->orderByDesc('purchase_probability')->orderBy('id')->limit($this->limit($arguments))->get();
        $data = [
            'horizon' => $horizon,
            'predictions' => CustomerPredictionResource::collection($rows)->resolve(Request::create('/')),
            'total_matches' => $total,
            'ranking_source' => $run->model_versions[(string) $horizon]['ranking_source'] ?? null,
            'definitions' => CustomerEnvelope::DEFINITIONS,
        ];
        if ($rows->isEmpty()) {
            return ToolResult::noData('No hay predicciones para ese comprador o producto en su empresa (puede no tener historial suficiente).', $envelope, $data);
        }

        return ToolResult::ok($data, $envelope);
    }
}
