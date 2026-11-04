<?php

declare(strict_types=1);

namespace App\Services\Bridge;

use App\Jobs\Bridge\ProcessBridgeBatch;
use App\Models\BridgeDevice;
use App\Models\BronzeObject;
use App\Models\DataSource;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Services\DataCore\Bronze\BronzeStore;
use App\Services\DataCore\Quality\SchemaDrift;
use App\Services\Imports\Fields\ImportFieldRegistry;
use App\Services\Imports\ImportEntity;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Recepción de lotes del Bridge (ADR-V25-10): declaración → URL prefirmada → PUT → registro en Bronze.
 *
 * 1. `declare`: el agente declara entidad, SHA-256, tamaño, filas y columnas del lote comprimido. El servidor valida (entidad permitida,
 *    límites, columnas canónicas) y responde:
 *      - `duplicate` si ese contenido YA está en Bronze (idempotencia por SHA-256: un lote nunca se registra dos veces; si su procesamiento
 *        había fallado se vuelve a encolar);
 *      - `upload` con una RUTA RELATIVA prefirmada (host-independiente) que expira (10 min por defecto) y fija empresa, dispositivo,
 *        entidad, SHA-256, tamaño, tipo de contenido y metadatos. El agente la une a SU URL de servidor configurada: el servidor nunca
 *        puede enviarlo a otro host.
 * 2. `receive`: el PUT escribe el cuerpo en un archivo temporal acotado al tamaño declarado, comprueba tamaño y SHA-256 (si no coinciden
 *    no se guarda nada) y registra el objeto en Bronze mediante `BronzeStore` (clave generada por el servidor, tipo `bridge_batch`,
 *    procedencia del dispositivo, huella y deriva de esquema), y encola el procesamiento por el pipeline de importación existente.
 */
final class BridgeBatchIntake
{
    public const CONTENT_TYPE = 'application/gzip';

    public const EXTENSION = 'ndjson.gz';

