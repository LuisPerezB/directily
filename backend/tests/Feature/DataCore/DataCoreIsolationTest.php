<?php

declare(strict_types=1);

namespace Tests\Feature\DataCore;

use App\Models\CalendarEvent;
use App\Models\CommercialCommitment;
use App\Models\DataSource;
use App\Models\SourceMapping;
use App\Models\SourceSync;
use App\Models\UnitConversion;
use App\Models\Warehouse;
use App\Support\Tenancy\Exceptions\TenantNotResolvedException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsImportFiles;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\DataCore\Support\ImportsCsv;
use Tests\TestCase;

/** E2-17 (parte datos/extensiones): la empresa A no lee ni escribe mapeos, credenciales, sincronizaciones, calendarios, compromisos, almacenes ni conversiones de B. */
final class DataCoreIsolationTest extends TestCase
{
    use BuildsImportFiles, ImportsCsv, InteractsWithTenants, RefreshDatabase;

    private $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeImportInfrastructure();
    }

    public function test_company_b_never_sees_what_company_a_loaded(): void
    {
        [$a, $userA] = $this->createTenantWithUser();
        $this->tenant = $a;
        $this->createBranch($a, '001', 'Matriz A');
        $this->actingAsTenantUser($userA, $a);
        $this->importCsv('products', ['sku', 'name', 'unit'], [['CEM-50', 'Cemento', 'unidad']]);
        $this->importCsv('warehouses', ['code', 'name', 'branch_code'], [['ALM-A', 'Bodega A', '001']]);
        $this->importCsv('unit_conversions', ['product_sku', 'from_unit', 'to_unit', 'factor'], [['CEM-50', 'caja', 'unidad', 12]]);
        $this->importCsv('calendar_events', ['scope', 'scope_ref', 'code', 'name', 'category', 'starts_on', 'known_at'], [['country', 'EC', 'evento_a', 'Evento A', 'holiday', '2026-12-25', '2026-01-01']]);
        $this->importCsv('commitments', ['commitment_type', 'external_id', 'issued_at', 'known_at', 'product_sku', 'quantity'], [['pedido', 'A-1', '2026-09-01', '2026-09-01 10:00:00', 'CEM-50', 10]]);
        $this->putJson('/api/v1/data-coverage', ['entity' => 'sales', 'complete_through' => '2026-10-20'])->assertOk();
        $source = (int) $this->postJson('/api/v1/data-sources', ['type' => 'connector', 'connector_key' => 'sql_postgresql', 'name' => 'ERP A',
            'config' => ['host' => '127.0.0.1', 'database' => 'erp'], 'credentials' => ['username' => 'u', 'password' => 'clave-secreta-A']])->assertCreated()->json('data.id');

        [$b, $userB] = $this->createTenantWithUser();
        $this->actingAsTenantUser($userB, $b);
        foreach (['warehouses', 'unit-conversions', 'commitments', 'data-coverage'] as $path) {
            $body = $this->getJson("/api/v1/{$path}")->assertOk()->json('data');
            $this->assertSame([], $body, "B no debe ver {$path} de A");
        }
        $this->assertSame([], $this->getJson('/api/v1/calendar-events')->json('data'));
        $this->assertSame([], $this->getJson('/api/v1/data-sources')->json('data'));
        $this->getJson("/api/v1/data-sources/{$source}/mappings")->assertNotFound();
        $this->postJson("/api/v1/data-sources/{$source}/sync", ['entity' => 'sales'])->assertNotFound();
        $quality = $this->getJson('/api/v1/data-quality/extended')->assertOk()->json('data.sections');
        $this->assertSame(0, $quality['units']['declared_conversions']);
        $this->assertSame('no_data', $quality['sales_days']['status']);
        $this->assertStringNotContainsString('clave-secreta-A', $this->getJson('/api/v1/data-sources')->getContent());

        // Escribir en B con identificadores de A no afecta a A.
        $this->createBranch($b, '001', 'Matriz B');
        $this->postJson('/api/v1/warehouses', ['code' => 'ALM-A', 'name' => 'Bodega de B', 'branch_code' => '001'])->assertCreated();
        $this->assertSame('Bodega A', DB::table('warehouses')->where('tenant_id', $a->id)->where('code', 'ALM-A')->value('name'));
        $this->assertSame(2, DB::table('warehouses')->where('code', 'ALM-A')->count(), 'el mismo código en otra empresa es otro almacén');
    }

    public function test_new_models_fail_closed_without_a_tenant_and_composite_fks_hold(): void
    {
        foreach ([SourceMapping::class, SourceSync::class, CommercialCommitment::class, Warehouse::class, UnitConversion::class, CalendarEvent::class] as $model) {
            try {
                $model::query()->count();
                $this->fail("{$model} consultó sin empresa activa");
            } catch (TenantNotResolvedException) {
                $this->addToAssertionCount(1);
            }
        }
        [$a] = $this->createTenantWithUser();
        [$b] = $this->createTenantWithUser();
        $sourceA = $this->withinTenant($a, fn () => DataSource::query()->create(['type' => 'rest_api', 'connector_key' => 'generic_rest', 'name' => 'A', 'config' => []]));
        $this->expectException(QueryException::class);
        DB::table('source_syncs')->insert(['tenant_id' => $b->id, 'data_source_id' => $sourceA->id, 'entity' => 'sales', 'mode' => 'full', 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
    }
}
