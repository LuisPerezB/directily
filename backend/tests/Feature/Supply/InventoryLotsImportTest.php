<?php

declare(strict_types=1);

namespace Tests\Feature\Supply;

use App\Models\ImportJob;
use App\Models\InventoryLot;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsImportFiles;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * V2.5 · W5-D (§10): importación de lotes de inventario (vencimiento por lote, FEFO en el motor) y de la perecibilidad del producto
 * (`is_perishable`, `shelf_life_days`). Cada fila inválida se REPORTA con su código y su clase de calidad (nunca se descarta en
 * silencio); las anomalías utilizables se escriben con una advertencia; los datos de una empresa no son visibles para otra.
 */
final class InventoryLotsImportTest extends TestCase
{
    use BuildsImportFiles, InteractsWithTenants, RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private const LOT_HEADERS = ['Fecha', 'Sucursal', 'SKU', 'Lote', 'Cantidad', 'Vencimiento'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeImportInfrastructure();
        [$this->tenant, $this->owner] = $this->createTenantWithUser();
        $this->createBranch($this->tenant, '001');
        $this->actingAsTenantUser($this->owner, $this->tenant);
    }

    private function validated(string $entity, array $headers, array $rows, array $options = [], ?Tenant $tenant = null): ImportJob
    {
        return $this->uploadAndValidate($tenant ?? $this->tenant, $this->csv($headers, $rows), $entity, [], $options, "{$entity}.csv");
    }

    private function importAndConfirm(string $entity, array $headers, array $rows, array $options = [], ?Tenant $tenant = null): ImportJob
    {
        $job = $this->validated($entity, $headers, $rows, $options, $tenant);
        $this->assertSame('validated', $job->status, (string) $job->error_message);
        $this->postJson("/api/v1/imports/{$job->id}/confirm")->assertAccepted();

        return $job;
    }

    /** Hallazgos persistidos de la última validación/importación de un job (código => clase). */
    private function findings(ImportJob $job): array
    {
        $report = DB::table('data_quality_reports')->where('import_job_id', $job->id)->latest('id')->firstOrFail();
        $out = [];
        foreach (DB::table('data_quality_issues')->where('report_id', $report->id)->orderBy('id')->get() as $row) {
            $out[] = ['code' => $row->code, 'class' => $row->dq_class, 'row' => $row->source_row, 'field' => $row->field, 'entity' => $row->entity];
        }

        return $out;
    }

    private function codes(array $findings): array
    {
        return array_values(array_unique(array_column($findings, 'code')));
    }

    private function product(string $sku, array $attributes = []): int
    {
        return (int) DB::table('products')->insertGetId(array_merge([
            'tenant_id' => $this->tenant->id, 'sku' => $sku, 'name' => "Producto {$sku}", 'unit' => 'unidad', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ], $attributes));
    }

