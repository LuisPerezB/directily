<?php

declare(strict_types=1);

namespace App\Services\DataCore\Quality;

use App\Exceptions\DomainStateException;
use App\Models\ImportJob;
use App\Models\QuarantineRecord;
use App\Models\SourceMapping;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Catalog\CatalogResolver;
use App\Services\Imports\Parsing\ValueParser;
use App\Services\Imports\Processors\ImportRunContext;
use App\Services\Plans\PlanLimitService;
use App\Services\Sales\IngestionModeResolver;
use App\Services\Sales\SalesDocumentAssembler;
use App\Services\Sales\SalesDocumentWriter;
use App\Services\Sales\SalesLineNormalizer;
use App\Support\Privacy\CustomerIdentity;
use App\Support\Privacy\PiiMasker;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Cuarentena de documentos de venta (ADR-V25-24; W2-A).
 *
 * RETENER (`retain`): con `skip_invalid_rows`, un documento con hallazgos REVIEW_REQUIRED o QUARANTINED NO se descarta: sus filas
 * normalizadas y MINIMIZADAS (campos canónicos con los mapeos de valores ya aplicados; sin nombres de clientes; el código de cliente tal
 * como queda en `customers`) se guardan en `quarantine_records`, enlazadas al objeto Bronze del original. Por lotes, sin consultas por
 * documento: una pasada auxiliar sobre el archivo recoge las filas de los documentos retenidos.
 *
 * LIBERAR (`release`): revalida con los mapeos de valores VIGENTES (la versión aprobada de la fuente SQL, los del import y los que
 * se agreguen en la misma llamada); si es válido, lo escribe con `SalesDocumentWriter` en modo `incremental` (conocido desde la
 * liberación, no desde su día de negocio: no reescribe el pasado) con el linaje del objeto Bronze; si no, 422 con lo que falta.
 * DESCARTAR (`discard`): exige un motivo y deja el registro y su resolución para auditoría.
 */
