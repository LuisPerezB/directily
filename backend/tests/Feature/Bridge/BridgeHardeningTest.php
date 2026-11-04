<?php

declare(strict_types=1);

namespace Tests\Feature\Bridge;

use App\Jobs\Bridge\ProcessBridgeBatch;
use App\Models\BridgeDevice;
use App\Models\BronzeObject;
use App\Models\DataSource;
use App\Models\Tenant;
use App\Services\Bridge\BridgeBatchIntake;
use App\Services\Bridge\BridgeBatchProcessor;
use App\Services\Bridge\BridgeDeviceAuthenticator;
use App\Services\Bridge\BridgeNonceStore;
use App\Services\Bridge\BridgeRejected;
use App\Services\Bridge\BridgeSignature;
use App\Services\DataCore\Bronze\BronzeStore;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsImportFiles;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\Bridge\Support\FakeBridgeDevice;
use Tests\Feature\Bridge\Support\InteractsWithBridge;
use Tests\TestCase;

/**
 * W6-B · Endurecimiento tras la revisión adversarial (B-1 … B-12): validación completa del lote antes de guardarlo, límites de descompresión, firma que cubre la
 * consulta, límite por IP que no se evade rotando credenciales, anti-repetición en la base de datos, un solo trabajo por lote, cuota diaria y cuarentena.
 */
