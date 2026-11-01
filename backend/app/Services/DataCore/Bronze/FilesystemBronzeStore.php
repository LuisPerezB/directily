<?php

declare(strict_types=1);

namespace App\Services\DataCore\Bronze;

use App\Models\BronzeObject;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

/**
 * Implementación del contrato sobre un disco de Laravel (`config('bronze.disk')`: `local` en desarrollo y pruebas;
 * `bronze_s3` en AWS o contra un emulador S3). La selección del disco es solo configuración: la interfaz no cambia.
 *
 * Garantías (ADR-V25-04, ADR-V25-24):
 * - La clave del objeto la genera SOLO el servidor: `tenants/<uuid>/bronze/<entidad>/<aaaa>/<mm>/<sha256>.<ext>`. La
 *   entidad se valida con una expresión estricta, la extensión con una lista blanca y el nombre de archivo declarado es un
 *   dato descriptivo que nunca forma parte de una ruta.
 * - Escritura única e idempotente: el mismo contenido de la misma empresa y entidad devuelve el objeto existente y no
 *   reescribe nada (índice único `tenant_id, sha256, entity`; una carrera entre dos escrituras iguales converge al mismo
 *   objeto). Un objeto purgado por retención que vuelve a recibirse se rehidrata con el MISMO contenido (mismo SHA-256).
 * - El objeto registra el disco con que se guardó (`metadata.disk`): cambiar `bronze.disk` después no deja ilegibles los
 *   objetos anteriores.
 * - Nunca queda un objeto «fantasma» (fila sin contenido): se comprueba el retorno de la escritura (un disco con `throw => false`
 *   devuelve false sin lanzar) y que el contenido exista después de escribir; si no, BronzeIntegrityException y NO se registra la fila.
 *   Al reutilizar un objeto existente se verifica que su contenido siga en el almacén y, si falta, se reescribe (`bronze.content_restored`).
 * - `read` verifica el SHA-256 y `purge` solo marca `purged` cuando el contenido ya no está en el almacén; ambos se auditan
 *   (`bronze.purged`) o lanzan BronzeIntegrityException.
 * - Tamaño máximo por objeto: `bronze.max_object_mb`.
 */
final class FilesystemBronzeStore implements BronzeStore
{
    private const EXTENSIONS = ['csv', 'xlsx', 'xls', 'json', 'ndjson', 'ndjson.gz', 'json.gz', 'csv.gz', 'txt'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly BronzeRetention $retention,
        private readonly AuditLogger $audit,
    ) {}

    public function store(Tenant $tenant, string $kind, string $entity, string $contents, string $extension, array $provenance = []): BronzeObject
    {
        $this->guard($tenant, $kind, $entity, $extension);
        $this->assertSize(strlen($contents));
        $sha = hash('sha256', $contents);

        return $this->persist($tenant, $kind, $entity, $extension, $sha, strlen($contents), $provenance,
            fn (Filesystem $disk, string $key): bool => $disk->put($key, $contents) !== false);
    }

    public function storeFile(Tenant $tenant, string $kind, string $entity, string $localPath, string $extension, array $provenance = []): BronzeObject
    {
        if (! is_file($localPath) || ! is_readable($localPath)) {
            throw new InvalidArgumentException('El archivo a guardar en Bronze no existe o no es legible.');
        }
        $this->guard($tenant, $kind, $entity, $extension);
        $size = (int) filesize($localPath);
        $this->assertSize($size);
        $sha = (string) hash_file('sha256', $localPath);

        return $this->persist($tenant, $kind, $entity, $extension, $sha, $size, $provenance,
            function (Filesystem $disk, string $key) use ($localPath): bool {
                $stream = fopen($localPath, 'rb');
                if ($stream === false) {
                    throw new InvalidArgumentException('El archivo a guardar en Bronze no se pudo abrir.');
                }
                try {
                    return $disk->writeStream($key, $stream) !== false;
                } finally {
                    fclose($stream);
                }
            });
    }

    public function read(BronzeObject $object): string
    {
        if ($this->context->has() && (int) $object->tenant_id !== $this->context->id()) {
            throw new InvalidArgumentException('Bronze solo lee objetos de la empresa activa.');
        }
        if ($object->status === 'purged') {
            throw new BronzeIntegrityException('El objeto Bronze fue purgado por retención.');
        }
        $disk = $this->diskFor($object);
        if (! $disk->exists($object->object_key)) {
            throw new BronzeIntegrityException('El objeto Bronze no existe en el almacén.');
        }
        $contents = (string) $disk->get($object->object_key);
        if (! hash_equals($object->sha256, hash('sha256', $contents))) {
            throw new BronzeIntegrityException('El contenido Bronze no coincide con su SHA-256.');
        }

        return $contents;
    }

