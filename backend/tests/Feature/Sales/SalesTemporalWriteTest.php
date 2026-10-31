<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Models\SaleItemRevision;
use App\Models\SalesDocumentRevision;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Catalog\CatalogResolver;
use App\Services\Sales\SalesDocumentWriter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\Sales\Support\ReadsSalesVersions;
use Tests\TestCase;

/**
 * Escritura temporal de ventas (ADR-V25-23): modo de ingestión, versiones de documento archivadas antes de sobrescribir,
 * ventanas de conocimiento [known_from, known_until), descarte de versiones anteriores (stale_skipped) y aislamiento por empresa.
 * Se ejercita por POST /sales/batch (misma escritura que el importador y la sincronización SQL).
 *
 * Empresa en America/Guayaquil (UTC-5, sin horario de verano): el inicio del día local 2026-05-04 es 2026-05-04 05:00 UTC.
 */
final class SalesTemporalWriteTest extends TestCase
{
    use InteractsWithTenants, ReadsSalesVersions, RefreshDatabase;

    private const T0 = '2026-06-10 12:00:00';

    private const T1 = '2026-06-12 15:30:00';

    private const T2 = '2026-06-15 09:45:00';

    /** Inicio del día local (Guayaquil) de la fecha de venta 2026-05-04, en UTC. */
    private const DAY_START = '2026-05-04 05:00:00';

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->tenant, $this->owner] = $this->createTenantWithUser();
        $this->createBranch($this->tenant, '001');
        $this->actingAsTenantUser($this->owner, $this->tenant);
    }

    /**
     * @param  list<array<string, mixed>>|null  $items
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function doc(string $number = 'F-1', array $overrides = [], ?array $items = null): array
    {
        return array_merge([
            'document_number' => $number, 'issued_at' => '2026-05-04T10:00:00-05:00', 'branch_code' => '001', 'channel' => 'web',
            'items' => $items ?? [
                ['product_sku' => 'A', 'product_name' => 'Producto A', 'quantity' => 2, 'unit_price' => 5],
                ['product_sku' => 'B', 'product_name' => 'Producto B', 'quantity' => 1, 'unit_price' => 10],
            ],
        ], $overrides);
    }

    /** @param  list<array<string, mixed>>  $documents */
    private function ingest(array $documents, ?string $mode = null): TestResponse
    {
        return $this->postJson('/api/v1/sales/batch', ['documents' => $documents] + ($mode !== null ? ['ingestion_mode' => $mode] : []));
    }

    /** Líneas de A y B con la cantidad de A modificada. */
    private function itemsWithQtyA(float $qtyA): array
    {
        return [
            ['product_sku' => 'A', 'product_name' => 'Producto A', 'quantity' => $qtyA, 'unit_price' => 5],
            ['product_sku' => 'B', 'product_name' => 'Producto B', 'quantity' => 1, 'unit_price' => 10],
        ];
    }

    public function test_new_backfill_document_is_known_since_the_start_of_its_business_day(): void
    {
        $this->travelTo(self::T0);
        $this->ingest([$this->doc()], 'backfill')->assertOk()
            ->assertJsonPath('data.created', 1)->assertJsonPath('data.ingestion_mode', 'backfill')->assertJsonPath('data.ingestion_mode_basis', 'declared');

        $sale = $this->saleRow($this->tenant, 'F-1');
        $this->assertSame('backfill', $sale->ingestion_mode);
        $this->assertNull($sale->version_known_from, 'backfill: NULL = inicio del día local de sale_date');
        $this->assertSame(0, (int) $sale->current_revision);
        $this->assertSame(self::T0, $this->utc($sale->first_seen_at));

        $this->assertSame([0 => ['current' => true, 'known_from' => self::DAY_START, 'known_until' => null, 'status' => 'completed', 'lines' => [1 => 2.0, 2 => 1.0]]], $this->versionsView($this->tenant, 'F-1'));
        $this->assertSame([], $this->visibleAt($this->tenant, '2026-05-03'), 'antes de su día de negocio no es visible');
        $this->assertSame(['F-1' => [0 => [1 => 2.0, 2 => 1.0]]], $this->visibleAt($this->tenant, '2026-05-04'));
        $this->assertSame(0, DB::table('sales_document_revisions')->count());
    }

    public function test_batch_defaults_to_incremental_and_a_late_document_is_not_visible_before_its_arrival(): void
    {
        $this->travelTo(self::T0);
        $this->ingest([$this->doc()])->assertOk()
            ->assertJsonPath('data.ingestion_mode', 'incremental')->assertJsonPath('data.ingestion_mode_basis', 'api_default');

        $sale = $this->saleRow($this->tenant, 'F-1');
        $this->assertSame('incremental', $sale->ingestion_mode);
        $this->assertSame(self::T0, $this->utc($sale->version_known_from));
        $this->assertSame(self::T0, $this->utc($sale->first_seen_at));

        // El documento es del 4 de mayo pero llegó el 10 de junio: ningún corte anterior a la llegada lo ve.
        $this->assertSame([], $this->visibleAt($this->tenant, '2026-05-04'));
        $this->assertSame([], $this->visibleAt($this->tenant, '2026-06-09'));
        $this->assertSame(['F-1' => [0 => [1 => 2.0, 2 => 1.0]]], $this->visibleAt($this->tenant, '2026-06-10'));
    }

    public function test_invalid_ingestion_mode_is_rejected(): void
    {
        $this->ingest([$this->doc()], 'semanal')->assertUnprocessable()->assertJsonValidationErrors('ingestion_mode');
        $this->assertSame(0, DB::table('sales')->count());
    }

    public function test_incremental_update_archives_header_and_lines_with_correct_windows(): void
    {
        $this->travelTo(self::T0);
        $this->ingest([$this->doc()])->assertOk();
        $this->travelTo(self::T1);
        $this->ingest([$this->doc('F-1', [], $this->itemsWithQtyA(5))])->assertOk()
            ->assertJsonPath('data.created', 0)->assertJsonPath('data.updated', 1)->assertJsonPath('data.revisions_archived', 1)
            ->assertJsonPath('data.stale_skipped', 0)->assertJsonPath('data.updated_without_source_order', 1);

        $sale = $this->saleRow($this->tenant, 'F-1');
        $this->assertSame(1, (int) $sale->current_revision);
        $this->assertSame(self::T1, $this->utc($sale->version_known_from));
        $this->assertSame(self::T0, $this->utc($sale->first_seen_at), 'first_seen_at nunca se actualiza');

        $revisions = $this->headerRevisions($this->tenant, 'F-1');
        $this->assertCount(1, $revisions);
        $revision = $revisions[0];
        $this->assertSame(0, (int) $revision->revision);
        $this->assertSame('incremental', $revision->ingestion_mode);
        $this->assertSame(self::T0, $this->utc($revision->known_from));
        $this->assertSame(self::T1, $this->utc($revision->known_until), 'known_until = known_from de la versión que la reemplazó');
        $this->assertSame('lines_changed', $revision->change_kind);
        $this->assertSame('completed', $revision->status);
        $this->assertEqualsWithDelta(20.0, (float) $revision->total, 0.0001, 'la cabecera archivada conserva los totales de la versión reemplazada');
        $this->assertNull($revision->superseded_by_import_job_id);

        $archivedLines = DB::table('sale_item_revisions')->where('sale_id', $sale->id)->orderBy('line_number')->get();
        $this->assertSame([0, 0], $archivedLines->pluck('revision')->map(fn ($r) => (int) $r)->all());
        $this->assertSame([2.0, 1.0], $archivedLines->pluck('quantity')->map(fn ($q) => (float) $q)->all());
        $this->assertSame([5.0, 1.0], DB::table('sale_items')->where('sale_id', $sale->id)->orderBy('line_number')->pluck('quantity')->map(fn ($q) => (float) $q)->all());

        // La vista devuelve ambas versiones con su ventana [known_from, known_until).
        $this->assertSame([
            0 => ['current' => false, 'known_from' => self::T0, 'known_until' => self::T1, 'status' => 'completed', 'lines' => [1 => 2.0, 2 => 1.0]],
            1 => ['current' => true, 'known_from' => self::T1, 'known_until' => null, 'status' => 'completed', 'lines' => [1 => 5.0, 2 => 1.0]],
        ], $this->versionsView($this->tenant, 'F-1'));

        // Lectura as-of: lo que se sabía en cada corte (fecha local).
        $this->assertSame([], $this->visibleAt($this->tenant, '2026-06-09'));
        $this->assertSame(['F-1' => [0 => [1 => 2.0, 2 => 1.0]]], $this->visibleAt($this->tenant, '2026-06-10'));
        $this->assertSame(['F-1' => [0 => [1 => 2.0, 2 => 1.0]]], $this->visibleAt($this->tenant, '2026-06-11'));
        $this->assertSame(['F-1' => [1 => [1 => 5.0, 2 => 1.0]]], $this->visibleAt($this->tenant, '2026-06-12'), 'el día del cambio ya se conoce la versión nueva y no la anterior');
        $this->assertSame(['F-1' => [1 => [1 => 5.0, 2 => 1.0]]], $this->visibleAt($this->tenant, '2026-06-20'));
    }

    public function test_status_change_to_voided_is_recorded_as_status_changed(): void
    {
        $this->travelTo(self::T0);
        $this->ingest([$this->doc()])->assertOk();
        $this->travelTo(self::T1);
        $this->ingest([$this->doc('F-1', ['status' => 'anulada'])])->assertOk()->assertJsonPath('data.updated', 1)->assertJsonPath('data.revisions_archived', 1);

        $this->assertSame('voided', $this->saleRow($this->tenant, 'F-1')->status);
        $revision = $this->headerRevisions($this->tenant, 'F-1')[0];
        $this->assertSame('status_changed', $revision->change_kind);
        $this->assertSame('completed', $revision->status, 'la versión archivada conserva el estado que tenía');
        $views = $this->versionsView($this->tenant, 'F-1');
        $this->assertSame('completed', $views[0]['status']);
        $this->assertSame('voided', $views[1]['status']);
    }

    public function test_header_only_and_mixed_changes_are_classified(): void
    {
        $this->travelTo(self::T0);
        $this->ingest([$this->doc('F-1', ['customer_code' => 'CLI-1', 'customer_segment' => 'Mayorista'])])->assertOk();

        $this->travelTo(self::T1); // solo cambia el canal
        $this->ingest([$this->doc('F-1', ['customer_code' => 'CLI-1', 'customer_segment' => 'Mayorista', 'channel' => 'wholesale'])])->assertOk()->assertJsonPath('data.updated', 1);
        $this->travelTo(self::T2); // estado y líneas a la vez
        $this->ingest([$this->doc('F-1', ['customer_code' => 'CLI-1', 'customer_segment' => 'Mayorista', 'channel' => 'wholesale', 'status' => 'anulada'], $this->itemsWithQtyA(9))])->assertOk()->assertJsonPath('data.updated', 1);
        $this->travelTo('2026-06-20 08:00:00'); // solo el segmento del cliente: forma parte de la huella pero no de `sales`
        $this->ingest([$this->doc('F-1', ['customer_code' => 'CLI-1', 'customer_segment' => 'Minorista', 'channel' => 'wholesale', 'status' => 'anulada'], $this->itemsWithQtyA(9))])->assertOk()->assertJsonPath('data.updated', 1);

        $kinds = array_map(fn ($r) => $r->change_kind, $this->headerRevisions($this->tenant, 'F-1'));
        $this->assertSame(['header_changed', 'mixed', 'header_changed'], $kinds);
        $this->assertSame(3, (int) $this->saleRow($this->tenant, 'F-1')->current_revision);
    }

    public function test_backfill_reload_collapses_versions_into_an_empty_window(): void
    {
        $this->travelTo(self::T0);
        $this->ingest([$this->doc()], 'backfill')->assertOk();
        $this->travelTo(self::T1);
        $this->ingest([$this->doc('F-1', [], $this->itemsWithQtyA(7))], 'backfill')->assertOk()
            ->assertJsonPath('data.updated', 1)->assertJsonPath('data.revisions_archived', 1);

        $revision = $this->headerRevisions($this->tenant, 'F-1')[0];
        $this->assertSame('backfill', $revision->ingestion_mode);
        $this->assertNull($revision->known_from);
        $this->assertSame(self::DAY_START, $this->utc($revision->known_until), 'known_until = inicio del día local de la sale_date anterior');

        $views = $this->versionsView($this->tenant, 'F-1');
        $this->assertSame($views[0]['known_from'], $views[0]['known_until'], 'ventana vacía: known_until = known_from');
        $this->assertSame(self::DAY_START, $views[1]['known_from']);
        $this->assertNull($views[1]['known_until']);
        $sale = $this->saleRow($this->tenant, 'F-1');
        $this->assertNull($sale->version_known_from);
        $this->assertSame(self::T0, $this->utc($sale->first_seen_at));

        // La versión colapsada no se ve en ningún corte; la nueva, desde su día de negocio.
        foreach (['2026-05-03', '2026-05-04', '2026-06-10', '2026-06-11', '2026-06-12', '2026-07-01'] as $cutoff) {
            $visible = $this->visibleAt($this->tenant, $cutoff);
            $this->assertArrayNotHasKey(0, $visible['F-1'] ?? [], "la versión 0 no debe verse al corte {$cutoff}");
        }
        $this->assertSame([], $this->visibleAt($this->tenant, '2026-05-03'));
        $this->assertSame(['F-1' => [1 => [1 => 7.0, 2 => 1.0]]], $this->visibleAt($this->tenant, '2026-05-04'));
    }

    public function test_backfill_reload_that_moves_the_sale_date_still_collapses_on_the_previous_date(): void
    {
        $this->travelTo(self::T0);
        $this->ingest([$this->doc()], 'backfill')->assertOk();
        $this->travelTo(self::T1);
        $this->ingest([$this->doc('F-1', ['issued_at' => '2026-05-06T10:00:00-05:00'])], 'backfill')->assertOk()->assertJsonPath('data.updated', 1);

        $revision = $this->headerRevisions($this->tenant, 'F-1')[0];
        $this->assertSame('header_changed', $revision->change_kind);
        $this->assertSame(self::DAY_START, $this->utc($revision->known_until), 'el inicio del día de la fecha ANTERIOR, no de la nueva');
        $views = $this->versionsView($this->tenant, 'F-1');
        $this->assertSame($views[0]['known_from'], $views[0]['known_until']);
        $this->assertSame('2026-05-06 05:00:00', $views[1]['known_from']);
    }

    public function test_incremental_update_of_a_backfill_document_closes_the_window_at_the_ingestion(): void
    {
        $this->travelTo(self::T0);
        $this->ingest([$this->doc()], 'backfill')->assertOk();
        $this->travelTo(self::T1);
        $this->ingest([$this->doc('F-1', [], $this->itemsWithQtyA(5))], 'incremental')->assertOk()->assertJsonPath('data.revisions_archived', 1);

        $revision = $this->headerRevisions($this->tenant, 'F-1')[0];
        $this->assertNull($revision->known_from);
        $this->assertSame(self::T1, $this->utc($revision->known_until));
        $sale = $this->saleRow($this->tenant, 'F-1');
        $this->assertSame('incremental', $sale->ingestion_mode, 'el modo es el de ESTA escritura');
        $this->assertSame(self::T1, $this->utc($sale->version_known_from));
        $this->assertSame(['F-1' => [0 => [1 => 2.0, 2 => 1.0]]], $this->visibleAt($this->tenant, '2026-05-10'), 'hasta la ingestión se conocía la versión histórica');
        $this->assertSame(['F-1' => [1 => [1 => 5.0, 2 => 1.0]]], $this->visibleAt($this->tenant, '2026-06-12'));
    }

    public function test_backfill_reload_of_an_incremental_document_never_leaves_an_inverted_window(): void
    {
        $this->travelTo(self::T0);
        $this->ingest([$this->doc()], 'incremental')->assertOk();
        $this->travelTo(self::T1);
        $this->ingest([$this->doc('F-1', [], $this->itemsWithQtyA(5))], 'backfill')->assertOk()->assertJsonPath('data.revisions_archived', 1);

        $revision = $this->headerRevisions($this->tenant, 'F-1')[0];
        $this->assertSame(self::T0, $this->utc($revision->known_from));
        $this->assertSame(self::T0, $this->utc($revision->known_until), 'ventana vacía en el instante en que se conoció, nunca known_until < known_from');
        $this->assertNull($this->saleRow($this->tenant, 'F-1')->version_known_from, 'la nueva versión declara histórico: conocida desde su día de negocio');
    }

    public function test_older_incoming_version_is_stale_skipped_and_writes_nothing(): void
    {
        $this->travelTo(self::T0);
        $this->ingest([$this->doc('F-1', ['source_recorded_at' => '2026-06-01T10:00:00-05:00'])])->assertOk();
        $before = $this->saleRow($this->tenant, 'F-1');
        $linesBefore = DB::table('sale_items')->where('sale_id', $before->id)->orderBy('line_number')->get(['line_number', 'quantity'])->toArray();

        // Un reintento o un lote tardío con una versión ANTERIOR (30 de mayo) no sobrescribe la nueva (1 de junio).
        $this->travelTo(self::T1);
        $this->ingest([$this->doc('F-1', ['source_recorded_at' => '2026-05-30T10:00:00-05:00'], $this->itemsWithQtyA(99))])->assertOk()
            ->assertJsonPath('data.stale_skipped', 1)->assertJsonPath('data.updated', 0)->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.unchanged', 0)->assertJsonPath('data.revisions_archived', 0)->assertJsonPath('data.rejected', 0);

        $after = $this->saleRow($this->tenant, 'F-1');
        $this->assertEquals($before, $after, 'ningún campo de la cabecera cambia');
        $this->assertEquals($linesBefore, DB::table('sale_items')->where('sale_id', $after->id)->orderBy('line_number')->get(['line_number', 'quantity'])->toArray());
        $this->assertSame(0, DB::table('sales_document_revisions')->count());
        $this->assertSame(0, DB::table('sale_item_revisions')->count());
    }

    public function test_stale_documents_do_not_create_catalog_rows_and_do_not_block_the_rest_of_the_batch(): void
    {
        $this->travelTo(self::T0);
        $this->ingest([$this->doc('F-1', ['source_recorded_at' => '2026-06-01T10:00:00-05:00'])])->assertOk();

        $this->travelTo(self::T1);
        $stale = $this->doc('F-1', ['source_recorded_at' => '2026-05-01T10:00:00-05:00'], [['product_sku' => 'ZZ-NUEVO', 'product_name' => 'No debe crearse', 'quantity' => 1, 'unit_price' => 1]]);
        $fresh = $this->doc('F-2', [], [['product_sku' => 'C', 'product_name' => 'Producto C', 'quantity' => 3, 'unit_price' => 2]]);
        $this->ingest([$stale, $fresh])->assertOk()->assertJsonPath('data.stale_skipped', 1)->assertJsonPath('data.created', 1);

        $this->assertFalse(DB::table('products')->where('tenant_id', $this->tenant->id)->where('sku', 'ZZ-NUEVO')->exists());
        $this->assertTrue(DB::table('products')->where('tenant_id', $this->tenant->id)->where('sku', 'C')->exists());
    }

    public function test_equal_or_newer_source_timestamp_is_applied_and_ordered(): void
    {
        $this->travelTo(self::T0);
        $this->ingest([$this->doc('F-1', ['source_recorded_at' => '2026-06-01T10:00:00-05:00'])])->assertOk();

        $this->travelTo(self::T1); // misma marca, contenido distinto: gana la última ingestión
        $this->ingest([$this->doc('F-1', ['source_recorded_at' => '2026-06-01T10:00:00-05:00'], $this->itemsWithQtyA(4))])->assertOk()
            ->assertJsonPath('data.updated', 1)->assertJsonPath('data.stale_skipped', 0)->assertJsonPath('data.updated_without_source_order', 0);
        $this->travelTo(self::T2); // marca posterior
        $this->ingest([$this->doc('F-1', ['source_recorded_at' => '2026-06-14T10:00:00-05:00'], $this->itemsWithQtyA(6))])->assertOk()
            ->assertJsonPath('data.updated', 1)->assertJsonPath('data.revisions_archived', 1);

        $this->assertSame(2, (int) $this->saleRow($this->tenant, 'F-1')->current_revision);
        $this->assertSame('2026-06-14 15:00:00', $this->utc($this->saleRow($this->tenant, 'F-1')->source_recorded_at));
    }

    public function test_missing_source_timestamp_lets_the_last_ingestion_win_and_is_counted(): void
    {
        $this->travelTo(self::T0);
        $this->ingest([$this->doc('F-1', ['source_recorded_at' => '2026-06-01T10:00:00-05:00']), $this->doc('F-2')])->assertOk()->assertJsonPath('data.created', 2);

        $this->travelTo(self::T1); // F-1: la almacenada tiene marca y la entrante no. F-2: al revés.
        $this->ingest([$this->doc('F-1', [], $this->itemsWithQtyA(4)), $this->doc('F-2', ['source_recorded_at' => '2026-01-01T10:00:00-05:00'], $this->itemsWithQtyA(4))])->assertOk()
            ->assertJsonPath('data.updated', 2)->assertJsonPath('data.stale_skipped', 0)->assertJsonPath('data.updated_without_source_order', 2)->assertJsonPath('data.revisions_archived', 2);
    }

    public function test_revisions_are_isolated_between_companies(): void
    {
        [$other, $otherOwner] = $this->createTenantWithUser();
        $this->createBranch($other, '001');

        $this->travelTo(self::T0);
        $this->ingest([$this->doc('F-1')])->assertOk();
        $this->travelTo(self::T1);
        $this->ingest([$this->doc('F-1', [], $this->itemsWithQtyA(5))])->assertOk()->assertJsonPath('data.revisions_archived', 1);

        // La otra empresa tiene un documento con el MISMO número: su historia es independiente.
        $this->actingAsTenantUser($otherOwner, $other);
        $this->travelTo(self::T2);
        $this->ingest([$this->doc('F-1')])->assertOk()->assertJsonPath('data.created', 1)->assertJsonPath('data.updated', 0);
        $this->assertSame(0, (int) $this->saleRow($other, 'F-1')->current_revision);
        $this->assertSame([], $this->headerRevisions($other, 'F-1'));

        // El scope fail-closed de los modelos de revisión y las vistas filtran por empresa.
        $this->assertSame(1, $this->withinTenant($this->tenant, fn () => SalesDocumentRevision::query()->count()));
        $this->assertSame(2, $this->withinTenant($this->tenant, fn () => SaleItemRevision::query()->count()));
        $this->assertSame(0, $this->withinTenant($other, fn () => SalesDocumentRevision::query()->count()));
        $this->assertSame(0, $this->withinTenant($other, fn () => SaleItemRevision::query()->count()));
        $this->assertSame([0], array_keys($this->versionsView($other, 'F-1')), 'la otra empresa solo ve su versión vigente');
        $this->assertSame([0, 1], array_keys($this->versionsView($this->tenant, 'F-1')));

        // La FK compuesta impide colgar una revisión de la venta de una empresa en otra.
        $foreignSale = $this->saleRow($this->tenant, 'F-1');
        $this->expectException(QueryException::class);
        DB::table('sales_document_revisions')->insert([
            'tenant_id' => $other->id, 'sale_id' => $foreignSale->id, 'revision' => 9, 'document_type' => 'invoice', 'status' => 'completed',
            'issued_at' => '2026-05-04 15:00:00+00', 'sale_date' => '2026-05-04', 'branch_id' => $foreignSale->branch_id, 'currency' => 'USD',
            'subtotal' => 0, 'discount_total' => 0, 'tax_total' => 0, 'total' => 0, 'source_hash' => str_repeat('a', 64), 'ingestion_mode' => 'incremental',
            'known_until' => '2026-06-12 15:30:00+00', 'change_kind' => 'mixed',
        ]);
    }

    public function test_the_writer_requires_an_explicit_valid_ingestion_mode(): void
    {
        $writer = app(SalesDocumentWriter::class);
        $catalog = new CatalogResolver($this->tenant);
        foreach ([[], ['ingestion_mode' => null], ['ingestion_mode' => 'semanal']] as $context) {
            try {
                $writer->write($this->tenant, [], $catalog, $context);
                $this->fail('El escritor debe exigir un modo de ingestión válido.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
