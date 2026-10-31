<?php

declare(strict_types=1);

namespace Tests\Feature\DataCore;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DataCore\Units\UnitConverter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsImportFiles;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\DataCore\Support\ImportsCsv;
use Tests\TestCase;

/** Conversiones de unidades, almacenes, las tres fechas (evento / fuente / ingestión) y cobertura de fuentes. */
final class CanonicalDataTest extends TestCase
{
    use BuildsImportFiles, ImportsCsv, InteractsWithTenants, RefreshDatabase;

    private const SALES = ['document_number', 'issued_at', 'branch_code', 'product_sku', 'product_name', 'quantity', 'unit_price', 'customer_code', 'source_recorded_at'];

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeImportInfrastructure();
        $this->travelTo(CarbonImmutable::parse('2026-10-29 15:00:00', 'America/Guayaquil'));
        [$this->tenant, $this->owner] = $this->createTenantWithUser();
        $this->createBranch($this->tenant, '001', 'Matriz');
        $this->actingAsTenantUser($this->owner, $this->tenant);
        $this->importCsv('products', ['sku', 'name', 'unit'], [['CEM-50', 'Cemento 50kg', 'unidad'], ['ARR-01', 'Arroz', 'kg']]);
    }

    // ───────────────────────── conversiones de unidades ─────────────────────────

    public function test_unit_conversions_import_is_idempotent_and_validated(): void
    {
        $headers = ['product_sku', 'from_unit', 'to_unit', 'factor', 'valid_from'];
        $first = $this->importCsv('unit_conversions', $headers, [['CEM-50', 'caja', 'unidad', 12, ''], ['', 'quintal', 'kg', 45.36, ''], ['ARR-01', 'saco', 'kg', 50, '2026-01-01']]);
        $this->assertSame(3, $first->stats['conversions_new']);
        $same = $this->importCsv('unit_conversions', $headers, [['CEM-50', 'caja', 'unidad', 12, '']]);
        $this->assertSame(1, $same->stats['conversions_unchanged']);
        $changed = $this->importCsv('unit_conversions', $headers, [['CEM-50', 'Caja', 'UNIDAD', 24, '']]);
        $this->assertSame(1, $changed->stats['conversions_updated'], 'la tipografía se normaliza: es la misma conversión');
        $this->assertSame(3, DB::table('unit_conversions')->where('tenant_id', $this->tenant->id)->count());

        $bad = $this->importCsv('unit_conversions', $headers, [
            ['CEM-50', 'unidad', 'unidad', 1, ''],      // sin nada que convertir
            ['CEM-50', 'par', 'unidad', 0, ''],         // factor no positivo
            ['NO-EXISTE', 'caja', 'unidad', 3, ''],     // producto inexistente
            ['CEM-50', 'unidad', 'caja', 0.5, ''],      // contradice el sentido inverso ya declarado (caja→unidad = 24)
            ['', 'docena', 'unidad', 12, ''], ['', 'docena', 'unidad', 12, ''], // repetida
        ], confirm: false);
        $codes = array_column($this->importErrors($bad), 'error_code');
        foreach (['invalid_value', 'invalid_number', 'unknown_product', 'conversion_conflict', 'duplicate_in_file'] as $code) {
            $this->assertContains($code, $codes);
        }
    }

    public function test_converter_resolves_only_declared_conversions(): void
    {
        $this->importCsv('unit_conversions', ['product_sku', 'from_unit', 'to_unit', 'factor', 'valid_from'], [
            ['', 'caja', 'unidad', 10, ''], ['CEM-50', 'caja', 'unidad', 12, ''], ['', 'quintal', 'kg', 45, ''], ['', 'kg', 'g', 1000, ''], ['', 'saco', 'kg', 50, '2026-06-01'], ['', 'saco', 'kg', 55, '2026-09-01'],
        ]);
        $converter = new UnitConverter($this->tenant);
        $cem = (int) DB::table('products')->where('tenant_id', $this->tenant->id)->where('sku', 'CEM-50')->value('id');
        $this->assertSame(1.0, $converter->resolve($cem, 'Unidad', 'unidad')->factor, 'misma unidad: factor 1');
        $this->assertSame(12.0, $converter->resolve($cem, 'caja', 'unidad')->factor, 'el producto manda sobre lo general');
        $this->assertSame(10.0, $converter->resolve(null, 'caja', 'unidad')->factor);
        $inverse = $converter->resolve(null, 'unidad', 'caja');
        $this->assertEqualsWithDelta(0.1, $inverse->factor, 1e-12);
        $this->assertTrue($inverse->inverse);
        $this->assertNull($converter->resolve(null, 'quintal', 'g'), 'sin cadenas transitivas: quintal→kg→g no se asume');
        $this->assertNull($converter->resolve(null, 'und', 'unidad'), 'und no es igual a unidad hasta declararlo');
        $this->assertNull($converter->resolve(null, 'litro', 'kg'));
        $this->assertSame(50.0, $converter->resolve(null, 'saco', 'kg', '2026-07-15')->factor, 'vigente en esa fecha');
        $this->assertSame(55.0, $converter->resolve(null, 'saco', 'kg', '2026-10-01')->factor);
        $this->assertNull($converter->resolve(null, 'saco', 'kg', '2026-05-01'), 'antes de su vigencia no existe');
    }

    // ───────────────────────── almacenes ─────────────────────────

    public function test_warehouses_import_requires_branch_and_capacity_unit(): void
    {
        $headers = ['code', 'name', 'branch_code', 'storage_capacity', 'capacity_unit', 'is_active'];
        $ok = $this->importCsv('warehouses', $headers, [['ALM-1', 'Bodega central', '001', 500, 'm3', 'sí'], ['ALM-2', 'Bodega auxiliar', '001', '', '', 'no']]);
        $this->assertSame(2, $ok->stats['warehouses_new']);
        $this->assertSame(1, $this->importCsv('warehouses', $headers, [['ALM-1', 'Bodega central', '001', 500, 'm3', 'sí']])->stats['warehouses_unchanged']);
        $this->assertSame(1, $this->importCsv('warehouses', $headers, [['alm-1', 'Bodega central 2', '001', 600, 'm3', 'sí']])->stats['warehouses_updated']);
        $this->assertSame(2, DB::table('warehouses')->where('tenant_id', $this->tenant->id)->count());
        $this->assertNull(DB::table('warehouses')->where('code', 'ALM-2')->value('storage_capacity'), 'capacidad desconocida = NULL, no cero');

        $bad = $this->importCsv('warehouses', $headers, [['ALM-3', 'x', '001', 100, '', 'sí'], ['ALM-4', 'x', '777', '', '', 'sí']], confirm: false);
        $codes = array_column($this->importErrors($bad), 'error_code');
        $this->assertContains('capacity_unit_required', $codes);
        $this->assertContains('unknown_branch', $codes);
        $this->postJson('/api/v1/warehouses', ['code' => 'ALM-9', 'name' => 'Nueva', 'branch_code' => '001', 'storage_capacity' => 40, 'capacity_unit' => 'pallets'])->assertCreated()->assertJsonPath('data.capacity_unit', 'pallets');
        $this->assertCount(3, $this->getJson('/api/v1/warehouses')->json('data'));
    }

    // ───────────────────────── tres fechas ─────────────────────────

    public function test_sales_keep_event_source_and_ingestion_dates_and_unknown_stays_null(): void
    {
        $this->importCsv('sales', self::SALES, [
            ['F-1', '2026-10-01 10:00:00', '001', 'CEM-50', 'Cemento', 3, 8.5, '1710034065', '2026-10-02 09:00:00'],
            ['F-2', '2026-10-02 11:00:00', '001', 'CEM-50', 'Cemento', 2, 8.5, '9999999999999', ''],
            ['F-3', '2026-10-03 11:00:00', '001', 'CEM-50', 'Cemento', 1, 8.5, '', 'no-es-fecha'],
        ], confirm: false)->refresh();
        $job = $this->importCsv('sales', self::SALES, [
            ['F-1', '2026-10-01 10:00:00', '001', 'CEM-50', 'Cemento', 3, 8.5, '1710034065', '2026-10-02 09:00:00'],
            ['F-2', '2026-10-02 11:00:00', '001', 'CEM-50', 'Cemento', 2, 8.5, '9999999999999', ''],
            ['F-3', '2026-10-03 11:00:00', '001', 'CEM-50', 'Cemento', 1, 8.5, '', 'no-es-fecha'],
        ]);
        $sales = DB::table('sales')->where('tenant_id', $this->tenant->id)->orderBy('document_number')->get()->keyBy('document_number');
        $this->assertSame('2026-10-01', $sales['F-1']->sale_date, 'fecha del evento');
        $this->assertNotNull($sales['F-1']->source_recorded_at, 'disponibilidad en la fuente');
        $this->assertSame('2026-10-02 14:00:00+00', $sales['F-1']->source_recorded_at);
        $this->assertNotNull($sales['F-1']->created_at, 'ingestión');
        $this->assertNull($sales['F-2']->source_recorded_at, 'sin dato: queda NULL, nunca la fecha de la carga');
        $this->assertNull($sales['F-3']->source_recorded_at, 'fecha inválida: advertencia y NULL');
        $this->assertContains('invalid_date', array_column($this->importErrors($job, 'warning'), 'error_code'));
        $this->assertTrue((bool) DB::table('customers')->where('tenant_id', $this->tenant->id)->where('external_code', 'CONSUMIDOR_FINAL')->value('is_generic'));
        $this->assertFalse((bool) DB::table('customers')->where('tenant_id', $this->tenant->id)->where('external_code', 'like', 'id:%')->value('is_generic'));

        // Reimportar el mismo contenido no cambia nada, ni siquiera la fecha de registro en la fuente.
        $again = $this->importCsv('sales', self::SALES, [['F-1', '2026-10-01 10:00:00', '001', 'CEM-50', 'Cemento', 3, 8.5, '1710034065', '2026-10-20 09:00:00']]);
        $this->assertSame(1, $again->stats['documents_unchanged']);
        $this->assertSame('2026-10-02 14:00:00+00', DB::table('sales')->where('document_number', 'F-1')->value('source_recorded_at'));
        // Un cambio de contenido adopta la nueva fecha de la fuente.
        $this->importCsv('sales', self::SALES, [['F-1', '2026-10-01 10:00:00', '001', 'CEM-50', 'Cemento', 4, 8.5, '1710034065', '2026-10-20 09:00:00']]);
        $this->assertSame('2026-10-20 14:00:00+00', DB::table('sales')->where('document_number', 'F-1')->value('source_recorded_at'));
    }

    public function test_rest_batch_accepts_source_recorded_at(): void
    {
        $this->postJson('/api/v1/sales/batch', ['documents' => [
            ['document_number' => 'R-1', 'issued_at' => '2026-10-01 10:00:00', 'branch_code' => '001', 'source_recorded_at' => '2026-10-01T12:00:00-05:00',
                'items' => [['product_sku' => 'CEM-50', 'quantity' => 1, 'unit_price' => 8.5]]],
            ['document_number' => 'R-2', 'issued_at' => '2026-10-02 10:00:00', 'branch_code' => '001', 'items' => [['product_sku' => 'CEM-50', 'quantity' => 1, 'unit_price' => 8.5]]],
        ]])->assertOk()->assertJsonPath('data.created', 2);
        $this->assertSame('2026-10-01 17:00:00+00', DB::table('sales')->where('document_number', 'R-1')->value('source_recorded_at'));
        $this->assertNull(DB::table('sales')->where('document_number', 'R-2')->value('source_recorded_at'));
    }

    public function test_price_and_cost_history_keep_when_the_source_knew_them_or_null(): void
    {
        $headers = ['sku', 'name', 'unit', 'current_price', 'current_cost', 'price_known_at', 'cost_known_at'];
        $this->importCsv('products', $headers, [
            ['P-1', 'Con fechas', 'kg', 10, 6, '2026-10-01 08:00:00', '2026-09-28 08:00:00'],
            ['P-2', 'Sin fechas', 'kg', 20, 12, '', ''],
        ]);
        $id = fn (string $sku) => DB::table('products')->where('tenant_id', $this->tenant->id)->where('sku', $sku)->value('id');
        $price1 = DB::table('product_price_history')->where('tenant_id', $this->tenant->id)->where('product_id', $id('P-1'))->first();
        $cost1 = DB::table('purchase_cost_history')->where('tenant_id', $this->tenant->id)->where('product_id', $id('P-1'))->first();
        $this->assertSame('2026-10-01 13:00:00+00', $price1->known_at);
        $this->assertSame('2026-09-28 13:00:00+00', $cost1->known_at);
        $this->assertSame('kg', $cost1->unit, 'el costo queda expresado en la unidad del producto');
        $this->assertNull(DB::table('product_price_history')->where('tenant_id', $this->tenant->id)->where('product_id', $id('P-2'))->value('known_at'), 'desconocido queda NULL (no la fecha de la carga)');
        $this->assertNull(DB::table('purchase_cost_history')->where('tenant_id', $this->tenant->id)->where('product_id', $id('P-2'))->value('known_at'));

        $bad = $this->importCsv('products', $headers, [['P-3', 'Futuro', 'kg', 1, 1, '2027-01-01 00:00:00', 'no-es-fecha']], confirm: false);
        $this->assertEqualsCanonicalizing(['future_date', 'invalid_date'], array_column($this->importErrors($bad), 'error_code'));
    }

    public function test_new_canonical_entities_publish_their_field_contract_and_templates(): void
    {
        $required = ['commitments' => ['commitment_type', 'external_id', 'issued_at', 'product_sku', 'quantity'], 'calendar_events' => ['scope', 'code', 'name', 'category', 'starts_on'],
            'unit_conversions' => ['from_unit', 'to_unit', 'factor'], 'warehouses' => ['code', 'name', 'branch_code']];
        foreach ($required as $entity => $mustHave) {
            $fields = collect($this->getJson("/api/v1/import-fields/{$entity}")->assertOk()->json('data'));
            $this->assertEqualsCanonicalizing($mustHave, $fields->where('required', true)->pluck('key')->all(), "campos obligatorios de {$entity}");
            $this->assertTrue($fields->every(fn ($f) => $f['description'] !== '' && in_array($f['type'], ['string', 'date', 'datetime', 'number', 'integer', 'boolean'], true)));
            $template = $this->get("/api/v1/import-templates/{$entity}")->assertOk()->streamedContent();
            $this->assertSame($fields->pluck('key')->all(), str_getcsv(ltrim(strtok($template, "\n"), "\xEF\xBB\xBF"), ',', '"', ''));
        }
        $this->assertContains('source_recorded_at', collect($this->getJson('/api/v1/import-fields/sales')->json('data'))->pluck('key')->all());
    }

    // ───────────────────────── cobertura ─────────────────────────

    public function test_coverage_declaration_precedence_and_validation(): void
    {
        $branch = (int) DB::table('branches')->where('tenant_id', $this->tenant->id)->value('id');
        $this->assertSame('none', $this->getJson('/api/v1/data-coverage')->assertOk()->json('effective.sales.rule'));

        $this->putJson('/api/v1/data-coverage', ['entity' => 'sales', 'complete_from' => '2026-01-01', 'complete_through' => '2026-10-15'])->assertOk()
            ->assertJsonPath('data.basis', 'declared')->assertJsonPath('effective.complete_through', '2026-10-15')->assertJsonPath('effective.rule', 'declared_by_user');
        $this->putJson('/api/v1/data-coverage', ['entity' => 'sales', 'complete_from' => '2026-02-01', 'complete_through' => '2026-10-20'])->assertOk();
        $this->assertSame(1, DB::table('data_coverage')->where('tenant_id', $this->tenant->id)->count(), 'upsert por entidad/fuente/sucursal');
        // Una sucursal con cobertura propia (más corta) manda para esa sucursal.
        $this->putJson('/api/v1/data-coverage', ['entity' => 'sales', 'complete_through' => '2026-09-30', 'branch_id' => $branch])->assertOk();
        $coverage = $this->getJson('/api/v1/data-coverage')->json();
        $this->assertSame('2026-10-20', $coverage['effective']['sales']['complete_through']);

        foreach ([
            ['entity' => 'ventas', 'complete_through' => '2026-10-01'],
            ['entity' => 'sales', 'complete_through' => '2026-11-30'],                         // futuro
            ['entity' => 'sales', 'complete_from' => '2026-10-10', 'complete_through' => '2026-10-01'],
            ['entity' => 'sales'],
            ['entity' => 'sales', 'complete_through' => '2026-10-01', 'data_source_id' => 999999],
        ] as $payload) {
            $this->putJson('/api/v1/data-coverage', $payload)->assertStatus(422);
        }
        $viewer = $this->addUserToTenant($this->tenant, TenantRole::Viewer);
        $this->actingAsTenantUser($viewer, $this->tenant);
        $this->getJson('/api/v1/data-coverage')->assertOk();
        $this->putJson('/api/v1/data-coverage', ['entity' => 'sales', 'complete_through' => '2026-10-01'])->assertForbidden();
    }

    public function test_coverage_is_isolated_between_tenants(): void
    {
        $this->putJson('/api/v1/data-coverage', ['entity' => 'sales', 'complete_through' => '2026-10-20'])->assertOk();
        $sourceA = $this->postJson('/api/v1/data-sources', ['type' => 'rest_api', 'name' => 'REST A'])->assertCreated()->json('data.id');
        [$tenantB, $userB] = $this->createTenantWithUser();
        $this->actingAsTenantUser($userB, $tenantB);
        $this->assertSame([], $this->getJson('/api/v1/data-coverage')->json('data'));
        $this->assertSame('none', $this->getJson('/api/v1/data-coverage')->json('effective.sales.rule'));
        $this->putJson('/api/v1/data-coverage', ['entity' => 'sales', 'complete_through' => '2026-10-01', 'data_source_id' => $sourceA])->assertStatus(422);
        $this->assertSame(1, DB::table('data_coverage')->count());
    }

    // ───────────────────────── reporte de calidad ampliado ─────────────────────────

    public function test_extended_quality_report_describes_coverage_gaps_identity_codes_prices_and_units(): void
    {
        // Ventas del 1 al 5 y del 10 al 12 de octubre (faltan 6-9), precios que cambian, cliente genérico y sin cliente.
        $rows = [];
        foreach ([1, 2, 3, 4, 5, 10, 11, 12] as $i => $day) {
            $rows[] = ['Q-'.$day, sprintf('2026-10-%02d 10:00:00', $day), '001', 'CEM-50', 'Cemento', 2, $day < 10 ? 8.0 : 10.0, $i % 2 === 0 ? '9999999999999' : '', ''];
        }
        $this->importCsv('sales', self::SALES, $rows);
        // Mismo nombre con otro código (posible cambio de código) en períodos consecutivos.
        $this->importCsv('products', ['sku', 'name', 'unit'], [['CEM-50B', 'Cemento 50kg', 'unidad']]);
        $this->importCsv('sales', self::SALES, [['Q-20', '2026-10-20 10:00:00', '001', 'CEM-50B', 'Cemento', 1, 10, '', '']]);
        // Costo con unidad distinta de la del producto y sin conversión.
        DB::table('purchase_cost_history')->insert(['tenant_id' => $this->tenant->id, 'product_id' => DB::table('products')->where('tenant_id', $this->tenant->id)->where('sku', 'ARR-01')->value('id'),
            'unit_cost' => 20, 'currency' => 'USD', 'valid_from' => '2026-10-01', 'source' => 'import', 'created_at' => now(), 'unit' => 'quintal']);

        $report = $this->getJson('/api/v1/data-quality/extended')->assertOk()->json('data');
        $sections = $report['sections'];
        $this->assertSame('warning', $report['overall_status']);

        $sales = $sections['temporal_coverage']['entities']['sales'];
        $this->assertSame('2026-10-01', $sales['observed_from']);
        $this->assertSame('undeclared', $sales['status']);
        $this->assertSame('none', $sales['coverage']['rule']);

        // Sin cobertura declarada: los días sin ventas son AUSENTES (no se pueden tratar como cero).
        $days = $sections['sales_days'];
        $this->assertSame('absent_days_coverage_unknown', $days['classification']);
        $this->assertSame(0, $days['observed_zero_days']);
        $this->assertGreaterThanOrEqual(4, $days['days_without_sales']);
        $this->assertSame(['from' => '2026-10-06', 'to' => '2026-10-09', 'days' => 4], collect($days['longest_gaps'])->first(fn ($g) => $g['days'] === 4) ? ['from' => '2026-10-06', 'to' => '2026-10-09', 'days' => 4] : null);

        // Con cobertura declarada los mismos días son ceros observados.
        $this->putJson('/api/v1/data-coverage', ['entity' => 'sales', 'complete_from' => '2026-10-01', 'complete_through' => '2026-10-20'])->assertOk();
        $declared = $this->getJson('/api/v1/data-quality/extended')->json('data.sections.sales_days');
        $this->assertSame('observed_zero_days', $declared['classification']);
        $this->assertSame(9, $declared['absent_days'], 'los días posteriores a la cobertura (21-29/10) son ausentes, no ceros');
        $this->assertSame(9, $declared['days_after_coverage']);
        $this->assertSame(['from' => '2026-10-01', 'to' => '2026-10-20', 'days' => 20], $declared['range']);

        $identity = $sections['customer_identity'];
        $this->assertSame(9, $identity['documents_total']);
        $this->assertSame(5, $identity['documents_without_customer']);
        $this->assertSame(4, $identity['documents_with_generic_customer']);
        $this->assertSame('warning', $identity['status']);
        $this->assertGreaterThan(0.9, $identity['share_without_stable_identifier']);

        $codes = $sections['product_code_changes'];
        $this->assertSame(1, $codes['groups_detected']);
        $this->assertEqualsCanonicalizing(['CEM-50', 'CEM-50B'], $codes['groups'][0]['skus']);
        $this->assertSame('sequential_codes_possible_code_change', $codes['groups'][0]['pattern'], 'el primer código deja de venderse el 12/10 y el segundo empieza el 20/10: sin solape');

        $prices = $sections['price_history'];
        $this->assertSame(1, $prices['products_with_price_variation']);
        $this->assertSame(1, $prices['products_with_variation_over_20pct']);
        $this->assertEquals(25.0, $prices['top_variation'][0]['spread_pct']);
        $this->assertSame('CEM-50', $prices['top_variation'][0]['sku']);

        $units = $sections['units'];
        $this->assertSame('warning', $units['status']);
        $this->assertSame(1, $units['incompatible_without_conversion']);
        $this->assertSame('quintal', $units['incompatible_units'][0]['unit']);
        $this->assertFalse($units['incompatible_units'][0]['has_declared_conversion']);
        $mixed = collect($units['categories_with_mixed_units'])->first();
        $this->assertSame(['kg', 'unidad'], collect($mixed['units'])->sort()->values()->all(), 'una categoría con unidades mezcladas no debe sumarse como si fueran la misma');
        // Declarar la conversión resuelve la advertencia.
        $this->postJson('/api/v1/unit-conversions', ['product_sku' => 'ARR-01', 'from_unit' => 'quintal', 'to_unit' => 'kg', 'factor' => 45.36])->assertCreated();
        $after = $this->getJson('/api/v1/data-quality/extended')->json('data.sections.units');
        $this->assertSame(0, $after['incompatible_without_conversion']);
        $this->assertTrue($after['incompatible_units'][0]['has_declared_conversion']);

        $this->postJson('/api/v1/unit-conversions', ['product_sku' => 'CEM-50', 'from_unit' => 'x', 'to_unit' => 'x', 'factor' => 1])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->deleteJson('/api/v1/unit-conversions/'.DB::table('unit_conversions')->where('tenant_id', $this->tenant->id)->value('id'))->assertNoContent();
    }
}
