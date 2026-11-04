<?php

declare(strict_types=1);

namespace Tests\Feature\Plans;

use App\Enums\TenantRole;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Plans\ActiveSeriesCounter;
use App\Services\Plans\PlanLimitService;
use App\Services\Plans\SeriesCountUnavailable;
use Database\Seeders\PlanSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Analytics\Support\AnalyticsFixtures;
use Tests\TestCase;

/**
 * W2-E · el conteo de series activas (`max_series`) es un DISTINCT sobre las ventas de 90 días: se cachea por empresa y día, corre con un
 * `statement_timeout` local, se invalida al terminar una importación y NUNCA se ejecuta por empresa en el listado de administración.
 */
final class SeriesCountTest extends TestCase
{
    use AnalyticsFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        Cache::flush();
    }

    /** @return array{0: Tenant, 1: User, 2: array<string, mixed>} empresa Growth con 6 series activas (2 sucursales × 3 productos) */
    private function growthWithSales(array $overrides = []): array
    {
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Owner, array_filter([
            'plan_id' => Plan::query()->where('code', 'growth')->value('id'), 'plan_overrides' => $overrides ?: null,
        ], fn ($v) => $v !== null));
        $cat = $this->seedCatalog($tenant);
        $this->seedSales($tenant, $cat, now()->subDay()->toDateString(), 5);

        return [$tenant, $user, $cat];
    }

    /** Consultas ejecutadas por $fn que tocan las ventas (sale_items) y sentencias SET LOCAL statement_timeout. */
    private function observe(callable $fn): array
    {
        $salesQueries = 0;
        $timeouts = [];
        DB::listen(function ($q) use (&$salesQueries, &$timeouts) {
            if (str_contains($q->sql, 'sale_items')) {
                $salesQueries++;
            }
            if (str_contains($q->sql, 'statement_timeout')) {
                $timeouts[] = $q->sql;
            }
        });
        $fn();

        return [$salesQueries, $timeouts];
    }

    public function test_the_count_is_cached_per_company_and_day_and_refreshable(): void
    {
        [$tenant, , $cat] = $this->growthWithSales();
        $plans = app(PlanLimitService::class);

        [$first] = $this->observe(fn () => $this->assertSame(6, $plans->activeSeries($tenant)));
        [$second] = $this->observe(fn () => $this->assertSame(6, $plans->activeSeries($tenant)));
        $this->assertSame([1, 0], [$first, $second], 'la segunda lectura sale de la caché: sin consultar ventas');

        // Una serie nueva no se cuenta hasta que expira la caché, se invalida o se pide refrescar (aproximación documentada).
        $this->seedExtraSeries($tenant, $cat);
        $this->assertSame(6, $plans->activeSeries($tenant), 'dentro del TTL el valor es el cacheado');
        $this->assertSame(7, $plans->activeSeries($tenant, refresh: true));
        $this->assertSame(7, $plans->activeSeries($tenant), 'el refresco actualiza la caché');

        // Otra empresa tiene su propia entrada.
        [$other] = $this->growthWithSales();
        $this->assertSame(6, $plans->activeSeries($other));
        $this->assertSame(7, $plans->activeSeries($tenant));
    }

    public function test_finishing_a_sales_import_invalidates_the_days_entry(): void
    {
        [$tenant, , $cat] = $this->growthWithSales();
        $plans = app(PlanLimitService::class);
        $this->assertSame(6, $plans->activeSeries($tenant));
        $this->seedExtraSeries($tenant, $cat);
        $this->assertSame(6, $plans->activeSeries($tenant));

        $plans->recordUsage($tenant, 'forecast_runs');     // otras métricas no invalidan
        $this->assertSame(6, $plans->activeSeries($tenant));
        $plans->recordUsage($tenant, 'import_rows', 25);   // lo que registra toda importación/lote de ventas al terminar
        [$queries] = $this->observe(fn () => $this->assertSame(7, $plans->activeSeries($tenant)));
        $this->assertSame(1, $queries, 'tras la importación se vuelve a medir una sola vez');
    }

    public function test_the_query_runs_with_a_local_statement_timeout(): void
    {
        [$tenant] = $this->growthWithSales();
        [, $timeouts] = $this->observe(fn () => app(PlanLimitService::class)->activeSeries($tenant));
        $this->assertSame(['SET LOCAL statement_timeout = 5000'], $timeouts);

        config(['predictily.series_count.timeout_ms' => 1234]);
        [, $timeouts] = $this->observe(fn () => app(PlanLimitService::class)->activeSeries($tenant, refresh: true));
        $this->assertSame(['SET LOCAL statement_timeout = 1234'], $timeouts);
    }

    public function test_postgres_reports_a_statement_timeout_with_the_sqlstate_the_counter_expects(): void
    {
        try {
            DB::transaction(function () {
                DB::statement('SET LOCAL statement_timeout = 1');
                DB::select('select pg_sleep(0.5)');
            });
            $this->fail('El tiempo de la sentencia debió cancelarla.');
        } catch (QueryException $e) {
            $this->assertSame(ActiveSeriesCounter::SQLSTATE_QUERY_CANCELED, (string) $e->errorInfo[0]);
            $this->assertTrue(ActiveSeriesCounter::isTimeout($e));
        }
        $this->assertFalse(ActiveSeriesCounter::isTimeout(new QueryException('pgsql', 'select 1', [], new \Exception('otro'))));
    }

    public function test_a_timed_out_count_never_blocks_and_falls_back_to_the_last_known_value(): void
    {
        Queue::fake();
        Log::spy();
        [$tenant, $user] = $this->growthWithSales(['max_series' => 5]);
        $plans = app(PlanLimitService::class);
        $this->assertSame(6, $plans->activeSeries($tenant));     // valor conocido (cacheado y guardado como último)
        Cache::forget('plans:active_series:'.$tenant->id.':'.now('America/Guayaquil')->toDateString());   // simula la expiración del TTL

        $this->app->instance(ActiveSeriesCounter::class, new class extends ActiveSeriesCounter
        {
            public function count(Tenant $tenant, string $since): int
            {
                throw new SeriesCountUnavailable('simulado');
            }
        });
        $api = $this->actingAsTenantUser($user, $tenant);
        $this->assertSame(6, app(PlanLimitService::class)->activeSeries($tenant), 'sin poder medir se usa el último valor conocido');
        $api->postJson('/api/v1/forecast-runs')->assertStatus(402)->assertJsonPath('limit', 'max_series')->assertJsonPath('current', 6);

        // Sin ningún valor previo: no se mide, el tope no se exige en esa petición (falla abierto) y queda el aviso en el log.
        Cache::flush();
        $this->assertNull(app(PlanLimitService::class)->activeSeries($tenant));
        $api->postJson('/api/v1/forecast-runs')->assertStatus(202);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m) => str_contains($m, 'max_series no se exige'))->atLeast()->once();
    }

    public function test_the_admin_listing_never_runs_the_series_query_per_company(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_superadmin' => true])->save();
        $tenants = [];
        foreach (range(1, 3) as $n) {
            [$tenant] = $this->growthWithSales();
            $tenants[] = $tenant;
        }
        Sanctum::actingAs($admin->refresh());

        $res = null;
        [$queries, $timeouts] = $this->observe(function () use (&$res) {
            $res = $this->getJson('/api/v1/admin/tenants')->assertOk();
        });
        $this->assertSame(0, $queries, 'el listado no toca las ventas de ninguna empresa');
        $this->assertSame([], $timeouts);
        $this->assertGreaterThanOrEqual(3, count($res->json('data')));
        foreach ($res->json('data') as $row) {
            $this->assertNull($row['usage']['series'], 'series no se mide en el listado');
            $this->assertArrayHasKey('branches', $row['usage']);
        }

        // El detalle (al editar una empresa) sí la mide, una vez y con caché.
        [$queries] = $this->observe(fn () => $this->patchJson("/api/v1/admin/tenants/{$tenants[0]->uuid}", ['plan_code' => 'growth'])
            ->assertOk()->assertJsonPath('data.usage.series', 6));
        $this->assertSame(1, $queries);
    }

    public function test_tenant_usage_reports_the_cached_series_and_null_without_a_cap(): void
    {
        [$tenant, $user] = $this->growthWithSales();
        $api = $this->actingAsTenantUser($user, $tenant);
        $api->getJson('/api/v1/tenant/usage')->assertOk()->assertJsonPath('data.metrics.series', 6);
        [$queries] = $this->observe(fn () => $api->getJson('/api/v1/tenant/usage')->assertOk()->assertJsonPath('data.metrics.series', 6));
        $this->assertSame(0, $queries, 'la segunda lectura no vuelve a consultar ventas');

        [$basic, $bu] = $this->createTenantWithUser(TenantRole::Owner, ['plan_id' => Plan::query()->where('code', 'basico')->value('id')]);
        $this->actingAsTenantUser($bu, $basic)->getJson('/api/v1/tenant/usage')->assertOk()->assertJsonPath('data.metrics.series', null);
    }

    /** Un producto más vendido en una sucursal: una serie activa nueva. */
    private function seedExtraSeries(Tenant $tenant, array $cat): void
    {
        $now = now();
        $product = (int) DB::table('products')->insertGetId([
            'tenant_id' => $tenant->id, 'category_id' => $cat['categories']['c1'], 'sku' => 'EXTRA-SKU', 'name' => 'Extra', 'unit' => 'unidad', 'is_active' => true,
            'current_price' => 5, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $saleId = (int) DB::table('sales')->insertGetId([
            'tenant_id' => $tenant->id, 'branch_id' => $cat['branches']['b1'], 'document_type' => 'invoice', 'document_number' => '009-001-000000001', 'status' => 'completed',
            'issued_at' => $now, 'sale_date' => $now->toDateString(), 'currency' => 'USD', 'source_hash' => str_repeat('c', 64), 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('sale_items')->insert([
            'tenant_id' => $tenant->id, 'sale_id' => $saleId, 'line_number' => 1, 'product_id' => $product, 'branch_id' => $cat['branches']['b1'], 'sale_date' => $now->toDateString(),
            'quantity' => 2, 'unit_price' => 5, 'discount_amount' => 0, 'net_amount' => 10, 'tax_amount' => 0, 'is_return' => false, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}
