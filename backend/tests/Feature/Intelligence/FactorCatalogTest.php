<?php

declare(strict_types=1);

namespace Tests\Feature\Intelligence;

use App\Enums\TenantRole;
use App\Models\FactorDefinition;
use App\Services\Intelligence\Exceptions\PublicCatalogReadOnlyException;
use App\Services\Intelligence\PlatformWrite;
use App\Support\Tenancy\Exceptions\TenantNotResolvedException;
use Database\Seeders\FactorCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Intelligence\Support\IntelligenceFixtures;
use Tests\TestCase;

/**
 * Catálogo público de factores (V2.1 §24.4; ADR-V25-25): 17 familias catalogadas, todas inactivas; lectura para toda empresa y escritura solo del
 * propietario de la plataforma, tanto por la ruta (/admin) como por el modelo.
 */
final class FactorCatalogTest extends TestCase
{
    use IntelligenceFixtures, RefreshDatabase;

    public function test_seeder_catalogs_the_17_families_all_inactive_with_complete_metadata(): void
    {
        $this->seedFactorCatalog();

        $rows = DB::table('factor_definitions')->whereNull('tenant_id')->get();
        $this->assertGreaterThanOrEqual(17, $rows->count());
        $families = $rows->pluck('family')->unique()->sort()->values()->all();
        $expected = FactorDefinition::FAMILIES;
        sort($expected);
        $this->assertSame($expected, $families, 'Debe haber al menos una definición por cada una de las 17 familias de V2.1 §24.4.');
        $this->assertCount(17, $expected);

        foreach ($rows as $row) {
            $this->assertFalse((bool) $row->is_active, "{$row->key} no puede nacer activa (ninguna familia se activa por defecto).");
            $this->assertContains($row->availability_class, FactorDefinition::AVAILABILITY_CLASSES, $row->key);
            $this->assertContains($row->storage, FactorDefinition::STORAGES, $row->key);
            $this->assertContains($row->missing_strategy, FactorDefinition::MISSING_STRATEGIES, $row->key);
            $this->assertNotEmpty($row->unit, "{$row->key} sin unidad");
            $this->assertNotEmpty($row->frequency, "{$row->key} sin frecuencia");
            $this->assertNotEmpty($row->geo_scope, "{$row->key} sin cobertura geográfica");
            $this->assertNotEmpty($row->sector_justification, "{$row->key} sin justificación sectorial");
            $schema = json_decode($row->schema, true);
            $this->assertArrayHasKey('data_status', $schema, "{$row->key} sin estado real del dato");
            $this->assertSame('a_aprobar_en_el_piloto', $schema['thresholds_status']);
            // Sin integraciones inventadas: ninguna definición declara una fuente o un indicador externo activo.
            $this->assertNull($row->source_key, "{$row->key} no puede declarar una fuente: no hay integraciones externas.");
            $this->assertNull($row->external_indicator_id);
        }
        // Las alertas oficiales son informativas y las clases temporales cubren los pronósticos de terceros.
        $informative = $rows->where('is_informative_alert', true)->pluck('family')->sort()->values()->all();
        $this->assertSame(['animal_plant_health', 'epidemiology', 'food_safety_alerts', 'trade_regulatory'], $informative);
        $this->assertContains('THIRD_PARTY_FORECAST', $rows->pluck('availability_class')->all());
        // Las dos con pronóstico de terceros exigen historial de emisiones (disponibilidad temporal, ADR-V25-06).
        $this->assertTrue(json_decode($rows->firstWhere('key', 'weather.forecast')->schema, true)['requires_issue_history']);
    }

    public function test_seeder_is_idempotent_and_never_overwrites_platform_owner_changes(): void
    {
        $this->seedFactorCatalog();
        $count = DB::table('factor_definitions')->count();
        DB::table('factor_definitions')->where('key', 'sales_history.units_sold')->update(['max_staleness_days' => 99, 'name' => 'Renombrada por la plataforma']);

        $this->seed(FactorCatalogSeeder::class);

        $this->assertSame($count, DB::table('factor_definitions')->count());
        $kept = DB::table('factor_definitions')->where('key', 'sales_history.units_sold')->first();
        $this->assertSame(99, $kept->max_staleness_days);
        $this->assertSame('Renombrada por la plataforma', $kept->name);
        $this->assertSame(0, DB::table('factor_definitions')->where('is_active', true)->count());
    }

