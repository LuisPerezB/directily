<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Supply;

use App\Models\PurchaseBudget;
use App\Support\Tenancy\TenantRule;
use Illuminate\Database\Eloquent\Model;

/** Presupuesto de compra por período. Aplica a las órdenes EMITIDAS dentro del período; el plan nunca lo excede. */
class BudgetController extends ConfigResourceController
{
    protected function model(): string
    {
        return PurchaseBudget::class;
    }

    protected function entity(): string
    {
        return 'budget';
    }

    protected function orderBy(): string
    {
        return 'period_start';
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
            'branch_id' => ['nullable', 'integer', TenantRule::exists('branches')],
            'period_start' => [$req, 'date_format:Y-m-d'],
            'period_end' => [$req, 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'amount' => [$req, 'numeric', 'min:0', 'max:999999999999'],
            'currency' => [$req, 'string', 'size:3', 'alpha'],
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
        /** @var PurchaseBudget $m */
        return ['id' => $m->id, 'branch_id' => $m->branch_id, 'branch' => $m->branch ? ['id' => $m->branch->id, 'code' => $m->branch->code, 'name' => $m->branch->name] : null,
            'period_start' => $m->period_start?->toDateString(), 'period_end' => $m->period_end?->toDateString(),
            'amount' => $m->amount, 'currency' => $m->currency, 'notes' => $m->notes];
    }
}
