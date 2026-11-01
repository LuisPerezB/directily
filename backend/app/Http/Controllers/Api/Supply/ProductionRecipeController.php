<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Supply;

use App\Models\ProductionRecipe;
use App\Support\Tenancy\TenantRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recetas genéricas insumo → producto: cantidad nominal por lote, rendimiento (0,1], tiempo de producción, recurso y
 * consumo del recurso. Sin recetas, la producción queda `not_configured` y no bloquea la reposición.
 */
class ProductionRecipeController extends ConfigResourceController
{
    protected function model(): string
    {
        return ProductionRecipe::class;
    }

    protected function entity(): string
    {
        return 'production_recipe';
    }

    protected function with(): array
    {
        return ['lines.inputProduct:id,sku,name,unit', 'outputProduct:id,sku,name,unit'];
    }

    protected function rules(?int $id): array
    {
        $req = $id === null ? 'required' : 'sometimes';

        return [
            'code' => [$req, 'string', 'max:50', TenantRule::unique('production_recipes', 'code', $id)],
            'name' => [$req, 'string', 'max:150'],
            'output_product_id' => [$req, 'integer', TenantRule::exists('products')],
            'branch_id' => ['nullable', 'integer', TenantRule::exists('branches')],
            'output_quantity' => [$req, 'numeric', 'gt:0'],
            'yield_rate' => ['sometimes', 'numeric', 'gt:0', 'lte:1'],
            'production_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'resource_id' => ['nullable', 'integer', TenantRule::exists('production_resources')],
            'resource_usage_per_batch' => ['sometimes', 'numeric', 'min:0'],
            'cost_per_batch' => ['nullable', 'numeric', 'min:0'],
            'max_batches_per_period' => ['nullable', 'numeric', 'gt:0'],
            'is_active' => ['sometimes', 'boolean'],
            'lines' => [$id === null ? 'required' : 'sometimes', 'array', 'min:1', 'max:100'],
            'lines.*.input_product_id' => ['required', 'integer', TenantRule::exists('products')],
            'lines.*.quantity_per_batch' => ['required', 'numeric', 'gt:0'],
        ];
    }

    protected function fill(array $data): array
    {
        unset($data['lines']);

        return $data;
    }

    protected function prepare(array $data, ?Model $existing): array
    {
        $ids = array_map(fn ($l) => (int) $l['input_product_id'], $data['lines'] ?? []);
        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['lines' => 'Un insumo no puede repetirse en la misma receta.']);
        }
        $out = (int) ($data['output_product_id'] ?? $existing?->output_product_id ?? 0);
        if (in_array($out, $ids, true)) {
            throw ValidationException::withMessages(['lines' => 'El producto de salida no puede ser su propio insumo.']);
        }

        return $data;
    }

    protected function afterSave(Model $model, array $data): void
    {
        if (! array_key_exists('lines', $data)) {
            return;
        }
        DB::transaction(function () use ($model, $data): void {
            /** @var ProductionRecipe $model */
            $model->lines()->delete();
            foreach ($data['lines'] as $l) {
                $model->lines()->create(['input_product_id' => (int) $l['input_product_id'], 'quantity_per_batch' => $l['quantity_per_batch']]);
            }
        });
    }

    protected function present(Model $m): array
    {
        /** @var ProductionRecipe $m */
        return [
            'id' => $m->id, 'code' => $m->code, 'name' => $m->name, 'output_product_id' => $m->output_product_id,
            'output_product' => $m->outputProduct ? ['id' => $m->outputProduct->id, 'sku' => $m->outputProduct->sku, 'name' => $m->outputProduct->name] : null,
            'branch_id' => $m->branch_id, 'output_quantity' => $m->output_quantity, 'yield_rate' => $m->yield_rate,
            'useful_output_per_batch' => round($m->output_quantity * $m->yield_rate, 6),
            'production_days' => $m->production_days, 'resource_id' => $m->resource_id,
            'resource_usage_per_batch' => $m->resource_usage_per_batch, 'cost_per_batch' => $m->cost_per_batch,
            'max_batches_per_period' => $m->max_batches_per_period, 'is_active' => (bool) $m->is_active,
            'lines' => $m->lines->map(fn ($l) => ['input_product_id' => $l->input_product_id, 'quantity_per_batch' => $l->quantity_per_batch,
                'input_product' => $l->inputProduct ? ['id' => $l->inputProduct->id, 'sku' => $l->inputProduct->sku, 'name' => $l->inputProduct->name] : null])->values(),
        ];
    }
}
