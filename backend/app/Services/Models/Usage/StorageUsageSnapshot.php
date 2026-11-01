<?php

declare(strict_types=1);

namespace App\Services\Models\Usage;

use App\Models\BronzeObject;
use App\Models\ModelRegistry;
use App\Models\Tenant;
use App\Services\Forecasting\ModelArtifactVerifier;
use App\Support\Tenancy\TenantContext;
use FilesystemIterator;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Bytes almacenados por empresa (V2.5 · W4): Bronze (suma de `bronze_objects.size_bytes` no purgados) y artefactos de modelos (archivos bajo
 * `<ENGINE_ARTIFACTS_DIR>/<empresa>/` si el volumen es visible desde el backend, más los objetos publicados en el almacén). Se miden las dos
 * ubicaciones del artefacto cuando existen (ambas ocupan almacenamiento). Un volumen que el backend no ve se informa con `artifacts_visible=false`
 * y NO se inventa un valor: en ese caso solo cuentan los objetos publicados.
 */
class StorageUsageSnapshot
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantUsageMeter $meter,
        private readonly ModelArtifactVerifier $artifacts,
    ) {}

    /** @return array{bronze_bytes: int, bronze_objects: int, artifact_bytes: int, artifact_objects: int, artifacts_visible: bool} */
    public function measure(Tenant $tenant): array
    {
        return $this->context->runAs($tenant, function () use ($tenant): array {
            $bronzeBytes = (int) BronzeObject::query()->where('status', '<>', 'purged')->sum('size_bytes');
            $bronzeObjects = BronzeObject::query()->where('status', '<>', 'purged')->count();
            [$volumeBytes, $volumeFiles, $visible] = $this->volume((int) $tenant->id);
            [$storeBytes, $storeFiles] = $this->published();

            return [
                'bronze_bytes' => $bronzeBytes, 'bronze_objects' => $bronzeObjects,
                'artifact_bytes' => $volumeBytes + $storeBytes, 'artifact_objects' => $volumeFiles + $storeFiles, 'artifacts_visible' => $visible,
            ];
        });
    }

    /**
     * Mide y registra la foto del día (idempotente por día).
     *
     * @return array{bronze_bytes: int, bronze_objects: int, artifact_bytes: int, artifact_objects: int, artifacts_visible: bool}
     */
    public function record(Tenant $tenant, string $day): array
    {
        $m = $this->measure($tenant);
        $this->meter->recordStorage((int) $tenant->id, $day, TenantUsageMeter::SOURCE_BRONZE, $m['bronze_bytes'], $m['bronze_objects']);
        $this->meter->recordStorage((int) $tenant->id, $day, TenantUsageMeter::SOURCE_ARTIFACTS, $m['artifact_bytes'], $m['artifact_objects']);

        return $m;
    }

    /** @return array{0: int, 1: int, 2: bool} bytes, archivos, ¿volumen visible? */
    private function volume(int $tenantId): array
    {
        $root = $this->artifacts->directory();
        if ($root === null) {
            return [0, 0, false];
        }
        $dir = $root.'/'.$tenantId;
        if (! is_dir($dir)) {
            return [0, 0, true];
        }
        $bytes = 0;
        $files = 0;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && ! $file->isLink()) {
                $bytes += (int) $file->getSize();
                $files++;
            }
        }

        return [$bytes, $files, true];
    }

    /** @return array{0: int, 1: int} bytes y objetos publicados (artefacto y manifiesto) en el almacén de objetos */
    private function published(): array
    {
        $disk = Storage::disk((string) config('mlops.published_disk', 'local'));
        $bytes = 0;
        $objects = 0;
        $keys = ModelRegistry::query()->whereNotNull('published_object_key')->distinct()->pluck('published_object_key');
        foreach ($keys as $key) {
            foreach ([(string) $key, $key.ModelArtifactVerifier::MANIFEST_SUFFIX] as $k) {
                try {
                    if ($disk->exists($k)) {
                        $bytes += (int) $disk->size($k);
                        $objects++;
                    }
                } catch (Throwable) {
                    // objeto no legible: no se cuenta
                }
            }
        }

        return [$bytes, $objects];
    }
}
