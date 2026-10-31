<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Supply;

use App\Models\SupplierOffer;
use App\Support\Tenancy\TenantRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Ofertas de proveedor (E2-26): precio, moneda, unidad, mínimo, múltiplo, plazo, capacidad, vigencia, known_at y gastos de
 * adquisición explícitos. Lo desconocido queda null (no se rellena). Una oferta sin conversión de unidades o sin tasa de
 * cambio explícita no es comparable: el motor la excluye y lo declara.
 */
class OfferController extends ConfigResourceController
{
    protected function model(): string
    {
        return SupplierOffer::class;
    }

    protected function entity(): string
    {
        return 'offer';
    }

    protected function with(): array
    {
        return ['supplier:id,code,name', 'product:id,sku,name,unit', 'branch:id,code,name'];
    }

    protected function filters(): array
    {
        return ['product_id', 'supplier_id', 'is_active'];
    }

    protected function rules(?int $id): array
    {
        $req = $id === null ? 'required' : 'sometimes';

        return [
            'supplier_id' => [$req, 'integer', TenantRule::exists('suppliers')],
            'product_id' => [$req, 'integer', TenantRule::exists('products')],
            'branch_id' => ['nullable', 'integer', TenantRule::exists('branches')],
            'unit_cost' => [$req, 'numeric', 'min:0', 'max:99999999'],
            'currency' => [$req, 'string', 'size:3', 'alpha'],
            'unit' => [$req, 'string', 'max:20'],
            'min_order_qty' => ['nullable', 'numeric', 'min:0'],
            'order_multiple' => ['nullable', 'numeric', 'gt:0'],
            'lead_time_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'lead_time_std_days' => ['nullable', 'numeric', 'min:0', 'max:180'],
            'capacity_per_period' => ['nullable', 'numeric', 'min:0'],
            'capacity_period_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'fixed_order_cost' => ['sometimes', 'numeric', 'min:0'],
            'acquisition_cost_pct' => ['sometimes', 'numeric', 'min:0', 'max:5'],
            'acquisition_cost_per_unit' => ['sometimes', 'numeric', 'min:0'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'known_at' => ['nullable', 'date'],
            'source' => ['sometimes', 'string', Rule::in(['manual', 'import', 'api'])],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function fill(array $data): array
    {
        if (isset($data['currency'])) {
            $data['currency'] = strtoupper((string) $data['currency']);
        }

        return $data;
    }

    protected function present(Model $m): array
    {
        /** @var SupplierOffer $m */
        return [
            'id' => $m->id,
            'supplier_id' => $m->supplier_id,
            'supplier' => $m->supplier ? ['id' => $m->supplier->id, 'code' => $m->supplier->code, 'name' => $m->supplier->name] : null,
            'product_id' => $m->product_id,
            'product' => $m->product ? ['id' => $m->product->id, 'sku' => $m->product->sku, 'name' => $m->product->name, 'unit' => $m->product->unit] : null,
            'branch_id' => $m->branch_id,
            'unit_cost' => $m->unit_cost, 'currency' => $m->currency, 'unit' => $m->unit,
            'min_order_qty' => $m->min_order_qty, 'order_multiple' => $m->order_multiple,
            'lead_time_days' => $m->lead_time_days, 'lead_time_std_days' => $m->lead_time_std_days,
            'capacity_per_period' => $m->capacity_per_period, 'capacity_period_days' => $m->capacity_period_days,
            'fixed_order_cost' => $m->fixed_order_cost, 'acquisition_cost_pct' => $m->acquisition_cost_pct,
            'acquisition_cost_per_unit' => $m->acquisition_cost_per_unit,
            'valid_from' => $m->valid_from?->toDateString(), 'valid_to' => $m->valid_to?->toDateString(),
            'known_at' => $m->known_at?->toIso8601String(), 'source' => $m->source, 'is_active' => (bool) $m->is_active,
            'notes' => $m->notes,
        ];
    }
}
