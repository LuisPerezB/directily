<?php

declare(strict_types=1);

namespace App\Services\Plans;

use App\Exceptions\PlanLimitExceededException;
use App\Models\Branch;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Horizons\HorizonCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Límites de uso por plan SaaS y registro de consumo (tenant_usage).
 *
 * Límites numéricos: max_branches, max_series, max_active_products, max_import_rows_per_month,
 * max_forecast_runs_per_day, max_users. Lista: horizons (subconjunto del catálogo único, ADR-V25-02). Banderas: features.*.
 * Un precio nunca es un límite (ADR-V25-18): `plans.monthly_price_usd` y `plans.metadata` son informativos.
 */
final class PlanLimitService
{
    /** Ventana (días) con la que se cuenta una serie activa para `max_series`: pares sucursal × producto con alguna venta válida en ella. */
    public const SERIES_WINDOW_DAYS = 90;

    public function __construct(private readonly ActiveSeriesCounter $seriesCounter) {}

    /** @return array<string, mixed> límites efectivos (plan + overrides de la empresa) */
    public function limits(Tenant $tenant): array
    {
        $planCode = config('predictily.default_plan', 'trial');
        $base = config("predictily.plans.{$planCode}.limits", []);

        $plan = $tenant->plan_id ? Plan::query()->find($tenant->plan_id) : null;
        if ($plan !== null) {
            $base = self::mergeLimits($base, $plan->limits ?? []);
        }

        $limits = self::mergeLimits($base, $tenant->plan_overrides ?? []);
        if (isset($limits['horizons']) && is_array($limits['horizons'])) {
            // Un horizonte fuera del catálogo único no existe para el motor: nunca se autoriza aunque un plan lo traiga por error.
            $limits['horizons'] = array_values(array_intersect(HorizonCatalog::ints($limits['horizons']), HorizonCatalog::all()));
        }

        return $limits;
    }

    /**
     * Combina límites: los mapas (p. ej. features) se combinan clave a clave, pero las
     * listas (p. ej. horizons) se REEMPLAZAN completas. array_replace_recursive fusionaría
     * [7, 30, 90] con [7, 30] dejando [7, 30, 90].
     *
     * Excepción: un override VACÍO sobre un mapa existente (`features: {}` o `[]`) significa «sin cambios». Si lo reemplazara, borraría capacidades
     * (p. ej. `customer=false` de Starter) y las que faltan se interpretan como incluidas. Quitar una capacidad se hace poniéndola en false.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    public static function mergeLimits(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if ($value === [] && isset($base[$key]) && is_array($base[$key]) && $base[$key] !== [] && ! array_is_list($base[$key])) {
                continue;
            }
            if (is_array($value) && ! array_is_list($value) && isset($base[$key]) && is_array($base[$key]) && ! array_is_list($base[$key])) {
                $base[$key] = self::mergeLimits($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    public function allows(Tenant $tenant, string $feature): bool
    {
        $limits = $this->limits($tenant);

        return (bool) ($limits['features'][$feature] ?? false);
    }

    public function allowsHorizon(Tenant $tenant, int $horizonDays): bool
    {
        return in_array($horizonDays, $this->limits($tenant)['horizons'] ?? HorizonCatalog::demand(), true);
    }

    /**
     * Comportamiento de compra (Customer) incluido en el plan: `features.customer` (ausente = incluido, como `features.assistant`; Starter lo
     * desactiva). Que los identificadores de comprador sean confiables NO lo decide el plan: el motor se abstiene si no hay compradores identificados.
     */
    public function allowsCustomer(Tenant $tenant): bool
    {
        return (bool) ($this->limits($tenant)['features']['customer'] ?? true);
    }

