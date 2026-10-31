<?php

declare(strict_types=1);

namespace Tests\Feature\DataCore;

use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsImportFiles;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\DataCore\Support\ImportsCsv;
use Tests\TestCase;

/**
 * E2-23 · Compromisos comerciales (cotización / pedido / contrato): importación idempotente por tipo + identificador
 * externo, cancelaciones, cumplimiento, `known_at`, reconstrucción a un corte (as_of) y conversiones de unidades declaradas.
 */
final class CommitmentsImportTest extends TestCase
{
    use BuildsImportFiles, ImportsCsv, InteractsWithTenants, RefreshDatabase;

    private const HEADERS = ['commitment_type', 'external_id', 'status', 'issued_at', 'known_at', 'valid_from', 'valid_to', 'expected_delivery_date', 'cancelled_at',
        'branch_code', 'customer_code', 'currency', 'line_number', 'product_sku', 'quantity', 'quantity_fulfilled', 'unit', 'unit_price', 'delivery_date'];

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
    }

    /** @param array<string, mixed> $override @return list<string|int|float|null> */
    private function row(array $override = []): array
    {
        $base = ['commitment_type' => 'pedido', 'external_id' => 'PED-1', 'status' => '', 'issued_at' => '2026-09-01 10:00:00', 'known_at' => '2026-09-01 10:30:00',
            'valid_from' => '', 'valid_to' => '', 'expected_delivery_date' => '2026-11-15', 'cancelled_at' => '', 'branch_code' => '001', 'customer_code' => '1710034065',
            'currency' => 'USD', 'line_number' => 1, 'product_sku' => 'CEM-50', 'quantity' => 100, 'quantity_fulfilled' => 0, 'unit' => '', 'unit_price' => 8.5, 'delivery_date' => ''];

        return array_map(fn (string $h) => array_key_exists($h, $override) ? $override[$h] : $base[$h], self::HEADERS);
    }

    /** @return array<string, mixed> */
    private function commitment(string $type, string $externalId): array
    {
        $row = DB::table('commercial_commitments')->where('tenant_id', $this->tenant->id)->where('commitment_type', $type)->where('external_id', $externalId)->first();
        $this->assertNotNull($row, "No existe {$type} {$externalId}");

        return (array) $row;
    }

    public function test_import_creates_commitments_with_derived_status_and_pending_quantities(): void
    {
        $job = $this->importCsv('commitments', self::HEADERS, [
            $this->row(['external_id' => 'PED-1', 'quantity' => 100, 'quantity_fulfilled' => 40]),
            $this->row(['external_id' => 'PED-1', 'line_number' => 2, 'product_sku' => 'VAR-12', 'quantity' => 50, 'quantity_fulfilled' => 0]),
            $this->row(['commitment_type' => 'cotizacion', 'external_id' => 'COT-1', 'known_at' => '', 'quantity' => 10]),
            $this->row(['commitment_type' => 'contrato', 'external_id' => 'CON-1', 'valid_from' => '2026-10-01', 'valid_to' => '2027-03-31', 'quantity' => 500]),
            $this->row(['external_id' => 'PED-2', 'quantity' => 20, 'quantity_fulfilled' => 20]),
        ]);

        $stats = $job->stats;
        $this->assertSame(4, $stats['commitments_new']);
        $this->assertSame(0, $stats['rows_error']);
        $this->assertSame('partially_fulfilled', $this->commitment('order', 'PED-1')['status']);
        $this->assertSame('open', $this->commitment('quote', 'COT-1')['status']);
        $this->assertSame('confirmed', $this->commitment('contract', 'CON-1')['status']);
        $this->assertSame('fulfilled', $this->commitment('order', 'PED-2')['status']);
        $this->assertNull($this->commitment('quote', 'COT-1')['known_at'], 'known_at desconocido queda NULL, no se rellena con la ingestión');
        $this->assertNotNull($this->commitment('order', 'PED-1')['known_at']);

        $detail = $this->getJson('/api/v1/commitments/'.$this->commitment('order', 'PED-1')['id'])->assertOk()->json('data');
        $this->assertEqualsWithDelta(110.0, $detail['pending_total'], 0.0001, 'pendiente = (100-40) + 50');
        $this->assertEquals(60.0, $detail['items'][0]['pending']);
        $this->assertCount(1, $detail['revisions']);

        // Cédula nunca en claro y procedencia de ingestión.
        $this->assertSame(0, DB::table('customers')->where('tenant_id', $this->tenant->id)->where('external_code', '1710034065')->count());
        $this->assertSame(0, DB::table('commercial_commitments')->where('tenant_id', $this->tenant->id)->whereNull('source_hash')->count());
    }

    public function test_reimport_is_idempotent_and_a_change_adds_a_revision_replacing_lines(): void
    {
        $rows = [$this->row(['quantity' => 100]), $this->row(['line_number' => 2, 'product_sku' => 'VAR-12', 'quantity' => 50])];
        $this->importCsv('commitments', self::HEADERS, $rows);
        $again = $this->importCsv('commitments', self::HEADERS, $rows);
        $this->assertSame(1, $again->stats['commitments_unchanged']);
        $this->assertSame(0, $again->stats['commitments_new']);
        $this->assertSame(1, DB::table('commercial_commitments')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(2, DB::table('commercial_commitment_items')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(1, DB::table('commercial_commitment_revisions')->where('tenant_id', $this->tenant->id)->count(), 'sin cambios no hay revisión nueva');

        $changed = $this->importCsv('commitments', self::HEADERS, [$this->row(['quantity' => 120, 'known_at' => '2026-09-20 09:00:00'])]);
        $this->assertSame(1, $changed->stats['commitments_updated']);
        $this->assertSame(1, DB::table('commercial_commitments')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(1, DB::table('commercial_commitment_items')->where('tenant_id', $this->tenant->id)->count(), 'las líneas se reemplazan (la segunda línea ya no existe)');
        $this->assertSame([1, 2], DB::table('commercial_commitment_revisions')->where('tenant_id', $this->tenant->id)->orderBy('revision')->pluck('revision')->map(fn ($r) => (int) $r)->all());
    }

    public function test_cancellation_and_as_of_reconstruction_respect_the_cutoff(): void
    {
        // v1 conocida el 1/9; v2 (más cantidad) conocida el 20/9; v3 cancelada el 5/10 (conocida el 6/10).
        $this->importCsv('commitments', self::HEADERS, [$this->row(['quantity' => 100, 'known_at' => '2026-09-01 10:30:00'])]);
        $this->importCsv('commitments', self::HEADERS, [$this->row(['quantity' => 150, 'known_at' => '2026-09-20 09:00:00'])]);
        $this->importCsv('commitments', self::HEADERS, [$this->row(['quantity' => 150, 'known_at' => '2026-10-06 08:00:00', 'cancelled_at' => '2026-10-05 17:00:00'])]);
        $this->assertSame('cancelled', $this->commitment('order', 'PED-1')['status']);
        $this->assertSame(3, DB::table('commercial_commitment_revisions')->where('tenant_id', $this->tenant->id)->count());

        $view = fn (string $asOf) => $this->getJson('/api/v1/commitments?as_of='.urlencode($asOf))->assertOk()->json('data');
        $this->assertSame([], $view('2026-08-31T00:00:00-05:00'), 'antes de conocerse no existe');
        $v1 = $view('2026-09-10T00:00:00-05:00');
        $this->assertSame(1, $v1[0]['revision']);
        $this->assertEquals(100.0, $v1[0]['pending_total'], 'el corte del 10/9 ve la cantidad conocida entonces (100), no la corregida posterior (150)');
        $v2 = $view('2026-09-30T00:00:00-05:00');
        $this->assertSame(2, $v2[0]['revision']);
        $this->assertEquals(150.0, $v2[0]['pending_total']);
        $this->assertSame('confirmed', $v2[0]['status_at_cutoff']);
        $v3 = $view('2026-10-10T00:00:00-05:00');
        $this->assertSame('cancelled', $v3[0]['status_at_cutoff']);
        $this->assertEquals(0.0, $v3[0]['pending_total']);
        // Cancelación futura respecto del corte: a ese corte aún estaba vigente.
        $this->assertEquals(150.0, $view('2026-10-05T12:00:00-05:00')[0]['pending_total']);
    }

    public function test_ingestion_bounds_knowledge_when_known_at_is_not_declared_and_undated_cancellations_are_flagged(): void
    {
        $this->importCsv('commitments', self::HEADERS, [
            $this->row(['external_id' => 'SINF-1', 'known_at' => '']),
            $this->row(['external_id' => 'CANC-1', 'status' => 'cancelado', 'known_at' => '2026-09-02 08:00:00', 'cancelled_at' => '']),
        ]);
        $this->assertNull($this->commitment('order', 'SINF-1')['known_at']);
        $this->assertNull($this->commitment('order', 'CANC-1')['cancelled_at']);
        $this->assertSame('cancelled', $this->commitment('order', 'CANC-1')['status']);

        // Importado HOY sin known_at: la ingestión es la cota superior del conocimiento. Un corte anterior a la importación no lo ve...
        $past = $this->getJson('/api/v1/commitments?as_of='.urlencode('2026-10-20T00:00:00-05:00'))->assertOk()->json('data');
        $this->assertNotContains('SINF-1', array_column($past, 'external_id'), 'importado después del corte: no se pudo conocer antes');
        $this->assertContains('CANC-1', array_column($past, 'external_id'));
        $cancelled = collect($past)->firstWhere('external_id', 'CANC-1');
        $this->assertContains('cancellation_date_unknown', $cancelled['flags']);
        $this->assertEquals(0.0, $cancelled['pending_total']);
        $this->assertSame('declared', $cancelled['known_basis']);
        // ...pero sí cuenta en el corte de hoy, marcado como conocimiento por ingestión.
        $today = $this->getJson('/api/v1/commitments?as_of='.urlencode(now()->addMinute()->toIso8601String()))->assertOk()->json('data');
        $sinf = collect($today)->firstWhere('external_id', 'SINF-1');
        $this->assertNotNull($sinf, 'el pedido confirmado importado sin known_at cuenta en el corte de hoy');
        $this->assertSame('ingestion', $sinf['known_basis']);
        $this->assertNotNull($sinf['known_at']);
        $this->assertEquals(100.0, $sinf['pending_total']);
        $detail = $this->getJson('/api/v1/commitments/'.$this->commitment('order', 'CANC-1')['id'])->json('data');
        $this->assertTrue($detail['cancellation_date_unknown']);
    }

    public function test_a_declared_known_at_makes_the_version_visible_from_that_instant_and_a_later_undeclared_version_only_from_its_ingestion(): void
    {
        $this->importCsv('commitments', self::HEADERS, [$this->row(['external_id' => 'DEC-1', 'quantity' => 40, 'known_at' => '2026-09-05 10:00:00'])]);
        $view = fn (string $asOf) => collect($this->getJson('/api/v1/commitments?as_of='.urlencode($asOf))->assertOk()->json('data'))->firstWhere('external_id', 'DEC-1');
        $this->assertNull($view('2026-09-05T09:59:00-05:00'));                                  // antes del known_at declarado
        $v = $view('2026-09-05T10:00:00-05:00');
        $this->assertSame('declared', $v['known_basis']);
        $this->assertEquals(40.0, $v['pending_total']);
        // Nueva versión (cantidad 90) sin known_at: visible solo desde su ingestión; antes sigue la versión declarada.
        $this->importCsv('commitments', self::HEADERS, [$this->row(['external_id' => 'DEC-1', 'quantity' => 90, 'known_at' => ''])]);
        $this->assertEquals(40.0, $view('2026-10-01T00:00:00-05:00')['pending_total']);
        $latest = $view(now()->addMinute()->toIso8601String());
        $this->assertSame('ingestion', $latest['known_basis']);
        $this->assertEquals(90.0, $latest['pending_total']);
        $this->assertSame(2, $latest['revision']);
    }

    public function test_validation_rules(): void
    {
        $job = $this->importCsv('commitments', self::HEADERS, [
            $this->row(['external_id' => 'E1', 'quantity' => 0]),                                     // cantidad cero
            $this->row(['external_id' => 'E2', 'issued_at' => '2027-12-01 10:00:00']),               // futuro
            $this->row(['external_id' => 'E3', 'currency' => 'EUR']),                                // moneda
            $this->row(['external_id' => 'E4', 'commitment_type' => 'regalo']),                      // tipo
            $this->row(['external_id' => 'E5', 'valid_from' => '2026-12-01', 'valid_to' => '2026-11-01']), // vigencia invertida
            $this->row(['external_id' => 'E6', 'branch_code' => '999']),                             // sucursal inexistente
            $this->row(['external_id' => 'OK-1']),
        ], confirm: false);
        $codes = collect($this->importErrors($job))->pluck('error_code')->all();
        foreach (['invalid_number', 'future_date', 'currency_mismatch', 'invalid_value', 'invalid_date', 'unknown_branch'] as $code) {
            $this->assertContains($code, $codes, "falta el error {$code}");
        }
        $this->assertSame(6, $job->stats['rows_error']);
        $this->assertSame(1, $job->stats['commitments_valid']);

        // Cabecera contradictoria dentro del mismo compromiso.
        $conflict = $this->importCsv('commitments', self::HEADERS, [$this->row(['external_id' => 'C1', 'expected_delivery_date' => '2026-11-15']), $this->row(['external_id' => 'C1', 'line_number' => 2, 'product_sku' => 'VAR-12', 'expected_delivery_date' => '2026-12-31'])], confirm: false);
        $this->assertContains('document_conflict', array_column($this->importErrors($conflict), 'error_code'));
    }

    public function test_units_are_never_converted_silently(): void
    {
        $this->importCsv('commitments', self::HEADERS, [$this->row(['external_id' => 'U0', 'product_sku' => 'CEM-50', 'quantity' => 1])]); // crea el producto con unidad «unidad»

        // Unidad distinta sin conversión declarada ⇒ ERROR.
        $job = $this->importCsv('commitments', self::HEADERS, [$this->row(['external_id' => 'U1', 'unit' => 'caja', 'quantity' => 5])], confirm: false);
        $this->assertContains('unit_conversion_missing', array_column($this->importErrors($job), 'error_code'));
        $this->assertSame(0, DB::table('commercial_commitments')->where('tenant_id', $this->tenant->id)->where('external_id', 'U1')->count());

        // Misma unidad con otra tipografía: no hace falta conversión.
        $this->importCsv('commitments', self::HEADERS, [$this->row(['external_id' => 'U2', 'unit' => 'Unidad', 'quantity' => 5])]);

        // «und» NO se asume igual a «unidad»: requiere declararlo (factor 1 explícito).
        $und = $this->importCsv('commitments', self::HEADERS, [$this->row(['external_id' => 'U3', 'unit' => 'und', 'quantity' => 5])], confirm: false);
        $this->assertContains('unit_conversion_missing', array_column($this->importErrors($und), 'error_code'));

        // Con la conversión declarada (1 caja = 12 unidades) la cantidad se convierte y se conserva el original.
        $this->postJson('/api/v1/unit-conversions', ['product_sku' => 'CEM-50', 'from_unit' => 'caja', 'to_unit' => 'unidad', 'factor' => 12])->assertCreated();
        $this->importCsv('commitments', self::HEADERS, [$this->row(['external_id' => 'U4', 'unit' => 'caja', 'quantity' => 5, 'quantity_fulfilled' => 1, 'unit_price' => 120])]);
        $item = (array) DB::table('commercial_commitment_items')->where('tenant_id', $this->tenant->id)
            ->where('commitment_id', $this->commitment('order', 'U4')['id'])->first();
        $this->assertEqualsWithDelta(60.0, (float) $item['quantity'], 0.0001);
        $this->assertEqualsWithDelta(12.0, (float) $item['quantity_fulfilled'], 0.0001);
        $this->assertEqualsWithDelta(10.0, (float) $item['unit_price'], 0.0001, 'precio por caja 120 → 10 por unidad');
        $this->assertEqualsWithDelta(5.0, (float) $item['source_quantity'], 0.0001);
        $this->assertSame('caja', $item['source_unit']);
        $this->assertEqualsWithDelta(12.0, (float) $item['conversion_factor'], 0.0001);
        $this->assertSame('unidad', $item['unit']);
    }

    public function test_api_creates_updates_and_cancels_commitments_through_the_same_pipeline(): void
    {
        $payload = [
            'commitment_type' => 'order', 'external_id' => 'API-1', 'issued_at' => '2026-10-01 09:00:00', 'known_at' => '2026-10-01 09:05:00', 'branch_code' => '001',
            'expected_delivery_date' => '2026-11-20', 'items' => [['product_sku' => 'CEM-50', 'quantity' => 30, 'unit_price' => 8.5], ['product_sku' => 'VAR-12', 'quantity' => 10]],
        ];
        $this->postJson('/api/v1/commitments', $payload)->assertCreated()->assertJsonPath('result', 'created')->assertJsonPath('data.pending_total', 40);
        $this->postJson('/api/v1/commitments', $payload)->assertOk()->assertJsonPath('result', 'unchanged');
        $payload['items'][0]['quantity_fulfilled'] = 10;
        $this->postJson('/api/v1/commitments', $payload)->assertOk()->assertJsonPath('result', 'updated')->assertJsonPath('data.pending_total', 30);
        // Datos inválidos: 422 con los errores del importador y sin dejar nada a medias.
        $this->postJson('/api/v1/commitments', ['commitment_type' => 'order', 'external_id' => 'API-2', 'issued_at' => '2026-10-01', 'items' => [['product_sku' => 'CEM-50', 'quantity' => -5]]])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonStructure(['errors' => ['quantity']]);
        $this->assertSame(0, DB::table('commercial_commitments')->where('tenant_id', $this->tenant->id)->where('external_id', 'API-2')->count());

        $id = $this->commitment('order', 'API-1')['id'];
        $this->postJson("/api/v1/commitments/{$id}/cancel", ['cancelled_at' => '2026-10-10T12:00:00-05:00', 'known_at' => '2026-10-10T12:05:00-05:00'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.pending_total', 0);
        $this->postJson("/api/v1/commitments/{$id}/cancel")->assertStatus(409)->assertJsonPath('code', 'already_cancelled');
        $this->assertSame(3, DB::table('commercial_commitment_revisions')->where('tenant_id', $this->tenant->id)->count(), 'alta, corrección y cancelación');
        $before = $this->getJson('/api/v1/commitments?as_of='.urlencode('2026-10-10T00:00:00-05:00'))->json('data');
        $this->assertEquals(30.0, $before[0]['pending_total'], 'antes de la cancelación sigue vigente');
        $this->getJson('/api/v1/commitments?type=order&status=cancelled')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_isolation_other_tenant_sees_nothing_and_composite_foreign_keys_hold(): void
    {
        $this->importCsv('commitments', self::HEADERS, [$this->row()]);
        $commitmentId = $this->commitment('order', 'PED-1')['id'];
        $productA = (int) DB::table('products')->where('tenant_id', $this->tenant->id)->value('id');

        [$tenantB, $userB] = $this->createTenantWithUser();
        $this->actingAsTenantUser($userB, $tenantB);
        $this->assertSame([], $this->getJson('/api/v1/commitments')->assertOk()->json('data'));
        $this->assertSame([], $this->getJson('/api/v1/commitments?as_of='.urlencode('2026-10-20T00:00:00-05:00'))->json('data'));
        $this->getJson("/api/v1/commitments/{$commitmentId}")->assertNotFound();
        $this->postJson("/api/v1/commitments/{$commitmentId}/cancel")->assertNotFound();
        // El mismo identificador externo en otra empresa es otro compromiso (la unicidad es por empresa).
        $this->createBranch($tenantB, '001', 'Matriz B');
        $this->postJson('/api/v1/commitments', ['commitment_type' => 'order', 'external_id' => 'PED-1', 'issued_at' => '2026-10-01', 'branch_code' => '001', 'items' => [['product_sku' => 'CEM-50', 'quantity' => 3]]])->assertCreated();
        $this->assertSame(2, DB::table('commercial_commitments')->where('external_id', 'PED-1')->count());

        // La base de datos impide por sí sola que un ítem de B apunte a un compromiso/producto de A.
        $this->expectException(QueryException::class);
        DB::table('commercial_commitment_items')->insert(['tenant_id' => $tenantB->id, 'commitment_id' => $commitmentId, 'line_number' => 9, 'product_id' => $productA, 'quantity' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }
}
