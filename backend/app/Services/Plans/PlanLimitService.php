<?php

declare(strict_types=1);

namespace App\Services\Plans;

use App\Exceptions\PlanLimitExceededException;
use App\Models\Branch;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Límites de uso por plan SaaS y registro de consumo (tenant_usage).
 *
 * Límites numéricos: max_branches, max_active_products, max_import_rows_per_month,
 * max_forecast_runs_per_day, max_users. Lista: horizons. Banderas: features.*.
 */
final class PlanLimitService
{
    /** @return array<string, mixed> límites efectivos (plan + overrides de la empresa) */
    public function limits(Tenant $tenant): array
    {
        $planCode = config('predictily.default_plan', 'trial');
        $base = config("predictily.plans.{$planCode}.limits", []);

        $plan = $tenant->plan_id ? Plan::query()->find($tenant->plan_id) : null;
        if ($plan !== null) {
            $base = array_replace_recursive($base, $plan->limits ?? []);
        }

        return array_replace_recursive($base, $tenant->plan_overrides ?? []);
    }

    public function allows(Tenant $tenant, string $feature): bool
    {
        $limits = $this->limits($tenant);

        return (bool) ($limits['features'][$feature] ?? false);
    }

    public function allowsHorizon(Tenant $tenant, int $horizonDays): bool
    {
        return in_array($horizonDays, $this->limits($tenant)['horizons'] ?? [7, 30, 90], true);
    }

    /** @throws PlanLimitExceededException */
    public function assertHorizon(Tenant $tenant, int $horizonDays): void
    {
        if (! $this->allowsHorizon($tenant, $horizonDays)) {
            throw new PlanLimitExceededException('horizons', $horizonDays, null, "El horizonte de {$horizonDays} días no está incluido en su plan.");
        }
    }

    /** @throws PlanLimitExceededException */
    public function assertFeature(Tenant $tenant, string $feature): void
    {
        if (! $this->allows($tenant, $feature)) {
            throw new PlanLimitExceededException("features.{$feature}", null, null, 'Esta capacidad no está incluida en su plan.');
        }
    }

    /**
     * Verifica que la operación (que agrega $increment unidades) no exceda el límite.
     * Para features use assertFeature(); para horizontes assertHorizon().
     *
     * @throws PlanLimitExceededException
     */
    public function assertCan(Tenant $tenant, string $limit, int $increment = 1): void
    {
        if (str_starts_with($limit, 'features.')) {
            $this->assertFeature($tenant, substr($limit, 9));

            return;
        }

        $max = $this->limits($tenant)[$limit] ?? null;
        if ($max === null) {
            return; // sin límite definido
        }

        $current = $this->current($tenant, $limit);
        if ($current + $increment > (int) $max) {
            throw new PlanLimitExceededException(
                $limit,
                $current,
                (int) $max,
                sprintf('Se alcanzó el límite del plan: %s (actual %s, máximo %s).', $this->label($limit), number_format($current, 0, ',', '.'), number_format((int) $max, 0, ',', '.')),
            );
        }
    }

    /** Valor actual del indicador asociado a un límite. */
    public function current(Tenant $tenant, string $limit): int
    {
        return match ($limit) {
            'max_branches' => Branch::withoutTenantScope()->where('tenant_id', $tenant->id)->where('is_active', true)->count(),
            'max_active_products' => Product::withoutTenantScope()->where('tenant_id', $tenant->id)->where('is_active', true)->count(),
            'max_users' => DB::table('tenant_users')->where('tenant_id', $tenant->id)->count(),
            'max_import_rows_per_month' => $this->usage($tenant, 'import_rows'),
            'max_forecast_runs_per_day' => DB::table('forecast_runs')
                ->where('tenant_id', $tenant->id)
                ->where('created_at', '>=', now($tenant->timezone)->startOfDay()->utc())
                ->count(),
            default => 0,
        };
    }

    public function usage(Tenant $tenant, string $metric, ?string $period = null): int
    {
        return (int) DB::table('tenant_usage')
            ->where('tenant_id', $tenant->id)
            ->where('period', $period ?? $this->period($tenant))
            ->where('metric', $metric)
            ->value('value');
    }

    public function recordUsage(Tenant $tenant, string $metric, int $amount = 1): void
    {
        if ($amount === 0) {
            return;
        }
        $now = now();
        DB::statement(
            'INSERT INTO tenant_usage (tenant_id, period, metric, value, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT (tenant_id, period, metric) DO UPDATE SET value = tenant_usage.value + EXCLUDED.value, updated_at = EXCLUDED.updated_at',
            [$tenant->id, $this->period($tenant), $metric, $amount, $now, $now],
        );
    }

    /** @return array<string, int> */
    public function snapshot(Tenant $tenant): array
    {
        return [
            'import_rows' => $this->usage($tenant, 'import_rows'),
            'forecast_runs' => $this->usage($tenant, 'forecast_runs'),
            'api_requests' => $this->usage($tenant, 'api_requests'),
            'forecast_runs_today' => $this->current($tenant, 'max_forecast_runs_per_day'),
            'active_products' => $this->current($tenant, 'max_active_products'),
            'branches' => $this->current($tenant, 'max_branches'),
            'users' => $this->current($tenant, 'max_users'),
        ];
    }

    public function period(Tenant $tenant): string
    {
        return now($tenant->timezone ?: 'America/Guayaquil')->format('Y-m');
    }

    private function label(string $limit): string
    {
        return [
            'max_branches' => 'sucursales activas',
            'max_active_products' => 'productos activos',
            'max_import_rows_per_month' => 'filas importadas en el mes',
            'max_forecast_runs_per_day' => 'pronósticos por día',
            'max_users' => 'usuarios',
        ][$limit] ?? $limit;
    }
}