    public function test_the_base_database_seeder_loads_the_catalog_and_can_run_twice(): void
    {
        $this->seed();
        $count = DB::table('factor_definitions')->whereNull('tenant_id')->count();
        $this->assertGreaterThanOrEqual(17, $count);

        $this->seed();

        $this->assertSame($count, DB::table('factor_definitions')->whereNull('tenant_id')->count());
        $this->assertSame(0, DB::table('factor_definitions')->where('is_active', true)->count());
    }

    public function test_every_company_role_reads_the_public_catalog_with_state_and_where_the_data_lives(): void
    {
        $this->seedFactorCatalog();
        [$tenant, $viewer] = $this->createTenantWithUser(TenantRole::Viewer);
        // Todos los roles de la empresa leen el catálogo público; la lectura no requiere ser administrador.
        foreach ([TenantRole::Analyst, TenantRole::Admin, TenantRole::Owner] as $role) {
            $this->actingAsTenantUser($this->addUserToTenant($tenant, $role), $tenant)->getJson('/api/v1/intelligence/factors')->assertOk()->assertJsonPath('meta.families', 17);
        }

        $response = $this->actingAsTenantUser($viewer, $tenant)->getJson('/api/v1/intelligence/factors')->assertOk();

        $data = collect($response->json('data'));
        $this->assertSame(17, $data->pluck('family')->unique()->count());
        $this->assertSame(17, $response->json('meta.families'));
        $this->assertSame(0, $response->json('meta.catalog_active'));
        $this->assertSame(0, $response->json('meta.company_activated'));
        foreach ($data as $item) {
            $this->assertSame('public', $item['scope']);
            $this->assertSame('inactive', $item['state']);
            $this->assertFalse($item['catalog_is_active']);
            $this->assertFalse($item['company_activation']);
            $this->assertArrayHasKey('table', $item['data_location']);
            $this->assertNotSame('', $item['data_location']['note']);
            $this->assertSame('none_until_pipeline_enabled', $item['engine_effect']);
        }
        // Dónde vive el dato: tabla nativa, serie externa (sin fuente activa), eventos y contrato desactivado.
        $byKey = $data->keyBy('key');
        $this->assertSame(['kind' => 'native', 'table' => 'sales', 'status' => 'native'], collect($byKey['sales_history.units_sold']['data_location'])->only(['kind', 'table', 'status'])->all());
        $this->assertSame('external_series', $byKey['weather.observed']['data_location']['kind']);
        $this->assertSame('no_source', $byKey['weather.observed']['data_location']['status']);
        $this->assertSame('external_events', $byKey['epidemiology.surveillance_alert']['data_location']['kind']);
        $this->assertSame('contract', $byKey['shared_projections.authorized_forecast']['data_location']['kind']);
        $this->assertSame('contract_disabled', $byKey['shared_projections.authorized_forecast']['data_location']['status']);
        // Filtros.
        $this->getJson('/api/v1/intelligence/factors?family=weather')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/intelligence/factors?is_informative_alert=1')->assertOk()->assertJsonCount(4, 'data');
        $this->getJson('/api/v1/intelligence/factors?state=active')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/intelligence/factors?family=inventada')->assertStatus(422);
    }

    public function test_catalog_requires_authentication_and_a_company(): void
    {
        $this->seedFactorCatalog();
        $this->getJson('/api/v1/intelligence/factors')->assertStatus(401);
    }

