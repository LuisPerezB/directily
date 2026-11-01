<?php

declare(strict_types=1);

namespace App\Services\DataCore\Bronze;

use App\Models\BronzeObject;
use App\Models\Tenant;

/**
 * Contrato del almacén Bronze (ADR-V25-04, ADR-V25-08, ADR-V25-24; arquitecto).
 *
 * - `store` guarda el contenido íntegro ANTES de normalizar, calcula SHA-256, genera la clave en el servidor
 *   (`tenants/<uuid>/bronze/<entity>/<yyyy>/<mm>/<sha256>.<ext>`) y registra `bronze_objects`. Escritura única:
 *   el mismo contenido para la misma empresa y entidad devuelve el objeto existente (idempotente) sin reescribirlo.
 * - Ningún parámetro proviene de una ruta enviada por el cliente: `extension` se valida contra una lista blanca y
 *   `originalName` solo se guarda como dato descriptivo.
 * - `read` exige que el objeto sea de la empresa activa (BelongsToTenant) y verifica el SHA-256 al leer.
 * - `purge` borra el contenido al vencer la retención y deja la fila con estado `purged` (auditable).
 */
interface BronzeStore
{
    /**
     * @param  array{data_source_id?: ?int, original_name?: ?string, content_type?: ?string, row_count?: ?int,
     *               schema_fingerprint?: ?string, schema_version?: ?int, received_by_user_id?: ?int,
     *               received_from_device?: ?string, metadata?: array<string, mixed>}  $provenance
     */
    public function store(Tenant $tenant, string $kind, string $entity, string $contents, string $extension, array $provenance = []): BronzeObject;

    /** Igual que store, leyendo el contenido desde un archivo local ya recibido (p. ej. una subida). */
    public function storeFile(Tenant $tenant, string $kind, string $entity, string $localPath, string $extension, array $provenance = []): BronzeObject;

    /** Contenido íntegro; lanza BronzeIntegrityException si el SHA-256 no coincide o el objeto fue purgado. */
    public function read(BronzeObject $object): string;

    public function purge(BronzeObject $object, string $reason): void;
}
