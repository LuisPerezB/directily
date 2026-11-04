<?php

declare(strict_types=1);

namespace App\Services\DataCore\Mapping\Templates;

use App\Enums\TenantRole;
use App\Models\DataSource;
use App\Models\MappingTemplate;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Connectors\Sql\SqlQueryRejected;
use App\Services\DataCore\Mapping\Discovery\DiscoveredTable;
use App\Services\DataCore\Mapping\Discovery\SchemaDiscoveryService;
use App\Services\DataCore\Mapping\Discovery\TypeClass;
use App\Services\DataCore\Mapping\Heuristics\EntityProposal;
use App\Services\DataCore\Mapping\Heuristics\FieldScorer;
use App\Services\DataCore\Mapping\Heuristics\Lexicon;
use App\Services\DataCore\Mapping\Heuristics\NameTokens;
use App\Services\DataCore\Mapping\Heuristics\PiiColumns;
use App\Services\DataCore\Mapping\Heuristics\TableRoles;
use App\Services\DataCore\Quality\ValueMaps;
use App\Services\Imports\Fields\ImportFieldRegistry;
use App\Services\Imports\ImportEntity;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Ciclo de vida de las plantillas de mapeo (V2.5 §4): propuesta → corrección humana → aprobación → retiro, con versiones por (empresa o plataforma,
 * sistema, proveedor, entidad).
 *
 * - Una plantilla PÚBLICA (tenant_id NULL) la crea, edita, aprueba y retira solo la plataforma (`$asPlatform = true`); las empresas la leen, la aplican a sus
 *   fuentes o la bifurcan (fork) en una propia, nunca la modifican (403 `public_template_read_only`).
 * - Solo una plantilla en estado `proposed` se edita; para cambiar una aprobada se crea otra versión (`revise`), que nace propuesta y, al aprobarse, retira la
 *   anterior. A lo sumo una plantilla aprobada por clave y fuente (índice único parcial).
 * - Aprobar es una acción HUMANA con habilidad (settings.manage) y queda auditada; exige los campos obligatorios mapeados y una consulta que pase la política
 *   de solo lectura. Nada en este servicio aprueba por sí solo.
 */