    public function purge(BronzeObject $object, string $reason): void
    {
        if ($this->context->has() && (int) $object->tenant_id !== $this->context->id()) {
            throw new InvalidArgumentException('Bronze solo purga objetos de la empresa activa.');
        }
        if ($object->status === 'purged') {
            return;
        }
        $disk = $this->diskFor($object);
        $disk->delete($object->object_key);
        if ($disk->exists($object->object_key)) {
            throw new BronzeIntegrityException('No se pudo borrar el contenido del objeto Bronze; no se marca como purgado.');
        }
        $purgedAt = now()->toIso8601String();
        $object->forceFill([
            'status' => 'purged',
            'metadata' => array_merge($object->metadata ?? [], ['purged_reason' => $reason, 'purged_at' => $purgedAt]),
        ])->save();
        $this->audit->log('bronze.purged', $object, [
            'entity' => $object->entity, 'sha256_prefix' => substr($object->sha256, 0, 12), 'size_bytes' => (int) $object->size_bytes,
            'reason' => $reason, 'retention_until' => $object->retention_until?->toDateString(),
        ], (int) $object->tenant_id);
    }

    /**
     * @param  callable(Filesystem, string): bool  $write  escribe el contenido; devuelve false (o lanza) si falla
     * @param  array<string, mixed>  $provenance
     */
    private function persist(Tenant $tenant, string $kind, string $entity, string $extension, string $sha, int $size, array $provenance, callable $write): BronzeObject
    {
        $existing = BronzeObject::query()->where('sha256', $sha)->where('entity', $entity)->first();
        if ($existing !== null && $existing->status !== 'purged') {
            return $this->ensureContent($existing, $write);
        }
        $diskName = (string) config('bronze.disk', 'local');
        $disk = Storage::disk($diskName);

        if ($existing !== null) {
            return $this->rehydrate($tenant, $existing, $write);
        }

        $key = $this->key($tenant, $entity, $sha, $extension);
        if (! $disk->exists($key)) {
            $this->writeVerified($disk, $key, $write);
        } elseif (! hash_equals($sha, hash('sha256', (string) $disk->get($key)))) {
            // Escritura única: nunca se sobrescribe. Un archivo ajeno en la clave del servidor es una anomalía, no un objeto reutilizable.
            throw new BronzeIntegrityException('Ya existe en el almacén un contenido distinto bajo la clave Bronze generada.');
        }

        try {
            return DB::transaction(fn () => $this->register($tenant, $kind, $entity, $key, $sha, $size, $diskName, $provenance));
        } catch (UniqueConstraintViolationException) {
            // Otra escritura del mismo contenido ganó la carrera: ambas convergen al mismo objeto.
            return BronzeObject::query()->where('sha256', $sha)->where('entity', $entity)->firstOrFail();
        }
    }

    /**
     * Escribe y comprueba el resultado: un disco con `throw => false` devuelve false sin lanzar, y un objeto registrado sin contenido
     * (fila «fantasma») haría que la carga siguiera sin original. Cualquier fallo del almacén es BronzeIntegrityException.
     *
     * @param  callable(Filesystem, string): bool  $write
     */
    private function writeVerified(Filesystem $disk, string $key, callable $write): void
    {
        try {
            $written = $write($disk, $key);
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new BronzeIntegrityException('No se pudo escribir el objeto Bronze en el almacén: '.$e->getMessage(), 0, $e);
        }
        if ($written === false || ! $disk->exists($key)) {
            throw new BronzeIntegrityException('No se pudo escribir el objeto Bronze en el almacén: la escritura falló sin excepción o el contenido no quedó guardado.');
        }
    }

    /** Un objeto existente no purgado se reutiliza solo si su contenido sigue en el almacén; si falta, se reescribe (mismo SHA-256, misma clave). */
    private function ensureContent(BronzeObject $object, callable $write): BronzeObject
    {
        $disk = $this->diskFor($object);
        if (! $disk->exists($object->object_key)) {
            $this->writeVerified($disk, $object->object_key, $write);
            $this->audit->log('bronze.content_restored', $object, ['entity' => $object->entity, 'sha256_prefix' => substr($object->sha256, 0, 12)], (int) $object->tenant_id);
        }

        return $object;
    }

