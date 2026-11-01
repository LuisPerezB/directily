<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Supply;

use App\Models\LocationCapacity;
use App\Support\Tenancy\TenantRule;
use Illuminate\Database\Eloquent\Model;

/** Capacidad de almacenamiento por sucursal, en una unidad física explícita (m3, pallets, kg, unidad...). */
class CapacityController extends ConfigResourceController
{
    protected function model(): string
    {
        return LocationCapacity::class;
    }

    protected function entity(): string
    {
        return 'capacity';
    }

    protected function with(): array
    {
        return ['branch:id,code,name'];
    }

    protected function filters(): array
    {
        return ['branch_id'];
    }

    protected function rules(?int $id): array
    {
        $req = $id === null ? 'required' : 'sometimes';

        return [
            'branch_id' => [$req, 'integer', TenantRule::exists('branches')],
            'warehouse_id' => ['nullable', 'integer', TenantRule::exists('warehouses')],
            'capacity' => [$req, 'numeric', 'min:0'],
            'capacity_unit' => [$req, 'string', 'max:20'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function present(Model $m): array
    {
        /** @var LocationCapacity $m */
        return ['id' => $m->id, 'branch_id' => $m->branch_id, 'branch' => $m->branch ? ['id' => $m->branch->id, 'code' => $m->branch->code, 'name' => $m->branch->name] : null,
            'warehouse_id' => $m->warehouse_id, 'capacity' => $m->capacity, 'capacity_unit' => $m->capacity_unit,
            'valid_from' => $m->valid_from?->toDateString(), 'valid_to' => $m->valid_to?->toDateString(), 'notes' => $m->notes];
    }
}