final class MappingTemplateService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SourceQueryBuilder $queries,
        private readonly ImportFieldRegistry $fields,
        private readonly TenantContext $context,
        private readonly Lexicon $lexicon,
        private readonly SchemaDiscoveryService $discovery,
    ) {}

    /** Campos que identifican registros: una constante para todos los registros no tiene sentido (y lo borra todo en una sola clave). */
    public const IDENTITY_FIELDS = ['document_number', 'product_sku', 'sku', 'code', 'snapshot_date', 'external_id', 'customer_code', 'supplier_code'];

    /**
     * Guarda una propuesta como plantilla de la empresa (versión nueva en estado `proposed`). Si la última versión de la misma clave y fuente tiene
     * exactamente el mismo contenido (mapa, valores, estructura), no crea otra: la devuelve.
     *
     * @return array{template: MappingTemplate, created: bool}
     */
    public function record(EntityProposal $proposal, DataSource $source, string $systemKind, ?string $vendor, ?User $user): array
    {
        $tenant = $this->context->require();
        $vendor = self::vendor($vendor);
        $referenced = SourceSpec::referenced($proposal->spec, $proposal->fieldMap);
        $fingerprint = SourceSpec::fingerprint((string) ($proposal->spec['dialect'] ?? ''), $referenced);

        return DB::transaction(function () use ($proposal, $source, $systemKind, $vendor, $user, $tenant, $fingerprint): array {
            $this->lock($tenant->id, $systemKind, $vendor, $proposal->entity);
            $latest = $this->keyQuery($tenant->id, $systemKind, $vendor, $proposal->entity)->where('data_source_id', $source->id)->where('status', '!=', MappingTemplate::STATUS_RETIRED)->orderByDesc('version')->first();
            if ($latest !== null && $this->sameContent($latest, $proposal->fieldMap, $proposal->valueMaps, $proposal->spec, $fingerprint)) {
                // Mismo contenido: no hay otra versión. Una propuesta aún sin aprobar refresca su evidencia (puntajes, advertencias, muestras) para no mostrar la de una lectura anterior.
                if ($latest->status === MappingTemplate::STATUS_PROPOSED && self::canonicalJson((array) $latest->confidence) !== self::canonicalJson($proposal->confidenceDocument())) {
                    $latest->forceFill(['confidence' => $proposal->confidenceDocument()])->save();
                }

                return ['template' => $latest, 'created' => false];
            }
            $template = new MappingTemplate;
            $template->forceFill([
                'tenant_id' => $tenant->id, 'system_kind' => $systemKind, 'vendor' => $vendor, 'entity' => $proposal->entity,
                'version' => $this->nextVersion($tenant->id, $systemKind, $vendor, $proposal->entity), 'schema_fingerprint' => $fingerprint,
                'field_map' => $proposal->fieldMap, 'value_maps' => $proposal->valueMaps, 'confidence' => $proposal->confidenceDocument(), 'source_spec' => $proposal->spec,
                'status' => MappingTemplate::STATUS_PROPOSED, 'data_source_id' => $source->id, 'created_by' => $user?->id,
            ])->save();
            $this->audit->log('mapping.template_proposed', $template, [
                'entity' => $proposal->entity, 'version' => $template->version, 'data_source_id' => $source->id, 'complete' => $proposal->complete,
                'mapped_fields' => count($proposal->fieldMap), 'unmapped_fields' => count($proposal->unmapped), 'missing_required' => $proposal->missingRequired(),
            ], userId: $user?->id);

            return ['template' => $template, 'created' => true];
        });
    }

    /**
     * Corrección humana de una plantilla propuesta: campos (alias.columna, {"const": valor} o null para quitar) y mapeos de valores.
     *
     * @param  array{field_map?: array<string, mixed>, value_maps?: array<string, mixed>}  $changes
     *
     * @throws MappingTemplateException|ValidationException
     */
    public function update(MappingTemplate $template, array $changes, User $user, bool $asPlatform = false): MappingTemplate
    {
        $this->assertOwned($template, $asPlatform);
        if ($template->status !== MappingTemplate::STATUS_PROPOSED) {
            throw new MappingTemplateException('Solo una plantilla propuesta se puede editar; cree otra versión para cambiar una aprobada.', 'template_not_editable', 409);
        }

        return DB::transaction(function () use ($template, $changes, $user): MappingTemplate {
            $before = array_keys((array) $template->field_map);
            $changed = $this->applyChanges($template, $changes);
            $template->forceFill(['schema_fingerprint' => $this->fingerprintOf($template), 'invalidated_at' => null, 'drift' => null])->save();
            $this->audit->log('mapping.template_edited', $template, ['entity' => $template->entity, 'version' => $template->version, 'fields_changed' => $changed, 'fields_before' => count($before)], userId: $user->id);

            return $template;
        });
    }

    /**
     * Aprobación humana. Retira la versión aprobada anterior de la misma clave y fuente.
     *
     * @throws MappingTemplateException
     */
    public function approve(MappingTemplate $template, User $user, bool $asPlatform = false): MappingTemplate
    {
        $this->assertOwned($template, $asPlatform);
        if ($template->status !== MappingTemplate::STATUS_PROPOSED) {
            throw new MappingTemplateException('Solo una plantilla propuesta se puede aprobar.', 'template_not_proposed', 409);
        }
        $this->assertSegregationOfDuties($template, $user, $asPlatform);
        $missing = $this->missingRequired($template);
        if ($missing !== []) {
            throw new MappingTemplateException('Faltan campos obligatorios por mapear: '.implode(', ', $missing).'.', 'template_incomplete', 422, ['missing_required' => $missing]);
        }
        $this->assertQueryAllowed($template);
        $this->assertNoSchemaDrift($template);

        return DB::transaction(function () use ($template, $user): MappingTemplate {
            $this->lock($template->tenant_id, $template->system_kind, $template->vendor, $template->entity);
            $previous = $this->keyQuery($template->tenant_id, $template->system_kind, $template->vendor, $template->entity)
                ->where('status', MappingTemplate::STATUS_APPROVED)->where('id', '!=', $template->id)
                ->when($template->tenant_id !== null, fn (Builder $q) => $template->data_source_id === null ? $q->whereNull('data_source_id') : $q->where('data_source_id', $template->data_source_id))
                ->get();
            foreach ($previous as $old) {
                $old->forceFill(['status' => MappingTemplate::STATUS_RETIRED])->save();
                $this->audit->log('mapping.template_retired', $old, ['entity' => $old->entity, 'version' => $old->version, 'reason' => 'superseded', 'superseded_by' => $template->id], tenantId: $old->tenant_id, userId: $user->id);
            }
            $template->forceFill(['status' => MappingTemplate::STATUS_APPROVED, 'approved_by' => $user->id, 'approved_at' => now(), 'invalidated_at' => null, 'drift' => null])->save();
            $this->audit->log('mapping.template_approved', $template, [
                'entity' => $template->entity, 'version' => $template->version, 'system_kind' => $template->system_kind, 'vendor' => $template->vendor,
                'scope' => $template->tenant_id === null ? 'platform' : 'tenant', 'schema_fingerprint_prefix' => self::short($template->schema_fingerprint), 'data_source_id' => $template->data_source_id,
            ], tenantId: $template->tenant_id, userId: $user->id);

            return $template;
        });
    }

    /** @throws MappingTemplateException */
    public function retire(MappingTemplate $template, User $user, bool $asPlatform = false): MappingTemplate
    {
        $this->assertOwned($template, $asPlatform);
        if ($template->status === MappingTemplate::STATUS_RETIRED) {
            throw new MappingTemplateException('La plantilla ya está retirada.', 'template_already_retired', 409);
        }
        $template->forceFill(['status' => MappingTemplate::STATUS_RETIRED])->save();
        $this->audit->log('mapping.template_retired', $template, ['entity' => $template->entity, 'version' => $template->version, 'reason' => 'manual'], tenantId: $template->tenant_id, userId: $user->id);

        return $template;
    }

    /**
     * Otra versión de la misma clave: copia el contenido, aplica las correcciones y nace propuesta (hay que aprobarla). La anterior no cambia hasta entonces.
     *
     * @param  array{field_map?: array<string, mixed>, value_maps?: array<string, mixed>}  $changes
     *
     * @throws MappingTemplateException|ValidationException
     */
    public function revise(MappingTemplate $template, array $changes, User $user, bool $asPlatform = false): MappingTemplate
    {
        $this->assertOwned($template, $asPlatform);

        return DB::transaction(function () use ($template, $changes, $user): MappingTemplate {
            $this->lock($template->tenant_id, $template->system_kind, $template->vendor, $template->entity);
            $copy = new MappingTemplate;
            $copy->forceFill([
                'tenant_id' => $template->tenant_id, 'system_kind' => $template->system_kind, 'vendor' => $template->vendor, 'entity' => $template->entity,
                'version' => $this->nextVersion($template->tenant_id, $template->system_kind, $template->vendor, $template->entity),
                'field_map' => $template->field_map, 'value_maps' => $template->value_maps, 'confidence' => $template->confidence, 'source_spec' => $template->source_spec,
                'schema_fingerprint' => $template->schema_fingerprint, 'status' => MappingTemplate::STATUS_PROPOSED, 'data_source_id' => $template->data_source_id,
                'forked_from_id' => $template->forked_from_id, 'created_by' => $user->id,
            ]);
            $this->applyChanges($copy, $changes);
            $copy->forceFill(['schema_fingerprint' => $this->fingerprintOf($copy)])->save();
            $this->audit->log('mapping.template_revised', $copy, ['entity' => $copy->entity, 'version' => $copy->version, 'from_version' => $template->version, 'from_template_id' => $template->id], tenantId: $copy->tenant_id, userId: $user->id);

            return $copy;
        });
    }

    /**
     * Copia de una plantilla pública aprobada como plantilla propia de la empresa (propuesta: hay que aprobarla).
     *
     * @throws MappingTemplateException
     */
    public function fork(MappingTemplate $public, ?DataSource $source, User $user): MappingTemplate
    {
        $tenant = $this->context->require();
        if (! $public->isPublic()) {
            throw new MappingTemplateException('Solo se puede bifurcar una plantilla pública de la plataforma.', 'template_not_public', 422);
        }
        if ($public->status !== MappingTemplate::STATUS_APPROVED) {
            throw new MappingTemplateException('Solo se puede bifurcar una plantilla pública aprobada.', 'template_not_approved', 409);
        }

        return DB::transaction(function () use ($public, $source, $user, $tenant): MappingTemplate {
            $this->lock($tenant->id, $public->system_kind, $public->vendor, $public->entity);
            $copy = new MappingTemplate;
            $copy->forceFill([
                'tenant_id' => $tenant->id, 'system_kind' => $public->system_kind, 'vendor' => $public->vendor, 'entity' => $public->entity,
                'version' => $this->nextVersion($tenant->id, $public->system_kind, $public->vendor, $public->entity), 'schema_fingerprint' => $public->schema_fingerprint,
                'field_map' => $public->field_map, 'value_maps' => $public->value_maps, 'confidence' => $public->confidence, 'source_spec' => $public->source_spec,
                'status' => MappingTemplate::STATUS_PROPOSED, 'data_source_id' => $source?->id, 'forked_from_id' => $public->id, 'created_by' => $user->id,
            ])->save();
            $this->audit->log('mapping.template_forked', $copy, ['entity' => $copy->entity, 'version' => $copy->version, 'forked_from_id' => $public->id, 'forked_from_version' => $public->version, 'data_source_id' => $source?->id], userId: $user->id);

            return $copy;
        });
    }

    /**
     * Plantilla pública nueva (solo plataforma). Se valida igual que una propuesta editada: estructura, campos del importador, mapeos de valores.
     *
     * @param  array{system_kind: string, vendor: string, entity: string, source_spec: array<string, mixed>, field_map: array<string, mixed>, value_maps?: array<string, mixed>}  $data
     *
     * @throws ValidationException
     */
    public function createPublic(array $data, User $admin): MappingTemplate
    {
        $vendor = self::vendor($data['vendor']);
        if ($vendor === null) {
            throw ValidationException::withMessages(['vendor' => ['Una plantilla pública debe indicar el proveedor o producto de origen.']]);
        }

        return DB::transaction(function () use ($data, $vendor, $admin): MappingTemplate {
            $this->lock(null, $data['system_kind'], $vendor, $data['entity']);
            $template = new MappingTemplate;
            $template->forceFill([
                'tenant_id' => null, 'system_kind' => $data['system_kind'], 'vendor' => $vendor, 'entity' => $data['entity'],
                'version' => $this->nextVersion(null, $data['system_kind'], $vendor, $data['entity']), 'field_map' => [], 'value_maps' => [], 'source_spec' => $data['source_spec'],
                'confidence' => ['method' => 'platform_reference', 'kind' => 'reference', 'note' => 'Plantilla de referencia publicada por la plataforma; no tiene puntajes heurísticos.', 'fields' => (object) [], 'unmapped' => []],
                'status' => MappingTemplate::STATUS_PROPOSED, 'created_by' => $admin->id,
            ]);
            $this->applyChanges($template, ['field_map' => $data['field_map'], 'value_maps' => $data['value_maps'] ?? []], full: true);
            $template->forceFill(['schema_fingerprint' => $this->fingerprintOf($template)])->save();
            $this->audit->log('mapping.public_template_created', $template, ['entity' => $template->entity, 'vendor' => $vendor, 'version' => $template->version, 'system_kind' => $template->system_kind], tenantId: null, userId: $admin->id);

            return $template;
        });
    }

    /** Campos obligatorios del importador sin mapear en la plantilla. @return list<string> */
    public function missingRequired(MappingTemplate $template): array
    {
        $required = $this->fields->requiredKeys(ImportEntity::from($template->entity));
        $map = (array) $template->field_map;

        // Una constante nula o vacía cuenta como campo SIN mapear: no satisface un campo obligatorio.
        return array_values(array_filter($required, fn (string $key) => ! isset($map[$key]) || (is_array($map[$key]) && self::emptyConst($map[$key]['const'] ?? null))));
    }

    public static function emptyConst(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    /**
     * Consulta que se ejecutaría (metadatos del origen, nunca datos). Null con el motivo si la estructura o la política de solo lectura la rechazan.
     *
     * @return array{sql: ?string, field_map: ?array<string, mixed>, error: ?string}
     */
    public function preview(MappingTemplate $template): array
    {
        try {
            $built = $this->queries->build((array) $template->source_spec, (array) $template->field_map);
            $this->queries->guard($built['sql'], (string) $template->source_spec['dialect']);

            return ['sql' => $built['sql'], 'field_map' => $built['field_map'], 'error' => null];
        } catch (SqlQueryRejected $e) {
            return ['sql' => null, 'field_map' => null, 'error' => 'La consulta generada no cumple la política de solo lectura: '.$e->getMessage()];
        } catch (InvalidArgumentException $e) {
            return ['sql' => null, 'field_map' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Segregación de funciones: quien propuso la plantilla no la aprueba si hay otra persona que pueda hacerlo (otro propietario o administrador de la empresa; otro superadministrador
     * en las públicas). Con una sola persona con esa habilidad se permite (queda `created_by` y `approved_by` en la bitácora).
     *
     * @throws MappingTemplateException
     */
    private function assertSegregationOfDuties(MappingTemplate $template, User $user, bool $asPlatform): void
    {
        if ($template->created_by === null || (int) $template->created_by !== (int) $user->id) {
            return;
        }
        $another = $asPlatform
            ? User::query()->where('is_superadmin', true)->where('id', '!=', $user->id)->exists()
            : DB::table('tenant_users')->where('tenant_id', $template->tenant_id)->whereIn('role', array_map(fn (TenantRole $r) => $r->value, array_filter(TenantRole::cases(), fn (TenantRole $r) => $r->can('settings.manage'))))
                ->where('user_id', '!=', $user->id)->exists();
        if ($another) {
            throw new MappingTemplateException('La persona que propuso la plantilla no la aprueba: debe aprobarla otra persona con la habilidad de ajustes (segregación de funciones).', 'approval_segregation_of_duties', 403);
        }
    }

    /**
     * Antes de aprobar, la plantilla de una fuente se compara con el esquema ACTUAL de esa fuente: no se aprueba lo que ya no coincide (409 `schema_drift_review_required`). Una plantilla
     * sin fuente (copia de una pública sin destino) o pública no se compara aquí: se compara al aplicarla.
     *
     * @throws MappingTemplateException
     */
    private function assertNoSchemaDrift(MappingTemplate $template): void
    {
        if ($template->tenant_id === null || $template->data_source_id === null) {
            return;
        }
        $source = DataSource::query()->find($template->data_source_id);
        if ($source === null) {
            return;
        }
        $schema = $this->discovery->discover($source);
        if ($schema->truncated) {
            throw new MappingTemplateException('El esquema de la fuente supera el tope de descubrimiento y no se puede comparar completo.', 'schema_too_large', 422);
        }
        $diff = SourceSpec::diff((array) $template->source_spec, (array) $template->field_map, $schema);
        if (! $diff['matches']) {
            throw new MappingTemplateException('El esquema de la fuente cambió desde que se propuso la plantilla: proponga de nuevo sobre el esquema actual antes de aprobar.', 'schema_drift_review_required', 409, ['drift' => $diff]);
        }
    }

    /** @throws MappingTemplateException */
    private function assertQueryAllowed(MappingTemplate $template): void
    {
        $preview = $this->preview($template);
        if ($preview['sql'] === null) {
            throw new MappingTemplateException((string) $preview['error'], 'sql_query_rejected', 422);
        }
    }

    /** @throws MappingTemplateException */
    private function assertOwned(MappingTemplate $template, bool $asPlatform): void
    {
        if ($asPlatform && ! $template->isPublic()) {
            throw new MappingTemplateException('Recurso no encontrado.', 'not_found', 404);
        }
        if (! $asPlatform && $template->isPublic()) {
            throw new MappingTemplateException('Las plantillas públicas de la plataforma son de solo lectura: bifurque una en su empresa para adaptarla.', 'public_template_read_only', 403);
        }
        if (! $asPlatform && (int) $template->tenant_id !== $this->context->id()) {
            throw new MappingTemplateException('Recurso no encontrado.', 'not_found', 404);
        }
    }

    /**
     * Aplica correcciones al mapa de campos y a los mapeos de valores, validadas contra el importador, la estructura y la política de datos personales.
     *
     * @param  array<string, mixed>  $changes
     * @return list<string> campos modificados
     *
     * @throws ValidationException
     */
    private function applyChanges(MappingTemplate $template, array $changes, bool $full = false): array
    {
        $entity = ImportEntity::from($template->entity);
        $definitions = $this->fields->keyed($entity);
        $rules = $this->lexicon->rules($entity);
        $spec = (array) $template->source_spec;
        $errors = [];
        $map = $full ? [] : (array) $template->field_map;
        $confidence = (array) $template->confidence;
        $confidence['fields'] = (array) ($confidence['fields'] ?? []);
        $unmapped = array_values((array) ($confidence['unmapped'] ?? []));
        $changed = [];

        foreach ((array) ($changes['field_map'] ?? []) as $field => $source) {
            $field = (string) $field;
            if (! isset($definitions[$field])) {
                $errors["field_map.{$field}"][] = "Campo canónico desconocido: {$field}.";

                continue;
            }
            if ($source === null || $source === '') {
                unset($map[$field], $confidence['fields'][$field]);
                $unmapped = array_values(array_filter($unmapped, fn ($u) => ($u['field'] ?? null) !== $field));
                $unmapped[] = ['field' => $field, 'reason' => 'removed_by_user', 'required' => $definitions[$field]->required, 'candidates' => []];
                $changed[] = $field;

                continue;
            }
            if (is_array($source)) {
                if (! array_key_exists('const', $source) || ! is_scalar($source['const']) || self::emptyConst($source['const']) || (is_string($source['const']) && mb_strlen($source['const']) > 200)) {
                    $errors["field_map.{$field}"][] = 'Use «alias.columna» o {"const": valor} con un valor no vacío (texto de hasta 200 caracteres).';

                    continue;
                }
                if (in_array($field, self::IDENTITY_FIELDS, true)) {
                    $errors["field_map.{$field}"][] = "«{$field}» identifica registros: no admite una constante, debe venir de una columna.";

                    continue;
                }
                $map[$field] = ['const' => $source['const']];
                $confidence['fields'][$field] = ['score' => null, 'band' => 'human', 'evidence' => ['human_override', 'const'], 'source' => 'const'];
            } else {
                $resolved = is_string($source) ? SourceSpec::resolve($spec, $source) : null;
                if ($resolved === null) {
                    $errors["field_map.{$field}"][] = 'La referencia no apunta a una columna de las tablas de la estructura (use «alias.columna»).';

                    continue;
                }
                $tableStub = new DiscoveredTable($resolved['table']['schema'] ?? null, $resolved['table']['name'], $resolved['table']['kind'] ?? 'table', null);
                $tokens = NameTokens::of($resolved['column']);
                $kind = PiiColumns::classify($tokens, TableRoles::detect($tableStub));
                if (! PiiColumns::mappable($kind, $template->entity, $field)) {
                    $errors["field_map.{$field}"][] = 'La columna «'.$resolved['column'].'» parece contener datos personales ('.$kind.') y no se puede mapear a este campo.';

                    continue;
                }
                $type = TypeClass::from($resolved['type']);
                if (isset($rules[$field]) && (new FieldScorer)->typeScore($rules[$field], $type) <= 0.0) {
                    $errors["field_map.{$field}"][] = 'El tipo de la columna «'.$resolved['column'].'» ('.$resolved['type'].') no es compatible con el campo.';

                    continue;
                }
                $map[$field] = $source;
                $confidence['fields'][$field] = ['score' => null, 'band' => 'human', 'evidence' => ['human_override'], 'source' => $source];
            }
            $unmapped = array_values(array_filter($unmapped, fn ($u) => ($u['field'] ?? null) !== $field));
            $changed[] = $field;
        }

        $valueMaps = $full ? [] : (array) $template->value_maps;
        if (array_key_exists('value_maps', $changes)) {
            foreach ((array) $changes['value_maps'] as $field => $values) {
                $field = (string) $field;
                if ($values === null || $values === []) {
                    unset($valueMaps[$field]);
                    $changed[] = 'value_maps.'.$field;

                    continue;
                }
                $clean = ValueMaps::validate([$field => $values], $template->entity, $errors, 'value_maps', array_keys($definitions));
                if (isset($clean[$field])) {
                    $valueMaps[$field] = $clean[$field];
                    $changed[] = 'value_maps.'.$field;
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $specErrors = SourceSpec::validate($spec, $map);
        if ($specErrors !== []) {
            throw ValidationException::withMessages(['source_spec' => $specErrors]);
        }

        $confidence['unmapped'] = $unmapped;
        $template->forceFill(['field_map' => $map, 'value_maps' => $valueMaps, 'confidence' => $confidence]);

        return array_values(array_unique($changed));
    }

    private function fingerprintOf(MappingTemplate $template): string
    {
        $spec = (array) $template->source_spec;

        return SourceSpec::fingerprint((string) ($spec['dialect'] ?? ''), SourceSpec::referenced($spec, (array) $template->field_map));
    }

    /** @param array<string, mixed> $fieldMap @param array<string, mixed> $valueMaps @param array<string, mixed> $spec */
    private function sameContent(MappingTemplate $template, array $fieldMap, array $valueMaps, array $spec, string $fingerprint): bool
    {
        return $template->schema_fingerprint === $fingerprint
            && self::canonicalJson((array) $template->field_map) === self::canonicalJson($fieldMap)
            && self::canonicalJson((array) $template->value_maps) === self::canonicalJson($valueMaps)
            && self::canonicalJson((array) $template->source_spec) === self::canonicalJson($spec);
    }

    private static function canonicalJson(array $value): string
    {
        $sort = function (mixed $v) use (&$sort): mixed {
            if (! is_array($v)) {
                return $v;
            }
            $v = array_map($sort, $v);
            if (! array_is_list($v)) {
                ksort($v);
            }

            return $v;
        };

        return (string) json_encode($sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Prefijo de una huella para la auditoría: la bitácora enmascara las cadenas hexadecimales largas (PiiMasker), así que se guardan los primeros 16 caracteres; la huella completa queda en la plantilla. */
    public static function short(?string $fingerprint): ?string
    {
        return $fingerprint === null ? null : substr($fingerprint, 0, 16);
    }

    /** Proveedor en minúsculas (o null). */
    public static function vendor(?string $vendor): ?string
    {
        $vendor = $vendor === null ? '' : Str::lower(trim($vendor));

        return $vendor === '' ? null : mb_substr($vendor, 0, 80);
    }

    /** Consulta de todas las versiones de una clave (empresa o plataforma, sistema, proveedor, entidad), sin el alcance de visibilidad. */
    private function keyQuery(?int $tenantId, string $systemKind, ?string $vendor, string $entity): Builder
    {
        return MappingTemplate::withoutTenantScope()
            ->when($tenantId === null, fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('system_kind', $systemKind)->where('entity', $entity)
            ->when($vendor === null, fn ($q) => $q->whereNull('vendor'), fn ($q) => $q->where('vendor', $vendor));
    }

    private function nextVersion(?int $tenantId, string $systemKind, ?string $vendor, string $entity): int
    {
        return (int) $this->keyQuery($tenantId, $systemKind, $vendor, $entity)->max('version') + 1;
    }

    /** Serializa versiones concurrentes de la misma clave. */
    private function lock(?int $tenantId, string $systemKind, ?string $vendor, string $entity): void
    {
        DB::statement('SELECT pg_advisory_xact_lock(?, ?)', [7434, (int) (crc32(($tenantId ?? 0).'|'.$systemKind.'|'.($vendor ?? '').'|'.$entity) & 0x7FFFFFFF)]);
    }
}
