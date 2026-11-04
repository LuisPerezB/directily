<?php

declare(strict_types=1);

namespace Tests\Feature\Bridge;

use App\Models\BronzeObject;
use App\Models\ImportJob;
use App\Models\Sale;
use App\Services\Bridge\BridgeSignature;
use App\Services\DataCore\Quality\BronzeReprocessor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsImportFiles;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\Bridge\Support\FakeBridgeDevice;
use Tests\Feature\Bridge\Support\InteractsWithBridge;
use Tests\TestCase;

/**
 * W6-B · Subida por URL prefirmada → Bronze (`bridge_batch`) → pipeline de importación: acotada a empresa/dispositivo/entidad/SHA-256/tamaño/tipo/TTL,
 * checksum validado en el servidor, idempotente por SHA-256 (un lote repetido nunca se registra ni se importa dos veces).
 */
final class BridgeUploadTest extends TestCase
{
    use BuildsImportFiles, InteractsWithBridge, InteractsWithTenants, RefreshDatabase;

    private FakeBridgeDevice $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeImportInfrastructure();
        $this->setUpBridgeTenant();
        $this->device = $this->enrolDevice();
    }

    private function sales(): Builder
    {
        return Sale::withoutTenantScope()->where('tenant_id', $this->tenant->id);
    }

    public function test_batch_goes_to_bronze_with_server_key_and_the_import_pipeline_creates_sales(): void
    {
        $up = $this->uploadBatch($this->device, FakeBridgeDevice::salesRows());
        $up['declare']->assertCreated()->assertJsonPath('data.status', 'upload')->assertJsonPath('data.method', 'PUT')->assertJsonPath('data.headers.Content-Type', 'application/gzip');
        $path = (string) $up['declare']->json('data.path');
        // Ruta RELATIVA prefirmada (el agente la une a SU servidor): nunca un host ajeno.
        $this->assertStringStartsWith('/api/v1/bridge/uploads/'.$this->device->deviceId.'/'.$up['batch']['sha256'].'?', $path);
        $this->assertStringContainsString('signature=', $path);
        $this->assertStringContainsString('expires=', $path);
        $this->assertStringNotContainsString('http', explode('?', $path)[0]);

        $up['put']->assertCreated()->assertJsonPath('data.status', 'stored')->assertJsonPath('data.sha256', $up['batch']['sha256'])->assertJsonPath('data.processing', 'completed');
        $object = DB::table('bronze_objects')->where('tenant_id', $this->tenant->id)->first();
        $this->assertSame('bridge_batch', $object->kind);
        $this->assertSame('sales', $object->entity);
        $this->assertSame($up['batch']['sha256'], $object->sha256);
        $this->assertSame($up['batch']['size_bytes'], (int) $object->size_bytes);
        $this->assertSame($this->sourceId, (int) $object->data_source_id);
        $this->assertSame('bridge:'.$this->device->deviceId, $object->received_from_device);
        $this->assertMatchesRegularExpression('#^tenants/'.$this->tenant->uuid.'/bronze/sales/\d{4}/\d{2}/'.$up['batch']['sha256'].'\.ndjson\.gz$#', $object->object_key);
        $this->assertSame($up['batch']['bytes'], Storage::disk('local')->get($object->object_key), 'el contenido guardado es el original, íntegro');
        $this->assertNotNull($object->schema_fingerprint);

        // Cola síncrona en pruebas: el pipeline de importación ya corrió.
        $this->assertSame('processed', $object->status);
        $metadata = json_decode($object->metadata, true);
        $this->assertSame('completed', $metadata['bridge']['processing']['status']);
        $this->assertSame('backfill', $metadata['bridge']['processing']['ingestion_mode']);
        $this->assertSame(3, $this->sales()->count());
        $this->assertSame(4, DB::table('sale_items')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(3, $this->sales()->where('data_source_id', $this->sourceId)->count(), 'procedencia: la fuente Bridge');
        $this->assertSame(3, $this->sales()->where('bronze_object_id', $object->id)->count(), 'linaje al objeto Bronze del lote');
        $sale = $this->sales()->where('document_number', 'BR-001')->firstOrFail();
        $this->assertEqualsWithDelta(120.0, (float) $sale->subtotal, 0.001);
        $job = ImportJob::withoutTenantScope()->where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->assertSame('completed', $job->status);
        $this->assertSame($this->sourceId, $job->data_source_id);
        $this->assertSame($object->id, $job->bronze_object_id);
        $this->assertNotNull(DB::table('data_sources')->where('id', $this->sourceId)->value('last_synced_at'));
        $this->assertNotNull(DB::table('audit_logs')->where('action', 'bridge.batch_received')->first());
    }

    public function test_replayed_batch_is_never_registered_nor_imported_twice(): void
    {
        $rows = FakeBridgeDevice::salesRows();
        $first = $this->uploadBatch($this->device, $rows);
        $first['put']->assertCreated();

        // Declarar otra vez el mismo contenido: duplicado, sin URL.
        $again = $this->uploadBatch($this->device, $rows);
        $again['declare']->assertOk()->assertJsonPath('data.status', 'duplicate')->assertJsonPath('data.processing', 'completed');
        $this->assertNull($again['put']);
        $this->assertArrayNotHasKey('path', $again['declare']->json('data'));

        // Reenviar el PUT con la URL ya emitida (aún válida): idempotente (200), sin nuevo objeto ni nueva importación.
        $replay = $this->putBatch((string) $first['declare']->json('data.path'), $first['batch']['bytes']);
        $replay->assertOk()->assertJsonPath('data.status', 'duplicate')->assertJsonPath('data.bronze_object_id', $first['put']->json('data.bronze_object_id'));

        $this->assertSame(1, DB::table('bronze_objects')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(1, ImportJob::withoutTenantScope()->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(3, $this->sales()->count());
        $this->assertSame(4, DB::table('sale_items')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_checksum_mismatch_and_wrong_size_store_nothing(): void
    {
        $batch = FakeBridgeDevice::batch(FakeBridgeDevice::salesRows());
        $declare = fn (array $b) => $this->deviceCall($this->device, 'POST', '/api/v1/bridge/uploads', [
            'entity' => 'sales', 'sha256' => $b['sha256'], 'size_bytes' => $b['size_bytes'], 'row_count' => $b['row_count'], 'content_type' => 'application/gzip', 'columns' => $b['columns'],
        ])->assertCreated();

        // Mismo tamaño, otro contenido: el SHA-256 no coincide.
        $path = (string) $declare($batch)->json('data.path');
        $tampered = $batch['bytes'];
        $tampered[20] = $tampered[20] === 'A' ? 'B' : 'A';
        $this->putBatch($path, $tampered)->assertStatus(422)->assertJsonPath('code', 'checksum_mismatch');

        // Cuerpo más corto y más largo que lo declarado.
        $this->putBatch($path, substr($batch['bytes'], 0, -3))->assertStatus(422)->assertJsonPath('code', 'size_mismatch');
        $this->putBatch($path, $batch['bytes'].'extra')->assertStatus(422);
        $this->assertSame(0, DB::table('bronze_objects')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(0, $this->sales()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('tenants/'.$this->tenant->uuid.'/bronze'));

        // El mismo cuerpo correcto sí se acepta con esa URL.
        $this->putBatch($path, $batch['bytes'])->assertCreated();
    }

    public function test_url_is_bound_to_content_type_signature_and_ttl(): void
    {
        $batch = FakeBridgeDevice::batch(FakeBridgeDevice::salesRows());
        $declare = $this->deviceCall($this->device, 'POST', '/api/v1/bridge/uploads', [
            'entity' => 'sales', 'sha256' => $batch['sha256'], 'size_bytes' => $batch['size_bytes'], 'row_count' => $batch['row_count'], 'content_type' => 'application/gzip', 'columns' => $batch['columns'],
        ])->assertCreated();
        $path = (string) $declare->json('data.path');

        $this->putBatch($path, $batch['bytes'], 'text/csv')->assertStatus(415)->assertJsonPath('code', 'unsupported_media_type');
        // Cualquier cambio de la URL invalida la firma: entidad, tamaño, SHA-256 o dispositivo.
        foreach ([str_replace('e=sales', 'e=products', $path), preg_replace('/n=\d+/', 'n=999', $path), str_replace($batch['sha256'], hash('sha256', 'otro'), $path),
            str_replace('/uploads/'.$this->device->deviceId.'/', '/uploads/'.($this->device->deviceId + 1).'/', $path), $path.'&extra=1'] as $bad) {
            $this->putBatch((string) $bad, $batch['bytes'])->assertForbidden();
        }
        // Vencida.
        $this->travel(11)->minutes();
        $this->putBatch($path, $batch['bytes'])->assertForbidden();
        $this->assertSame(0, DB::table('bronze_objects')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_declaration_is_validated_before_a_url_is_issued(): void
    {
        $rows = FakeBridgeDevice::salesRows();
        $batch = FakeBridgeDevice::batch($rows);
        $base = ['entity' => 'sales', 'sha256' => $batch['sha256'], 'size_bytes' => $batch['size_bytes'], 'row_count' => $batch['row_count'], 'content_type' => 'application/gzip', 'columns' => $batch['columns']];
        $declare = fn (array $over) => $this->deviceCall($this->device, 'POST', '/api/v1/bridge/uploads', array_merge($base, $over));

        $declare(['columns' => $batch['columns'] + ['columna_rara' => 'string']])->assertStatus(422)->assertJsonPath('code', 'unknown_columns');
        $declare(['columns' => array_diff_key($batch['columns'], ['quantity' => 1])])->assertStatus(422)->assertJsonPath('code', 'missing_required_columns')->assertJsonPath('context.missing.0', 'quantity');
        $declare(['entity' => 'customers'])->assertStatus(422)->assertJsonPath('code', 'entity_not_allowed');
        $declare(['entity' => 'inventory_lots'])->assertStatus(422)->assertJsonPath('code', 'entity_not_allowed');
        $declare(['sha256' => 'abc'])->assertStatus(422)->assertJsonValidationErrors(['sha256']);
        $declare(['content_type' => 'text/csv'])->assertStatus(422)->assertJsonValidationErrors(['content_type']);
        $declare(['row_count' => 0])->assertStatus(422)->assertJsonValidationErrors(['row_count']);
        $declare(['size_bytes' => 10 * 1024 * 1024 * 1024])->assertStatus(413)->assertJsonPath('code', 'batch_too_large');
        config(['bridge.max_batch_rows' => 2]);
        $declare([])->assertStatus(422)->assertJsonValidationErrors(['row_count']);
        $this->assertSame(0, DB::table('bronze_objects')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_body_whose_first_line_is_not_ndjson_or_uses_undeclared_columns_is_rejected(): void
    {
        $bytes = (string) gzencode("esto no es json\n", 6);
        $declare = $this->deviceCall($this->device, 'POST', '/api/v1/bridge/uploads', [
            'entity' => 'sales', 'sha256' => hash('sha256', $bytes), 'size_bytes' => strlen($bytes), 'row_count' => 1, 'content_type' => 'application/gzip',
            'columns' => FakeBridgeDevice::batch(FakeBridgeDevice::salesRows())['columns'],
        ])->assertCreated();
        $this->putBatch((string) $declare->json('data.path'), $bytes)->assertStatus(422)->assertJsonPath('code', 'invalid_row');

        $rows = FakeBridgeDevice::salesRows();
        $rows[0]['columna_extra'] = 'x';
        $batch = FakeBridgeDevice::batch($rows);
        $columns = FakeBridgeDevice::batch(FakeBridgeDevice::salesRows())['columns'];   // el agente declara menos de lo que envía
        $declare = $this->deviceCall($this->device, 'POST', '/api/v1/bridge/uploads', [
            'entity' => 'sales', 'sha256' => $batch['sha256'], 'size_bytes' => $batch['size_bytes'], 'row_count' => 4, 'content_type' => 'application/gzip', 'columns' => $columns,
        ])->assertCreated();
        $this->putBatch((string) $declare->json('data.path'), $batch['bytes'])->assertStatus(422)->assertJsonPath('code', 'unknown_columns');
        $this->assertSame(0, DB::table('bronze_objects')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_later_runs_are_incremental_and_re_sending_a_corrected_document_updates_without_duplicating(): void
    {
        $this->uploadBatch($this->device, FakeBridgeDevice::salesRows(), declareOverrides: ['run_id' => 'run-0001', 'run_mode' => 'full'])['put']->assertCreated();

        // Segunda extracción (incremental) con un documento corregido en origen (otra cantidad y otra fecha de modificación).
        $rows = FakeBridgeDevice::salesRows();
        $rows[0]['quantity'] = 12;
        $rows[0]['net_amount'] = '102.0000';
        $rows[0]['source_recorded_at'] = now('America/Guayaquil')->subMinutes(5)->format('Y-m-d H:i:s');
        $second = $this->uploadBatch($this->device, [$rows[0], $rows[1]], declareOverrides: ['run_id' => 'run-0002', 'run_mode' => 'incremental', 'seq' => 1]);
        $second['put']->assertCreated();

        $this->assertSame(3, $this->sales()->count(), 'el documento corregido se actualiza, no se duplica');
        $this->assertSame(4, DB::table('sale_items')->where('tenant_id', $this->tenant->id)->count());
        $quantity = (float) DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')->where('sales.document_number', 'BR-001')->where('sale_items.line_number', 1)->value('sale_items.quantity');
        $this->assertEqualsWithDelta(12.0, $quantity, 0.0001);
        $objects = DB::table('bronze_objects')->where('tenant_id', $this->tenant->id)->orderBy('id')->get();
        $this->assertCount(2, $objects);
        $this->assertSame('incremental', json_decode($objects[1]->metadata, true)['bridge']['processing']['ingestion_mode']);
        $this->assertSame('backfill', json_decode($objects[0]->metadata, true)['bridge']['processing']['ingestion_mode']);
    }

    public function test_batch_with_invalid_rows_waits_for_review_and_a_replay_does_not_import_again(): void
    {
        $rows = FakeBridgeDevice::salesRows();
        $rows[2]['quantity'] = 'no es un numero';
        $up = $this->uploadBatch($this->device, $rows);
        $up['put']->assertCreated();

        $metadata = json_decode((string) DB::table('bronze_objects')->where('tenant_id', $this->tenant->id)->value('metadata'), true);
        $this->assertSame('needs_review', $metadata['bridge']['processing']['status']);
        $this->assertSame('stored', DB::table('bronze_objects')->where('tenant_id', $this->tenant->id)->value('status'), 'el original queda a salvo en Bronze');
        $this->assertSame(0, $this->sales()->count(), 'sin filas válidas importadas hasta que una persona revise');
        $this->assertSame('needs_review', $up['put']->json('data.processing'));

        $jobs = ImportJob::withoutTenantScope()->where('tenant_id', $this->tenant->id)->count();
        $this->uploadBatch($this->device, $rows)['declare']->assertOk()->assertJsonPath('data.status', 'duplicate')->assertJsonPath('data.processing', 'needs_review');
        $this->assertSame($jobs, ImportJob::withoutTenantScope()->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_skip_invalid_rows_option_of_the_source_imports_the_valid_ones(): void
    {
        $this->patchJson("/api/v1/data-sources/{$this->sourceId}", ['config' => ['bridge' => ['import' => ['skip_invalid_rows' => true]]]])->assertOk();
        $rows = FakeBridgeDevice::salesRows();
        $rows[3]['quantity'] = 'x';
        $this->uploadBatch($this->device, $rows)['put']->assertCreated();
        $this->assertSame(2, $this->sales()->count());
        $metadata = json_decode((string) DB::table('bronze_objects')->where('tenant_id', $this->tenant->id)->value('metadata'), true);
        $this->assertSame('completed', $metadata['bridge']['processing']['status']);
    }

    public function test_failed_processing_is_recorded_and_retried_when_the_agent_resends_the_batch(): void
    {
        $this->patchJson("/api/v1/data-sources/{$this->sourceId}", ['config' => ['bridge' => ['source_timezone' => 'Marte/Olympus']]])->assertOk();
        $up = $this->uploadBatch($this->device, FakeBridgeDevice::salesRows());
        $up['put']->assertCreated();
        $object = DB::table('bronze_objects')->where('tenant_id', $this->tenant->id)->first();
        $this->assertSame('stored', $object->status);
        $processing = json_decode($object->metadata, true)['bridge']['processing'];
        $this->assertSame('failed', $processing['status']);
        $this->assertSame('bridge_config_invalid', $processing['error_code']);
        $this->assertSame(0, $this->sales()->count());

        // Se corrige la fuente; el agente reenvía el mismo lote: el servidor lo reconoce y lo vuelve a procesar.
        $this->patchJson("/api/v1/data-sources/{$this->sourceId}", ['config' => ['bridge' => ['source_timezone' => 'America/Guayaquil']]])->assertOk();
        $again = $this->uploadBatch($this->device, FakeBridgeDevice::salesRows());
        $again['declare']->assertOk()->assertJsonPath('data.status', 'duplicate');
        $this->assertSame(1, DB::table('bronze_objects')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(3, $this->sales()->count());
        $this->assertSame('completed', json_decode((string) DB::table('bronze_objects')->where('tenant_id', $this->tenant->id)->value('metadata'), true)['bridge']['processing']['status']);
    }

    public function test_inactive_source_refuses_batches_and_other_entities_import_through_the_same_pipeline(): void
    {
        $products = [['sku' => 'NEW-1', 'name' => 'Producto nuevo', 'category' => 'Cemento', 'unit' => 'unidad', 'current_price' => '9.5000', 'is_active' => true]];
        $up = $this->uploadBatch($this->device, $products, 'products');
        $up['put']->assertCreated();
        $this->assertSame(1, DB::table('products')->where('tenant_id', $this->tenant->id)->where('sku', 'NEW-1')->count());
        $this->assertSame('processed', DB::table('bronze_objects')->where('tenant_id', $this->tenant->id)->value('status'));

        $this->patchJson("/api/v1/data-sources/{$this->sourceId}", ['status' => 'inactive'])->assertOk();
        $this->uploadBatch($this->device, FakeBridgeDevice::salesRows())['declare']->assertStatus(409)->assertJsonPath('code', 'source_inactive');
    }

    public function test_same_content_in_another_source_of_the_same_company_is_a_conflict_not_a_silent_duplicate(): void
    {
        $this->uploadBatch($this->device, FakeBridgeDevice::salesRows())['put']->assertCreated();
        $sourceTwo = $this->createBridgeSource('Segunda fuente');
        $second = $this->enrolDevice($sourceTwo);
        $this->uploadBatch($second, FakeBridgeDevice::salesRows())['declare']->assertStatus(409)->assertJsonPath('code', 'batch_conflict');
    }

    public function test_bridge_batch_is_reprocessed_deterministically_from_bronze_without_duplicating_or_regressing(): void
    {
        $up = $this->uploadBatch($this->device, FakeBridgeDevice::salesRows());
        $up['put']->assertCreated();
        $object = $this->withinTenant($this->tenant, fn () => BronzeObject::query()->firstOrFail());
        $before = $this->sales()->count();

        // Reprocesar el original (NDJSON gzip) repite la misma normalización: todo `unchanged`, nada nuevo.
        $result = $this->withinTenant($this->tenant, fn () => app(BronzeReprocessor::class)->reprocess($this->tenant, $object));
        $this->assertSame('bridge_batch', $result['kind']);
        $this->assertSame(['new' => 0, 'updated' => 0, 'unchanged' => 3, 'stale_skipped' => 0, 'rejected' => 0], $result['documents']);
        $this->assertSame($before, $this->sales()->count());
        $this->assertSame(4, DB::table('sale_items')->where('tenant_id', $this->tenant->id)->count());

        // Si el ERP corrigió el documento DESPUÉS (versión más nueva con orden de fuente), reprocesar el original viejo NO la revierte.
        $newer = FakeBridgeDevice::salesRows();
        $newer[0]['quantity'] = 12;
        $newer[0]['net_amount'] = '102.0000';
        $newer[0]['source_recorded_at'] = now('America/Guayaquil')->subMinutes(5)->format('Y-m-d H:i:s');
        $this->uploadBatch($this->device, [$newer[0], $newer[1]], declareOverrides: ['run_id' => 'run-0002', 'run_mode' => 'incremental'])['put']->assertCreated();
        $again = $this->withinTenant($this->tenant, fn () => app(BronzeReprocessor::class)->reprocess($this->tenant, $object->refresh()));
        $this->assertSame(0, $again['documents']['updated'], 'una versión más vieja no sobrescribe la más nueva');
        $quantity = (float) DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')->where('sales.tenant_id', $this->tenant->id)->where('sales.document_number', 'BR-001')->where('sale_items.line_number', 1)->value('sale_items.quantity');
        $this->assertEqualsWithDelta(12.0, $quantity, 0.0001);
    }

    public function test_upload_url_signature_is_the_servers_and_cannot_be_forged_by_a_device(): void
    {
        $batch = FakeBridgeDevice::batch(FakeBridgeDevice::salesRows());
        $claims = http_build_query(['e' => 'sales', 'n' => $batch['size_bytes'], 'r' => 4, 't' => $this->tenant->uuid, 'c' => BridgeSignature::encode((string) json_encode($batch['columns'])),
            'expires' => time() + 600, 'signature' => str_repeat('a', 64)]);
        $this->putBatch("/api/v1/bridge/uploads/{$this->device->deviceId}/{$batch['sha256']}?{$claims}", $batch['bytes'])->assertForbidden();
        $this->assertSame(0, DB::table('bronze_objects')->count());
    }
}
