<?php

declare(strict_types=1);

namespace App\Services\DataCore\Mapping\Templates;

use App\Models\DataSource;
use App\Models\MappingTemplate;
use App\Models\SourceMapping;
use App\Models\SourceSync;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Connectors\Sql\SqlQueryRejected;
use App\Services\DataCore\Mapping\Discovery\DiscoveredSchema;
use App\Services\DataCore\Mapping\Discovery\SchemaDiscoveryService;
use App\Services\DataCore\Sync\SourceMappingService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Convierte una plantilla APROBADA en un mapeo que consume el pipeline de importación existente (no hay un segundo importador): genera la consulta SELECT,
 * la entrega a SourceMappingService (que la valida con SqlQueryGuard y la versiona) y la deja aprobada y activa como la persona que aplica. No inicia ninguna
 * sincronización: eso sigue siendo una acción propia (POST /data-sources/{id}/sync).
 *
 * Antes de aplicar, compara la huella del esquema de la plantilla con el esquema ACTUAL de la fuente. Si cambió una columna que la plantilla usa (quitada o con
 * otra clase de tipo), no aplica: una plantilla de la empresa vuelve a «propuesta» con el informe de deriva (hay que revisarla y aprobarla otra vez) y una
 * pública queda intacta (es de solo lectura). La deriva se informa con el mismo formato que la detección de W2 (columnas agregadas, quitadas y con tipo cambiado).
 */