    public function test_a_company_sees_its_own_definitions_but_never_those_of_another_company(): void
    {
        $this->seedFactorCatalog();
        [$tenantA, $ownerA] = $this->createTenantWithUser();
        [$tenantB, $ownerB] = $this->createTenantWithUser();
        $this->withinTenant($tenantA, fn () => FactorDefinition::query()->create([
            'family' => 'competition', 'key' => 'competition.private_a', 'name' => 'Competencia privada A', 'availability_class' => 'OBSERVED_HISTORICAL',
            'storage' => 'external_indicator_observations', 'missing_strategy' => 'disable_feature',
        ]));

        $keysA = collect($this->actingAsTenantUser($ownerA, $tenantA)->getJson('/api/v1/intelligence/factors')->json('data'))->pluck('key');
        $keysB = collect($this->actingAsTenantUser($ownerB, $tenantB)->getJson('/api/v1/intelligence/factors')->json('data'))->pluck('key');

        $this->assertTrue($keysA->contains('competition.private_a'));
        $this->assertFalse($keysB->contains('competition.private_a'));
        $this->assertSame('tenant', collect($this->actingAsTenantUser($ownerA, $tenantA)->getJson('/api/v1/intelligence/factors')->json('data'))->firstWhere('key', 'competition.private_a')['scope']);
        $this->assertCount($keysB->count() + 1, $keysA);
    }

    // ── Escritura solo del propietario de la plataforma ──

    public function test_models_are_fail_closed_without_a_company_in_context(): void
    {
        $this->seedFactorCatalog();

        $this->expectException(TenantNotResolvedException::class);
        FactorDefinition::query()->count();
    }

    public function test_a_company_can_never_modify_delete_or_publish_catalog_rows(): void
    {
        $this->seedFactorCatalog();
        [$tenant] = $this->createTenantWithUser();
        $public = FactorDefinition::publicCatalog()->where('key', 'sales_history.units_sold')->firstOrFail();

        $this->withinTenant($tenant, function () use ($tenant): void {
            // Modificar una fila pública.
            $row = FactorDefinition::query()->where('key', 'sales_history.units_sold')->firstOrFail();
            $this->assertTrue($row->isPublic());
            try {
                $row->update(['name' => 'Hackeada']);
                $this->fail('Una empresa no debe poder modificar el catálogo público.');
            } catch (PublicCatalogReadOnlyException) {
                $this->assertTrue(true);
            }
            // Borrarla.
            try {
                $row->delete();
                $this->fail('Una empresa no debe poder borrar el catálogo público.');
            } catch (PublicCatalogReadOnlyException) {
                $this->assertTrue(true);
            }
            // Abrir «modo plataforma» con una empresa en contexto es imposible.
            try {
                PlatformWrite::run(fn () => null);
                $this->fail('PlatformWrite no puede abrirse dentro de una empresa.');
            } catch (PublicCatalogReadOnlyException) {
                $this->assertTrue(true);
            }
            // Crear sin tenant_id queda SIEMPRE a nombre de la empresa (nunca pública).
            $mine = FactorDefinition::query()->create(['family' => 'weather', 'key' => 'weather.mine', 'name' => 'Mi clima', 'availability_class' => 'OBSERVED_HISTORICAL', 'storage' => 'external_indicator_observations']);
            $this->assertSame($tenant->id, $mine->refresh()->tenant_id);
            // Hacer pública una fila privada (cambiar su tenant_id) está prohibido.
            try {
                $mine->forceFill(['tenant_id' => null])->save();
                $this->fail('No se puede volver pública una fila privada.');
            } catch (PublicCatalogReadOnlyException) {
                $this->assertTrue(true);
            }
        });
        $this->assertSame('Ventas históricas (unidades)', DB::table('factor_definitions')->where('id', $public->id)->value('name'));
        $this->assertSame($tenant->id, (int) DB::table('factor_definitions')->where('key', 'weather.mine')->value('tenant_id'), 'La fila privada sigue siendo de la empresa.');
    }

    public function test_a_company_cannot_create_rows_for_another_company(): void
    {
        [$tenantA] = $this->createTenantWithUser();
        [$tenantB] = $this->createTenantWithUser();

        $this->expectException(TenantNotResolvedException::class);
        $this->withinTenant($tenantA, function () use ($tenantB): void {
            $row = new FactorDefinition;
            $row->forceFill(['tenant_id' => $tenantB->id, 'family' => 'weather', 'key' => 'weather.ajena', 'name' => 'x', 'availability_class' => 'STATIC', 'storage' => 'sales'])->save();
        });
    }

