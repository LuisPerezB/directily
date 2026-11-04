<?php

declare(strict_types=1);

namespace Tests\Feature\Lineage;

use App\Models\BronzeObject;
use App\Models\Tenant;
use App\Services\DataCore\Bronze\BronzeIntegrityException;
use App\Services\DataCore\Bronze\BronzeStore;
use App\Support\Tenancy\TenantContext;
use Aws\S3\S3Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * EMULADOR · Controlador S3 de Bronze probado contra un emulador S3 LOCAL (moto en modo servidor), NO contra AWS.
 *
 * Qué demuestra: el disco `bronze_s3` (config/filesystems.php) con endpoint y path-style por entorno guarda, lee, verifica el
 * SHA-256, detecta alteraciones, purga y aplica cifrado del lado del servidor (la cabecera ServerSideEncryption se envía; moto la
 * acepta y la devuelve). Qué NO demuestra: permisos IAM, KMS real, versionado del bucket ni rendimiento de AWS (BLOQUEADO hasta
 * tener cuenta y credenciales del propietario; ADR-V25-08).
 *
 * Para ejecutarla (sin emulador la prueba se OMITE con este mismo mensaje):
 *   pip install "moto[server]"  (en un venv propio)  y  moto_server -H 127.0.0.1 -p 5057
 *   TEST_S3_EMULATOR_ENDPOINT=http://127.0.0.1:5057 php artisan test --filter=BronzeS3EmulatorTest
 */