final class TemplateApplier
{
    public function __construct(
        private readonly SchemaDiscoveryService $discovery,
        private readonly SourceQueryBuilder $queries,
        private readonly SourceMappingService $mappings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Revisa la deriva de la plantilla contra la fuente SIN aplicar nada (y sin invalidar la plantilla).
     *
     * @return array{matches: bool, expected_fingerprint: string, actual_fingerprint: string, dialect_mismatch: bool, added: list<string>, removed: list<string>, retyped: list<string>, tables_removed: list<string>, w2_sync_drift: ?array<string, mixed>}
     */
    public function check(MappingTemplate $template, DataSource $source): array
    {
        $schema = $this->discoverComplete($source);

        return $this->diff($template, $schema, $source);
    }

    /**
     * @return array{mapping: SourceMapping, reused: bool}
     *
     * @throws MappingTemplateException
     */
    public function apply(MappingTemplate $template, DataSource $source, User $user): array
    {
        if ($template->status !== MappingTemplate::STATUS_APPROVED) {
            throw new MappingTemplateException('La plantilla no está aprobada: apruébela antes de aplicarla a una fuente.', 'template_not_approved', 409, ['status' => $template->status]);
        }
        if ($template->tenant_id !== null && $template->data_source_id !== null && $template->data_source_id !== $source->id) {
            throw new MappingTemplateException('Esta plantilla nació de otra fuente; bifurque una copia o genere una propuesta para esta fuente.', 'template_source_mismatch', 409);
        }
        $schema = $this->discoverComplete($source);
        $drift = $this->diff($template, $schema, $source);
        if (! $drift['matches']) {
            $this->onDrift($template, $source, $drift, $user);
            throw new MappingTemplateException('El esquema de la fuente cambió respecto de la plantilla: revise la deriva antes de aplicarla.', 'schema_drift_review_required', 409, ['drift' => $drift, 'template_status' => $template->status]);
        }

        try {
            $built = $this->queries->build((array) $template->source_spec, (array) $template->field_map);
            $this->queries->guard($built['sql'], (string) $template->source_spec['dialect']);
        } catch (SqlQueryRejected|InvalidArgumentException $e) {
            throw new MappingTemplateException('La consulta generada no cumple la política de solo lectura: '.$e->getMessage(), 'sql_query_rejected', 422);
        }
        $options = [];
        if ((array) $template->value_maps !== []) {
            $options['value_maps'] = (array) $template->value_maps;
        }

        // Idempotencia: si el mapeo activo de la fuente ya es exactamente este, no se crea otra versión.
        $active = $this->mappings->activeFor($source, $template->entity);
        if ($active !== null && $active->query === $built['sql'] && json_encode($active->field_map) === json_encode($built['field_map']) && json_encode($active->options ?? []) === json_encode($options)) {
            return ['mapping' => $active, 'reused' => true];
        }

        $mapping = DB::transaction(function () use ($source, $template, $built, $options, $user): SourceMapping {
            $created = $this->mappings->create($source, ['entity' => $template->entity, 'query' => $built['sql'], 'field_map' => $built['field_map'], 'options' => $options], $user);

            return $this->mappings->approve($created, $user);
        });
        $this->audit->log('mapping.template_applied', $mapping, [
            'template_id' => $template->id, 'template_version' => $template->version, 'template_scope' => $template->tenant_id === null ? 'platform' : 'tenant', 'entity' => $template->entity,
            'data_source_id' => $source->id, 'source_mapping_id' => $mapping->id, 'source_mapping_version' => $mapping->version, 'schema_fingerprint_prefix' => MappingTemplateService::short($template->schema_fingerprint),
        ], userId: $user->id);

        return ['mapping' => $mapping, 'reused' => false];
    }

    /**
     * @return array<string, mixed>
     */
    private function diff(MappingTemplate $template, DiscoveredSchema $schema, DataSource $source): array
    {
        $diff = SourceSpec::diff((array) $template->source_spec, (array) $template->field_map, $schema);
        // Hallazgo de W2 si existe: la última sincronización de esta fuente y entidad que reportó deriva de columnas (SchemaDrift/IngestionQuality).
        $w2 = null;
        $sync = SourceSync::query()->where('data_source_id', $source->id)->where('entity', $template->entity)->whereNotNull('stats')->orderByDesc('id')->first();
        $drift = $sync !== null ? (((array) $sync->stats)['schema']['drift'] ?? null) : null;
        if (is_array($drift)) {
            $w2 = ['source_sync_id' => $sync->id, 'added' => $drift['added'] ?? [], 'removed' => $drift['removed'] ?? [], 'retyped' => $drift['retyped'] ?? []];
        }

        return $diff + ['w2_sync_drift' => $w2];
    }

    /**
     * Qué pasa con la plantilla cuando el esquema de la fuente ya no coincide.
     *
     * @param  array<string, mixed>  $drift
     */
    private function onDrift(MappingTemplate $template, DataSource $source, array $drift, User $user): void
    {
        $meta = ['template_id' => $template->id, 'template_version' => $template->version, 'entity' => $template->entity, 'data_source_id' => $source->id, 'removed' => count($drift['removed']),
            'retyped' => count($drift['retyped']), 'added' => count($drift['added']), 'dialect_mismatch' => $drift['dialect_mismatch'], 'expected_fingerprint_prefix' => MappingTemplateService::short($drift['expected_fingerprint']), 'actual_fingerprint_prefix' => MappingTemplateService::short($drift['actual_fingerprint'])];
        if ($template->tenant_id === null) {
            // Pública: solo lectura. No se modifica; queda el rastro en la auditoría de la empresa que intentó aplicarla.
            $this->audit->log('mapping.template_drift_detected', $template, $meta + ['scope' => 'platform'], userId: $user->id);

            return;
        }
        $template->forceFill([
            'status' => MappingTemplate::STATUS_PROPOSED, 'approved_by' => null, 'approved_at' => null, 'invalidated_at' => now(),
            'drift' => array_intersect_key($drift, array_flip(['expected_fingerprint', 'actual_fingerprint', 'dialect_mismatch', 'added', 'removed', 'retyped', 'tables_removed'])) + ['detected_at' => now()->toIso8601String(), 'data_source_id' => $source->id],
        ])->save();
        $this->audit->log('mapping.template_invalidated', $template, $meta + ['scope' => 'tenant'], userId: $user->id);
    }

    /** @throws MappingTemplateException */
    private function discoverComplete(DataSource $source): DiscoveredSchema
    {
        $schema = $this->discovery->discover($source);
        if ($schema->truncated) {
            throw new MappingTemplateException('El esquema de la fuente supera el tope de descubrimiento y no se puede comparar completo; aumente PREDICTILY_MAPPING_MAX_TABLES/MAX_COLUMNS.', 'schema_too_large', 422);
        }

        return $schema;
    }
}
