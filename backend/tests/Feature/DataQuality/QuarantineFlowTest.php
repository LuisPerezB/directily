<?php

declare(strict_types=1);

namespace Tests\Feature\DataQuality;

use App\Enums\TenantRole;
use App\Models\ImportJob;
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
 * Cuarentena de ingestión (ADR-V25-24; W2-A): con `skip_invalid_rows`, un documento con hallazgos REVIEW_REQUIRED o QUARANTINED se RETIENE en
 * `quarantine_records` (no se descarta); sin `skip_invalid_rows` la confirmación se bloquea; la liberación revalida con los mapeos vigentes
 * y escribe en modo incremental enlazado a Bronze; el descarte exige motivo; todo está aislado por empresa y por habilidad.
 */
final class QuarantineFlowTest extends TestCase
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

    /** @return list<list<string>> dos documentos válidos y tres con hallazgos de revisión o inválidos */
    private function mixedRows(): array
    {
        return [
            $this->salesRow('F-1', 'Factura', '', '2026-01-05 10:00:00', '001', 'CEM-50', '2', '8.50', '1710034065'),
            $this->salesRow('F-2', 'Factura', '', '2026-01-06 10:00:00', '001', 'CEM-50', '1', '8.50'),
            $this->salesRow('COT-7', 'Cotización', '', '2026-01-07 10:00:00', '001', 'CEM-50', '3', '8.50', '1710034065'), // REVIEW_REQUIRED
            $this->salesRow('COT-7', 'Cotización', '', '2026-01-07 10:00:00', '001', 'VAR-12', '1', '7.20', '1710034065'),
            $this->salesRow('F-3', 'Factura', 'Rechazada', '2026-01-08 10:00:00', '001', 'CEM-50', '1', '8.50'),           // REVIEW_REQUIRED (estado)
            $this->salesRow('F-4', 'Factura', '', 'no-es-fecha', '001', 'CEM-50', '1', '8.50'),                            // QUARANTINED (fecha inválida)
        ];
    }

    public function test_skip_invalid_rows_retains_review_and_quarantined_documents_instead_of_discarding_them(): void
    {
        $job = $this->importSales($this->mixedRows(), ['skip_invalid_rows' => true]);

        $this->assertSame('completed', $job->status);
        $this->assertSame(['F-1', 'F-2'], DB::table('sales')->orderBy('document_number')->pluck('document_number')->all(), 'solo lo válido llega a Silver');
        $records = DB::table('quarantine_records')->where('tenant_id', $this->tenant->id)->orderBy('id')->get();
        $this->assertSame(['COT-7' => 'unknown|COT-7', 'F-3' => 'invoice|F-3', 'F-4' => 'invoice|F-4'], $records->mapWithKeys(fn ($r) => [explode('|', $r->record_key)[1] => $r->record_key])->all());
        $this->assertSame(3, $records->count());
        foreach ($records as $record) {
            $this->assertSame('pending', $record->status);
            $this->assertSame('sales', $record->entity);
            $this->assertSame($job->id, (int) $record->import_job_id);
            $this->assertNotNull($record->bronze_object_id, 'enlazado al original en Bronze');
        }
        $byKey = $records->keyBy('record_key');
        $this->assertSame(['unknown_document_type'], $this->decode($byKey['unknown|COT-7']->issue_codes));
        $this->assertSame(['unknown_document_status'], $this->decode($byKey['invoice|F-3']->issue_codes));
        $this->assertSame(['invalid_date'], $this->decode($byKey['invoice|F-4']->issue_codes));

        // Payload normalizado y minimizado: las dos filas del documento, sin nombres de clientes y con el código de cliente como en `customers`.
        $payload = $this->decode($byKey['unknown|COT-7']->payload);
        $this->assertCount(2, $payload['rows']);
        $this->assertSame([3, 4], array_column($payload['rows'], 'source_row'));
        $customerCode = $payload['rows'][0]['fields']['customer_code'];
        $this->assertStringStartsWith('id:', $customerCode);
        $this->assertStringNotContainsString('1710034065', (string) $byKey['unknown|COT-7']->payload, 'la cédula nunca se guarda en claro');
        $this->assertTrue(DB::table('customers')->where('tenant_id', $this->tenant->id)->where('external_code', $customerCode)->exists(), 'el mismo código con que `customers` identifica al comprador');
        $this->assertArrayNotHasKey('customer_name', $payload['rows'][0]['fields']);
        $this->assertSame(3, $job->stats['documents_quarantined']);
        $this->assertSame(3, $this->decode($this->qualityReport($job)->summary)['documents_quarantined'], 'el resumen del informe lo expone');
    }

    public function test_a_row_without_a_document_number_is_retained_on_its_own_and_cannot_be_released_until_it_is_valid(): void
    {
        $job = $this->importSales([$this->salesRow('F-1'), $this->salesRow('')], ['skip_invalid_rows' => true]);

        $this->assertSame('completed', $job->status);
        $record = DB::table('quarantine_records')->first();
        $this->assertSame('sin_numero|fila 2', $record->record_key);
        $this->assertSame(['required'], $this->decode($record->issue_codes));
        $this->assertSame([2], array_column($this->decode($record->payload)['rows'], 'source_row'));
        $this->postJson("/api/v1/data-quality/quarantine/{$record->id}/release")->assertUnprocessable()->assertJsonPath('findings.0.code', 'required');
        $this->postJson("/api/v1/data-quality/quarantine/{$record->id}/discard", ['reason' => 'Fila vacía del exportador'])->assertOk();
    }

    public function test_reprocessing_the_original_does_not_duplicate_what_already_waits_for_review(): void
    {
        $job = $this->importSales($this->mixedRows(), ['skip_invalid_rows' => true]);
        $this->assertSame(3, DB::table('quarantine_records')->where('status', 'pending')->count());

        $this->assertSame(0, Artisan::call('predictily:bronze-reprocess', ['id' => $job->bronze_object_id]));
        $this->assertSame(3, DB::table('quarantine_records')->count(), 'el mismo original no vuelve a retener lo que ya está pendiente');
        $this->assertSame(2, DB::table('sales')->count());
    }

    public function test_without_skip_invalid_rows_the_confirmation_is_blocked_and_the_findings_are_recorded(): void
    {
        $job = $this->importSales($this->mixedRows(), [], confirm: false);
        $this->postJson("/api/v1/imports/{$job->id}/confirm")->assertStatus(409)->assertJsonPath('code', 'has_errors');

        $this->assertSame(0, DB::table('quarantine_records')->count(), 'sin skip_invalid_rows no hay cuarentena: la confirmación se bloquea');
        $this->assertSame(0, DB::table('sales')->count());
        $report = $this->qualityReport($job);
        $counts = $this->decode($report->issue_counts);
        $this->assertSame(3, $counts['REVIEW_REQUIRED'], 'tipo desconocido en 2 filas de COT-7 y estado desconocido en F-3 (un hallazgo por fila)');
        $this->assertSame(1, $counts['QUARANTINED'], 'fecha inválida');
        $classes = $this->findings($report->id)->groupBy('code')->map(fn ($g) => $g->first()->dq_class)->all();
        $this->assertSame('REVIEW_REQUIRED', $classes['unknown_document_type']);
        $this->assertSame('REVIEW_REQUIRED', $classes['unknown_document_status']);
        $this->assertSame('QUARANTINED', $classes['invalid_date']);
    }

    public function test_the_quarantine_api_lists_filters_and_shows_a_record(): void
    {
        $job = $this->importSales($this->mixedRows(), ['skip_invalid_rows' => true]);

        $list = $this->getJson('/api/v1/data-quality/quarantine')->assertOk();
        $this->assertSame(3, $list->json('meta.total'));
        $this->assertEqualsCanonicalizing(['id', 'entity', 'record_key', 'status', 'issue_codes', 'rows', 'import_job_id', 'source_sync_id', 'bronze_object_id', 'resolution', 'created_at', 'resolved_by', 'resolved_at'], array_keys($list->json('data.0')));
        $this->assertCount(3, $this->getJson("/api/v1/data-quality/quarantine?import_job_id={$job->id}&status=pending&entity=sales")->assertOk()->json('data'));
        $this->assertCount(0, $this->getJson('/api/v1/data-quality/quarantine?status=released')->assertOk()->json('data'));
        $this->assertCount(0, $this->getJson('/api/v1/data-quality/quarantine?import_job_id=999999')->assertOk()->json('data'));
        $this->assertCount(2, $this->getJson('/api/v1/data-quality/quarantine?per_page=2')->assertOk()->json('data'));
        $this->getJson('/api/v1/data-quality/quarantine?status=otro')->assertUnprocessable();

        $id = DB::table('quarantine_records')->where('record_key', 'unknown|COT-7')->value('id');
        $show = $this->getJson("/api/v1/data-quality/quarantine/{$id}")->assertOk();
        $this->assertCount(2, $show->json('data.payload.rows'));
        $this->assertSame('unknown_document_type', $show->json('data.findings.0.code'));
        $this->assertSame('REVIEW_REQUIRED', $show->json('data.findings.0.dq_class'));
    }

    public function test_releasing_after_adding_a_value_map_writes_incrementally_and_links_bronze(): void
    {
        $job = $this->importSales($this->mixedRows(), ['skip_invalid_rows' => true]);
        $id = DB::table('quarantine_records')->where('record_key', 'unknown|COT-7')->value('id');
        $bronzeId = DB::table('quarantine_records')->where('id', $id)->value('bronze_object_id');

        // Sin el mapeo, la revalidación sigue fallando: 422 con los hallazgos que quedan.
        $still = $this->postJson("/api/v1/data-quality/quarantine/{$id}/release")->assertUnprocessable();
        $still->assertJsonPath('code', 'quarantine_still_invalid');
        $this->assertSame('unknown_document_type', $still->json('findings.0.code'));
        $this->assertSame('REVIEW_REQUIRED', $still->json('findings.0.dq_class'));
        $this->assertSame('pending', DB::table('quarantine_records')->where('id', $id)->value('status'));

        // Un destino fuera del vocabulario canónico se rechaza al guardar el mapeo.
        $this->postJson("/api/v1/data-quality/quarantine/{$id}/release", ['value_maps' => ['document_type' => ['Cotización' => 'cualquier cosa']]])
            ->assertUnprocessable()->assertJsonValidationErrors(['value_maps.document_type']);

        $released = $this->postJson("/api/v1/data-quality/quarantine/{$id}/release", ['value_maps' => ['document_type' => ['Cotización' => 'Otro']]])->assertOk();
        $released->assertJsonPath('data.status', 'released')->assertJsonPath('data.write.state', 'new')->assertJsonPath('data.write.value_maps_added', true);

        $sale = DB::table('sales')->where('document_number', 'COT-7')->first();
        $this->assertNotNull($sale);
        $this->assertSame('other', $sale->document_type);
        $this->assertSame('incremental', $sale->ingestion_mode, 'conocido desde la liberación');
        $this->assertSame($bronzeId, $sale->bronze_object_id, 'enlazado al objeto Bronze del original');
        $this->assertSame(3, (int) $sale->source_row, 'primera fila del documento en el origen');
        $this->assertSame('sales@2026.03.1', $sale->schema_version);
        $this->assertSame(2, DB::table('sale_items')->where('sale_id', $sale->id)->count());
        $this->assertNotNull($sale->version_known_from);
        $this->assertGreaterThan('2026-01-07', substr((string) $sale->version_known_from, 0, 10), 'incremental: conocido desde que se liberó, no desde su día de negocio');
        $this->assertSame('reprocessed', DB::table('data_quality_issues')->where('record_ref', 'unknown|COT-7')->value('status'));

        // El mapeo queda en la importación para las siguientes liberaciones; el registro ya no admite otra liberación.
        $this->assertSame(['document_type' => ['Cotización' => 'Otro']], $this->withinTenant($this->tenant, fn () => ImportJob::query()->find($job->id)->options['value_maps']));
        $this->postJson("/api/v1/data-quality/quarantine/{$id}/release")->assertStatus(409)->assertJsonPath('code', 'invalid_state');
        $this->assertCount(2, $this->getJson('/api/v1/data-quality/quarantine?status=pending')->json('data'));
    }

    public function test_discarding_requires_a_reason_and_keeps_the_record_for_audit(): void
    {
        $this->importSales($this->mixedRows(), ['skip_invalid_rows' => true]);
        $id = DB::table('quarantine_records')->where('record_key', 'invoice|F-4')->value('id');

        $this->postJson("/api/v1/data-quality/quarantine/{$id}/discard")->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->postJson("/api/v1/data-quality/quarantine/{$id}/discard", ['reason' => ' '])->assertUnprocessable();

        $discarded = $this->postJson("/api/v1/data-quality/quarantine/{$id}/discard", ['reason' => 'Documento de prueba del proveedor del ERP'])->assertOk();
        $discarded->assertJsonPath('data.status', 'discarded')->assertJsonPath('data.resolution.action', 'discarded');
        $this->assertSame('Documento de prueba del proveedor del ERP', $discarded->json('data.resolution.reason'));
        $this->assertSame($this->owner->id, (int) DB::table('quarantine_records')->where('id', $id)->value('resolved_by'));
        $this->assertFalse(DB::table('sales')->where('document_number', 'F-4')->exists(), 'descartar no escribe en Silver');
        $this->assertSame('rejected', DB::table('data_quality_issues')->where('record_ref', 'invoice|F-4')->value('status'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'quarantine.discarded')->where('tenant_id', $this->tenant->id)->count(), 'descarte auditado');
        $this->postJson("/api/v1/data-quality/quarantine/{$id}/release")->assertStatus(409);
    }

    public function test_the_quarantine_is_isolated_between_companies_and_by_ability(): void
    {
        $this->importSales($this->mixedRows(), ['skip_invalid_rows' => true]);
        $id = (int) DB::table('quarantine_records')->where('record_key', 'unknown|COT-7')->value('id');

        // Otra empresa: no ve, no abre, no libera ni descarta.
        [$other, $otherOwner] = $this->createTenantWithUser();
        $this->actingAsTenantUser($otherOwner, $other);
        $this->assertSame(0, $this->getJson('/api/v1/data-quality/quarantine')->assertOk()->json('meta.total'));
        $this->getJson("/api/v1/data-quality/quarantine/{$id}")->assertNotFound();
        $this->postJson("/api/v1/data-quality/quarantine/{$id}/release", ['value_maps' => ['document_type' => ['Cotización' => 'Otro']]])->assertNotFound();
        $this->postJson("/api/v1/data-quality/quarantine/{$id}/discard", ['reason' => 'ajeno'])->assertNotFound();
        $this->assertSame('pending', DB::table('quarantine_records')->where('id', $id)->value('status'));

        // Misma empresa, rol de consulta: lee (data.view) pero no libera ni descarta (imports.manage).
        $viewer = $this->addUserToTenant($this->tenant, TenantRole::Viewer);
        $this->actingAsTenantUser($viewer, $this->tenant);
        $this->getJson('/api/v1/data-quality/quarantine')->assertOk();
        $this->getJson("/api/v1/data-quality/quarantine/{$id}")->assertOk();
        $this->postJson("/api/v1/data-quality/quarantine/{$id}/release")->assertForbidden();
        $this->postJson("/api/v1/data-quality/quarantine/{$id}/discard", ['reason' => 'sin permiso'])->assertForbidden();
        $this->assertSame('pending', DB::table('quarantine_records')->where('id', $id)->value('status'));
    }

    public function test_a_blocker_finding_blocks_the_confirmation_even_with_skip_invalid_rows(): void
    {
        $job = $this->importSales([$this->salesRow('F-1')], ['skip_invalid_rows' => true], confirm: false);
        $this->assertSame('validated', $job->status);
        // Un hallazgo BLOCKER en el informe de la importación (p. ej. un problema estructural detectado por una regla) bloquea SIEMPRE.
        $report = $this->qualityReport($job);
        DB::table('data_quality_reports')->where('id', $report->id)->update(['issue_counts' => json_encode(['BLOCKER' => 1, 'REVIEW_REQUIRED' => 0])]);

        $this->postJson("/api/v1/imports/{$job->id}/confirm")->assertStatus(409)->assertJsonPath('code', 'blocked_by_quality');
        $this->assertSame(0, DB::table('sales')->count());
    }
}