    /** @throws PlanLimitExceededException */
    public function assertCustomer(Tenant $tenant): void
    {
        if (! $this->allowsCustomer($tenant)) {
            throw new PlanLimitExceededException('features.customer', null, null, 'Su plan no incluye el comportamiento de compra de compradores.');
        }
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
            'max_series' => $this->activeSeries($tenant) ?? 0,   // sin medición (tiempo excedido y sin valor previo): no se bloquea, ver activeSeries()
            'max_import_rows_per_month' => $this->usage($tenant, 'import_rows'),
            'max_forecast_runs_per_day' => DB::table('forecast_runs')
                ->where('tenant_id', $tenant->id)
                ->where('created_at', '>=', now($tenant->timezone)->startOfDay()->utc())
                ->count(),
            default => 0,
        };
    }

    /**
     * Series activas: pares (sucursal, producto) con alguna venta válida en los últimos SERIES_WINDOW_DAYS días del huso de la empresa.
     *
     * Es un DISTINCT sobre las ventas de la ventana, así que se acota de tres maneras (aproximación documentada en
     * docs/product/RETAIL_STARTER_GROWTH.md §3):
     *  1. caché por empresa y día (`predictily.series_count.cache_seconds`, 10 min por defecto): una serie nueva puede tardar hasta ese TTL en contarse,
     *     salvo que una importación de ventas termine antes (`recordUsage(import_rows)` invalida la entrada del día);
     *  2. `statement_timeout` local a la consulta (`predictily.series_count.timeout_ms`, 5 s);
     *  3. si la consulta excede el tiempo se devuelve el último valor conocido (caché de 2 días) o null si no hay ninguno: el tope NO se exige
     *     en esa petición (falla abierto, con aviso en el log) en vez de bloquear pronósticos por una consulta lenta.
     *
     * @param  bool  $refresh  ignora la caché y vuelve a medir
     */
    public function activeSeries(Tenant $tenant, bool $refresh = false): ?int
    {
        $today = now($tenant->timezone ?: 'America/Guayaquil')->startOfDay();
        $key = self::seriesCacheKey($tenant, $today->toDateString());
        if (! $refresh && ($hit = Cache::get($key)) !== null) {
            return (int) $hit;
        }

        try {
            $count = $this->seriesCounter->count($tenant, $today->copy()->subDays(self::SERIES_WINDOW_DAYS)->toDateString());
        } catch (SeriesCountUnavailable $e) {
            Log::warning('Conteo de series activas excedió el tiempo; el tope max_series no se exige en esta petición.', ['tenant_id' => $tenant->id]);
            $last = Cache::get($key.':last');

            return $last === null ? null : (int) $last;
        }
        Cache::put($key, $count, max(1, (int) config('predictily.series_count.cache_seconds', 600)));
        Cache::put($key.':last', $count, 172_800);

        return $count;
    }

    private static function seriesCacheKey(Tenant $tenant, string $day): string
    {
        return "plans:active_series:{$tenant->id}:{$day}";
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
        if ($metric === 'import_rows') {
            // Terminó (o avanzó) una importación de ventas: el conteo de series del día pudo cambiar. Se invalida solo la entrada del día.
            Cache::forget(self::seriesCacheKey($tenant, now($tenant->timezone ?: 'America/Guayaquil')->toDateString()));
        }
        $now = now();
        DB::statement(
            'INSERT INTO tenant_usage (tenant_id, period, metric, value, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT (tenant_id, period, metric) DO UPDATE SET value = tenant_usage.value + EXCLUDED.value, updated_at = EXCLUDED.updated_at',
            [$tenant->id, $this->period($tenant), $metric, $amount, $now, $now],
        );
    }

    /**
     * @param  bool  $withSeries  false = no mide las series (p. ej. el listado de administración, que recorre muchas empresas)
     * @return array<string, int|null> `series` solo se mide (con caché y tiempo acotado) si el plan define `max_series` y $withSeries; si no, null
     */
    public function snapshot(Tenant $tenant, bool $withSeries = true): array
    {
        return [
            'import_rows' => $this->usage($tenant, 'import_rows'),
            'forecast_runs' => $this->usage($tenant, 'forecast_runs'),
            'api_requests' => $this->usage($tenant, 'api_requests'),
            'forecast_runs_today' => $this->current($tenant, 'max_forecast_runs_per_day'),
            'active_products' => $this->current($tenant, 'max_active_products'),
            'branches' => $this->current($tenant, 'max_branches'),
            'users' => $this->current($tenant, 'max_users'),
            'series' => $withSeries && isset($this->limits($tenant)['max_series']) ? $this->activeSeries($tenant) : null,
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
            'max_series' => 'series activas (sucursal × producto con ventas en los últimos '.self::SERIES_WINDOW_DAYS.' días)',
            'max_active_products' => 'productos activos',
            'max_import_rows_per_month' => 'filas importadas en el mes',
            'max_forecast_runs_per_day' => 'pronósticos por día',
            'max_users' => 'usuarios',
        ][$limit] ?? $limit;
    }
}