#[Group('emulator')]
final class BronzeS3EmulatorTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private Tenant $tenant;

    private S3Client $client;

    private string $bucket;

    private BronzeStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $endpoint = (string) getenv('TEST_S3_EMULATOR_ENDPOINT');
        if ($endpoint === '') {
            $this->markTestSkipped('EMULADOR: prueba OMITIDA. El controlador S3 de Bronze NO se verificó porque no hay emulador S3: defina TEST_S3_EMULATOR_ENDPOINT (p. ej. moto_server en http://127.0.0.1:5057).');
        }
        $parts = parse_url($endpoint);
        $socket = @fsockopen((string) ($parts['host'] ?? '127.0.0.1'), (int) ($parts['port'] ?? 80), $errno, $error, 1.0);
        if ($socket === false) {
            $this->markTestSkipped("EMULADOR: prueba OMITIDA. El emulador S3 {$endpoint} no responde ({$error}); el controlador S3 de Bronze NO se verificó.");
        }
        fclose($socket);

        // El SDK de AWS carga reglas de endpoints muy grandes: en la suite completa (un solo proceso, 128 MB por defecto) agotaría la memoria.
        // No se restaura el límite: el proceso ya supera los 128 MB y PHP no permite bajarlo por debajo del uso actual.
        ini_set('memory_limit', '512M');

        // Credenciales ficticias de una sola ejecución: el emulador acepta cualquiera; nada de esto es una credencial real.
        $key = 'emulador'.bin2hex(random_bytes(4));
        $secret = bin2hex(random_bytes(16));
        $this->bucket = 'predictily-bronze-'.bin2hex(random_bytes(4));
        $this->client = new S3Client([
            'version' => 'latest', 'region' => 'us-east-1', 'endpoint' => $endpoint, 'use_path_style_endpoint' => true,
            'credentials' => ['key' => $key, 'secret' => $secret],
        ]);
        $this->client->createBucket(['Bucket' => $this->bucket]);

        config([
            'filesystems.disks.bronze_s3' => array_merge(config('filesystems.disks.bronze_s3'), [
                'key' => $key, 'secret' => $secret, 'region' => 'us-east-1', 'bucket' => $this->bucket,
                'endpoint' => $endpoint, 'use_path_style_endpoint' => true, 'options' => ['ServerSideEncryption' => 'AES256'],
            ]),
            'bronze.disk' => 'bronze_s3',
            'bronze.retention_days' => 0,
        ]);
        Storage::forgetDisk('bronze_s3');
        [$this->tenant] = $this->createTenantWithUser();
        app(TenantContext::class)->set($this->tenant);
        $this->store = app(BronzeStore::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->client, $this->bucket)) {
            try {
                foreach ($this->client->getPaginator('ListObjectsV2', ['Bucket' => $this->bucket]) as $page) {
                    foreach ($page['Contents'] ?? [] as $object) {
                        $this->client->deleteObject(['Bucket' => $this->bucket, 'Key' => $object['Key']]);
                    }
                }
                $this->client->deleteBucket(['Bucket' => $this->bucket]);
            } catch (\Throwable) {
                // limpieza best-effort del emulador
            }
        }
        parent::tearDown();
    }

    private function keep(string $contents, string $entity = 'sales'): BronzeObject
    {
        return $this->store->store($this->tenant, 'file_upload', $entity, $contents, 'csv', ['original_name' => 'ventas.csv']);
    }

    public function test_emulator_s3_store_writes_an_encrypted_object_under_the_server_generated_key(): void
    {
        $contents = "sku,qty\nA,1\n";
        $object = $this->keep($contents);

        $this->assertSame('s3', $object->storage_driver);
        $this->assertSame('bronze_s3', $object->metadata['disk']);
        $this->assertMatchesRegularExpression('#^tenants/'.$this->tenant->uuid.'/bronze/sales/\d{4}/\d{2}/'.hash('sha256', $contents).'\.csv$#', $object->object_key);

        $head = $this->client->headObject(['Bucket' => $this->bucket, 'Key' => $object->object_key]);
        $this->assertSame(strlen($contents), (int) $head['ContentLength']);
        $this->assertSame('AES256', $head['ServerSideEncryption'] ?? null, 'cifrado del lado del servidor aplicado al escribir');
        $this->assertSame($contents, (string) $this->client->getObject(['Bucket' => $this->bucket, 'Key' => $object->object_key])['Body']);
    }

    public function test_emulator_s3_read_verifies_the_sha256_and_detects_alteration(): void
    {
        $object = $this->keep('contenido íntegro');
        $this->assertSame('contenido íntegro', $this->store->read($object));

        $this->client->putObject(['Bucket' => $this->bucket, 'Key' => $object->object_key, 'Body' => 'alterado fuera de Predictily']);
        $this->expectException(BronzeIntegrityException::class);
        $this->expectExceptionMessage('SHA-256');
        $this->store->read($object);
    }

    public function test_emulator_s3_store_is_idempotent_and_never_overwrites(): void
    {
        $first = $this->keep('una sola vez');
        $etag = $this->client->headObject(['Bucket' => $this->bucket, 'Key' => $first->object_key])['ETag'];
        $second = $this->keep('una sola vez');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, BronzeObject::query()->count());
        $this->assertSame($etag, $this->client->headObject(['Bucket' => $this->bucket, 'Key' => $first->object_key])['ETag']);
        $keys = array_map(fn ($o) => $o['Key'], (array) $this->client->listObjectsV2(['Bucket' => $this->bucket])['Contents']);
        $this->assertSame([$first->object_key], $keys);
    }

    public function test_emulator_s3_store_file_and_purge(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bronze');
        file_put_contents($path, str_repeat("línea de ventas\n", 5000));
        try {
            $object = $this->store->storeFile($this->tenant, 'file_upload', 'sales', $path, 'csv');
            $this->assertSame(hash_file('sha256', $path), $object->sha256);
            $this->assertSame(file_get_contents($path), $this->store->read($object));
        } finally {
            @unlink($path);
        }

        $this->store->purge($object, 'retention');
        $this->assertSame('purged', $object->refresh()->status);
        $this->assertFalse($this->client->doesObjectExist($this->bucket, $object->object_key), 'el contenido se borró del bucket');
        $this->expectException(BronzeIntegrityException::class);
        $this->store->read($object);
    }

    public function test_emulator_s3_keeps_companies_apart_by_prefix(): void
    {
        $mine = $this->keep('mismo contenido');
        [$other] = $this->createTenantWithUser();
        app(TenantContext::class)->set($other);
        $theirs = $this->store->store($other, 'file_upload', 'sales', 'mismo contenido', 'csv');

        $this->assertNotSame($mine->object_key, $theirs->object_key);
        $this->assertStringStartsWith('tenants/'.$other->uuid.'/bronze/', $theirs->object_key);
        $this->assertSame(2, count((array) $this->client->listObjectsV2(['Bucket' => $this->bucket])['Contents']));
    }
}
