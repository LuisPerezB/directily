<?php

declare(strict_types=1);

namespace App\Services\DataCore\Bronze;

use App\Models\BronzeObject;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Implementación del contrato sobre un disco de Laravel (`config('bronze.disk')`: `local` en desarrollo y pruebas;
 * `s3` cuando exista el adaptador y la cuenta). Implementación mínima del arquitecto: el paquete W2-B la amplía
 * (controlador S3 probado con emulador, retención y purga programadas, reprocesamiento).
 */
final class FilesystemBronzeStore implements BronzeStore
{
    private const EXTENSIONS = ['csv', 'xlsx', 'xls', 'json', 'ndjson', 'ndjson.gz', 'json.gz', 'csv.gz', 'txt'];

    public function __construct(private readonly TenantContext $context) {}

    public function store(Tenant $tenant, string $kind, string $entity, string $contents, string $extension, array $provenance = []): BronzeObject
    {
        $this->guard($tenant, $kind, $entity, $extension);
        $sha = hash('sha256', $contents);
        $existing = BronzeObject::query()->where('sha256', $sha)->where('entity', $entity)->first();
        if ($existing !== null) {
            return $existing;
        }
        $key = $this->key($tenant, $entity, $sha, $extension);
        $disk = Storage::disk((string) config('bronze.disk', 'local'));
        if (! $disk->exists($key)) {
            $disk->put($key, $contents);
        }

        return $this->register($tenant, $kind, $entity, $key, $sha, strlen($contents), $provenance);
    }

    public function storeFile(Tenant $tenant, string $kind, string $entity, string $localPath, string $extension, array $provenance = []): BronzeObject
    {
        if (! is_file($localPath) || ! is_readable($localPath)) {
            throw new InvalidArgumentException('El archivo a guardar en Bronze no existe o no es legible.');
        }

        return $this->store($tenant, $kind, $entity, (string) file_get_contents($localPath), $extension, $provenance);
    }

    public function read(BronzeObject $object): string
    {
        if ($object->status === 'purged') {
            throw new BronzeIntegrityException('El objeto Bronze fue purgado por retención.');
        }
        $disk = Storage::disk((string) config('bronze.disk', 'local'));
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
        Storage::disk((string) config('bronze.disk', 'local'))->delete($object->object_key);
        $object->forceFill([
            'status' => 'purged',
            'metadata' => array_merge($object->metadata ?? [], ['purged_reason' => $reason, 'purged_at' => now()->toIso8601String()]),
        ])->save();
    }

    private function guard(Tenant $tenant, string $kind, string $entity, string $extension): void
    {
        if ($this->context->id() !== $tenant->id) {
            throw new InvalidArgumentException('Bronze solo escribe para la empresa activa.');
        }
        if (! in_array($kind, BronzeObject::KINDS, true)) {
            throw new InvalidArgumentException("Tipo de objeto Bronze no permitido: {$kind}.");
        }
        if (! preg_match('/^[a-z_]{2,30}$/', $entity)) {
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
    private function register(Tenant $tenant, string $kind, string $entity, string $key, string $sha, int $size, array $provenance): BronzeObject
    {
        $days = (int) config('bronze.retention_days', 0);

        return BronzeObject::query()->create([
            'data_source_id' => $provenance['data_source_id'] ?? null,
            'kind' => $kind,
            'entity' => $entity,
            'storage_driver' => (string) config('filesystems.disks.'.config('bronze.disk', 'local').'.driver', 'local'),
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
            'received_at' => now(),
            'retention_until' => $days > 0 ? now()->addDays($days)->toDateString() : null,
            'status' => 'stored',
            'metadata' => $provenance['metadata'] ?? [],
        ]);
    }
}
