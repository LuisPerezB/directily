<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Supply;

use App\Models\ProductionResource;
use App\Support\Tenancy\TenantRule;
use Illuminate\Database\Eloquent\Model;

/** Recursos de producción genéricos con capacidad por período (horas, lotes, kg...). Sin reglas sectoriales. */
class ProductionResourceController extends ConfigResourceController
{
    protected function model(): string
    {
        return ProductionResource::class;
    }

    protected function entity(): string
    {
        return 'production_resource';
    }

    protected function rules(?int $id): array
    {
        $req = $id === null ? 'required' : 'sometimes';

        return [
            'code' => [$req, 'string', 'max:50', TenantRule::unique('production_resources', 'code', $id)],
            'name' => [$req, 'string', 'max:150'],
            'branch_id' => ['nullable', 'integer', TenantRule::exists('branches')],
            'capacity_per_period' => [$req, 'numeric', 'min:0'],
            'capacity_unit' => [$req, 'string', 'max:20'],
            'period_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function present(Model $m): array
    {
        /** @var ProductionResource $m */
        return ['id' => $m->id, 'code' => $m->code, 'name' => $m->name, 'branch_id' => $m->branch_id,
            'capacity_per_period' => $m->capacity_per_period, 'capacity_unit' => $m->capacity_unit,
            'period_days' => $m->period_days, 'is_active' => (bool) $m->is_active];
    }
}