    /**
     * Un objeto purgado que vuelve a recibirse recupera su contenido (mismo SHA-256). `received_at` conserva la PRIMERA recepción; la
     * fecha de esta recepción queda en `metadata.last_rehydrated_at` y en el historial `metadata.rehydrations`, y la retención corre
     * desde ella (BronzeRetention).
     *
     * @param  callable(Filesystem, string): bool  $write
     */
    private function rehydrate(Tenant $tenant, BronzeObject $object, callable $write): BronzeObject
    {
        $disk = $this->diskFor($object);
        if (! $disk->exists($object->object_key)) {
            $this->writeVerified($disk, $object->object_key, $write);
        } elseif (! hash_equals($object->sha256, hash('sha256', (string) $disk->get($object->object_key)))) {
            throw new BronzeIntegrityException('Ya existe en el almacén un contenido distinto bajo la clave Bronze del objeto purgado.');
        }
        $now = now();
        $metadata = $object->metadata ?? [];
        $history = $metadata['rehydrations'] ?? [];
        $history[] = [
            'purged_reason' => $metadata['purged_reason'] ?? null, 'purged_at' => $metadata['purged_at'] ?? null,
            'received_at' => $object->received_at?->toIso8601String(), 'rehydrated_at' => $now->toIso8601String(),
        ];
        unset($metadata['purged_reason'], $metadata['purged_at']);
        $metadata['rehydrations'] = $history;
        $metadata['last_rehydrated_at'] = $now->toIso8601String();
        $object->forceFill([
            'status' => 'stored',
            'retention_until' => $this->retention->untilFor($tenant, $now),
            'metadata' => $metadata,
        ])->save();
        $this->audit->log('bronze.rehydrated', $object, ['entity' => $object->entity, 'sha256_prefix' => substr($object->sha256, 0, 12)], (int) $object->tenant_id);

        return $object;
    }

    private function diskFor(BronzeObject $object): Filesystem
    {
        $name = $object->metadata['disk'] ?? null;
        if (! is_string($name) || config("filesystems.disks.{$name}") === null) {
            $name = (string) config('bronze.disk', 'local');
        }

        return Storage::disk($name);
    }

    private function assertSize(int $bytes): void
    {
        $megabytes = max(0.001, (float) config('bronze.max_object_mb', 64));
        if ($bytes > (int) round($megabytes * 1024 * 1024)) {
            throw new InvalidArgumentException('El objeto supera el tamaño máximo permitido en Bronze ('.rtrim(rtrim(number_format($megabytes, 3, '.', ''), '0'), '.').' MB).');
        }
    }

    private function guard(Tenant $tenant, string $kind, string $entity, string $extension): void
    {
        if ($this->context->id() !== $tenant->id) {
            throw new InvalidArgumentException('Bronze solo escribe para la empresa activa.');
        }
        if (! in_array($kind, BronzeObject::KINDS, true)) {
            throw new InvalidArgumentException("Tipo de objeto Bronze no permitido: {$kind}.");
        }
        if (preg_match('/^[a-z_]{2,30}\z/', $entity) !== 1) {
            throw new InvalidArgumentException('Entidad Bronze inválida.');
        }
        if (! in_array(strtolower($extension), self::EXTENSIONS, true)) {
            throw new InvalidArgumentException("Extensión no permitida en Bronze: {$extension}.");
        }
    }

    private function key(Tenant $tenant, string $entity, string $sha, string $extension): string
    {
        $now = Carbon::now('UTC');

        return sprintf('tenants/%s/bronze/%s/%s/%s/%s.%s', $tenant->uuid, $entity, $now->format('Y'), $now->format('m'), $sha, strtolower($extension));
    }

    /** @param  array<string, mixed>  $provenance */
    private function register(Tenant $tenant, string $kind, string $entity, string $key, string $sha, int $size, string $diskName, array $provenance): BronzeObject
    {
        $now = now();

        return BronzeObject::query()->create([
            'data_source_id' => $provenance['data_source_id'] ?? null,
            'kind' => $kind,
            'entity' => $entity,
            'storage_driver' => (string) config("filesystems.disks.{$diskName}.driver", 'local'),
            'object_key' => $key,
            'sha256' => $sha,
            'size_bytes' => $size,
            'content_type' => $provenance['content_type'] ?? null,
            'schema_fingerprint' => $provenance['schema_fingerprint'] ?? null,
            'schema_version' => $provenance['schema_version'] ?? null,
            'row_count' => $provenance['row_count'] ?? null,
            'original_name' => isset($provenance['original_name']) ? mb_substr((string) $provenance['original_name'], 0, 255) : null,
            'received_by_user_id' => $provenance['received_by_user_id'] ?? null,
            'received_from_device' => $provenance['received_from_device'] ?? null,
            'received_at' => $now,
            'retention_until' => $this->retention->untilFor($tenant, $now),
            'status' => 'stored',
            'metadata' => array_merge($provenance['metadata'] ?? [], ['disk' => $diskName]),
        ]);
    }
}
