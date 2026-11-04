<?php

declare(strict_types=1);

namespace Tests\Feature\Bridge;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsImportFiles;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\Bridge\Support\FakeBridgeDevice;
use Tests\Feature\Bridge\Support\InteractsWithBridge;
use Tests\TestCase;

/**
 * W6-B · Checkpoints confirmados solo tras el acuse del servidor: el lote citado debe estar en Bronze (misma empresa, entidad y fuente) y NO haber fallado; la
 * marca de agua no puede estar en el futuro ni retroceder, y solo se mueve si los lotes de la corrida son contiguos (1…N), sin huecos ni lotes fallidos
 * (revisión adversarial B-3); repetir una confirmación es idempotente.
 */
final class BridgeCheckpointTest extends TestCase
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

    private function commit(array $body, ?FakeBridgeDevice $device = null): TestResponse
    {
        return $this->deviceCall($device ?? $this->device, 'POST', '/api/v1/bridge/checkpoints', $body + ['run_id' => 'run-0001']);
    }

    /** Sube un lote como la posición $index de la corrida. @return array{sha: string, put: TestResponse} */
    private function batch(string $prefix, int $index, ?FakeBridgeDevice $device = null, string $runId = 'run-0001', array $rows = []): array
    {
        $up = $this->uploadBatch($device ?? $this->device, $rows ?: FakeBridgeDevice::salesRows($prefix), declareOverrides: ['run_id' => $runId, 'seq' => $index]);
        $up['put']->assertCreated();

        return ['sha' => $up['batch']['sha256'], 'put' => $up['put']];
    }

    public function test_intermediate_checkpoint_requires_an_uploaded_batch_then_run_completion_stores_the_watermark(): void
    {
        $rows = FakeBridgeDevice::salesRows();
        $sha = FakeBridgeDevice::batch($rows)['sha256'];
        $cursor = '{"r":"run-0001","a":["sBR-002","i1"]}';

        // Antes de subir el lote no hay acuse: el servidor no avanza el checkpoint.
        $this->commit(['entity' => 'sales', 'batch_sha256' => $sha, 'run_batch_index' => 1, 'checkpoint' => $cursor, 'run_complete' => false])->assertStatus(409)->assertJsonPath('code', 'batch_not_found');
        $this->assertSame([], $this->deviceCall($this->device, 'GET', '/api/v1/bridge/checkpoints')->json('data'));

        $objectId = $this->batch('BR', 1)['put']->json('data.bronze_object_id');
        $first = $this->commit(['entity' => 'sales', 'batch_sha256' => $sha, 'run_batch_index' => 1, 'checkpoint' => $cursor, 'run_complete' => false])->assertOk();
        $first->assertJsonPath('data.checkpoint', $cursor)->assertJsonPath('data.watermark', null)->assertJsonPath('data.bronze_object_id', $objectId);
        $this->commit(['entity' => 'sales', 'batch_sha256' => $sha, 'run_batch_index' => 1, 'checkpoint' => $cursor, 'run_complete' => false])->assertOk();   // idempotente
        $this->assertSame(1, DB::table('bridge_checkpoints')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(1, DB::table('bridge_run_batches')->where('tenant_id', $this->tenant->id)->count(), 'el libro no duplica la posición');

        $watermark = now()->subMinutes(10)->utc()->toIso8601String();
        $done = $this->commit(['entity' => 'sales', 'batch_sha256' => $sha, 'run_batch_index' => 1, 'run_batches' => 1, 'watermark' => $watermark, 'run_complete' => true])->assertOk();
        $done->assertJsonPath('data.checkpoint', null);
        $this->assertSame(now()->subMinutes(10)->utc()->format('Y-m-d H:i'), CarbonImmutable::parse($done->json('data.watermark'))->utc()->format('Y-m-d H:i'));
        $this->assertSame($done->json('data'), $this->deviceCall($this->device, 'GET', '/api/v1/bridge/checkpoints')->json('data.0'));
        // Repetir la confirmación final es idempotente.
        $this->commit(['entity' => 'sales', 'batch_sha256' => $sha, 'run_batch_index' => 1, 'run_batches' => 1, 'watermark' => $watermark, 'run_complete' => true])->assertOk();
    }

    public function test_request_shape_is_validated(): void
    {
        $this->commit(['entity' => 'sales', 'run_complete' => false, 'checkpoint' => 'x', 'batch_sha256' => str_repeat('a', 64)])->assertStatus(422)->assertJsonValidationErrors(['run_batch_index']);
        $this->commit(['entity' => 'sales', 'run_complete' => true, 'watermark' => now()->subHour()->utc()->toIso8601String()])->assertStatus(422)->assertJsonValidationErrors(['run_batches']);
        $this->deviceCall($this->device, 'POST', '/api/v1/bridge/checkpoints', ['entity' => 'sales', 'run_complete' => false, 'checkpoint' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['run_id']);
        $this->commit(['entity' => 'sales', 'run_complete' => false, 'checkpoint' => 'x', 'batch_sha256' => str_repeat('a', 64), 'run_batch_index' => 0])->assertStatus(422)->assertJsonValidationErrors(['run_batch_index']);
        $this->commit(['entity' => 'sales', 'run_complete' => 'quizas'])->assertStatus(422);
    }

    public function test_watermark_is_bounded_never_in_the_future_and_never_regresses(): void
    {
        $empty = fn (array $over) => $this->commit($over + ['entity' => 'sales', 'run_batches' => 0, 'run_complete' => true]);
        $empty(['watermark' => now()->addHour()->utc()->toIso8601String()])->assertStatus(422)->assertJsonPath('code', 'watermark_in_future');
        $empty(['watermark' => null])->assertStatus(422)->assertJsonPath('code', 'watermark_required');
        $empty(['watermark' => now()->utc()->toIso8601String(), 'checkpoint' => 'x'])->assertStatus(422)->assertJsonPath('code', 'checkpoint_not_allowed');
        $this->commit(['entity' => 'sales', 'watermark' => now()->utc()->toIso8601String(), 'run_complete' => false, 'checkpoint' => 'x', 'batch_sha256' => str_repeat('a', 64), 'run_batch_index' => 1])->assertStatus(422)->assertJsonPath('code', 'watermark_not_allowed');
        $this->commit(['entity' => 'sales', 'run_complete' => false])->assertStatus(422)->assertJsonPath('code', 'checkpoint_required');
        $this->commit(['entity' => 'sales', 'checkpoint' => 'x', 'run_complete' => false])->assertStatus(422)->assertJsonPath('code', 'batch_required');

        // Una corrida sin filas confirma solo la marca de agua (sin lote), declarando que tuvo 0 lotes.
        $at = now()->subMinutes(30)->utc();
        $empty(['watermark' => $at->toIso8601String()])->assertOk();
        $empty(['watermark' => $at->copy()->subMinutes(5)->toIso8601String()])->assertStatus(409)->assertJsonPath('code', 'watermark_regression');
        $empty(['watermark' => $at->copy()->addMinutes(5)->toIso8601String()])->assertOk();
        $empty(['entity' => 'customers', 'watermark' => $at->toIso8601String()])->assertStatus(422)->assertJsonPath('code', 'entity_not_allowed');
    }

    public function test_a_run_with_rows_cannot_move_the_watermark_without_proof(): void
    {
        $now = now()->utc()->toIso8601String();
        // Dice haber tenido lotes pero no hay ninguno confirmado en el libro (u7 de la revisión): sin prueba no hay marca de agua.
        $this->commit(['entity' => 'sales', 'run_batches' => 2, 'watermark' => $now, 'run_complete' => true])->assertStatus(409)->assertJsonPath('code', 'run_incomplete')->assertJsonPath('context.missing', [1, 2]);
        $this->commit(['entity' => 'sales', 'run_batches' => 1, 'watermark' => $now, 'run_complete' => true])->assertStatus(409)->assertJsonPath('code', 'run_incomplete');
        // Dice no haber tenido lotes y cita uno.
        $sha = $this->batch('PR', 1)['sha'];
        $this->commit(['entity' => 'sales', 'run_batches' => 0, 'batch_sha256' => $sha, 'run_batch_index' => 1, 'watermark' => $now, 'run_complete' => true])->assertStatus(422)->assertJsonPath('code', 'batch_not_allowed');
        // El lote citado debe ser el último.
        $this->commit(['entity' => 'sales', 'run_batches' => 3, 'batch_sha256' => $sha, 'run_batch_index' => 1, 'watermark' => $now, 'run_complete' => true])->assertStatus(422)->assertJsonPath('code', 'run_batch_index_mismatch');
        // Declara 3 lotes y solo uno está confirmado: sin hueco no hay marca de agua.
        $this->commit(['entity' => 'sales', 'run_batches' => 3, 'batch_sha256' => $sha, 'run_batch_index' => 3, 'watermark' => $now, 'run_complete' => true])
            ->assertStatus(409)->assertJsonPath('code', 'run_incomplete')->assertJsonPath('context.missing', [1, 2]);
        $this->assertNull(DB::table('bridge_checkpoints')->where('bridge_device_id', $this->device->deviceId)->value('watermark'));
        // Una corrida declarada vacía pero con lotes confirmados tampoco pasa.
        $this->commit(['entity' => 'sales', 'checkpoint' => 'c', 'batch_sha256' => $sha, 'run_batch_index' => 1, 'run_complete' => false])->assertOk();
        $this->commit(['entity' => 'sales', 'run_batches' => 0, 'watermark' => $now, 'run_complete' => true])->assertStatus(409)->assertJsonPath('code', 'run_incomplete');
        $this->assertNull(DB::table('bridge_checkpoints')->where('bridge_device_id', $this->device->deviceId)->value('watermark'));
    }

    public function test_batches_of_a_run_must_be_contiguous_and_in_their_own_position(): void
    {
        $now = now()->utc()->toIso8601String();
        $one = $this->batch('C1', 1)['sha'];
        $two = $this->batch('C2', 2, rows: [FakeBridgeDevice::salesRows('C2')[0]])['sha'];
        $three = $this->batch('C3', 3, rows: [FakeBridgeDevice::salesRows('C3')[2]])['sha'];
        $this->commit(['entity' => 'sales', 'checkpoint' => 'c1', 'batch_sha256' => $one, 'run_batch_index' => 1, 'run_complete' => false])->assertOk();
        // Salta el 2: el hueco impide completar.
        $this->commit(['entity' => 'sales', 'run_batches' => 3, 'batch_sha256' => $three, 'run_batch_index' => 3, 'watermark' => $now, 'run_complete' => true])
            ->assertStatus(409)->assertJsonPath('code', 'run_incomplete')->assertJsonPath('context.missing', [2]);
        // La posición 1 no puede ocuparla otro lote.
        $this->commit(['entity' => 'sales', 'checkpoint' => 'cx', 'batch_sha256' => $two, 'run_batch_index' => 1, 'run_complete' => false])->assertStatus(409)->assertJsonPath('code', 'run_batch_conflict');
        // Con 1 y 3 confirmados el libro tiene un hueco: completar SIN citar lote (como hace el agente tras perder su estado) tampoco pasa; la prueba es el libro.
        $this->commit(['entity' => 'sales', 'checkpoint' => 'c3', 'batch_sha256' => $three, 'run_batch_index' => 3, 'run_complete' => false])->assertStatus(200);
        $this->commit(['entity' => 'sales', 'run_batches' => 3, 'watermark' => $now, 'run_complete' => true])->assertStatus(409)->assertJsonPath('code', 'run_incomplete')->assertJsonPath('context.missing', [2]);
        // Confirmado el 2, el libro es 1…3 y la corrida completa; aquí sí cita su último lote.
        $this->commit(['entity' => 'sales', 'checkpoint' => 'c2', 'batch_sha256' => $two, 'run_batch_index' => 2, 'run_complete' => false])->assertOk();
        $this->commit(['entity' => 'sales', 'run_batches' => 3, 'batch_sha256' => $three, 'run_batch_index' => 3, 'watermark' => $now, 'run_complete' => true])->assertOk()->assertJsonPath('data.checkpoint', null);
        // Al completar se poda el libro de otras corridas.
        $other = $this->batch('C4', 1, runId: 'run-0002', rows: [FakeBridgeDevice::salesRows('C4')[1]])['sha'];
        $this->commit(['run_id' => 'run-0002', 'entity' => 'sales', 'checkpoint' => 'd1', 'batch_sha256' => $other, 'run_batch_index' => 1, 'run_complete' => false])->assertOk();
        $this->commit(['run_id' => 'run-0002', 'entity' => 'sales', 'run_batches' => 1, 'batch_sha256' => $other, 'run_batch_index' => 1, 'watermark' => now()->utc()->addSeconds(1)->toIso8601String(), 'run_complete' => true])->assertOk();
        $this->assertSame(['run-0002'], DB::table('bridge_run_batches')->where('tenant_id', $this->tenant->id)->distinct()->pluck('run_id')->all());
    }

    public function test_a_checkpoint_for_a_failed_batch_is_refused_and_accepted_after_the_server_reprocesses_it(): void
    {
        $this->patchJson("/api/v1/data-sources/{$this->sourceId}", ['config' => ['bridge' => ['source_timezone' => 'Marte/Olympus']]])->assertOk();
        $up = $this->uploadBatch($this->device, FakeBridgeDevice::salesRows('FAIL'), declareOverrides: ['run_id' => 'run-0001', 'seq' => 1]);
        $up['put']->assertCreated()->assertJsonPath('data.processing', 'failed');
        $sha = $up['batch']['sha256'];

        $body = ['entity' => 'sales', 'batch_sha256' => $sha, 'run_batch_index' => 1, 'run_batches' => 1, 'watermark' => now()->utc()->toIso8601String(), 'run_complete' => true];
        $this->commit($body)->assertStatus(409)->assertJsonPath('code', 'batch_failed')->assertJsonPath('context.processing', 'failed');
        $this->commit(['entity' => 'sales', 'batch_sha256' => $sha, 'run_batch_index' => 1, 'checkpoint' => 'x', 'run_complete' => false])->assertStatus(409)->assertJsonPath('code', 'batch_failed');
        $this->assertNull(DB::table('bridge_checkpoints')->where('bridge_device_id', $this->device->deviceId)->value('watermark'));
        $this->assertSame(0, DB::table('bridge_run_batches')->count(), 'un lote fallido no entra en el libro');

        // Se corrige la causa; el agente reenvía: el servidor lo reprocesa y avisa el estado en `duplicate`; ahora sí se confirma.
        $this->patchJson("/api/v1/data-sources/{$this->sourceId}", ['config' => ['bridge' => ['source_timezone' => 'America/Guayaquil']]])->assertOk();
        $again = $this->uploadBatch($this->device, FakeBridgeDevice::salesRows('FAIL'), declareOverrides: ['run_id' => 'run-0001', 'seq' => 1]);
        $again['declare']->assertOk()->assertJsonPath('data.status', 'duplicate');
        $this->commit($body)->assertOk()->assertJsonPath('data.checkpoint', null);
    }

    public function test_a_quarantined_batch_never_gets_a_checkpoint(): void
    {
        $sha = $this->batch('Q', 1)['sha'];
        DB::table('bronze_objects')->where('tenant_id', $this->tenant->id)->where('sha256', $sha)->update(['status' => 'quarantined']);
        $this->commit(['entity' => 'sales', 'batch_sha256' => $sha, 'run_batch_index' => 1, 'checkpoint' => 'x', 'run_complete' => false])->assertStatus(409)->assertJsonPath('code', 'batch_failed')->assertJsonPath('context.bronze_status', 'quarantined');
    }

    public function test_a_batch_failing_inside_the_run_blocks_completion(): void
    {
        $one = $this->batch('F1', 1)['sha'];
        $two = $this->batch('F2', 2, rows: [FakeBridgeDevice::salesRows('F2')[0]])['sha'];
        $this->commit(['entity' => 'sales', 'checkpoint' => 'c1', 'batch_sha256' => $one, 'run_batch_index' => 1, 'run_complete' => false])->assertOk();
        // El lote 1 queda marcado como fallido DESPUÉS de haberse confirmado (procesamiento asíncrono que falla).
        $meta = json_decode((string) DB::table('bronze_objects')->where('sha256', $one)->value('metadata'), true);
        $meta['bridge']['processing']['status'] = 'failed';
        DB::table('bronze_objects')->where('sha256', $one)->update(['metadata' => json_encode($meta)]);
        $this->commit(['entity' => 'sales', 'run_batches' => 2, 'batch_sha256' => $two, 'run_batch_index' => 2, 'watermark' => now()->utc()->toIso8601String(), 'run_complete' => true])
            ->assertStatus(409)->assertJsonPath('code', 'batch_failed')->assertJsonPath('context.failed_batches', 1);
        $this->assertNull(DB::table('bridge_checkpoints')->where('bridge_device_id', $this->device->deviceId)->value('watermark'));
    }

    public function test_checkpoint_cannot_cite_a_batch_of_another_source_or_company_or_entity(): void
    {
        // Lote de OTRA fuente de la misma empresa.
        $sourceTwo = $this->createBridgeSource('Segunda');
        $deviceTwo = $this->enrolDevice($sourceTwo);
        $other = $this->uploadBatch($deviceTwo, FakeBridgeDevice::salesRows('S2'));
        $other['put']->assertCreated();
        $this->commit(['entity' => 'sales', 'batch_sha256' => $other['batch']['sha256'], 'run_batch_index' => 1, 'checkpoint' => 'x', 'run_complete' => false])->assertStatus(409)->assertJsonPath('code', 'batch_not_found');

        // Lote de la propia fuente pero otra entidad.
        $own = $this->uploadBatch($this->device, FakeBridgeDevice::salesRows('OWN'));
        $own['put']->assertCreated();
        $this->commit(['entity' => 'products', 'batch_sha256' => $own['batch']['sha256'], 'run_batch_index' => 1, 'checkpoint' => 'x', 'run_complete' => false])->assertStatus(409)->assertJsonPath('code', 'batch_not_found');
        $this->commit(['entity' => 'sales', 'batch_sha256' => $own['batch']['sha256'], 'run_batch_index' => 1, 'checkpoint' => 'x', 'run_complete' => false])->assertOk();
        $this->assertSame(1, DB::table('bridge_checkpoints')->where('bridge_device_id', $this->device->deviceId)->count());
        $this->assertSame(0, DB::table('bridge_checkpoints')->where('bridge_device_id', $deviceTwo->deviceId)->count());
        $this->assertSame(0, DB::table('bridge_run_batches')->where('bridge_device_id', $deviceTwo->deviceId)->count());
    }

    public function test_cursor_is_limited_and_checkpoints_are_per_device(): void
    {
        $this->commit(['entity' => 'sales', 'checkpoint' => str_repeat('x', 201), 'run_complete' => false, 'batch_sha256' => str_repeat('b', 64), 'run_batch_index' => 1])->assertStatus(422)->assertJsonValidationErrors(['checkpoint']);

        $this->commit(['entity' => 'sales', 'watermark' => now()->subHour()->utc()->toIso8601String(), 'run_batches' => 0, 'run_complete' => true])->assertOk();
        $second = $this->enrolDevice();
        $this->assertSame([], $this->deviceCall($second, 'GET', '/api/v1/bridge/checkpoints')->json('data'));
        $this->assertCount(1, $this->deviceCall($this->device, 'GET', '/api/v1/bridge/checkpoints')->json('data'));
    }

    public function test_admin_can_reset_a_checkpoint_to_request_a_full_reload_and_it_is_audited(): void
    {
        $empty = fn (string $watermark) => $this->commit(['entity' => 'sales', 'watermark' => $watermark, 'run_batches' => 0, 'run_complete' => true]);
        $empty(now()->subHour()->utc()->toIso8601String())->assertOk();
        $this->getJson("/api/v1/bridge/devices/{$this->device->deviceId}")->assertOk()->assertJsonCount(1, 'data.checkpoints');
        $this->deleteJson("/api/v1/bridge/devices/{$this->device->deviceId}/checkpoints/sales")->assertNoContent();
        $this->assertSame([], $this->deviceCall($this->device, 'GET', '/api/v1/bridge/checkpoints')->json('data'));
        $this->assertNotNull(DB::table('audit_logs')->where('action', 'bridge.checkpoint_reset')->first());
        // Tras el reinicio la marca de agua puede volver a fijarse (una carga completa nueva).
        $empty(now()->subDays(2)->utc()->toIso8601String())->assertOk();
    }
}
