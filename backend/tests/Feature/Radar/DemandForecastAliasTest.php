<?php

declare(strict_types=1);

namespace Tests\Feature\Radar;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Analytics\Support\AnalyticsFixtures;
use Tests\TestCase;

/**
 * V2.5 · W5-A: GET /demand/forecasts (V25-AGD-014, -017) es un alias V2.2 de los pronósticos agregados vigentes: mismo cuerpo, misma validación y mismo aislamiento, sin
 * controlador duplicado (delega en /forecasts/products, /forecasts/categories o /forecasts/series).
 */
class DemandForecastAliasTest extends TestCase
{
    use AnalyticsFixtures;
    use RefreshDatabase;

    private const CUTOFF = '2026-09-30';

    /** @return array{tenant: Tenant, user: User, cat: array} */
    private function company(TenantRole $role = TenantRole::Viewer): array
    {
        [$tenant, $user] = $this->createTenantWithUser($role);
        $cat = $this->seedCatalog($tenant);
        $this->seedSales($tenant, $cat, self::CUTOFF, 40);
        $this->seedForecastRun($tenant, $cat, self::CUTOFF, ['horizon' => 30]);

        return compact('tenant', 'user', 'cat');
    }

    /** Mismo cuerpo salvo las URL de paginación. */
    private function body(array $json): array
    {
        unset($json['links'], $json['meta']['path'], $json['meta']['links']);

        return $json;
    }

    public function test_every_level_answers_with_the_body_of_the_current_forecast_endpoint(): void
    {
        $c = $this->company();
        $api = $this->actingAsTenantUser($c['user'], $c['tenant']);
        $branch = $c['cat']['branches']['b1'];
        $product = $c['cat']['products']['p1'];

        $cases = [
            ['/demand/forecasts?level=product&horizon=30', '/forecasts/products?horizon=30', '/forecasts/products'],
            ['/demand/forecasts?horizon=30&sort=-forecast_units&per_page=2', '/forecasts/products?horizon=30&sort=-forecast_units&per_page=2', '/forecasts/products'],
            ['/demand/forecasts?level=category&horizon=30', '/forecasts/categories?horizon=30', '/forecasts/categories'],
            ["/demand/forecasts?level=series&horizon=7&branch_id={$branch}&product_id={$product}", "/forecasts/series?horizon=7&branch_id={$branch}&product_id={$product}", '/forecasts/series'],
        ];
        foreach ($cases as [$alias, $direct, $equivalent]) {
            $a = $api->getJson("/api/v1{$alias}")->assertOk();
            $d = $api->getJson("/api/v1{$direct}")->assertOk();
            $this->assertEquals($this->body($d->json()), $this->body($a->json()), "{$alias} ≠ {$direct}");
            $this->assertSame("/api/v1{$equivalent}", $a->headers->get('X-Equivalent-Of'));
        }
        $products = $api->getJson('/api/v1/demand/forecasts?horizon=30')->json('data');
        $this->assertCount(3, $products, 'sin level se usa el nivel de producto');
        $this->assertNotEmpty($api->getJson('/api/v1/demand/forecasts?level=series&horizon=7')->json('data.forecast'));
    }

    public function test_the_validation_of_the_underlying_endpoint_applies_through_the_alias(): void
    {
        $c = $this->company();
        $other = $this->company();
        $api = $this->actingAsTenantUser($c['user'], $c['tenant']);

        $api->getJson('/api/v1/demand/forecasts?level=region')->assertStatus(422)->assertJsonValidationErrors('level');
        $api->getJson('/api/v1/demand/forecasts?horizon=5')->assertStatus(422)->assertJsonValidationErrors('horizon');
        $api->getJson('/api/v1/demand/forecasts?category_id='.$other['cat']['categories']['c1'])->assertStatus(422)->assertJsonValidationErrors('category_id');
        $api->getJson('/api/v1/demand/forecasts?level=series&product_id='.$other['cat']['products']['p1'])->assertStatus(422);
    }

    public function test_the_alias_is_isolated_by_company_and_open_to_every_existing_role(): void
    {
        $a = $this->company(TenantRole::Analyst);
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Owner);   // otra empresa sin pronóstico

        $mine = $this->actingAsTenantUser($a['user'], $a['tenant'])->getJson('/api/v1/demand/forecasts?horizon=30')->assertOk();
        $theirs = $this->actingAsTenantUser($user, $tenant)->getJson('/api/v1/demand/forecasts?horizon=30')->assertOk();

        $this->assertCount(3, $mine->json('data'));
        $this->assertSame([], $theirs->json('data'));
        $this->assertNull($theirs->json('run'));
        foreach ([TenantRole::Viewer, TenantRole::Admin] as $role) {
            $c = $this->company($role);
            $this->actingAsTenantUser($c['user'], $c['tenant'])->getJson('/api/v1/demand/forecasts?horizon=30')->assertOk();
        }
    }

    public function test_it_requires_a_session(): void
    {
        $this->getJson('/api/v1/demand/forecasts')->assertUnauthorized();
    }
}
