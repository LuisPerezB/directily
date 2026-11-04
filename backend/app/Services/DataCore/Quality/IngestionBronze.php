<?php

declare(strict_types=1);

namespace App\Services\DataCore\Quality;

use App\Models\BronzeObject;
use App\Models\Tenant;
use App\Services\DataCore\Bronze\BronzeStore;
use Throwable;

/**
 * Captura Bronze de los tres caminos de ingestión ANTES de normalizar (ADR-V25-04 y ADR-V25-24; W2-A), siempre a través de la interfaz
 * `BronzeStore` (no reimplementa el almacén):
 *  - archivo subido (`file_upload`): el archivo íntegro, tal como llegó;
 *  - extracción SQL (`sql_extract`): NDJSON comprimido con las columnas que usa el mapeo aprobado, una línea por fila de origen;
 *  - lote REST (`api_batch`): el cuerpo validado del lote.
 * Cada captura calcula la huella de esquema y la deriva (`SchemaDrift`) y las deja en el objeto (huella, versión, metadatos).
 *
 * El original se guarda TAL CUAL llegó (también las identificaciones de clientes que envía la fuente, como ya ocurría con el archivo subido
 * en el almacén de importaciones): minimizarlo rompería el reprocesamiento determinista, porque `source_hash` incluye el código de cliente.
 * Los controles de datos personales de Bronze son los del almacén: acceso solo del servidor y por empresa, retención y purga por empresa.
 * Si el almacén falla, la carga se detiene (BLOCKER `bronze_capture_failed`): sin original no hay linaje ni reprocesamiento.
 */
final class IngestionBronze
{
    public function __construct(private readonly BronzeStore $store, private readonly SchemaDrift $drift) {}

    /**
     * @param  list<string>  $headers  encabezados detectados del archivo
     * @return array{object: BronzeObject, schema: array<string, mixed>}
     *
     * @throws BronzeCaptureException
     */
    public function captureFile(Tenant $tenant, ?int $userId, string $localPath, string $originalName, string $extension, string $entity, array $headers, ?int $dataSourceId, string $kind = 'file_upload'): array
    {
        $schema = $this->drift->observe($dataSourceId, $entity, array_fill_keys(array_values(array_filter($headers, fn ($h) => $h !== '')), null));

        return $this->store($tenant, $dataSourceId, $schema, fn (array $provenance) => $this->store->storeFile($tenant, $kind, $entity, $localPath, $extension, $provenance + [
            'original_name' => $originalName,
            'content_type' => $extension === 'csv' ? 'text/csv' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'received_by_user_id' => $userId,
        ]), ['kind' => $kind]);
    }

    /**
     * @param  array<string, ?string>  $columns  columna de origen => tipo observado
     * @return array{object: BronzeObject, schema: array<string, mixed>}
     *
     * @throws BronzeCaptureException
     */
    public function captureSqlExtract(Tenant $tenant, ?int $userId, int $dataSourceId, string $entity, string $ndjsonGzPath, int $rows, array $columns, array $metadata = []): array
    {
        $schema = $this->drift->observe($dataSourceId, $entity, $columns);

        return $this->store($tenant, $dataSourceId, $schema, fn (array $provenance) => $this->store->storeFile($tenant, 'sql_extract', $entity, $ndjsonGzPath, 'ndjson.gz', $provenance + [
            'content_type' => 'application/gzip',
            'received_by_user_id' => $userId,
            'row_count' => $rows,
        ]), ['kind' => 'sql_extract'] + $metadata);
    }

    /**
     * @param  array<string, mixed>  $body  `documents`, `options` e `ingestion_mode` del lote
     * @return array{object: BronzeObject, schema: array<string, mixed>}
     *
     * @throws BronzeCaptureException
     */
    public function captureBatch(Tenant $tenant, ?int $userId, ?int $dataSourceId, array $body): array
    {
        $columns = [];
        foreach ((array) ($body['documents'] ?? []) as $document) {
            foreach (array_keys((array) $document) as $key) {
                if ($key !== 'items') {
                    $columns[(string) $key] = null;
                }
            }
            foreach ((array) ($document['items'] ?? []) as $item) {
                foreach (array_keys((array) $item) as $key) {
                    $columns['items.'.$key] = null;
                }
            }
        }
        $schema = $this->drift->observe($dataSourceId, 'sales', $columns);
        $contents = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';

        return $this->store($tenant, $dataSourceId, $schema, fn (array $provenance) => $this->store->store($tenant, 'api_batch', 'sales', $contents, 'json', $provenance + [
            'content_type' => 'application/json',
            'received_by_user_id' => $userId,
            'row_count' => count((array) ($body['documents'] ?? [])),
        ]), ['kind' => 'api_batch']);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  callable(array<string, mixed>): BronzeObject  $save
     * @param  array<string, mixed>  $metadata
     * @return array{object: BronzeObject, schema: array<string, mixed>}
     *
     * @throws BronzeCaptureException
     */
    private function store(Tenant $tenant, ?int $dataSourceId, array $schema, callable $save, array $metadata): array
    {
        try {
            $object = $save([
                'data_source_id' => $dataSourceId,
                'schema_fingerprint' => $schema['fingerprint'],
                'schema_version' => $schema['version'],
                'metadata' => $metadata + ['columns' => $schema['columns'], 'drift' => $schema['drift'], 'captured' => 'before_normalization'],
            ]);
            if ($object->wasRecentlyCreated && $dataSourceId !== null) {
                $this->drift->recordOnSource($tenant->id, $dataSourceId, $schema['drift'] !== null);
            }
        } catch (Throwable $e) {
            throw new BronzeCaptureException('No se pudo guardar el original en Bronze: '.mb_substr($e->getMessage(), 0, 200), previous: $e);
        }

        return ['object' => $object, 'schema' => $schema];
    }
}
