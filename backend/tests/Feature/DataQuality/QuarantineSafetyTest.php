<?php

declare(strict_types=1);

namespace Tests\Feature\DataQuality;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsImportFiles;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\DataQuality\Support\IngestsSales;
use Tests\TestCase;

/**
 * Seguridad de la cuarentena frente a cargas posteriores (revisión adversarial de W2-A): liberar NUNCA sobrescribe en silencio una versión más
 * nueva del documento (409 `newer_version_exists`; `force = true` lo confirma y queda auditado); una carga posterior que deja bien el documento
 * SUPERA los registros pendientes; y reprocesar el mismo original no reabre lo ya resuelto ni duplica lo pendiente (también las filas sin número).
 */
final class QuarantineSafetyTest extends TestCase
{
    use BuildsImportFiles, IngestsSales, InteractsWithTenants, RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeImportInfrastructure();
        [$this->tenant, $this->owner] = $this->createTenantWithUser();
        $this->createBranch($this->tenant, '001', 'Matriz');
        $this->actingAsTenantUser($this->owner, $this->tenant);
    }

    /** @return array<string, mixed> */
    private function batchDoc(string $number, string $type, float $qty): array
    {
        return ['document_number' => $number, 'document_type' => $type, 'issued_at' => '2026-01-07T10:00:00-05:00', 'branch_code' => '001',
            'items' => [['product_sku' => 'CEM-50', 'product_name' => 'Cemento 50kg', 'quantity' => $qty, 'unit_price' => 8.5]]];
    }

    private function quantityOf(string $number): float
    {
        return (float) DB::table('sale_items as i')->join('sales as s', 's.id', '=', 'i.sale_id')->where('s.document_number', $number)->value('i.quantity');
    }

    public function test_a_later_load_that_writes_the_document_supersedes_its_pending_record_and_the_stale_release_is_refused(): void
    {
        $this->travelTo('2026-06-10 12:00:00');
        $this->importSales([$this->salesRow('F-0'), $this->salesRow('F-3', 'Factura', 'Rechazada', '2026-01-08 10:00:00', '001', 'CEM-50', '1')], ['skip_invalid_rows' => true]);
        $id = (int) DB::table('quarantine_records')->where('record_key', 'invoice|F-3')->value('id');
        $this->assertSame('pending', DB::table('quarantine_records')->where('id', $id)->value('status'));

        // El ERP reenvía F-3 CORREGIDA (completada, 5 unidades): entra a Silver y supera el registro pendiente.
        $this->travelTo('2026-06-11 09:00:00');
        $this->importSales([$this->salesRow('F-3', 'Factura', '', '2026-01-08 10:00:00', '001', 'CEM-50', '5')], ['skip_invalid_rows' => true]);
        $record = DB::table('quarantine_records')->where('id', $id)->first();
        $this->assertSame('superseded', $record->status);
        $this->assertSame(['superseded', 'later_load'], [$this->decode($record->payload)['resolution']['action'], $this->decode($record->payload)['resolution']['reason']]);
        $this->assertNotNull($record->resolved_at);
        $this->assertCount(1, $this->getJson('/api/v1/data-quality/quarantine?status=superseded')->assertOk()->json('data'));
        $this->assertCount(0, $this->getJson('/api/v1/data-quality/quarantine?status=pending')->json('data'));

        // Liberar el registro viejo ya no es posible: la versión corregida no se anula.
        $this->postJson("/api/v1/data-quality/quarantine/{$id}/release", ['value_maps' => ['status' => ['Rechazada' => 'Anulada']]])->assertStatus(409)->assertJsonPath('code', 'invalid_state');
        $this->postJson("/api/v1/data-quality/quarantine/{$id}/discard", ['reason' => 'ya superado'])->assertStatus(409);
        $sale = DB::table('sales')->where('document_number', 'F-3')->first();
        $this->assertSame(['completed', 0], [$sale->status, (int) $sale->current_revision]);
        $this->assertSame(5.0, $this->quantityOf('F-3'));
    }

    public function test_release_after_a_reload_under_another_key_is_refused_with_the_current_version_unless_forced_and_audited(): void
    {
        // T0: la cotización F-3 (tipo desconocido) queda en cuarentena bajo la clave unknown|F-3.
        $this->travelTo('2026-06-10 12:00:00');
        $this->importSales([$this->salesRow('F-0'), $this->salesRow('F-3', 'Cotización', '', '2026-01-07 10:00:00', '001', 'CEM-50', '3')], ['skip_invalid_rows' => true]);
        $id = (int) DB::table('quarantine_records')->where('record_key', 'unknown|F-3')->value('id');
        // T1: el integrador envía por lote el mismo número ya como «otro» con otro contenido (clave other|F-3): no supera al registro unknown|F-3.
        $this->travelTo('2026-06-11 09:00:00');
        $this->postJson('/api/v1/sales/batch', ['documents' => [$this->batchDoc('F-3', 'otro', 7)]])->assertOk()->assertJsonPath('data.created', 1);
        $this->assertSame('pending', DB::table('quarantine_records')->where('id', $id)->value('status'), 'sin supersesión: la clave es otra');

        // T2: liberar con el mapeo sobrescribiría la versión de T1 (más nueva, sin orden de fuente): 409 con la versión vigente.
        $this->travelTo('2026-06-12 08:00:00');
        $maps = ['value_maps' => ['document_type' => ['Cotización' => 'Otro']]];
        $refused = $this->postJson("/api/v1/data-quality/quarantine/{$id}/release", $maps)->assertStatus(409);
        $refused->assertJsonPath('code', 'newer_version_exists');
        $current = $refused->json('context.current_versions.0');
        $this->assertSame(['other', 'F-3', 'completed', 0], [$current['document_type'], $current['document_number'], $current['status'], $current['current_revision']]);
        $this->assertEquals(59.5, (float) $current['total'], 'la versión vigente: 7 × 8,50');
        $this->assertNotNull($refused->json('context.quarantined_at'));
        $this->assertSame(7.0, $this->quantityOf('F-3'), 'no se escribió nada');
        $this->assertSame('pending', DB::table('quarantine_records')->where('id', $id)->value('status'));
        $this->assertSame(0, DB::table('sales_document_revisions')->count());

        // Con confirmación explícita se escribe y queda auditado (quién, qué versión sobrescribió).
        $forced = $this->postJson("/api/v1/data-quality/quarantine/{$id}/release", $maps + ['force' => true])->assertOk();
        $forced->assertJsonPath('data.status', 'released')->assertJsonPath('data.write.state', 'updated')->assertJsonPath('data.write.forced', true);
        $this->assertSame(3.0, $this->quantityOf('F-3'));
        $this->assertSame(1, DB::table('sales_document_revisions')->count(), 'la versión sobrescrita se archivó');
        $audit = DB::table('audit_logs')->where('action', 'quarantine.released')->first();
        $metadata = $this->decode($audit->metadata);
        $this->assertTrue($metadata['forced_over_newer_version']);
        $this->assertSame(['F-3'], $metadata['overwritten_documents']);
        $this->assertSame($this->owner->id, (int) $audit->user_id);
        $this->assertSame('F-3', $forced->json('data.resolution.forced_over_newer_version.0.document_number'));
    }

    public function test_release_over_a_version_written_before_the_quarantine_is_the_expected_update(): void
    {
        $this->travelTo('2026-06-09 10:00:00');
        $this->postJson('/api/v1/sales/batch', ['documents' => [$this->batchDoc('F-3', 'otro', 1)]])->assertOk();
        $this->travelTo('2026-06-10 12:00:00');
        $this->importSales([$this->salesRow('F-0'), $this->salesRow('F-3', 'Cotización', '', '2026-01-07 10:00:00', '001', 'CEM-50', '4')], ['skip_invalid_rows' => true]);
        $id = (int) DB::table('quarantine_records')->where('record_key', 'unknown|F-3')->value('id');

        // La versión de Silver es ANTERIOR a la cuarentena: la liberación es la actualización esperada (sin 409, sin force).
        $this->travelTo('2026-06-12 08:00:00');
        $released = $this->postJson("/api/v1/data-quality/quarantine/{$id}/release", ['value_maps' => ['document_type' => ['Cotización' => 'Otro']]])->assertOk();
        $released->assertJsonPath('data.write.state', 'updated')->assertJsonPath('data.write.forced', false);
        $this->assertSame(4.0, $this->quantityOf('F-3'));
        $this->assertSame(1, DB::table('sales_document_revisions')->count());
    }

    public function test_releasing_a_record_supersedes_other_pending_records_of_the_same_document(): void
    {
        $this->travelTo('2026-06-10 12:00:00');
        $this->importSales([$this->salesRow('F-0'), $this->salesRow('F-3', 'Factura', 'Rechazada', '2026-01-08 10:00:00', '001', 'CEM-50', '1')], ['skip_invalid_rows' => true]);
        $this->travelTo('2026-06-10 13:00:00');
        $this->importSales([$this->salesRow('F-7'), $this->salesRow('F-3', 'Factura', 'Rechazada', '2026-01-08 10:00:00', '001', 'CEM-50', '2')], ['skip_invalid_rows' => true]);
        $ids = DB::table('quarantine_records')->where('record_key', 'invoice|F-3')->orderBy('id')->pluck('id')->all();
        $this->assertCount(2, $ids);

        $this->postJson("/api/v1/data-quality/quarantine/{$ids[1]}/release", ['value_maps' => ['status' => ['Rechazada' => 'Anulada']]])->assertOk();
        $this->assertSame(['superseded', 'released'], DB::table('quarantine_records')->whereIn('id', $ids)->orderBy('id')->pluck('status')->all());
    }

    public function test_reprocessing_does_not_duplicate_unnumbered_rows_nor_reopen_discarded_or_released_records(): void
    {
        $job = $this->importSales([$this->salesRow('F-1'), $this->salesRow(''), $this->salesRow('F-9', 'Factura', '', 'no-fecha'), $this->salesRow('COT-1', 'Cotización')], ['skip_invalid_rows' => true]);
        $keys = DB::table('quarantine_records')->orderBy('id')->pluck('record_key', 'id')->all();
        $this->assertEqualsCanonicalizing(['sin_numero|fila 2', 'invoice|F-9', 'unknown|COT-1'], array_values($keys));

        $discarded = (int) DB::table('quarantine_records')->where('record_key', 'invoice|F-9')->value('id');
        $released = (int) DB::table('quarantine_records')->where('record_key', 'unknown|COT-1')->value('id');
        $this->postJson("/api/v1/data-quality/quarantine/{$discarded}/discard", ['reason' => 'fecha irrecuperable'])->assertOk();
        $this->postJson("/api/v1/data-quality/quarantine/{$released}/release", ['value_maps' => ['document_type' => ['Cotización' => 'Otro']]])->assertOk();

        foreach ([1, 2] as $_) {
            $this->assertSame(0, Artisan::call('predictily:bronze-reprocess', ['id' => $job->bronze_object_id]), Artisan::output());
        }
        $after = DB::table('quarantine_records')->orderBy('id')->pluck('status', 'record_key')->all();
        ksort($after);
        $this->assertSame(
            ['invoice|F-9' => 'discarded', 'sin_numero|fila 2' => 'pending', 'unknown|COT-1' => 'released'],
            $after,
            'ni un registro nuevo por la fila sin número, ni el descartado reabierto, ni el liberado vuelto a retener',
        );
        $this->assertSame(3, DB::table('quarantine_records')->count());
    }
}
