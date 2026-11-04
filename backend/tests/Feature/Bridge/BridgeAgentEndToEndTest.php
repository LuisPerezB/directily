<?php

declare(strict_types=1);

namespace Tests\Feature\Bridge;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Bridge\BridgeEnrolmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\Bridge\Support\BridgeErpFixture;
use Tests\Feature\DataCore\Support\ErpSourceDatabase;
use Tests\TestCase;

/**
 * W6-B · EXTREMO A EXTREMO con el agente Python REAL: base «ERP de origen» ficticia en PostgreSQL → agente (`predictily-bridge`) → servidor HTTP real
 * del backend (php -S) → URL prefirmada → Bronze (`bridge_batch`) → cola «imports» → pipeline de importación → ventas.
 *
 * Se OMITE con un mensaje explícito si falta una dependencia (base ERP, entorno Python del agente con psycopg, `proc_open`, puerto local libre).
 * No usa transacción de prueba (el servidor es otro proceso): crea una empresa propia y la elimina (en cascada) al terminar.
 */
final class BridgeAgentEndToEndTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private const ERP_PASSWORD_VAR = 'BRIDGE_E2E_ERP_PASSWORD';

    private ErpSourceDatabase $erp;

    private BridgeErpFixture $fixture;

    private Process $server;

    private string $baseUrl;

    private string $workDir;

    private string $python;

    private Tenant $tenant;

    private User $owner;

    private int $sourceId;

    private string $bridgeRoot;

    /** @return list<string> */
    protected function connectionsToTransact(): array
    {
        return [];   // el servidor HTTP es otro proceso: los datos deben confirmarse; la limpieza es explícita
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bridgeRoot = realpath(__DIR__.'/../../../../bridge') ?: '';
        $this->python = $this->bridgeRoot.'/.venv/bin/python';
        $missing = [];
        if (! function_exists('proc_open')) {
            $missing[] = 'proc_open está deshabilitado';
        }
        if (! is_file($this->python)) {
            $missing[] = "no existe el entorno Python del agente ({$this->python}); créelo con: python3.12 -m venv bridge/.venv && bridge/.venv/bin/pip install -e 'bridge[dev]'";
        } elseif ((new Process([$this->python, '-c', 'import psycopg, cryptography']))->run() !== 0) {
            $missing[] = 'el entorno Python del agente no tiene psycopg ni cryptography instalados';
        }
        $erp = ErpSourceDatabase::tryConnect();
        if ($erp === null) {
            $missing[] = 'no hay base «ERP de origen» PostgreSQL (TEST_ERP_DATABASE)';
        }
        $port = $this->freePort();
        if ($port === null) {
            $missing[] = 'no se pudo reservar un puerto local';
        }
        if ($missing !== []) {
            $this->markTestSkipped('OMITIDA SIN VERIFICAR (no es un fallo): faltan dependencias del extremo a extremo del Bridge: '.implode('; ', $missing).'. '
                .'QUEDA SIN VERIFICAR: agente real → servidor HTTP → URL prefirmada → Bronze → importación → ventas, reanudación tras interrupción y revocación. Detalle: docs/integrations/BRIDGE_WINDOWS.md §Pruebas.');
        }
        $this->erp = $erp;   // solo para la conexión: las tablas del ERP ficticio llevan prefijo aleatorio (BridgeErpFixture)
        $this->fixture = new BridgeErpFixture($erp->pdo);
        $this->fixture->create();
        $this->fixture->seed();
        $this->workDir = sys_get_temp_dir().'/bridge-e2e-'.bin2hex(random_bytes(6));
        mkdir($this->workDir, 0700, true);

        [$this->tenant, $this->owner] = $this->createTenantWithUser();
        $this->createBranch($this->tenant, '001', 'Matriz');
        $this->actingAsTenantUser($this->owner, $this->tenant);
        $this->sourceId = (int) $this->postJson('/api/v1/data-sources', ['type' => 'connector', 'connector_key' => BridgeEnrolmentService::CONNECTOR_KEY, 'name' => 'ERP ficticio vía Bridge'])->assertCreated()->json('data.id');

        $this->baseUrl = "http://127.0.0.1:{$port}";
        $this->startServer($port);
    }

    protected function tearDown(): void
    {
        if (isset($this->server) && $this->server->isRunning()) {
            $this->server->stop(5);
        }
        if (isset($this->tenant)) {
            $uuid = $this->tenant->uuid;
            $id = $this->tenant->id;
            DB::table('audit_logs')->where('tenant_id', $id)->delete();
            DB::table('tenants')->where('id', $id)->delete();   // cascada a todo lo de la empresa
            DB::table('audit_logs')->where('user_id', $this->owner->id)->delete();
            DB::table('users')->where('id', $this->owner->id)->delete();
            DB::table('jobs')->delete();
            DB::table('cache_locks')->delete();   // candados de trabajo único que dejó el servidor de prueba
            DB::table('cache')->delete();
            Storage::disk('local')->deleteDirectory("tenants/{$uuid}");
            File::deleteDirectory(base_path("storage/app/private/tenants/{$uuid}"));   // red de seguridad: nunca dejar rastro en el almacenamiento real
        }
        if (isset($this->fixture)) {
            $this->fixture->drop();
        }
        if (isset($this->workDir) && is_dir($this->workDir)) {
            File::deleteDirectory($this->workDir);
        }
        parent::tearDown();
    }

    private function freePort(): ?int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            return null;
        }
        $port = (int) explode(':', (string) stream_socket_get_name($socket, false))[1];
        fclose($socket);

        return $port;
    }

    private function startServer(int $port): void
    {
        $db = config('database.connections.pgsql');
        $env = [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => (string) config('app.key'),
            'DB_CONNECTION' => 'pgsql', 'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => (string) $db['database'],
            'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) $db['password'], 'DB_URL' => '',
            // Cola real en base de datos (como producción: el PUT encola y un trabajador procesa). Caché también en la base de PRUEBAS: el candado de trabajo único de
            // ProcessBridgeBatch (1 h) vive en cache_locks, que se vacía al terminar y con cada migrate:fresh; en caché de archivos sobrevivía a la corrida y, como los ids
            // vuelven a empezar tras migrate:fresh, la siguiente corrida descartaba en silencio el trabajo del mismo id.
            'QUEUE_CONNECTION' => 'database', 'CACHE_STORE' => 'database', 'CACHE_PREFIX' => 'e2e_'.bin2hex(random_bytes(4)), 'SESSION_DRIVER' => 'array',
            'LARAVEL_STORAGE_PATH' => storage_path(), 'PREDICTILY_BRONZE_DISK' => 'local', 'LOG_CHANNEL' => 'single', 'BROADCAST_CONNECTION' => 'null',
            'MAIL_MAILER' => 'array', 'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false', 'ENGINE_URL' => 'http://127.0.0.1:9',
            'PHP_CLI_SERVER_WORKERS' => '1',
        ];
        $router = base_path('vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php');
        // variables_order=EGPCS: el servidor integrado de PHP debe ver LARAVEL_STORAGE_PATH (almacenamiento AISLADO de la prueba) en $_ENV.
        $this->server = new Process([PHP_BINARY, '-d', 'variables_order=EGPCS', '-S', "127.0.0.1:{$port}", $router], public_path(), $env, null, 120);
        $this->server->start();
        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) {
            $probe = @file_get_contents("{$this->baseUrl}/up", false, stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]));
            if ($probe !== false) {
                return;
            }
            usleep(150_000);
        }
        $this->fail('El servidor HTTP de prueba no arrancó: '.$this->server->getErrorOutput());
    }

    /** @param array<string, mixed> $agent */
    private function writeConfig(array $agent = []): string
    {
        $erp = $this->erp;
        $agentToml = '';
        foreach (array_merge(['batch_rows' => 3, 'overlap_minutes' => 0, 'source_timezone' => 'America/Guayaquil'], $agent) as $k => $v) {
            $agentToml .= $k.' = '.(is_string($v) ? '"'.$v.'"' : (is_bool($v) ? ($v ? 'true' : 'false') : $v))."\n";
        }
        $sql = $this->fixture->salesTemplateSql();
        $toml = <<<TOML
[server]
url = "{$this->baseUrl}"
allow_insecure_localhost = true
timeout_seconds = 30

[agent]
{$agentToml}
[source]
kind = "postgresql"
host = "{$erp->host}"
port = {$erp->port}
database = "{$erp->database}"
user = "{$erp->username}"
password_env = "BRIDGE_E2E_ERP_PASSWORD"
# El agente exige TLS por defecto (sslmode = "require"); el PostgreSQL LOCAL de pruebas no lo tiene: se relaja de forma EXPLÍCITA solo aquí.
sslmode = "prefer"

[[templates]]
id = "erp.ficticio.sales"
entity = "sales"
version = 1
recorded_at_column = "source_recorded_at"
order_by = ["document_number", "line_number"]
sql = '''
{$sql}
'''
TOML;
        $path = $this->workDir.'/config.toml';
        file_put_contents($path, $toml);
        chmod($path, 0600);

        return $path;
    }

    /** Ejecuta el agente real (CLI) y devuelve [código de salida, stdout, stderr]. @return array{0: int, 1: string, 2: string} */
    private function agent(array $args, ?string $config = null): array
    {
        $process = new Process([$this->python, '-m', 'predictily_bridge', '--home', $this->workDir.'/home', '--config', $config ?? $this->workDir.'/config.toml', '--no-keyring', ...$args], $this->bridgeRoot, [
            'PYTHONPATH' => $this->bridgeRoot.'/src', 'PYTHONDONTWRITEBYTECODE' => '1', self::ERP_PASSWORD_VAR => $this->erp->password, 'PREDICTILY_BRIDGE_LOG_LEVEL' => 'INFO',
        ], null, 120);
        $process->run();

        return [$process->getExitCode() ?? -1, $process->getOutput(), $process->getErrorOutput()];
    }

    private function enrol(): int
    {
        $code = (string) $this->postJson("/api/v1/data-sources/{$this->sourceId}/bridge/enrolments")->assertCreated()->json('data.code');
        [$exit, $out, $err] = $this->agent(['enroll', '--code', $code, '--name', 'Servidor ERP (prueba E2E)']);
        $this->assertSame(0, $exit, $out.$err);

        return (int) json_decode($out, true)['device_id'];
    }

    /** El trabajador de la cola «imports» (como en producción: el PUT solo encola). */
    private function drainQueue(): void
    {
        // `--memory` alto: el trabajador comparte proceso con la suite completa (> 128 MB, el tope por defecto) y, con el valor por defecto, saldría tras el primer trabajo.
        // El bucle garantiza que no quede nada en la cola «imports» (un trabajo fallido pasa a failed_jobs: no se reintenta con --tries 1).
        for ($i = 0; $i < 20 && DB::table('jobs')->where('queue', 'imports')->exists(); $i++) {
            Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'imports', '--stop-when-empty' => true, '--tries' => 1, '--memory' => 8192, '--quiet' => true]);
        }
        $this->assertSame(0, DB::table('jobs')->where('queue', 'imports')->count(), 'la cola «imports» quedó vacía');
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $t = $this->tenant->id;

        return [
            'bronze' => DB::table('bronze_objects')->where('tenant_id', $t)->where('kind', 'bridge_batch')->count(),
            'sales' => DB::table('sales')->where('tenant_id', $t)->count(),
            'items' => DB::table('sale_items')->where('tenant_id', $t)->count(),
        ];
    }

    private function erpChecksum(): string
    {
        return $this->fixture->checksum();
    }

    public function test_real_agent_extracts_from_postgres_into_bronze_and_the_pipeline_creates_sales(): void
    {
        $this->writeConfig(['batch_rows' => 3]);
        $erpBefore = $this->erpChecksum();
        $deviceId = $this->enrol();

        [$exit, $out, $err] = $this->agent(['run', '--once']);
        $this->assertSame(0, $exit, $out.$err);
        $report = json_decode($out, true);
        $this->assertSame(8, $report['rows_sent']);
        $this->assertSame(3, $report['batches_sent'], '8 líneas en lotes de 3');

        // 1) Bronze: tres objetos del tipo bridge_batch, con la convención de clave del servidor y el contenido íntegro (SHA-256).
        $objects = DB::table('bronze_objects')->where('tenant_id', $this->tenant->id)->orderBy('id')->get();
        $this->assertCount(3, $objects);
        foreach ($objects as $object) {
            $this->assertSame('bridge_batch', $object->kind);
            $this->assertSame($this->sourceId, (int) $object->data_source_id);
            $this->assertSame('bridge:'.$deviceId, $object->received_from_device);
            $this->assertMatchesRegularExpression('#^tenants/'.$this->tenant->uuid.'/bronze/sales/\d{4}/\d{2}/'.$object->sha256.'\.ndjson\.gz$#', $object->object_key);
            $this->assertSame($object->sha256, hash('sha256', (string) Storage::disk('local')->get($object->object_key)), 'el contenido guardado coincide con su SHA-256');
        }
        $this->assertSame(0, DB::table('sales')->where('tenant_id', $this->tenant->id)->count(), 'el PUT solo encola: aún no hay ventas hasta que la cola procese');

        // 2) Cola «imports» → pipeline de importación → ventas (el mismo resultado que la sincronización SQL de SqlSourceSyncTest).
        $this->drainQueue();
        $this->assertSame(['bronze' => 3, 'sales' => 6, 'items' => 8], $this->counts());
        $states = DB::table('bronze_objects')->where('tenant_id', $this->tenant->id)->pluck('status')->all();
        $this->assertSame(['processed', 'processed', 'processed'], $states);
        $this->assertSame(6, DB::table('sales')->where('tenant_id', $this->tenant->id)->where('data_source_id', $this->sourceId)->count(), 'procedencia: la fuente Bridge');
        $this->assertSame(6, DB::table('sales')->where('tenant_id', $this->tenant->id)->whereIn('bronze_object_id', $objects->pluck('id'))->count(), 'linaje al objeto Bronze del lote');
        $this->assertSame(1, DB::table('sales')->where('tenant_id', $this->tenant->id)->where('status', 'voided')->count());
        $this->assertSame(1, DB::table('sales')->where('tenant_id', $this->tenant->id)->where('document_type', 'credit_note')->count());
        $invoice = DB::table('sales')->where('tenant_id', $this->tenant->id)->where('document_number', '001-001-000000001')->first();
        $this->assertEqualsWithDelta(120.0, (float) $invoice->subtotal, 0.001);
        $this->assertSame('09:15', CarbonImmutable::parse($invoice->issued_at)->setTimezone('America/Guayaquil')->format('H:i'), 'hora naíf del origen interpretada en su zona');
        $this->assertSame(0, DB::table('customers')->where('tenant_id', $this->tenant->id)->where('external_code', '1710034065')->count(), 'la cédula nunca queda en claro');
        $this->assertSame(1, DB::table('customers')->where('tenant_id', $this->tenant->id)->where('is_generic', true)->count(), 'consumidor final marcado como genérico');

        // 3) Checkpoint confirmado por el servidor: marca de agua fijada, sin cursor pendiente; latido registrado.
        $checkpoint = DB::table('bridge_checkpoints')->where('tenant_id', $this->tenant->id)->where('bridge_device_id', $deviceId)->where('entity', 'sales')->first();
        $this->assertNotNull($checkpoint->watermark);
        $this->assertNull($checkpoint->checkpoint);
        $this->assertNotNull($checkpoint->bronze_object_id);
        $this->assertLessThanOrEqual(time() + 5, strtotime((string) $checkpoint->watermark), 'marca de agua acotada: nunca en el futuro');
        $device = DB::table('bridge_devices')->where('id', $deviceId)->first();
        $this->assertNotNull($device->last_seen_at);
        $this->assertSame(64, strlen($device->public_key_fingerprint));
        $this->assertNotNull(DB::table('data_sources')->where('id', $this->sourceId)->value('last_synced_at'));

        // 4) El ERP no cambió (solo lectura demostrada contra la base real).
        $this->assertSame($erpBefore, $this->erpChecksum());

        // 5) Segunda ejecución: incremental, sin duplicados.
        [$exit, $out, $err] = $this->agent(['run', '--once']);
        $this->assertSame(0, $exit, $out.$err);
        $this->drainQueue();
        $counts = $this->counts();
        $this->assertSame(6, $counts['sales']);
        $this->assertSame(8, $counts['items']);
        $this->assertSame($erpBefore, $this->erpChecksum());
    }

    public function test_source_correction_flows_through_a_later_incremental_run_without_duplicating(): void
    {
        $this->writeConfig(['batch_rows' => 50]);
        $this->enrol();
        $this->assertSame(0, $this->agent(['run', '--once'])[0]);
        $this->drainQueue();
        $this->assertSame(['bronze' => 1, 'sales' => 6, 'items' => 8], $this->counts());

        sleep(1);   // la corrección debe tener una fecha de modificación posterior a la marca de agua
        $this->fixture->correctInvoiceLine('001-001-000000001', 'CEM-50', 12);
        [$exit, $out, $err] = $this->agent(['run', '--once']);
        $this->assertSame(0, $exit, $out.$err);
        $this->drainQueue();
        $this->assertSame(6, $this->counts()['sales'], 'sigue habiendo 6 documentos');
        $this->assertSame(8, $this->counts()['items'], 'las líneas se reemplazan, no se acumulan');
        $quantity = (float) DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')->where('sales.tenant_id', $this->tenant->id)
            ->where('sales.document_number', '001-001-000000001')->where('sale_items.line_number', 1)->value('sale_items.quantity');
        $this->assertEqualsWithDelta(12.0, $quantity, 0.0001);
        $this->assertGreaterThanOrEqual(2, $this->counts()['bronze'], 'la ventana incremental es otro lote en Bronze');
    }

    public function test_interrupted_agent_resumes_from_the_confirmed_checkpoint_without_duplicate_batches(): void
    {
        $this->writeConfig(['batch_rows' => 3]);
        $deviceId = $this->enrol();

        [$exit, $out, $err] = $this->agent(['run', '--once', '--max-batches', '1']);
        $this->assertSame(0, $exit, $out.$err);
        $this->assertTrue(json_decode($out, true)['interrupted']);
        $partial = DB::table('bridge_checkpoints')->where('bridge_device_id', $deviceId)->where('entity', 'sales')->first();
        $this->assertNotNull($partial->checkpoint, 'cursor de reanudación confirmado por el servidor');
        $this->assertLessThanOrEqual(200, strlen((string) $partial->checkpoint));
        $this->assertNull($partial->watermark, 'la marca de agua solo se confirma al terminar la extracción');
        $this->assertSame(1, $this->counts()['bronze']);

        [$exit, $out, $err] = $this->agent(['run', '--once']);
        $this->assertSame(0, $exit, $out.$err);
        $this->drainQueue();
        $this->assertSame(['bronze' => 3, 'sales' => 6, 'items' => 8], $this->counts(), 'ni lotes repetidos ni ventas duplicadas');
        $this->assertSame(3, DB::table('bronze_objects')->where('tenant_id', $this->tenant->id)->distinct()->count('sha256'));
        $done = DB::table('bridge_checkpoints')->where('bridge_device_id', $deviceId)->where('entity', 'sales')->first();
        $this->assertNull($done->checkpoint);
        $this->assertNotNull($done->watermark);
    }

    public function test_retry_after_network_failure_and_lost_local_state_never_duplicate_batches(): void
    {
        $this->writeConfig(['batch_rows' => 3]);
        $this->enrol();
        $this->assertSame(0, $this->agent(['run', '--once', '--max-batches', '1'])[0]);
        // El equipo pierde su estado local y su búfer (reinstalación): reanuda desde el cursor que guardó el servidor.
        File::delete($this->workDir.'/home/state.enc');
        File::deleteDirectory($this->workDir.'/home/spool');
        [$exit, $out, $err] = $this->agent(['run', '--once']);
        $this->assertSame(0, $exit, $out.$err);
        $this->drainQueue();
        $this->assertSame(['bronze' => 3, 'sales' => 6, 'items' => 8], $this->counts());

        // Reenviar TODO desde cero (el administrador reinicia el checkpoint): los mismos lotes ya existen en Bronze y no se duplican ni se reimportan.
        $deviceId = (int) DB::table('bridge_devices')->where('tenant_id', $this->tenant->id)->value('id');
        $this->deleteJson("/api/v1/bridge/devices/{$deviceId}/checkpoints/sales")->assertNoContent();
        $jobs = DB::table('import_jobs')->where('tenant_id', $this->tenant->id)->count();
        File::delete($this->workDir.'/home/state.enc');
        $this->assertSame(0, $this->agent(['run', '--once'])[0]);
        $this->drainQueue();
        $this->assertSame(['bronze' => 3, 'sales' => 6, 'items' => 8], $this->counts());
        $this->assertSame($jobs, DB::table('import_jobs')->where('tenant_id', $this->tenant->id)->count(), 'ninguna reimportación de lotes ya procesados');
    }

    public function test_revoked_device_uploads_nothing_and_the_agent_wipes_its_buffer(): void
    {
        $this->writeConfig(['batch_rows' => 3]);
        $deviceId = $this->enrol();
        $this->assertSame(0, $this->agent(['run', '--once', '--max-batches', '1'])[0]);
        $before = $this->counts()['bronze'];

        $this->postJson("/api/v1/bridge/devices/{$deviceId}/revoke", ['reason' => 'Equipo retirado'])->assertOk();
        $this->fixture->correctInvoiceLine('001-001-000000002', 'CEM-50', 7);
        [$exit, $out, $err] = $this->agent(['run', '--once']);
        $this->assertSame(3, $exit, 'código de salida 3 = revocado: '.$out.$err);
        $this->assertSame($before, $this->counts()['bronze'], 'un dispositivo revocado no sube nada');
        $this->assertDirectoryDoesNotExist($this->workDir.'/home/spool', 'el búfer local se borra al ser revocado');
        $this->assertFileExists($this->workDir.'/home/revoked.flag');
        [$exit, , $err] = $this->agent(['run', '--once']);
        $this->assertSame(3, $exit);
        $this->assertStringContainsString('revocado', $err);
        $this->assertSame('inactive', DB::table('data_sources')->where('id', $this->sourceId)->value('status'));
    }

    public function test_agent_leaves_no_secret_in_its_output_or_on_the_servers_audit_trail(): void
    {
        $this->writeConfig(['batch_rows' => 50]);
        $this->enrol();
        [$exit, $out, $err] = $this->agent(['run', '--once']);
        $this->assertSame(0, $exit);
        $device = $this->agent(['status']);
        foreach ([$out, $err, $device[1], $device[2]] as $text) {
            $this->assertStringNotContainsString($this->erp->password, $text);
            $this->assertStringNotContainsString('brt_', $text);
        }
        // La contraseña de la base de origen NUNCA llega a Predictily: ni en la base de datos ni en Bronze ni en la bitácora.
        $this->drainQueue();
        $dump = json_encode([DB::table('audit_logs')->where('tenant_id', $this->tenant->id)->get(), DB::table('bridge_devices')->where('tenant_id', $this->tenant->id)->get(),
            DB::table('data_sources')->where('id', $this->sourceId)->get(), DB::table('bronze_objects')->where('tenant_id', $this->tenant->id)->get()]);
        $this->assertStringNotContainsString($this->erp->password, (string) $dump);
        $this->assertStringNotContainsString('postgresql://', (string) $dump);
        $this->assertSame([], (array) (DB::table('data_sources')->where('id', $this->sourceId)->value('credentials_encrypted') ? ['guardadas'] : []), 'la fuente Bridge no guarda credenciales del ERP');
    }
}
