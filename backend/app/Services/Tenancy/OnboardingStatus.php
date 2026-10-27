<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/** Estado de incorporación de una empresa (consultas EXISTS, baratas). */
final class OnboardingStatus
{
    /** @return array<string, mixed> */
    public function for(Tenant $tenant): array
    {
        $exists = fn (string $table, array $where = []) => DB::table($table)->where('tenant_id', $tenant->id)->where($where)->exists();

        return [
            'has_branches' => $exists('branches'),
            'has_products' => $exists('products'),
            'has_sales' => $exists('sales'),
            'has_inventory' => $exists('inventory_snapshots'),
            'has_forecast' => $exists('forecast_runs', [['status', '=', 'completed']]),
            'completed_at' => $tenant->onboarding_completed_at?->toIso8601String(),
        ];
    }
}
