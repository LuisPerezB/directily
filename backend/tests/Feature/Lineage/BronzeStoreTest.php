<?php

declare(strict_types=1);

namespace Tests\Feature\Lineage;

use App\Models\AuditLog;
use App\Models\BronzeObject;
use App\Models\Tenant;
use App\Services\DataCore\Bronze\BronzeIntegrityException;
use App\Services\DataCore\Bronze\BronzeStore;
use App\Services\DataCore\Bronze\FilesystemBronzeStore;
use App\Support\Tenancy\Exceptions\TenantNotResolvedException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Almacén Bronze (ADR-V25-04 y ADR-V25-24) sobre el disco local: clave generada SOLO por el servidor, escritura única e
 * idempotente, SHA-256 verificado al leer, aislamiento entre empresas, purga auditada y límites. El controlador S3 se prueba
 * aparte contra un emulador (BronzeS3EmulatorTest).
 */
final class BronzeStoreTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private Tenant $tenant;

    private BronzeStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['bronze.disk' => 'local', 'bronze.retention_days' => 0, 'bronze.max_object_mb' => 64]);
        [$this->tenant] = $this->createTenantWithUser();
        app(TenantContext::class)->set($this->tenant);
        $this->store = app(BronzeStore::class);
    }

    private function keep(string $contents = "sku,qty\nA,1\n", array $provenance = [], string $entity = 'sales', string $ext = 'csv'): BronzeObject
    {
        return $this->store->store($this->tenant, 'file_upload', $entity, $contents, $ext, $provenance);
    }

    public function test_store_generates_the_key_on_the_server_and_registers_integrity_and_provenance(): void
    {
        $contents = "sku,qty\nA,1\n";
        $object = $this->keep($contents, [
            'original_name' => 'ventas enero.csv', 'content_type' => 'text/csv', 'row_count' => 1, 'schema_version' => 3,
            'schema_fingerprint' => str_repeat('a', 64), 'metadata' => ['canal' => 'api'],
        ]);

        $sha = hash('sha256', $contents);
        $this->assertSame($sha, $object->sha256);
        $this->assertSame(strlen($contents), (int) $object->size_bytes);
        $this->assertMatchesRegularExpression('#^tenants/'.$this->tenant->uuid.'/bronze/sales/\d{4}/\d{2}/'.$sha.'\.csv$#', $object->object_key);
        $this->assertSame('local', $object->storage_driver);
        $this->assertSame('stored', $object->status);
        $this->assertSame('ventas enero.csv', $object->original_name);
        $this->assertSame(['canal' => 'api', 'disk' => 'local'], $object->metadata, 'el disco usado queda registrado');
        Storage::disk('local')->assertExists($object->object_key);
        $this->assertSame($contents, Storage::disk('local')->get($object->object_key));
        $this->assertSame($contents, $this->store->read($object));
    }

    public function test_the_key_never_comes_from_client_input(): void
    {
        $object = $this->keep('x', ['original_name' => '../../../etc/passwd', 'metadata' => ['object_key' => 'tenants/otra/robo.csv', 'disk' => 's3']]);
        $this->assertStringStartsWith('tenants/'.$this->tenant->uuid.'/bronze/sales/', $object->object_key);
        $this->assertStringNotContainsString('..', $object->object_key);
        $this->assertStringNotContainsString('passwd', $object->object_key);
        $this->assertSame('local', $object->metadata['disk'], 'el disco lo fija el servidor, no la procedencia enviada');
        $this->assertSame('../../../etc/passwd', $object->original_name, 'el nombre declarado es solo un dato descriptivo');

        foreach ([['entity', '../x'], ['entity', 'Sales'], ['entity', 'a'], ['entity', str_repeat('a', 31)], ['entity', "sales\n"], ['entity', "sales\r\n"], ['ext', 'php'], ['ext', 'csv/../x'], ['ext', "csv\n"], ['ext', '../csv']] as [$what, $value]) {
            try {
                $what === 'entity' ? $this->keep('y', [], $value) : $this->keep('y', [], 'sales', $value);
                $this->fail("Debió rechazar {$what}={$value}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        try {
            $this->store->store($this->tenant, 'desconocido', 'sales', 'z', 'csv');
            $this->fail('Debió rechazar el tipo de objeto');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(1, BronzeObject::query()->count(), 'las entradas rechazadas no dejaron objetos');
    }

    public function test_store_is_idempotent_for_the_same_content_tenant_and_entity(): void
    {
        $first = $this->keep('mismo contenido');
        $disk = Storage::disk('local');
        $before = $disk->lastModified($first->object_key);
        $this->travelTo(now()->addHour());
        $second = $this->keep('mismo contenido', ['original_name' => 'otro-nombre.csv']);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, BronzeObject::query()->count());
        $this->assertSame($before, $disk->lastModified($first->object_key), 'no se reescribió el contenido');
        $this->assertNull($second->original_name, 'el segundo envío no reemplaza la procedencia del primero');

        $other = $this->keep('mismo contenido', [], 'products');
        $this->assertNotSame($first->id, $other->id, 'otra entidad = otro objeto');
        $this->assertSame(2, BronzeObject::query()->count());
    }

    public function test_concurrent_writes_of_the_same_content_converge_on_one_object(): void
    {
        $sha = hash('sha256', 'carrera');
        $tenantId = $this->tenant->id;
        $real = Storage::disk('local');
        $fired = false;
        // Otra petición registra el mismo contenido entre la escritura en el almacén y nuestro registro.
        $racing = \Mockery::mock($real)->makePartial();
        $racing->shouldReceive('put')->andReturnUsing(function (...$args) use ($real, $sha, $tenantId, &$fired) {
            $fired = true;
            DB::table('bronze_objects')->insert([
                'tenant_id' => $tenantId, 'kind' => 'file_upload', 'entity' => 'sales', 'storage_driver' => 'local', 'object_key' => 'tenants/x/otro.csv',
                'sha256' => $sha, 'size_bytes' => 7, 'received_at' => now(), 'status' => 'stored', 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $real->put(...$args);
        });
        Storage::set('local', $racing);

        $object = $this->keep('carrera');

        $this->assertTrue($fired);
        $this->assertSame('tenants/x/otro.csv', $object->object_key, 'devuelve el objeto que ganó la carrera');
        $this->assertSame(1, BronzeObject::query()->where('sha256', $sha)->count());
        $this->assertSame(1, BronzeObject::query()->count());
    }

    public function test_the_unique_index_rejects_a_duplicate_registration(): void
    {
        $object = $this->keep('unico');
        $this->expectException(UniqueConstraintViolationException::class);
        BronzeObject::query()->create([
            'kind' => 'file_upload', 'entity' => 'sales', 'storage_driver' => 'local', 'object_key' => 'tenants/y.csv', 'sha256' => $object->sha256,
            'size_bytes' => 5, 'received_at' => now(), 'status' => 'stored',
        ]);
    }

    public function test_read_verifies_the_sha256_and_detects_altered_missing_or_purged_content(): void
    {
        $object = $this->keep('contenido original');
        $this->assertSame('contenido original', $this->store->read($object));

        Storage::disk('local')->put($object->object_key, 'contenido alterado');
        try {
            $this->store->read($object);
            $this->fail('Debió detectar el contenido alterado');
        } catch (BronzeIntegrityException $e) {
            $this->assertStringContainsString('SHA-256', $e->getMessage());
        }

        Storage::disk('local')->delete($object->object_key);
        $this->expectException(BronzeIntegrityException::class);
        $this->expectExceptionMessage('no existe');
        $this->store->read($object);
    }

    public function test_store_never_overwrites_a_foreign_file_at_the_generated_key(): void
    {
        $sha = hash('sha256', 'legítimo');
        $key = sprintf('tenants/%s/bronze/sales/%s/%s/%s.csv', $this->tenant->uuid, now('UTC')->format('Y'), now('UTC')->format('m'), $sha);
        Storage::disk('local')->put($key, 'otro contenido en la misma clave');

        try {
            $this->keep('legítimo');
            $this->fail('Debió negarse a reutilizar o sobrescribir el archivo ajeno');
        } catch (BronzeIntegrityException) {
            $this->assertSame('otro contenido en la misma clave', Storage::disk('local')->get($key), 'escritura única: no se sobrescribió');
            $this->assertSame(0, BronzeObject::query()->count());
        }
    }

    /** Disco cuyas escrituras fallan SIN lanzar (lo que hace un disco con `throw => false`). */
    private function failingDisk(bool $lies = false): Filesystem
    {
        $real = Storage::disk('local');
        $disk = \Mockery::mock($real)->makePartial();
        // `lies`: dice que escribió (true) pero el contenido no queda en el almacén.
        $disk->shouldReceive('put')->andReturn($lies);
        $disk->shouldReceive('writeStream')->andReturn($lies);
        Storage::set('local', $disk);

        return $real;
    }

    public function test_a_failed_write_never_registers_a_phantom_object(): void
    {
        foreach ([false, true] as $lies) {
            $real = $this->failingDisk($lies);
            try {
                $this->keep('contenido que no se escribe');
                $this->fail('Debió fallar la escritura');
            } catch (BronzeIntegrityException $e) {
                $this->assertStringContainsString('No se pudo escribir', $e->getMessage());
            }
            $path = tempnam(sys_get_temp_dir(), 'bronze');
            file_put_contents($path, 'archivo que no se escribe');
            try {
                $this->store->storeFile($this->tenant, 'file_upload', 'sales', $path, 'csv');
                $this->fail('Debió fallar la escritura del archivo');
            } catch (BronzeIntegrityException) {
                $this->addToAssertionCount(1);
            } finally {
                @unlink($path);
            }
            $this->assertSame(0, BronzeObject::query()->count(), 'sin contenido no hay fila: la carga se detiene en lugar de seguir sin original');
            Storage::set('local', $real);
        }

        // Con el almacén sano el mismo contenido se guarda normalmente.
        $this->assertSame('contenido que no se escribe', $this->store->read($this->keep('contenido que no se escribe')));
    }

    public function test_a_flysystem_exception_is_also_a_clean_integrity_failure(): void
    {
        $real = Storage::disk('local');
        $disk = \Mockery::mock($real)->makePartial();
        $disk->shouldReceive('put')->andThrow(new \RuntimeException('disco lleno'));
        Storage::set('local', $disk);

        try {
            $this->keep('no cabe');
            $this->fail('Debió fallar');
        } catch (BronzeIntegrityException $e) {
            $this->assertStringContainsString('disco lleno', $e->getMessage());
            $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }
        $this->assertSame(0, BronzeObject::query()->count());
    }

    public function test_an_unwritable_directory_on_a_real_local_disk_is_a_failure_not_a_phantom(): void
    {
        $dir = sys_get_temp_dir().'/bronze_ro_'.bin2hex(random_bytes(4));
        mkdir($dir, 0755);
        chmod($dir, 0555);
        try {
            if (is_writable($dir)) {
                $this->markTestSkipped('El usuario puede escribir en un directorio de solo lectura (¿root?): no se puede simular el fallo.');
            }
            config(['filesystems.disks.local.root' => $dir]);
            Storage::forgetDisk('local');
            try {
                $this->keep('sin permiso');
                $this->fail('Debió fallar: el directorio no admite escritura');
            } catch (BronzeIntegrityException) {
                $this->assertSame(0, BronzeObject::query()->count());
            }
        } finally {
            chmod($dir, 0755);
            @rmdir($dir);
        }
    }

    public function test_reusing_an_existing_object_restores_content_that_went_missing(): void
    {
        $object = $this->keep('contenido que desaparece');
        Storage::disk('local')->delete($object->object_key);

        $again = $this->keep('contenido que desaparece');

        $this->assertSame($object->id, $again->id, 'sigue siendo el mismo objeto');
        Storage::disk('local')->assertExists($object->object_key);
        $this->assertSame('contenido que desaparece', $this->store->read($again));
        $this->assertSame(1, AuditLog::query()->where('action', 'bronze.content_restored')->count());

        // Si el contenido falta y no se puede reescribir, la carga se detiene (no se devuelve un objeto sin contenido).
        Storage::disk('local')->delete($object->object_key);
        $this->failingDisk();
        $this->expectException(BronzeIntegrityException::class);
        $this->keep('contenido que desaparece');
    }

    public function test_reusing_an_object_with_its_content_present_does_not_touch_the_store(): void
    {
        $object = $this->keep('intacto');
        $real = Storage::disk('local');
        $spy = \Mockery::mock($real)->makePartial();
        $spy->shouldNotReceive('put');
        $spy->shouldNotReceive('writeStream');
        Storage::set('local', $spy);

        $this->assertSame($object->id, $this->keep('intacto')->id);
        $this->assertSame(0, AuditLog::query()->where('action', 'bronze.content_restored')->count());
    }

    public function test_a_failed_rehydration_leaves_the_object_purged(): void
    {
        $object = $this->keep('purgado');
        $this->store->purge($object, 'retention');
        $this->failingDisk();

        try {
            $this->keep('purgado');
            $this->fail('Debió fallar la rehidratación');
        } catch (BronzeIntegrityException) {
            $this->assertSame('purged', $object->refresh()->status, 'sin contenido sigue purgado');
            $this->assertArrayNotHasKey('rehydrations', $object->metadata);
        }
    }

    public function test_store_file_streams_from_disk_with_the_same_guarantees(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bronze');
        file_put_contents($path, "a,b\n1,2\n");
        try {
            $object = $this->store->storeFile($this->tenant, 'file_upload', 'sales', $path, 'csv', ['original_name' => 'x.csv']);
            $this->assertSame(hash_file('sha256', $path), $object->sha256);
            $this->assertSame("a,b\n1,2\n", $this->store->read($object));
            $this->assertSame($object->id, $this->keep("a,b\n1,2\n")->id, 'storeFile y store convergen en el mismo objeto');
        } finally {
            @unlink($path);
        }
        $this->expectException(InvalidArgumentException::class);
        $this->store->storeFile($this->tenant, 'file_upload', 'sales', '/no/existe/archivo.csv', 'csv');
    }

    public function test_objects_over_the_size_limit_are_rejected_before_writing(): void
    {
        config(['bronze.max_object_mb' => 0.001]);   // 1 KB aprox. (1049 bytes): la prueba no necesita megabytes de memoria
        $limit = (int) round(0.001 * 1024 * 1024);
        try {
            $this->keep(str_repeat('x', $limit + 1));
            $this->fail('Debió rechazar el objeto grande');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('0.001 MB', $e->getMessage());
        }
        $this->assertSame(0, BronzeObject::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('tenants'));
        $this->keep(str_repeat('x', $limit));
        $this->assertSame(1, BronzeObject::query()->count(), 'el límite es inclusivo');

        // También en el camino por archivo (se rechaza antes de calcular la huella o subir nada).
        $path = tempnam(sys_get_temp_dir(), 'bronze');
        file_put_contents($path, str_repeat('y', $limit + 1));
        try {
            $this->store->storeFile($this->tenant, 'file_upload', 'sales', $path, 'csv');
            $this->fail('Debió rechazar el archivo grande');
        } catch (InvalidArgumentException) {
            $this->assertSame(1, BronzeObject::query()->count());
        } finally {
            @unlink($path);
        }
    }

    public function test_companies_are_isolated_in_bronze(): void
    {
        $a = $this->keep('contenido compartido');
        [$b] = $this->createTenantWithUser();

        // B no puede escribir mientras la empresa activa es A, ni leer el objeto de A.
        try {
            $this->store->store($b, 'file_upload', 'sales', 'x', 'csv');
            $this->fail('Bronze solo escribe para la empresa activa');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        app(TenantContext::class)->set($b);
        try {
            $this->store->read($a);
            $this->fail('B no puede leer un objeto de A');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->assertNull(BronzeObject::query()->find($a->id), 'el global scope oculta los objetos de A');
        $this->assertSame(0, BronzeObject::query()->count());

        // El mismo contenido en B es otro objeto, con clave bajo el prefijo de B.
        $own = $this->store->store($b, 'file_upload', 'sales', 'contenido compartido', 'csv');
        $this->assertNotSame($a->id, $own->id);
        $this->assertStringStartsWith('tenants/'.$b->uuid.'/bronze/', $own->object_key);
        $this->assertStringStartsWith('tenants/'.$this->tenant->uuid.'/bronze/', $a->object_key);
        $this->assertSame(2, BronzeObject::withoutTenantScope()->count());

        app(TenantContext::class)->forget();
        $this->expectException(TenantNotResolvedException::class);
        BronzeObject::query()->count();
    }

    public function test_purge_deletes_the_content_marks_purged_and_is_audited(): void
    {
        $object = $this->keep('a purgar');
        $this->store->purge($object, 'retention');

        $object->refresh();
        $this->assertSame('purged', $object->status);
        Storage::disk('local')->assertMissing($object->object_key);
        $this->assertSame('retention', $object->metadata['purged_reason']);
        $this->assertArrayHasKey('purged_at', $object->metadata);
        $this->assertSame(hash('sha256', 'a purgar'), $object->sha256, 'la huella se conserva como evidencia');

        $audit = AuditLog::query()->where('action', 'bronze.purged')->get();
        $this->assertCount(1, $audit);
        $this->assertSame($this->tenant->id, (int) $audit[0]->tenant_id);
        $this->assertSame($object->id, (int) $audit[0]->auditable_id);
        $this->assertSame('retention', $audit[0]->metadata['reason']);
        $this->assertStringNotContainsString($object->sha256, json_encode($audit[0]->metadata), 'la bitácora no guarda la huella completa');

        $this->store->purge($object, 'retention');   // idempotente
        $this->assertSame(1, AuditLog::query()->where('action', 'bronze.purged')->count());

        $this->expectException(BronzeIntegrityException::class);
        $this->expectExceptionMessage('purgado');
        $this->store->read($object);
    }

    public function test_purge_refuses_objects_of_another_company(): void
    {
        $object = $this->keep('de A');
        [$b] = $this->createTenantWithUser();
        app(TenantContext::class)->set($b);
        $this->expectException(InvalidArgumentException::class);
        $this->store->purge($object, 'retention');
    }

    public function test_a_purged_object_received_again_is_restored_with_the_same_content(): void
    {
        $this->travelTo('2026-01-10 10:00:00');
        $object = $this->keep('vuelve');
        $firstReceipt = $object->received_at->toIso8601String();
        $this->store->purge($object, 'retention');
        Storage::disk('local')->assertMissing($object->object_key);

        $this->travelTo('2026-06-10 10:00:00');
        $again = $this->keep('vuelve');

        $this->assertSame($object->id, $again->id, 'misma identidad: mismo SHA-256');
        $this->assertSame('stored', $again->status);
        $this->assertSame('vuelve', $this->store->read($again));
        $this->assertArrayNotHasKey('purged_reason', $again->metadata);
        $this->assertSame($firstReceipt, $again->received_at->toIso8601String(), 'received_at conserva la PRIMERA recepción (procedencia)');
        $this->assertCount(1, $again->metadata['rehydrations']);
        $this->assertSame('retention', $again->metadata['rehydrations'][0]['purged_reason']);
        $this->assertSame($firstReceipt, $again->metadata['rehydrations'][0]['received_at']);
        $this->assertSame('2026-06-10T10:00:00+00:00', $again->metadata['rehydrations'][0]['rehydrated_at']);
        $this->assertSame('2026-06-10T10:00:00+00:00', $again->metadata['last_rehydrated_at'], 'la retención corre desde la última recepción');
        $this->assertSame(1, AuditLog::query()->where('action', 'bronze.rehydrated')->count());
    }

    public function test_objects_stay_readable_after_the_configured_disk_changes(): void
    {
        $object = $this->keep('en disco local');
        config(['bronze.disk' => 'public']);   // el despliegue cambia de disco después

        $this->assertSame('en disco local', $this->store->read($object), 'se lee del disco con que se guardó');
        $this->store->purge($object, 'retention');
        Storage::disk('local')->assertMissing($object->object_key);
    }

    public function test_it_binds_the_interface_to_the_filesystem_implementation(): void
    {
        $this->assertInstanceOf(FilesystemBronzeStore::class, app(BronzeStore::class));
        $methods = array_map(fn ($m) => $m->getName(), (new \ReflectionClass(BronzeStore::class))->getMethods());
        sort($methods);
        $this->assertSame(['purge', 'read', 'store', 'storeFile'], $methods, 'la interfaz del contrato no cambia');
    }
}