final class BridgeHardeningTest extends TestCase
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

    /** @return array{bytes: string, sha256: string, size_bytes: int} */
    private function raw(string $ndjson): array
    {
        $bytes = (string) gzencode($ndjson, 6);

        return ['bytes' => $bytes, 'sha256' => hash('sha256', $bytes), 'size_bytes' => strlen($bytes)];
    }

    private function salesColumns(): array
    {
        return FakeBridgeDevice::batch(FakeBridgeDevice::salesRows())['columns'];
    }

    /** Declara y sube cuerpo arbitrario con las columnas y filas declaradas. @return array{0: \Illuminate\Testing\TestResponse, 1: ?\Illuminate\Testing\TestResponse} */
    private function send(array $b, int $rows, ?array $columns = null): array
    {
        $declare = $this->deviceCall($this->device, 'POST', '/api/v1/bridge/uploads', [
            'entity' => 'sales', 'sha256' => $b['sha256'], 'size_bytes' => $b['size_bytes'], 'row_count' => $rows, 'content_type' => 'application/gzip', 'columns' => $columns ?? $this->salesColumns(),
            'run_id' => 'run-0001', 'seq' => 1, 'run_mode' => 'full',
        ]);
        $put = $declare->json('data.status') === 'upload' ? $this->putBatch((string) $declare->json('data.path'), $b['bytes']) : null;

        return [$declare, $put];
    }

    private function bronzeCount(): int
    {
        return DB::table('bronze_objects')->where('tenant_id', $this->tenant->id)->count();
    }

    // ── B-1 · cada línea se valida ANTES de guardar ──
    public function test_u1_pii_column_that_appears_only_on_row_two_is_rejected_and_nothing_is_stored(): void
    {
        $lines = [];
        foreach (FakeBridgeDevice::salesRows('PII') as $i => $row) {
            if ($i === 1) {
                $row += ['customer_email' => 'maria.perez@example.com', 'customer_name' => 'Maria Perez', 'customer_phone' => '0999999999'];
            }
            $lines[] = json_encode($row);
        }
        $b = $this->raw(implode("\n", $lines)."\n");
        [, $put] = $this->send($b, 4);
        $put->assertStatus(422)->assertJsonPath('code', 'unknown_columns')->assertJsonPath('context.row', 2);
        $this->assertStringNotContainsString('maria.perez', $put->getContent(), 'el mensaje no repite los valores del lote');
        $this->assertSame(0, $this->bronzeCount());
        $this->assertSame([], Storage::disk('local')->allFiles('tenants/'.$this->tenant->uuid));
    }

    public function test_u3_array_object_and_huge_values_are_rejected(): void
    {
        foreach ([['product_name', ['a' => ['b' => 'c']]], ['quantity', [1, 2]], ['product_name', str_repeat('A', 2001)]] as $n => [$column, $value]) {
            $rows = FakeBridgeDevice::salesRows('ARR'.$n);
            $rows[2][$column] = $value;
            $b = $this->raw(implode("\n", array_map('json_encode', $rows))."\n");
            [, $put] = $this->send($b, 4);
            $put->assertStatus(422)->assertJsonPath('code', 'invalid_row');
        }
        $this->assertSame(0, $this->bronzeCount());
    }

    public function test_lines_that_are_not_json_objects_are_rejected(): void
    {
        foreach (["[1,2,3]\n", "\"texto\"\n", "{}\n", "{\"a\":\n"] as $n => $body) {
            [, $put] = $this->send($this->raw($body.str_repeat(' ', $n)), 1);
            $put->assertStatus(422)->assertJsonPath('code', 'invalid_row');
        }
        $this->assertSame(0, $this->bronzeCount());
    }

    // ── B-7 · el número de filas declarado es el real ──
    public function test_declared_row_count_must_match_the_streamed_rows(): void
    {
        $rows = FakeBridgeDevice::salesRows('RC');
        $b = $this->raw(implode("\n", array_map('json_encode', $rows))."\n");
        [, $put] = $this->send($b, 3);
        $put->assertStatus(422)->assertJsonPath('code', 'row_count_mismatch')->assertJsonPath('context.actual', 4)->assertJsonPath('context.declared', 3);
        [, $put] = $this->send($b, 5);
        $put->assertStatus(422)->assertJsonPath('code', 'row_count_mismatch');
        $this->assertSame(0, $this->bronzeCount());
        [, $put] = $this->send($b, 4);
        $put->assertCreated();
    }

    // ── B-10 · magia gzip ──
    public function test_a_body_that_is_not_gzip_is_rejected_before_anything_is_decoded(): void
    {
        $plain = json_encode(FakeBridgeDevice::salesRows('TXT')[0])."\n";
        $b = ['bytes' => $plain, 'sha256' => hash('sha256', $plain), 'size_bytes' => strlen($plain)];
        [, $put] = $this->send($b, 1);
        $put->assertStatus(422)->assertJsonPath('code', 'batch_invalid');
        // Un zip o un gzip truncado (magia correcta, cuerpo roto) tampoco pasa.
        $zip = "PK\x03\x04".str_repeat("\0", 40);
        [, $put] = $this->send(['bytes' => $zip, 'sha256' => hash('sha256', $zip), 'size_bytes' => strlen($zip)], 1);
        $put->assertStatus(422)->assertJsonPath('code', 'batch_invalid');
        $broken = substr((string) gzencode($plain), 0, 12);
        [, $put] = $this->send(['bytes' => $broken, 'sha256' => hash('sha256', $broken), 'size_bytes' => strlen($broken)], 1);
        $put->assertStatus(422);
        $this->assertSame(0, $this->bronzeCount());
    }

    // ── B-6 · bombas de compresión ──
    public function test_decompression_ratio_and_size_caps_stop_a_compression_bomb(): void
    {
        $row = FakeBridgeDevice::salesRows('BOMB')[0];
        $row['product_name'] = str_repeat('A', 1990);
        $lines = '';
        for ($i = 0; $i < 4500; $i++) {   // ≈ 9 MB descomprimidos: por encima del umbral a partir del cual se aplica la relación
            $lines .= json_encode(['document_number' => "BOMB-{$i}"] + $row)."\n";
        }
        $b = $this->raw($lines);
        $this->assertGreaterThan(200, strlen($lines) / $b['size_bytes'], 'la muestra es realmente una bomba (relación > 200×)');
        [, $put] = $this->send($b, 4500);
        $put->assertStatus(413)->assertJsonPath('code', 'batch_too_large');
        $this->assertSame(0, $this->bronzeCount());

        // Con la relación relajada el mismo lote pasa el filtro de relación pero el tope absoluto de bytes lo frena.
        config(['bridge.max_decompression_ratio' => 100_000, 'bridge.max_decompressed_mb' => 5]);
        [, $put] = $this->send($b, 4500);
        $put->assertStatus(413)->assertJsonPath('code', 'batch_too_large');
        $this->assertSame(0, $this->bronzeCount());

        // Y con ambos límites holgados se acepta (la cola se simula: no se importan 4500 filas aquí).
        config(['bridge.max_decompression_ratio' => 100_000, 'bridge.max_decompressed_mb' => 64]);
        Queue::fake();
        [, $put] = $this->send($b, 4500);
        $put->assertCreated();
    }

    public function test_row_cap_and_line_length_cap(): void
    {
        config(['bridge.max_batch_rows' => 3]);
        $b = $this->raw(implode("\n", array_map('json_encode', FakeBridgeDevice::salesRows('CAP')))."\n");
        $declare = $this->deviceCall($this->device, 'POST', '/api/v1/bridge/uploads', ['entity' => 'sales', 'sha256' => $b['sha256'], 'size_bytes' => $b['size_bytes'], 'row_count' => 4,
            'content_type' => 'application/gzip', 'columns' => $this->salesColumns()]);
        $declare->assertStatus(422)->assertJsonValidationErrors(['row_count']);

        config(['bridge.max_batch_rows' => 50_000, 'bridge.max_line_bytes' => 1024, 'bridge.max_value_length' => 5000]);
        $row = FakeBridgeDevice::salesRows('LONG')[0];
        $row['product_name'] = str_repeat('B', 3000);
        [, $put] = $this->send($this->raw(json_encode($row)."\n"), 1);
        $put->assertStatus(422)->assertJsonPath('code', 'invalid_row');
        $this->assertSame(0, $this->bronzeCount());
    }

    // ── B-1 · cuarentena de lotes inválidos que ya estaban guardados ──
    public function test_a_stored_batch_that_violates_the_contract_is_quarantined_and_never_accepted(): void
    {
        $rows = FakeBridgeDevice::salesRows('QQ');
        $rows[1]['customer_email'] = 'otra.persona@example.com';
        $b = $this->raw(implode("\n", array_map('json_encode', $rows))."\n");
        $tmp = tempnam(sys_get_temp_dir(), 'q');
        file_put_contents($tmp, $b['bytes']);
        // Simula un objeto que llegó a Bronze sin pasar por la recepción (p. ej. de una versión anterior): el procesador lo detecta.
        $object = app(TenantContext::class)->runAs($this->tenant, fn () => app(BronzeStore::class)->storeFile($this->tenant, 'bridge_batch', 'sales', $tmp, 'ndjson.gz', [
            'data_source_id' => $this->sourceId, 'content_type' => 'application/gzip', 'row_count' => 4, 'metadata' => ['bridge' => ['run_id' => 'run-q', 'processing' => ['status' => 'queued']]],
        ]));
        @unlink($tmp);
        app(TenantContext::class)->runAs($this->tenant, fn () => app(BridgeBatchProcessor::class)->process($object->id));

        $fresh = BronzeObject::withoutTenantScope()->findOrFail($object->id);
        $this->assertSame('quarantined', $fresh->status);
        $this->assertSame('failed', $fresh->metadata['bridge']['processing']['status']);
        $this->assertSame('bridge_batch_invalid', $fresh->metadata['bridge']['processing']['error_code']);
        $this->assertSame(0, DB::table('sales')->where('tenant_id', $this->tenant->id)->count());

        $declare = $this->deviceCall($this->device, 'POST', '/api/v1/bridge/uploads', ['entity' => 'sales', 'sha256' => $b['sha256'], 'size_bytes' => $b['size_bytes'], 'row_count' => 4,
            'content_type' => 'application/gzip', 'columns' => $this->salesColumns()]);
        $declare->assertStatus(409)->assertJsonPath('code', 'batch_quarantined');
        $this->deviceCall($this->device, 'POST', '/api/v1/bridge/checkpoints', ['run_id' => 'run-q', 'entity' => 'sales', 'batch_sha256' => $b['sha256'], 'run_batch_index' => 1, 'checkpoint' => 'x', 'run_complete' => false])
            ->assertStatus(409)->assertJsonPath('code', 'batch_failed');
    }

    // ── B-8 · un solo trabajo por lote ──
    public function test_repeated_declarations_do_not_stack_identical_processing_jobs(): void
    {
        Queue::fake();
        $rows = FakeBridgeDevice::salesRows('UQ');
        $up = $this->uploadBatch($this->device, $rows);
        $up['put']->assertCreated();
        for ($i = 0; $i < 4; $i++) {
            $this->uploadBatch($this->device, $rows)['declare']->assertOk()->assertJsonPath('data.status', 'duplicate')->assertJsonPath('data.processing', 'queued');
        }
        Queue::assertPushed(ProcessBridgeBatch::class, 1);
        $job = new ProcessBridgeBatch(7, 9);
        $this->assertSame('7:9', $job->uniqueId());
        $this->assertContains(ShouldBeUnique::class, class_implements($job));
    }

    // ── B-4 · el límite por IP no se evade rotando credenciales ──
    public function test_rotating_bearer_values_does_not_evade_the_ip_rate_limit(): void
    {
        config(['bridge.ip_requests_per_minute' => 240, 'bridge.device_requests_per_minute' => 240]);
        $statuses = [];
        for ($i = 0; $i < 260; $i++) {
            $token = 'brt_'.BridgeSignature::encode(random_bytes(32));
            $statuses[] = $this->call('POST', '/api/v1/bridge/heartbeat', [], [], [], ['HTTP_AUTHORIZATION' => "Bearer {$token}", 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '203.0.113.9'], '{}')->status();
        }
        $this->assertSame(240, count(array_filter($statuses, fn ($s) => $s === 401)));
        $this->assertSame(20, count(array_filter($statuses, fn ($s) => $s === 429)));
        $this->assertSame(429, end($statuses));
    }

    // ── B-5 · la repetición se detecta aunque la caché se vacíe o sea por proceso ──
    public function test_replay_protection_survives_a_cache_flush(): void
    {
        $server = $this->device->signedServerVars('POST', '/api/v1/bridge/heartbeat', '{}');
        $this->call('POST', '/api/v1/bridge/heartbeat', [], [], [], $server, '{}')->assertOk();
        $this->assertSame(1, DB::table('bridge_request_nonces')->where('bridge_device_id', $this->device->deviceId)->count());
        Cache::flush();
        $this->call('POST', '/api/v1/bridge/heartbeat', [], [], [], $server, '{}')->assertStatus(401)->assertJsonPath('code', 'device_replay');
    }

    public function test_expired_nonces_are_pruned_and_deleted_with_the_device(): void
    {
        $store = app(BridgeNonceStore::class);
        $this->assertTrue($store->remember($this->device->deviceId, 'nonce-vigente-0001'));
        $this->assertFalse($store->remember($this->device->deviceId, 'nonce-vigente-0001'));
        DB::table('bridge_request_nonces')->insert(['bridge_device_id' => $this->device->deviceId, 'nonce' => 'nonce-vencido-0001', 'expires_at' => now()->subHour()]);
        $this->assertSame(1, $store->prune());
        $this->assertSame(['nonce-vigente-0001'], DB::table('bridge_request_nonces')->pluck('nonce')->all());
        DB::table('bridge_devices')->where('id', $this->device->deviceId)->delete();
        $this->assertSame(0, DB::table('bridge_request_nonces')->count(), 'cascada con el dispositivo');
    }

    // ── B-11 · la firma cubre la CONSULTA ──
    public function test_signature_covers_the_query_string(): void
    {
        $signedWithQuery = $this->device->signedServerVars('POST', '/api/v1/bridge/heartbeat', '{}', 'x=1');
        // Misma firma, otra consulta: rechazada. Sin consulta: rechazada.
        $this->call('POST', '/api/v1/bridge/heartbeat?x=2', [], [], [], $signedWithQuery, '{}')->assertStatus(401)->assertJsonPath('code', 'device_signature_invalid');
        $this->call('POST', '/api/v1/bridge/heartbeat', [], [], [], $this->device->signedServerVars('POST', '/api/v1/bridge/heartbeat', '{}', 'x=1'), '{}')->assertStatus(401);
        // Firmada para la consulta real: aceptada.
        $this->call('POST', '/api/v1/bridge/heartbeat?x=1', [], [], [], $this->device->signedServerVars('POST', '/api/v1/bridge/heartbeat', '{}', 'x=1'), '{}')->assertOk();
        // Firmada SIN consulta pero enviada con consulta: rechazada.
        $this->call('POST', '/api/v1/bridge/heartbeat?x=1', [], [], [], $this->device->signedServerVars('POST', '/api/v1/bridge/heartbeat', '{}', ''), '{}')->assertStatus(401);
    }

    // ── defensa en profundidad de la URL prefirmada y del tope del cuerpo ──
    public function test_presigned_url_authorisation_checks_the_tenant_of_the_device_itself(): void
    {
        $auth = app(BridgeDeviceAuthenticator::class);
        $this->assertSame($this->device->deviceId, $auth->authorizeById($this->device->deviceId, $this->tenant->uuid)['device']->id);
        [$other] = $this->createTenantWithUser();
        $this->assertInstanceOf(Tenant::class, $other);
        try {
            $auth->authorizeById($this->device->deviceId, $other->uuid);
            $this->fail('Debía rechazar una empresa que no es la del dispositivo.');
        } catch (BridgeRejected $e) {
            $this->assertSame('device_unauthorized', $e->errorCode);
        }
    }

    public function test_the_body_is_cut_at_the_declared_size_even_without_content_length(): void
    {
        $batch = FakeBridgeDevice::batch(FakeBridgeDevice::salesRows('CUT'));
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $batch['bytes'].str_repeat('x', 5000));
        rewind($stream);
        $device = BridgeDevice::withoutTenantScope()->findOrFail($this->device->deviceId);
        $source = DataSource::withoutTenantScope()->findOrFail($this->sourceId);
        $claims = ['e' => 'sales', 'n' => $batch['size_bytes'], 'r' => 4, 't' => $this->tenant->uuid, 'c' => BridgeSignature::encode((string) json_encode($batch['columns']))];
        try {
            app(BridgeBatchIntake::class)->receive($device, $source, $this->tenant, $batch['sha256'], $claims, $stream, 'application/gzip', null);
            $this->fail('Debía cortar un cuerpo mayor que el declarado.');
        } catch (BridgeRejected $e) {
            $this->assertSame('size_mismatch', $e->errorCode);
            $this->assertSame(413, $e->status);
        }
        $this->assertSame(0, $this->bronzeCount());
    }

    // ── B-12 · cuota diaria por empresa ──
    public function test_tenant_daily_quota_stops_new_uploads_with_retry_after_but_not_duplicates(): void
    {
        $first = $this->uploadBatch($this->device, FakeBridgeDevice::salesRows('Q1'));
        $first['put']->assertCreated();
        config(['bridge.tenant_daily_quota_mb' => 1]);
        $declare = fn (int $size) => $this->deviceCall($this->device, 'POST', '/api/v1/bridge/uploads', ['entity' => 'sales', 'sha256' => hash('sha256', "q{$size}"), 'size_bytes' => $size, 'row_count' => 1,
            'content_type' => 'application/gzip', 'columns' => $this->salesColumns()]);
        $declare(1024)->assertCreated();
        $over = $declare(1_100_000);
        $over->assertStatus(429)->assertJsonPath('code', 'quota_exceeded');
        $this->assertGreaterThanOrEqual(60, (int) $over->headers->get('Retry-After'));
        $this->assertArrayNotHasKey('retry_after', (array) $over->json('context'));
        // Lo ya subido sigue siendo un duplicado idempotente aunque la cuota esté agotada.
        config(['bridge.tenant_daily_quota_mb' => 0]);
        $declare(1_100_000)->assertCreated();   // 0 = sin tope
        config(['bridge.tenant_daily_quota_mb' => 1]);
        $this->uploadBatch($this->device, FakeBridgeDevice::salesRows('Q1'))['declare']->assertOk()->assertJsonPath('data.status', 'duplicate');
    }
}