    public function test_lots_are_upserted_idempotently_by_branch_product_lot_and_snapshot_date(): void
    {
        $this->product('YOG-1', ['is_perishable' => true, 'shelf_life_days' => 30]);
        $rows = [
            ['2026-10-20', '001', 'YOG-1', 'L-001', '40', '2026-11-05'],
            ['2026-10-20', '001', 'YOG-1', 'L-002', '60', '2026-11-20'],
            ['2026-10-20', '001', 'YOG-1', 'L-003', '0', '2026-11-25'],   // lote agotado: se conserva (cantidad 0)
        ];
        $job = $this->importAndConfirm('inventory_lots', self::LOT_HEADERS, $rows);
        $stats = $this->getJson("/api/v1/imports/{$job->id}")->json('data.stats');
        $this->assertSame(3, $stats['rows_valid']);
        $this->assertSame(0, $stats['rows_error']);
        $this->assertSame(3, $stats['lots_with_expiry']);
        $this->assertSame(3, DB::table('inventory_lots')->where('tenant_id', $this->tenant->id)->count());

        $rows[0][4] = '35';
        $rows[0][5] = '2026-11-06';
        $this->importAndConfirm('inventory_lots', self::LOT_HEADERS, $rows);
        $this->assertSame(3, DB::table('inventory_lots')->where('tenant_id', $this->tenant->id)->count(), 'reimportar no duplica');
        $l1 = DB::table('inventory_lots')->where('lot_code', 'L-001')->first();
        $this->assertEquals(35.0, (float) $l1->quantity);
        $this->assertSame('2026-11-06', substr((string) $l1->expiry_date, 0, 10));

        // Otra fecha de corte es otra foto del mismo lote (no la reemplaza).
        $rows2 = [['2026-10-27', '001', 'YOG-1', 'L-001', '10', '2026-11-06']];
        $this->importAndConfirm('inventory_lots', self::LOT_HEADERS, $rows2);
        $this->assertSame(4, DB::table('inventory_lots')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_negative_quantity_and_invalid_expiry_are_reported_with_their_quality_class_and_never_dropped_silently(): void
    {
        $this->product('YOG-1', ['is_perishable' => true, 'shelf_life_days' => 30]);
        $rows = [
            ['2026-10-20', '001', 'YOG-1', 'L-OK', '10', '2026-11-05'],
            ['2026-10-20', '001', 'YOG-1', 'L-NEG', '-4', '2026-11-05'],         // cantidad negativa
            ['2026-10-20', '001', 'YOG-1', 'L-DATE', '5', 'mañana'],              // vencimiento no interpretable
            ['2026-10-20', '001', 'YOG-1', 'L-FAR', '5', '2099-12-31'],           // vencimiento implausible
            ['2026-10-20', '001', 'YOG-1', 'L-NAN', 'diez', '2026-11-05'],        // cantidad no numérica
            ['2026-10-20', '001', 'YOG-1', 'L-OK', '3', '2026-11-05'],            // duplicado en el archivo
        ];
        $job = $this->validated('inventory_lots', self::LOT_HEADERS, $rows, ['skip_invalid_rows' => true]);
        $this->assertSame('validated', $job->status, (string) $job->error_message);
        $this->assertSame(5, $job->stats['rows_error'], 'las cinco filas inválidas se cuentan como error');
        $this->assertSame(1, $job->stats['rows_valid']);

        // Cada fila inválida deja su error en import_errors (no se pierde en silencio).
        $errors = DB::table('import_errors')->where('import_job_id', $job->id)->orderBy('row_number')->get(['row_number', 'error_code', 'severity', 'column']);
        $byRow = [];
        foreach ($errors as $e) {
            $byRow[$e->row_number][] = $e->error_code;
        }
        $this->assertSame(['negative_lot_quantity'], $byRow[2]);
        $this->assertSame(['invalid_date'], $byRow[3]);
        $this->assertSame(['expiry_implausible'], $byRow[4]);
        $this->assertSame(['invalid_number'], $byRow[5]);
        $this->assertSame(['duplicate_in_file'], $byRow[6]);

        // Y su hallazgo de calidad con la clase del catálogo (QUARANTINED), entidad y campo.
        $findings = $this->findings($job);
        $byCode = [];
        foreach ($findings as $f) {
            $byCode[$f['code']] = $f;
        }
        foreach (['negative_lot_quantity', 'invalid_date', 'expiry_implausible', 'invalid_number', 'duplicate_in_file'] as $code) {
            $this->assertArrayHasKey($code, $byCode, $code);
            $this->assertSame('QUARANTINED', $byCode[$code]['class'], $code);
            $this->assertSame('inventory_lots', $byCode[$code]['entity']);
        }
        $this->assertSame('quantity', $byCode['negative_lot_quantity']['field']);
        $this->assertSame('expiry_date', $byCode['expiry_implausible']['field']);

        // Sin `skip_invalid_rows` la importación no se puede confirmar: nada se escribe a medias.
        $strict = $this->validated('inventory_lots', self::LOT_HEADERS, $rows);
        $this->postJson("/api/v1/imports/{$strict->id}/confirm")->assertStatus(409);
        $this->assertSame(0, DB::table('inventory_lots')->where('tenant_id', $this->tenant->id)->count());

        // Con `skip_invalid_rows` solo se escribe la fila válida; las demás siguen reportadas.
        $this->postJson("/api/v1/imports/{$job->id}/confirm")->assertAccepted();
        $this->assertSame(['L-OK'], DB::table('inventory_lots')->where('tenant_id', $this->tenant->id)->pluck('lot_code')->all());
        $this->assertGreaterThanOrEqual(5, DB::table('import_errors')->where('import_job_id', $job->id)->where('severity', 'error')->count());
    }

    public function test_usable_anomalies_are_written_with_a_warning_instead_of_being_dropped(): void
    {
        $this->product('YOG-1', ['is_perishable' => true, 'shelf_life_days' => 30]);
        $this->product('ARR-1');
        DB::table('inventory_snapshots')->insert([
            'tenant_id' => $this->tenant->id, 'branch_id' => DB::table('branches')->where('tenant_id', $this->tenant->id)->value('id'),
            'product_id' => DB::table('products')->where('sku', 'YOG-1')->value('id'), 'snapshot_date' => '2026-10-20',
            'on_hand' => 50, 'reserved' => 0, 'in_transit' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $rows = [
            ['2026-10-20', '001', 'YOG-1', 'L-OLD', '10', '2026-10-15'],   // ya vencido al corte
            ['2026-10-20', '001', 'YOG-1', 'L-NOEXP', '20', ''],           // perecible sin vencimiento
            ['2026-10-20', '001', 'YOG-1', 'L-LONG', '30', '2027-06-01'],  // vence después de la vida útil declarada (30 d)
            ['2026-10-20', '001', 'ARR-1', 'L-A', '5', ''],                // durable sin vencimiento: sin hallazgo
        ];
        $job = $this->importAndConfirm('inventory_lots', self::LOT_HEADERS, $rows);
        $stats = $this->getJson("/api/v1/imports/{$job->id}")->json('data.stats');
        $this->assertSame(4, $stats['rows_valid']);
        $this->assertSame(0, $stats['rows_error']);
        $this->assertSame(4, DB::table('inventory_lots')->where('tenant_id', $this->tenant->id)->count(), 'las anomalías utilizables se escriben');
        $this->assertSame(1, $stats['lots_expired_on_snapshot']);
        $this->assertSame(2, $stats['lots_without_expiry']);

        $codes = $this->codes($this->findings($job));
        foreach (['lot_expired_on_snapshot', 'lot_expiry_missing', 'expiry_exceeds_shelf_life', 'lots_exceed_on_hand'] as $code) {
            $this->assertContains($code, $codes, $code);
        }
        $classes = array_column($this->findings($job), 'class', 'code');
        foreach (['lot_expired_on_snapshot', 'lot_expiry_missing', 'expiry_exceeds_shelf_life', 'lots_exceed_on_hand'] as $code) {
            $this->assertSame('WARNING', $classes[$code], $code);
        }
        $this->assertSame(1, $stats['series_exceeding_on_hand'], 'los lotes (60) superan la existencia del snapshot (50)');
        // El lote durable sin vencimiento no genera ningún hallazgo de vencimiento.
        $this->assertNull(DB::table('inventory_lots')->where('lot_code', 'L-A')->value('expiry_date'));
    }

    public function test_unknown_branch_or_product_is_reported_not_silently_created_when_creation_is_not_authorized(): void
    {
        $rows = [
            ['2026-10-20', '999', 'YOG-1', 'L-1', '10', '2026-11-05'],     // sucursal inexistente
            ['2026-10-20', '001', 'NOEXISTE', 'L-2', '10', '2026-11-05'],  // producto inexistente
        ];
        $job = $this->validated('inventory_lots', self::LOT_HEADERS, $rows, ['skip_invalid_rows' => true, 'create_missing_products' => false]);
        $this->assertSame(2, $job->stats['rows_error']);
        $codes = $this->codes($this->findings($job));
        $this->assertContains('unknown_branch', $codes);
        $this->assertContains('unknown_product', $codes);
        $this->assertSame(0, DB::table('inventory_lots')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_products_import_writes_perishability_and_the_api_returns_it(): void
    {
        $headers = ['Código', 'Nombre', 'Perecible', 'Vida útil'];
        $rows = [
            ['YOG-1', 'Yogur', 'si', '30'],
            ['QUE-1', 'Queso', '', '45'],        // bandera vacía (columna mapeada) = no perecible; la vida útil no la convierte en perecible
            ['ARR-1', 'Arroz', 'no', ''],
            ['LEC-1', 'Leche', 'sí', ''],         // perecible sin vida útil: advertencia, se escribe
            ['SAL-1', 'Sal', 'no', '700'],        // vida útil en no perecible: advertencia, se escribe
        ];
        $job = $this->importAndConfirm('products', $headers, $rows);
        $this->assertSame(5, $this->getJson("/api/v1/imports/{$job->id}")->json('data.stats.rows_valid'));
        $p = fn (string $sku) => DB::table('products')->where('tenant_id', $this->tenant->id)->where('sku', $sku)->first();
        $this->assertTrue((bool) $p('YOG-1')->is_perishable);
        $this->assertSame(30, (int) $p('YOG-1')->shelf_life_days);
        $this->assertFalse((bool) $p('QUE-1')->is_perishable, 'una bandera vacía no se deriva de la vida útil');
        $this->assertSame(45, (int) $p('QUE-1')->shelf_life_days);
        $this->assertFalse((bool) $p('ARR-1')->is_perishable);
        $this->assertNull($p('ARR-1')->shelf_life_days);
        $this->assertTrue((bool) $p('LEC-1')->is_perishable);
        $this->assertNull($p('LEC-1')->shelf_life_days);
        $this->assertFalse((bool) $p('SAL-1')->is_perishable);
        $codes = $this->codes($this->findings($job));
        $this->assertContains('perishable_without_shelf_life', $codes);
        $this->assertContains('shelf_life_without_perishable', $codes);

        // La API del catálogo devuelve los dos campos.
        $listed = collect($this->getJson('/api/v1/products?per_page=50')->assertOk()->json('data'))->keyBy('sku');
        $this->assertTrue($listed['YOG-1']['is_perishable']);
        $this->assertSame(30, $listed['YOG-1']['shelf_life_days']);
        $this->assertFalse($listed['ARR-1']['is_perishable']);
        $this->assertNull($listed['ARR-1']['shelf_life_days']);
    }

    public function test_invalid_perishability_values_are_quarantined_and_unmapped_columns_do_not_overwrite_the_declaration(): void
    {
        $this->product('YOG-1', ['is_perishable' => true, 'shelf_life_days' => 30]);
        $headers = ['Código', 'Nombre', 'Perecible', 'Vida útil'];
        $rows = [
            ['A-1', 'Uno', 'tal vez', '10'],      // bandera no reconocida
            ['A-2', 'Dos', 'si', '0'],            // vida útil fuera de rango
            ['A-3', 'Tres', 'si', '12,5'],        // vida útil no entera
            ['A-4', 'Cuatro', 'si', '9999'],      // vida útil fuera de rango
            ['A-5', 'Cinco', 'si', '14'],         // válida
        ];
        $job = $this->validated('products', $headers, $rows, ['skip_invalid_rows' => true]);
        $this->assertSame(4, $job->stats['rows_error']);
        $this->assertSame(1, $job->stats['rows_valid']);
        $byCode = array_column($this->findings($job), 'class', 'code');
        $this->assertSame('QUARANTINED', $byCode['invalid_value']);
        $this->assertSame('QUARANTINED', $byCode['invalid_number']);

        // Un archivo de productos SIN columnas de perecibilidad no pisa lo ya declarado.
        $this->importAndConfirm('products', ['Código', 'Nombre', 'PVP'], [['YOG-1', 'Yogur natural', '2.50']]);
        $yog = DB::table('products')->where('sku', 'YOG-1')->first();
        $this->assertSame('Yogur natural', $yog->name);
        $this->assertTrue((bool) $yog->is_perishable);
        $this->assertSame(30, (int) $yog->shelf_life_days);
    }

    public function test_lots_and_perishability_of_one_company_are_invisible_to_another(): void
    {
        $this->product('YOG-1', ['is_perishable' => true, 'shelf_life_days' => 30]);
        $this->importAndConfirm('inventory_lots', self::LOT_HEADERS, [['2026-10-20', '001', 'YOG-1', 'L-001', '40', '2026-11-05']]);
        $this->assertSame(1, DB::table('inventory_lots')->where('tenant_id', $this->tenant->id)->count());

        [$other, $otherOwner] = $this->createTenantWithUser();
        $this->createBranch($other, '001');
        $this->actingAsTenantUser($otherOwner, $other);

        // Con el tenant B en contexto el modelo solo ve sus lotes (alcance fail-closed por tenant).
        $this->assertSame(0, $this->withinTenant($other, fn () => InventoryLot::query()->count()));
        $this->assertSame(1, $this->withinTenant($this->tenant, fn () => InventoryLot::query()->count()));

        // El SKU de A no existe para B: sin autorización para crear productos, la fila se reporta y NO se escribe en A ni en B.
        $job = $this->validated('inventory_lots', self::LOT_HEADERS, [['2026-10-20', '001', 'YOG-1', 'L-B1', '7', '2026-11-05']],
            ['skip_invalid_rows' => true, 'create_missing_products' => false], $other);
        $this->assertSame(1, $job->stats['rows_error']);
        $this->assertSame(0, DB::table('inventory_lots')->where('tenant_id', $other->id)->count());
        $this->assertSame(1, DB::table('inventory_lots')->where('tenant_id', $this->tenant->id)->count());

        // Con creación autorizada, B crea SU producto (no toca el de A) y sus lotes.
        $this->importAndConfirm('inventory_lots', self::LOT_HEADERS, [['2026-10-20', '001', 'YOG-1', 'L-B1', '7', '2026-11-05']], [], $other);
        $this->assertSame(1, DB::table('inventory_lots')->where('tenant_id', $other->id)->count());
        $productA = DB::table('products')->where('tenant_id', $this->tenant->id)->where('sku', 'YOG-1')->value('id');
        $productB = DB::table('products')->where('tenant_id', $other->id)->where('sku', 'YOG-1')->value('id');
        $this->assertNotSame($productA, $productB);
        $this->assertFalse((bool) DB::table('products')->where('id', $productB)->value('is_perishable'), 'la perecibilidad de A no se hereda');
        $this->assertSame(1, DB::table('inventory_lots')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(['L-001'], DB::table('inventory_lots')->where('tenant_id', $this->tenant->id)->pluck('lot_code')->all());
    }

    public function test_a_products_file_with_only_one_perishability_column_never_touches_the_other(): void
    {
        $this->product('YOG-1', ['is_perishable' => true, 'shelf_life_days' => 30]);
        $this->product('QUE-1', ['is_perishable' => true, 'shelf_life_days' => 20]);

        // solo la bandera: la vida útil declarada se conserva
        $this->importAndConfirm('products', ['Código', 'Nombre', 'Perecible'], [['YOG-1', 'Yogur', 'si']]);
        $yog = DB::table('products')->where('sku', 'YOG-1')->first();
        $this->assertTrue((bool) $yog->is_perishable);
        $this->assertSame(30, (int) $yog->shelf_life_days, 'mapear solo la bandera no borra la vida útil');
        $this->importAndConfirm('products', ['Código', 'Nombre', 'Perecible'], [['YOG-1', 'Yogur', 'no']]);
        $this->assertFalse((bool) DB::table('products')->where('sku', 'YOG-1')->value('is_perishable'));
        $this->assertSame(30, (int) DB::table('products')->where('sku', 'YOG-1')->value('shelf_life_days'));

        // solo la vida útil (incluso con celda vacía): la bandera no se deriva ni se pisa
        $this->importAndConfirm('products', ['Código', 'Nombre', 'Vida útil'], [['QUE-1', 'Queso', '']]);
        $que = DB::table('products')->where('sku', 'QUE-1')->first();
        $this->assertTrue((bool) $que->is_perishable, 'mapear solo la vida útil no cambia la bandera');
        $this->assertNull($que->shelf_life_days, 'celda vacía en una columna mapeada = vida útil no declarada');
        $this->importAndConfirm('products', ['Código', 'Nombre', 'Vida útil'], [['QUE-1', 'Queso', '12']]);
        $this->assertTrue((bool) DB::table('products')->where('sku', 'QUE-1')->value('is_perishable'));
        $this->assertSame(12, (int) DB::table('products')->where('sku', 'QUE-1')->value('shelf_life_days'));

        // un producto NUEVO con solo la vida útil queda con la bandera por omisión (no perecible), sin derivarla
        $this->importAndConfirm('products', ['Código', 'Nombre', 'Vida útil'], [['NEW-1', 'Nuevo', '9']]);
        $new = DB::table('products')->where('sku', 'NEW-1')->first();
        $this->assertFalse((bool) $new->is_perishable);
        $this->assertSame(9, (int) $new->shelf_life_days);
    }

    public function test_a_corrected_snapshot_replaces_the_lots_of_its_branch_product_and_date_without_touching_the_rest(): void
    {
        $this->product('YOG-1', ['is_perishable' => true, 'shelf_life_days' => 30]);
        $this->product('QUE-1', ['is_perishable' => true, 'shelf_life_days' => 30]);
        $this->importAndConfirm('inventory_lots', self::LOT_HEADERS, [
            ['2026-10-20', '001', 'YOG-1', 'L-001', '40', '2026-11-05'],
            ['2026-10-20', '001', 'YOG-1', 'L-002', '60', '2026-11-20'],
            ['2026-10-20', '001', 'QUE-1', 'Q-001', '10', '2026-11-05'],       // otro producto: el archivo corregido no lo trae
            ['2026-10-21', '001', 'YOG-1', 'L-009', '5', '2026-11-05'],        // otra fecha de corte
        ]);
        $this->assertSame(4, DB::table('inventory_lots')->where('tenant_id', $this->tenant->id)->count());

        // archivo corregido: sin L-002 y con «l-001» en minúsculas, solo para YOG-1 del 20/10
        $job = $this->validated('inventory_lots', self::LOT_HEADERS, [['2026-10-20', '001', 'YOG-1', 'l-001', '35', '2026-11-05']]);
        $this->assertSame(1, $job->stats['lots_replaced'], 'la validación avisa cuántos lotes se eliminarán');
        $this->assertContains('lots_replaced', $this->codes($this->findings($job)));
        $this->assertSame(4, DB::table('inventory_lots')->where('tenant_id', $this->tenant->id)->count(), 'validar no escribe ni elimina');
        $this->postJson("/api/v1/imports/{$job->id}/confirm")->assertAccepted();

        $codes = DB::table('inventory_lots')->where('tenant_id', $this->tenant->id)->orderBy('lot_code')->pluck('quantity', 'lot_code')->map(fn ($q) => (float) $q)->all();
        $this->assertSame(['L-001' => 35.0, 'L-009' => 5.0, 'Q-001' => 10.0], $codes, 'L-002 desaparece; «l-001» es el mismo lote L-001; lo demás no se toca');
    }

    public function test_a_rejected_row_never_deletes_a_good_lot_of_the_snapshot_it_replaces(): void
    {
        $this->product('YOG-1', ['is_perishable' => true, 'shelf_life_days' => 30]);
        $this->importAndConfirm('inventory_lots', self::LOT_HEADERS, [
            ['2026-10-20', '001', 'YOG-1', 'L-001', '40', '2026-11-05'],
            ['2026-10-20', '001', 'YOG-1', 'L-002', '60', '2026-11-20'],
        ]);
        // L-002 viene con cantidad negativa (fila rechazada): el lote existente se conserva; L-001 se actualiza
        $job = $this->validated('inventory_lots', self::LOT_HEADERS, [
            ['2026-10-20', '001', 'YOG-1', 'L-001', '41', '2026-11-05'],
            ['2026-10-20', '001', 'YOG-1', 'L-002', '-3', '2026-11-20'],
        ], ['skip_invalid_rows' => true]);
        $this->assertSame(0, $job->stats['lots_replaced']);
        $this->postJson("/api/v1/imports/{$job->id}/confirm")->assertAccepted();
        $codes = DB::table('inventory_lots')->where('tenant_id', $this->tenant->id)->orderBy('lot_code')->pluck('quantity', 'lot_code')->map(fn ($q) => (float) $q)->all();
        $this->assertSame(['L-001' => 41.0, 'L-002' => 60.0], $codes);
    }

    public function test_lot_codes_differing_only_in_case_are_the_same_lot_and_an_oversized_quantity_is_a_row_finding_not_a_job_failure(): void
    {
        $this->product('YOG-1', ['is_perishable' => true, 'shelf_life_days' => 30]);
        $job = $this->validated('inventory_lots', self::LOT_HEADERS, [
            ['2026-10-20', '001', 'YOG-1', 'L-001', '10', '2026-11-05'],
            ['2026-10-20', '001', 'YOG-1', 'l-001', '11', '2026-11-05'],        // mismo lote: duplicado en el archivo
            ['2026-10-20', '001', 'YOG-1', 'L-BIG', '1000000000000', '2026-11-05'],   // 10^12: no cabe en decimal(16,4)
            ['2026-10-20', '001', 'YOG-1', 'L-OK', '999999999999', '2026-11-05'],     // el máximo que cabe
        ], ['skip_invalid_rows' => true]);
        $this->assertSame('validated', $job->status, (string) $job->error_message);
        $byCode = [];
        foreach (DB::table('import_errors')->where('import_job_id', $job->id)->get(['row_number', 'error_code']) as $e) {
            $byCode[$e->row_number][] = $e->error_code;
        }
        $this->assertSame(['duplicate_in_file'], $byCode[2]);
        $this->assertSame(['invalid_number'], $byCode[3], 'cantidad fuera de rango: hallazgo de la fila, no fallo del job al confirmar');
        $this->assertArrayNotHasKey(4, $byCode);
        $this->postJson("/api/v1/imports/{$job->id}/confirm")->assertAccepted();
        $this->assertSame('completed', ImportJob::query()->withoutGlobalScopes()->find($job->id)->status);
        $this->assertSame(['L-001', 'L-OK'], DB::table('inventory_lots')->where('tenant_id', $this->tenant->id)->orderBy('lot_code')->pluck('lot_code')->all());
    }

    public function test_the_expired_on_snapshot_boundary_is_inclusive_of_the_snapshot_day(): void
    {
        $this->product('YOG-1', ['is_perishable' => true, 'shelf_life_days' => 30]);
        $job = $this->validated('inventory_lots', self::LOT_HEADERS, [
            ['2026-10-21', '001', 'YOG-1', 'L-EQ', '5', '2026-10-21'],        // vence el propio día del corte: ya no es vendible el día siguiente
            ['2026-10-21', '001', 'YOG-1', 'L-P1', '5', '2026-10-22'],        // vence al día siguiente: vigente
        ]);
        $warned = DB::table('import_errors')->where('import_job_id', $job->id)->where('error_code', 'lot_expired_on_snapshot')->pluck('row_number')->all();
        $this->assertSame([1], $warned, 'solo la fila con vencimiento ≤ fecha de corte');
        $this->assertSame(1, $job->stats['lots_expired_on_snapshot']);
    }

    public function test_perishability_of_another_company_with_the_same_sku_never_leaks_into_a_lot_import(): void
    {
        $this->product('YOG-1');                                                  // en A es un durable
        [$other] = $this->createTenantWithUser();
        DB::table('products')->insert(['tenant_id' => $other->id, 'sku' => 'YOG-1', 'name' => 'Yogur B', 'unit' => 'unidad', 'is_active' => true,
            'is_perishable' => true, 'shelf_life_days' => 5, 'created_at' => now(), 'updated_at' => now()]);   // en B (creado DESPUÉS) es perecible
        $job = $this->validated('inventory_lots', self::LOT_HEADERS, [['2026-10-20', '001', 'YOG-1', 'L-001', '40', '']]);
        $this->assertSame([], $this->codes($this->findings($job)), 'el durable de A no hereda la perecibilidad (lot_expiry_missing) del SKU homónimo de B');
        // y a la inversa: la vida útil de B (5 d) no dispara «expiry_exceeds_shelf_life» en A
        $job = $this->validated('inventory_lots', self::LOT_HEADERS, [['2026-10-20', '001', 'YOG-1', 'L-002', '40', '2026-11-30']]);
        $this->assertNotContains('expiry_exceeds_shelf_life', $this->codes($this->findings($job)));
    }
}