final class QuarantineService
{
    public function __construct(
        private readonly SalesDocumentWriter $writer,
        private readonly AuditLogger $audit,
        private readonly PlanLimitService $plans,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array<string, array{rows: list<int>, codes: list<string>, numbered: bool}>  $groups  clave del documento => filas y códigos de error
     * @return int registros retenidos
     */
    public function retain(ImportRunContext $ctx, array $groups): int
    {
        if ($groups === []) {
            return 0;
        }
        $wanted = [];
        foreach ($groups as $key => $group) {
            foreach ($group['rows'] as $row) {
                $wanted[$row] = $key;
            }
        }
        $payloadRows = [];
        $customers = [];
        $remaining = count($wanted);
        foreach ($ctx->canonicalRows(false) as $n => $raw) {
            if (! isset($wanted[$n])) {
                continue;
            }
            [$fields, $identity] = $this->minimize($raw);
            $payloadRows[$wanted[$n]][] = ['source_row' => $n, 'fields' => $fields];
            if ($identity !== null) {
                $customers[$wanted[$n]][$identity['external_code']] = ['customer_type' => $identity['customer_type'], 'tax_id_hash' => $identity['tax_id_hash']];
            }
            if (--$remaining === 0) {
                break;
            }
        }

        $tenantId = $ctx->tenant->id;
        // Reintento de la misma confirmación: los pendientes de este import se reemplazan (los ya resueltos no se tocan).
        DB::table('quarantine_records')->where('tenant_id', $tenantId)->where('import_job_id', $ctx->job->id)->where('status', 'pending')->delete();

        // Clave del registro: la del documento o, sin número, `sin_numero|fila N` (la fila de origen es estable para el mismo original).
        $keys = [];
        foreach ($groups as $key => $group) {
            $keys[$key] = $group['numbered'] ? mb_substr((string) $key, 0, 200) : 'sin_numero|fila '.($group['rows'][0] ?? 0);
        }
        // Reprocesar el MISMO original (Bronze) no vuelve a retener lo que ya tiene un registro en CUALQUIER estado: ni lo pendiente (duplicado),
        // ni lo liberado o superado (ya está en Silver o lo reemplazó otra carga), ni lo descartado (un descarte no se reabre).
        $already = [];
        if ($ctx->bronze !== null) {
            $already = DB::table('quarantine_records')->where('tenant_id', $tenantId)->where('bronze_object_id', $ctx->bronze->id)
                ->whereIn('record_key', array_values($keys))->pluck('record_key')->flip()->all();
        }
        $now = now();
        $rows = [];
        foreach ($groups as $key => $group) {
            if (isset($already[$keys[$key]])) {
                continue;
            }
            $rows[] = [
                'tenant_id' => $tenantId,
                'bronze_object_id' => $ctx->bronze?->id,
                'import_job_id' => $ctx->job->id,
                'source_sync_id' => $ctx->sync?->id,
                'entity' => $ctx->job->entity_type,
                'record_key' => $keys[$key],
                'payload' => json_encode([
                    'version' => 1, 'entity' => $ctx->job->entity_type, 'rows' => $payloadRows[$key] ?? [],
                    'customers' => $customers[$key] ?? new \stdClass,
                ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                'issue_codes' => json_encode(array_values($group['codes'])),
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('quarantine_records')->insert($chunk);
        }

        return count($rows);
    }

    /** Campos descriptivos de texto libre: una columna mal mapeada puede traer ahí una identificación, así que pasan por PiiMasker. */
    private const FREE_TEXT_FIELDS = ['document_type', 'status', 'channel', 'customer_segment', 'customer_province', 'product_name', 'category', 'currency'];

    /**
     * Fila canónica minimizada para el payload: texto acotado, sin valores no escalares ni nombres de clientes; el código de cliente en la
     * forma en que `customers` lo guarda (HMAC para cédula/RUC; un código de formato libre como «CI 1710034065» se guarda igual que en
     * `customers.external_code`: es la excepción documentada) y los campos descriptivos de texto libre enmascarados.
     *
     * @param  array<string, mixed>  $raw
     * @return array{0: array<string, string>, 1: ?array{external_code: string, customer_type: string, tax_id_hash: ?string}}
     */
    private function minimize(array $raw): array
    {
        $fields = [];
        $identity = null;
        foreach ($raw as $field => $value) {
            if ($value instanceof DateTimeInterface) {
                $value = $value->format('Y-m-d H:i:s');
            }
            if ($value === null || ! is_scalar($value) || (is_string($value) && trim($value) === '') || in_array($field, ['customer_name', 'customer_email', 'customer_phone'], true)) {
                continue;
            }
            $text = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            if ($field === 'customer_code') {
                $identity = CustomerIdentity::fromCode($text);
                $text = $identity['external_code'];
            }
            $text = trim($text);
            $fields[(string) $field] = mb_substr(in_array($field, self::FREE_TEXT_FIELDS, true) ? PiiMasker::mask($text) : $text, 0, 255);
        }

        return [$fields, $identity];
    }

    /**
     * Revalida y, si es válido, escribe el documento.
     *
     * @param  array<string, mixed>  $extraMaps  mapeos de valores nuevos ({campo: {crudo: canónico}}), que se validan y persisten en el import
     * @param  bool  $force  confirma explícitamente escribir sobre una versión VIGENTE más nueva del mismo documento (queda auditado)
     * @return array{state: string, documents: int, states: array<string, string>, value_maps_added: bool, forced: bool}
     *
     * @throws DomainStateException|QuarantineStillInvalidException
     */
    public function release(QuarantineRecord $record, ?User $user, array $extraMaps = [], bool $force = false): array
    {
        $tenant = $this->context->require();
        $errors = [];
        $extra = $extraMaps === [] ? [] : ValueMaps::validate($extraMaps, 'sales', $errors, 'value_maps', ValueMaps::VOCABULARY_FIELDS);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($record, $user, $tenant, $extra, $force): array {
            $locked = QuarantineRecord::query()->lockForUpdate()->findOrFail($record->id);
            if ($locked->status !== 'pending') {
                throw new DomainStateException('El registro ya fue resuelto.', 'invalid_state', ['status' => $locked->status]);
            }
            if ($locked->entity !== 'sales') {
                throw new DomainStateException('Solo se pueden liberar documentos de ventas.', 'unsupported_entity', ['entity' => $locked->entity]);
            }
            $job = $locked->import_job_id !== null ? ImportJob::query()->find($locked->import_job_id) : null;
            $options = array_merge(ImportRunContext::defaultOptions(), $job?->options ?? []);
            $maps = new ValueMaps(ValueMaps::merge($this->activeMappingMaps($job), (array) ($options['value_maps'] ?? []), $extra));

            $parser = new ValueParser($tenant->timezone ?: 'America/Guayaquil', $options);
            $normalizer = new SalesLineNormalizer($parser, $tenant->currency ?: 'USD', $tenant->timezone ?: 'America/Guayaquil');
            $catalog = new CatalogResolver($tenant, $options);
            $assembler = new SalesDocumentAssembler;
            $payload = (array) $locked->payload;
            $findings = [];
            $invalid = [];
            $firstRows = [];
            foreach ((array) ($payload['rows'] ?? []) as $row) {
                $number = (int) ($row['source_row'] ?? 0);
                $raw = (array) ($row['fields'] ?? []);
                foreach ($maps->fields() as $field) {
                    if (array_key_exists($field, $raw)) {
                        $raw[$field] = $maps->apply($field, $raw[$field], false);
                    }
                }
                $key = $normalizer->documentKey($raw);
                $firstRows[$key ?? ''] ??= $number;
                [$line, $issues] = $normalizer->normalize($raw);
                if ($line !== null) {
                    [$branchCode, $branchIssues] = $catalog->checkBranch($line['branch_code']);
                    $issues = array_merge($issues, $branchIssues, $branchCode !== null ? $catalog->checkProduct($line['product_sku'], 'product_sku', $line['sale_date']) : []);
                    $line['branch_code'] = $branchCode;
                    $line['source_row'] = $number;
                    if (count(array_filter($issues, fn ($i) => $i->isError())) === 0) {
                        $issues = array_merge($issues, $assembler->add($line));
                    }
                }
                foreach ($issues as $issue) {
                    if ($issue->isError()) {
                        [$code, $class] = DataQualityCatalog::resolve($issue);
                        $findings[] = ['source_row' => $number, 'code' => $code, 'dq_class' => $class, 'field' => $issue->column, 'message' => $issue->message];
                    }
                }
                if ($key !== null && count(array_filter($issues, fn ($i) => $i->isError())) > 0) {
                    $assembler->markInvalid($key);
                    $invalid[$key] = true;
                }
            }
            $documents = $assembler->drain();
            if ($findings !== [] || $documents === [] || $invalid !== []) {
                throw new QuarantineStillInvalidException($findings !== [] ? $findings : [['source_row' => null, 'code' => 'empty_document', 'dq_class' => DataQualityCatalog::WARNING, 'field' => null, 'message' => 'El registro no tiene líneas válidas.']]);
            }
            if ($catalog->productsToCreate() > 0) {
                $this->plans->assertCan($tenant, 'max_active_products', $catalog->productsToCreate());
            }
            // `source_row` = la primera fila del documento en el origen (también si su primera fila fue una línea de cantidad cero que se omite).
            foreach ($documents as $i => $doc) {
                $documents[$i]['source_row'] = $firstRows[$doc['doc_key']] ?? ($doc['source_row'] ?? null);
            }

            // Nunca se escribe en silencio sobre una versión MÁS NUEVA: si el documento ya está en Silver con otro contenido, se escribió después de
            // que este registro entró a la cuarentena y no hay orden de fuente que decida (`source_recorded_at` en alguna de las dos), la liberación
            // se detiene con 409 y la versión vigente; sólo `force = true` la sobrescribe (auditado). Con orden de fuente manda la regla de W1
            // (`stale_skipped` / actualización) y con una versión guardada ANTES de la cuarentena la liberación es la actualización esperada.
            $newer = $this->newerVersions($tenant, $documents, $locked);
            if ($newer !== [] && ! $force) {
                throw new DomainStateException(
                    'El documento ya existe en Silver con otro contenido y se escribió DESPUÉS de que este registro entró a la cuarentena: liberarlo lo sobrescribiría. Descártelo o libérelo con force=true.',
                    'newer_version_exists',
                    ['current_versions' => $newer, 'quarantined_at' => $locked->created_at?->toIso8601String(), 'hint' => 'force=true confirma explícitamente la sobrescritura (queda auditada)'],
                );
            }
            $this->ensureCustomers($tenant->id, (array) ($payload['customers'] ?? []), $catalog, $documents);

            $written = $this->writer->write($tenant, $documents, $catalog, [
                'import_job_id' => $job?->id,
                'data_source_id' => $job?->data_source_id,
                'ingestion_mode' => IngestionModeResolver::INCREMENTAL, // conocido desde la liberación, no desde su día de negocio
                'bronze_object_id' => $locked->bronze_object_id,
            ]);
            $this->plans->recordUsage($tenant, 'import_rows', (int) $written['lines_written']);

            $state = (string) (array_values($written['states'])[0] ?? 'new');
            $forced = $newer !== [] && $force;
            $payload['resolution'] = array_filter([
                'action' => 'released', 'by' => $user?->id, 'at' => now()->toIso8601String(), 'write_state' => $state,
                'value_maps_added' => $extra !== [] ? $extra : null,
                'forced_over_newer_version' => $forced ? $newer : null,
            ], fn ($v) => $v !== null);
            $locked->forceFill(['status' => 'released', 'resolved_by' => $user?->id, 'resolved_at' => now(), 'payload' => $payload])->save();
            $this->resolveIssues($locked, 'reprocessed');
            if ($extra !== [] && $job !== null) {
                $job->forceFill(['options' => array_merge($job->options ?? [], ['value_maps' => ValueMaps::merge((array) ($options['value_maps'] ?? []), $extra)])])->save();
            }
            // El documento ya está en Silver: otros registros pendientes del mismo documento quedan superados por esta liberación.
            $this->supersedePending($tenant->id, array_column($documents, 'doc_key'), 'released_record', exceptId: $locked->id);
            $this->audit->log('quarantine.released', $locked, [
                'entity' => $locked->entity, 'record_key' => $locked->record_key, 'write_state' => $state, 'value_maps_added' => $extra !== [],
                'forced_over_newer_version' => $forced, 'overwritten_documents' => $forced ? array_column($newer, 'document_number') : null,
            ], userId: $user?->id);

            return ['state' => $state, 'documents' => count($documents), 'states' => $written['states'], 'value_maps_added' => $extra !== [], 'forced' => $forced];
        });
    }

    /** Descarta un registro (motivo obligatorio): no se escribe en Silver; el registro y su motivo quedan para auditoría. */
    public function discard(QuarantineRecord $record, ?User $user, string $reason): QuarantineRecord
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('El motivo del descarte es obligatorio.');
        }

        return DB::transaction(function () use ($record, $user, $reason): QuarantineRecord {
            $locked = QuarantineRecord::query()->lockForUpdate()->findOrFail($record->id);
            if ($locked->status !== 'pending') {
                throw new DomainStateException('El registro ya fue resuelto.', 'invalid_state', ['status' => $locked->status]);
            }
            $payload = (array) $locked->payload;
            $payload['resolution'] = ['action' => 'discarded', 'by' => $user?->id, 'at' => now()->toIso8601String(), 'reason' => mb_substr($reason, 0, 500)];
            $locked->forceFill(['status' => 'discarded', 'resolved_by' => $user?->id, 'resolved_at' => now(), 'payload' => $payload])->save();
            $this->resolveIssues($locked, 'rejected');
            $this->audit->log('quarantine.discarded', $locked, ['entity' => $locked->entity, 'record_key' => $locked->record_key, 'reason' => mb_substr($reason, 0, 500)], userId: $user?->id);

            return $locked;
        });
    }

    /** Hallazgos del registro (por su referencia) en los informes de su importación: pasan a `reprocessed` o `rejected`. */
    private function resolveIssues(QuarantineRecord $record, string $status): void
    {
        if ($record->import_job_id === null) {
            return;
        }
        DB::table('data_quality_issues')
            ->where('tenant_id', $record->tenant_id)->where('record_ref', $record->record_key)->where('status', 'open')
            ->whereIn('report_id', DB::table('data_quality_reports')->where('tenant_id', $record->tenant_id)->where('import_job_id', $record->import_job_id)->select('id'))
            ->update(['status' => $status, 'resolved_by' => $record->resolved_by, 'resolved_at' => now()]);
    }

    /**
     * Mapeos de valores de la versión APROBADA y activa del mapeo SQL de ventas de la fuente (los que aplicaría hoy una sincronización).
     *
     * @return array<string, mixed>
     */
    private function activeMappingMaps(?ImportJob $job): array
    {
        if ($job?->data_source_id === null) {
            return [];
        }
        $mapping = SourceMapping::query()->where('data_source_id', $job->data_source_id)->where('entity', 'sales')->where('is_active', true)
            ->whereNotNull('approved_at')->orderByDesc('version')->first();

        return $mapping !== null ? (array) (((array) $mapping->options)['value_maps'] ?? []) : [];
    }

    /**
     * Documentos que ya están en Silver con otro contenido, escritos DESPUÉS de que el registro entró a la cuarentena y sin orden de fuente
     * que permita decidir cuál es la más nueva. Devuelve la versión vigente de cada uno (sin datos personales).
     *
     * @param  list<array<string, mixed>>  $documents
     * @return list<array<string, mixed>>
     */
    private function newerVersions(Tenant $tenant, array $documents, QuarantineRecord $record): array
    {
        $classes = $this->writer->classify($tenant, $documents, IngestionModeResolver::INCREMENTAL);
        $ids = [];
        foreach ($documents as $doc) {
            $class = $classes[$doc['doc_key']] ?? null;
            if ($class !== null && $class['state'] === SalesDocumentWriter::STATE_UPDATED && $class['without_order'] && $class['stored'] !== null
                && $record->created_at !== null && CarbonImmutable::parse((string) $class['stored']->updated_at)->greaterThan($record->created_at)) {
                $ids[] = (int) $class['id'];
            }
        }
        if ($ids === []) {
            return [];
        }

        return DB::table('sales')->where('tenant_id', $tenant->id)->whereIn('id', $ids)->orderBy('id')
            ->get(['id', 'document_type', 'document_number', 'status', 'issued_at', 'sale_date', 'total', 'current_revision', 'ingestion_mode', 'source_recorded_at', 'updated_at'])
            ->map(fn (object $s) => [
                'id' => (int) $s->id, 'document_type' => $s->document_type, 'document_number' => $s->document_number, 'status' => $s->status,
                'issued_at' => $s->issued_at, 'sale_date' => $s->sale_date, 'total' => $s->total, 'current_revision' => (int) $s->current_revision,
                'ingestion_mode' => $s->ingestion_mode, 'source_recorded_at' => $s->source_recorded_at, 'written_at' => $s->updated_at,
            ])->all();
    }

    /**
     * Marca como SUPERADOS los registros PENDIENTES de cuarentena de documentos que acaban de quedar bien en Silver (una carga posterior
     * trajo el documento corregido, o se liberó otro registro del mismo documento): liberarlos sobrescribiría una versión más nueva. Por
     * lotes (una sentencia por 1000 claves). La clave es la del documento (`tipo|NÚMERO`); un registro de tipo no reconocido
     * (`unknown|NÚMERO`) no se supera solo: el mismo número puede ser de otro tipo o serie.
     *
     * @param  list<string>  $documentKeys
     * @param  string  $reason  `later_load` (carga posterior) | `released_record` (otra liberación)
     * @return int registros superados
     */
    public function supersedePending(int $tenantId, array $documentKeys, string $reason, ?int $exceptId = null): int
    {
        $keys = array_values(array_unique(array_map(fn (string $k) => mb_substr($k, 0, 200), $documentKeys)));
        $count = 0;
        foreach (array_chunk($keys, 1000) as $chunk) {
            $resolution = json_encode(['action' => 'superseded', 'reason' => $reason, 'at' => now()->toIso8601String()]);
            $count += DB::affectingStatement(
                "UPDATE quarantine_records SET status = 'superseded', resolved_at = ?, updated_at = ?, payload = jsonb_set(payload, '{resolution}', ?::jsonb)"
                .' WHERE tenant_id = ? AND entity = ? AND status = ? AND record_key IN ('.implode(',', array_fill(0, count($chunk), '?')).')'
                .($exceptId !== null ? ' AND id <> ?' : ''),
                [now(), now(), $resolution, $tenantId, 'sales', 'pending', ...$chunk, ...($exceptId !== null ? [$exceptId] : [])],
            );
        }

        return $count;
    }

    /**
     * Asegura la identidad de cliente del payload con su tipo y huella (el código ya está en la forma de `customers`). Un código que tiene un
     * alias vigente (`customer_aliases`) NO se pre-inserta: lo resuelve `CatalogResolver` hacia el cliente canónico al escribir y crear otra
     * identidad «id:…» sería la duplicación que el alias existe para evitar.
     *
     * @param  array<string, array{customer_type?: string, tax_id_hash?: ?string}>  $customers
     * @param  list<array<string, mixed>>  $documents
     */
    private function ensureCustomers(int $tenantId, array $customers, CatalogResolver $catalog, array $documents): void
    {
        if ($customers === []) {
            return;
        }
        $dates = [];
        foreach ($documents as $doc) {
            if ($doc['customer_code'] !== null) {
                $dates[$doc['customer_code']][$doc['sale_date']] = true;
            }
        }
        $now = now();
        $rows = [];
        foreach ($customers as $code => $info) {
            $aliased = isset($dates[$code]) && array_reduce(array_keys($dates[$code]), fn (bool $all, string $date) => $all && $catalog->customerIdByAlias((string) $code, $date) !== null, true);
            if ($aliased) {
                continue;
            }
            $rows[] = [
                'tenant_id' => $tenantId,
                'external_code' => $code,
                'customer_type' => $info['customer_type'] ?? 'unknown',
                'is_generic' => ($info['customer_type'] ?? null) === 'final_consumer',
                'tax_id_hash' => $info['tax_id_hash'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        if ($rows !== []) {
            DB::table('customers')->insertOrIgnore($rows);
        }
    }
}