    public function test_only_the_platform_owner_writes_the_catalog_through_the_admin_routes(): void
    {
        $this->seedFactorCatalog();
        [$tenant, $owner] = $this->createTenantWithUser(TenantRole::Owner);
        $payload = [
            'family' => 'search_trends', 'key' => 'search_trends.nuevo', 'name' => 'Interés nuevo', 'availability_class' => 'OBSERVED_HISTORICAL',
            'storage' => 'external_indicator_observations', 'unit' => 'índice', 'frequency' => 'W', 'geo_scope' => 'country', 'missing_strategy' => 'disable_feature',
        ];

        // El propietario de una EMPRESA no escribe el catálogo público (ni siquiera con su rol más alto).
        $this->actingAsTenantUser($owner, $tenant)->postJson('/api/v1/admin/intelligence/factors', $payload)->assertStatus(403);
        $this->patchJson('/api/v1/admin/intelligence/factors/sales_history.units_sold', ['name' => 'x'])->assertStatus(403);
        $this->flushHeaders();
        $this->postJson('/api/v1/admin/intelligence/factors', $payload)->assertStatus(403);

        // El propietario de la plataforma sí; nace inactiva y queda auditado.
        $this->actingAsSuperadmin();
        $created = $this->postJson('/api/v1/admin/intelligence/factors', $payload)->assertCreated()->json('data');
        $this->assertSame('public', $created['scope']);
        $this->assertFalse($created['catalog_is_active']);
        $this->assertNull(DB::table('factor_definitions')->where('key', 'search_trends.nuevo')->value('tenant_id'));
        $this->postJson('/api/v1/admin/intelligence/factors', $payload)->assertStatus(409)->assertJsonPath('code', 'factor_key_taken');
        $this->patchJson('/api/v1/admin/intelligence/factors/search_trends.nuevo', ['name' => 'Interés renombrado', 'max_staleness_days' => 21])->assertOk()->assertJsonPath('data.name', 'Interés renombrado');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'intelligence.factor_definition_created')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'intelligence.factor_definition_updated')->count());
        $this->getJson('/api/v1/admin/intelligence/factors')->assertOk()->assertJsonPath('meta.total', DB::table('factor_definitions')->whereNull('tenant_id')->count());
        $this->patchJson('/api/v1/admin/intelligence/factors/no.existe', ['name' => 'x'])->assertStatus(404);
    }

    public function test_the_platform_owner_cannot_declare_active_an_external_factor_without_source(): void
    {
        $this->seedFactorCatalog();
        $this->actingAsSuperadmin();

        // Sin integraciones externas: no se puede activar en el catálogo una familia externa sin fuente (no se inventan conectores).
        $this->patchJson('/api/v1/admin/intelligence/factors/weather.observed', ['is_active' => true])->assertStatus(422)->assertJsonPath('code', 'factor_without_source');
        // Con una fuente declarada sí; la de proyecciones compartidas depende de la bandera (apagada por defecto).
        $this->patchJson('/api/v1/admin/intelligence/factors/weather.observed', ['is_active' => true, 'source_key' => 'fuente_aprobada_futura'])->assertOk()->assertJsonPath('data.catalog_is_active', true);
        $this->patchJson('/api/v1/admin/intelligence/factors/shared_projections.authorized_forecast', ['is_active' => true])->assertStatus(422)->assertJsonPath('code', 'factor_feature_disabled');
        // Un indicador privado de una empresa no puede colgar de una definición pública.
        [$tenant] = $this->createTenantWithUser();
        $indicator = DB::table('external_indicators')->insertGetId([
            'tenant_id' => $tenant->id, 'code' => 'PRIV', 'name' => 'Privado', 'source' => 'manual', 'frequency' => 'monthly', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->patchJson('/api/v1/admin/intelligence/factors/competition.competitor_price', ['storage' => 'external_indicator_observations', 'external_indicator_id' => $indicator])->assertStatus(422);
    }
}