    public function __construct(
        private readonly BronzeStore $store,
        private readonly SchemaDrift $drift,
        private readonly ImportFieldRegistry $fields,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validado por BridgeUploadDeclareRequest
     * @return array<string, mixed>
     */
    public function declare(BridgeDevice $device, DataSource $source, Tenant $tenant, array $data): array
    {
        $this->assertSourceActive($source);
        $entity = (string) $data['entity'];
        $columns = $this->validatedColumns($entity, (array) $data['columns']);
        $sha = strtolower((string) $data['sha256']);

        $existing = BronzeObject::query()->where('sha256', $sha)->where('entity', $entity)->first();
        if ($existing !== null && $existing->status === 'quarantined') {
            throw new BridgeRejected('Ese contenido está en cuarentena en Predictily porque incumple el contrato de lotes; corrija la plantilla del agente.', 'batch_quarantined', 409);
        }
        if ($existing !== null && $existing->status !== 'purged' && $this->hasContent($existing)) {
            if ((int) $existing->data_source_id !== (int) $source->id) {
                throw new BridgeRejected('Ese contenido ya existe en Bronze para otra fuente de datos de la empresa.', 'batch_conflict', 409);
            }
            // Idempotente: no se vuelve a subir ni a registrar. Si su procesamiento falló (o nunca arrancó), se re-encola.
            $processing = (string) (($existing->metadata['bridge']['processing']['status'] ?? null) ?: 'queued');
            if (in_array($processing, ['failed', 'queued'], true) && $existing->status === 'stored') {
                ProcessBridgeBatch::dispatch($tenant->id, $existing->id)->onQueue('imports');
            }

            return ['status' => 'duplicate', 'bronze_object_id' => $existing->id, 'processing' => $processing];
        }

        $this->assertDailyQuota((int) $data['size_bytes']);

        $ttl = max(30, (int) config('bridge.upload_url_ttl_seconds', 600));
        $expires = now()->addSeconds($ttl);
        $claims = array_filter([
            'e' => $entity,
            'n' => (int) $data['size_bytes'],
            'r' => (int) $data['row_count'],
            't' => $tenant->uuid,
            'c' => BridgeSignature::encode((string) json_encode($columns, JSON_UNESCAPED_UNICODE)),
            'tz' => $data['source_timezone'] ?? null,
            'run' => $data['run_id'] ?? null,
            'seq' => $data['seq'] ?? null,
            'rm' => $data['run_mode'] ?? null,
            'tv' => $data['template_version'] ?? null,
        ], fn ($v) => $v !== null);
        $path = URL::temporarySignedRoute('bridge.uploads.put', $expires, ['device' => $device->id, 'sha256' => $sha] + $claims, absolute: false);

        return [
            'status' => 'upload',
            'method' => 'PUT',
            'path' => $path,
            'headers' => ['Content-Type' => self::CONTENT_TYPE, 'Content-Length' => (int) $data['size_bytes']],
            'expires_at' => $expires->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $claims  parámetros de la URL prefirmada (ya verificados por la firma)
     * @param  resource  $body
     * @return array{created: bool, object: BronzeObject}
     */
    public function receive(BridgeDevice $device, DataSource $source, Tenant $tenant, string $sha, array $claims, mixed $body, ?string $contentType, ?int $contentLength): array
    {
        $this->assertSourceActive($source);
        $entity = (string) ($claims['e'] ?? '');
        $size = (int) ($claims['n'] ?? 0);
        $this->assertEntity($entity);
        if ($size < 1 || $size > $this->maxBytes()) {
            throw new BridgeRejected('El tamaño declarado del lote no es válido.', 'batch_too_large', 413);
        }
        if ($contentType === null || strtolower(trim(explode(';', $contentType)[0])) !== self::CONTENT_TYPE) {
            throw new BridgeRejected('El lote debe enviarse como '.self::CONTENT_TYPE.'.', 'unsupported_media_type', 415);
        }
        if ($contentLength !== null && $contentLength !== $size) {
            throw new BridgeRejected('El tamaño enviado no coincide con el declarado.', 'size_mismatch', 422);
        }
        $columns = $this->decodeColumns((string) ($claims['c'] ?? ''));
        $columns = $this->validatedColumns($entity, $columns);

        $tmp = tempnam(sys_get_temp_dir(), 'bridge-');
        if ($tmp === false) {
            throw new BridgeRejected('No se pudo preparar el almacenamiento temporal.', 'storage_unavailable', 503);
        }
        try {
            $this->spool($body, $tmp, $size, $sha);
            // ANTES de guardar nada en Bronze: gzip real, y TODAS las líneas contra las columnas, tipos y filas declarados (B-1, B-7, B-10).
            $this->validateBatch($tmp, $columns, (int) ($claims['r'] ?? 0), $size);

            return $this->context->runAs($tenant, function () use ($device, $source, $tenant, $entity, $claims, $columns, $tmp): array {
                $schema = $this->drift->observe($source->id, $entity, $columns);
                $object = $this->store->storeFile($tenant, 'bridge_batch', $entity, $tmp, self::EXTENSION, [
                    'data_source_id' => $source->id,
                    'content_type' => self::CONTENT_TYPE,
                    'row_count' => (int) ($claims['r'] ?? 0),
                    'schema_fingerprint' => $schema['fingerprint'],
                    'schema_version' => $schema['version'],
                    'received_from_device' => 'bridge:'.$device->id,
                    'metadata' => [
                        'kind' => 'bridge_batch', 'columns' => $schema['columns'], 'drift' => $schema['drift'], 'captured' => 'before_normalization',
                        'bridge' => array_filter([
                            'device_id' => $device->id, 'run_id' => $claims['run'] ?? null, 'seq' => isset($claims['seq']) ? (int) $claims['seq'] : null,
                            'run_mode' => $claims['rm'] ?? null, 'source_timezone' => $claims['tz'] ?? null, 'template_version' => $claims['tv'] ?? null,
                            'processing' => ['status' => 'queued'],
                        ], fn ($v) => $v !== null),
                    ],
                ]);
                if ($object->wasRecentlyCreated) {
                    $this->drift->recordOnSource($tenant->id, $source->id, $schema['drift'] !== null);
                    $this->audit->log('bridge.batch_received', $object, ['device_id' => $device->id, 'entity' => $entity, 'rows' => (int) ($claims['r'] ?? 0), 'size_bytes' => (int) $object->size_bytes, 'sha256_prefix' => substr($object->sha256, 0, 12)], $tenant->id);
                }
                if ((int) $object->data_source_id !== (int) $source->id) {
                    throw new BridgeRejected('Ese contenido ya existe en Bronze para otra fuente de datos de la empresa.', 'batch_conflict', 409);
                }
                $status = (string) ($object->metadata['bridge']['processing']['status'] ?? 'queued');
                if ($object->wasRecentlyCreated || (in_array($status, ['failed', 'queued'], true) && $object->status === 'stored')) {
                    ProcessBridgeBatch::dispatch($tenant->id, $object->id)->onQueue('imports');
                    $created = $object->wasRecentlyCreated;
                    $object = BronzeObject::query()->findOrFail($object->id);   // con cola síncrona el procesamiento ya pudo terminar

                    return ['created' => $created, 'object' => $object];
                }

                return ['created' => $object->wasRecentlyCreated, 'object' => $object];
            });
        } finally {
            @unlink($tmp);
        }
    }

    /** Entidades que un dispositivo puede entregar. @throws BridgeRejected */
    public function assertEntity(string $entity): void
    {
        $allowed = (array) config('bridge.entities', []);
        if (! in_array($entity, $allowed, true) || ImportEntity::tryFrom($entity) === null) {
            throw new BridgeRejected('Entidad no permitida para el Bridge: '.$entity.'.', 'entity_not_allowed', 422, ['allowed' => array_values($allowed)]);
        }
    }

    public function maxBytes(): int
    {
        $mb = min((float) config('bridge.max_batch_mb', 32), (float) config('bronze.max_object_mb', 64));

        return (int) round(max(0.001, $mb) * 1024 * 1024);
    }

    /**
     * Columnas del lote = campos canónicos de la entidad (el agente los renombra en su plantilla de consulta). Rechaza desconocidas y la
     * ausencia de campos obligatorios, para que el error llegue al agente al declarar y no después, en el importador.
     *
     * @param  array<string, mixed>  $columns  columna => tipo observado
     * @return array<string, ?string>
     */
    public function validatedColumns(string $entity, array $columns): array
    {
        $this->assertEntity($entity);
        $definitions = $this->fields->keyed(ImportEntity::from($entity));
        $unknown = array_values(array_diff(array_map('strval', array_keys($columns)), array_keys($definitions)));
        if ($unknown !== [] || $columns === []) {
            throw new BridgeRejected('El lote trae columnas que no son campos canónicos de «'.$entity.'»: '.($unknown === [] ? '(ninguna)' : implode(', ', array_slice($unknown, 0, 10))).'. Renómbrelas en la plantilla de consulta del agente.', 'unknown_columns', 422, ['unknown' => array_slice($unknown, 0, 20), 'accepted' => array_keys($definitions)]);
        }
        $missing = [];
        foreach ($definitions as $key => $definition) {
            if ($definition->required && ! array_key_exists($key, $columns)) {
                $missing[] = $key;
            }
        }
        if ($missing !== []) {
            throw new BridgeRejected('Faltan campos obligatorios de «'.$entity.'» en el lote: '.implode(', ', $missing).'.', 'missing_required_columns', 422, ['missing' => $missing]);
        }
        $out = [];
        foreach ($columns as $name => $type) {
            $out[(string) $name] = is_string($type) && preg_match('/^[a-z_]{1,20}$/', $type) === 1 ? $type : null;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function decodeColumns(string $encoded): array
    {
        try {
            $decoded = json_decode(BridgeSignature::decode($encoded), true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new BridgeRejected('La URL de subida no trae las columnas del lote.', 'upload_url_invalid', 422);
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function assertSourceActive(DataSource $source): void
    {
        if ($source->status === 'inactive') {
            throw new BridgeRejected('La fuente de datos está inactiva.', 'source_inactive', 409);
        }
    }

    private function hasContent(BronzeObject $object): bool
    {
        $name = $object->metadata['disk'] ?? null;
        if (! is_string($name) || config("filesystems.disks.{$name}") === null) {
            $name = (string) config('bronze.disk', 'local');
        }

        return Storage::disk($name)->exists($object->object_key);
    }

    /**
     * Copia el cuerpo a `$path` sin superar el tamaño declarado y comprueba tamaño y SHA-256. Un cuerpo más grande se corta al pasar el
     * tope: nunca se acumula en memoria ni se guarda.
     *
     * @param  resource  $body
     */
    private function spool(mixed $body, string $path, int $size, string $expectedSha): void
    {
        $out = fopen($path, 'wb');
        if ($out === false) {
            throw new BridgeRejected('No se pudo preparar el almacenamiento temporal.', 'storage_unavailable', 503);
        }
        $hash = hash_init('sha256');
        $written = 0;
        try {
            while (! feof($body)) {
                $chunk = fread($body, 65_536);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $written += strlen($chunk);
                if ($written > $size) {
                    throw new BridgeRejected('El cuerpo supera el tamaño declarado del lote.', 'size_mismatch', 413);
                }
                hash_update($hash, $chunk);
                fwrite($out, $chunk);
            }
        } finally {
            fclose($out);
        }
        if ($written !== $size) {
            throw new BridgeRejected('El tamaño recibido no coincide con el declarado.', 'size_mismatch', 422);
        }
        if (! hash_equals($expectedSha, hash_final($hash))) {
            throw new BridgeRejected('El SHA-256 del lote recibido no coincide con el declarado.', 'checksum_mismatch', 422);
        }
    }

    /**
     * Valida el lote COMPLETO en flujo (no solo la primera línea): gzip con su magia, cada línea un objeto JSON con claves ⊆ columnas declaradas (que ya son campos
     * canónicos de la entidad: una columna con PII que aparezca solo en la fila 2 se rechaza), valores escalares y acotados (BridgeBatchReader) y el número de filas
     * IGUAL al declarado. Los tipos declarados son informativos (huella de esquema): un texto en una columna numérica es un dato sucio que el importador manda a
     * revisión (`needs_review`), no un lote estructuralmente inválido. Si algo falla no se guarda nada (el temporal se borra).
     *
     * @param  array<string, ?string>  $columns
     */
    private function validateBatch(string $path, array $columns, int $declaredRows, int $compressedBytes): void
    {
        $rows = 0;
        try {
            foreach (BridgeBatchReader::rows($path, $compressedBytes) as $number => $row) {
                $rows++;
                $extra = array_values(array_diff(array_map('strval', array_keys($row)), array_keys($columns)));
                if ($extra !== []) {
                    throw new BridgeRejected('La fila '.$number.' trae columnas no declaradas ni canónicas: '.implode(', ', array_map(fn ($c) => mb_substr($c, 0, 40), array_slice($extra, 0, 10))).'.', 'unknown_columns', 422, ['row' => $number, 'unknown' => array_map(fn ($c) => mb_substr($c, 0, 40), array_slice($extra, 0, 20))]);
                }
            }
        } catch (BridgeBatchInvalid $e) {
            throw new BridgeRejected($e->getMessage(), $e->errorCode, $e->errorCode === 'batch_too_large' ? 413 : 422);
        }
        if ($rows < 1 || $rows !== $declaredRows) {
            throw new BridgeRejected("El lote trae {$rows} fila(s) y se declararon {$declaredRows}.", 'row_count_mismatch', 422, ['declared' => $declaredRows, 'actual' => $rows]);
        }
    }

    /** Cuota diaria de bytes subidos por la empresa (B-12; 0 = sin tope): un dispositivo comprometido no puede llenar Bronze. 429 con `Retry-After` hasta el reinicio (00:00 UTC). */
    private function assertDailyQuota(int $incomingBytes): void
    {
        $quota = (int) config('bridge.tenant_daily_quota_mb', 4096) * 1024 * 1024;
        if ($quota <= 0) {
            return;
        }
        $resets = now()->utc()->addDay()->startOfDay();
        $used = (int) BronzeObject::query()->where('kind', 'bridge_batch')->where('received_at', '>=', now()->utc()->startOfDay())->sum('size_bytes');
        if ($used + $incomingBytes > $quota) {
            throw new BridgeRejected('La empresa agotó su cuota diaria de subida del Bridge; el agente reintentará después del reinicio (00:00 UTC).', 'quota_exceeded', 429,
                ['used_bytes' => $used, 'quota_bytes' => $quota, 'resets_at' => $resets->toIso8601String(), 'retry_after' => max(60, (int) now()->diffInSeconds($resets, true))]);
        }
    }
}
