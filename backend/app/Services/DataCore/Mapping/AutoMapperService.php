<?php

declare(strict_types=1);

namespace App\Services\DataCore\Mapping;

use App\Models\DataSource;
use App\Models\MappingTemplate;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\DataCore\Mapping\Discovery\ColumnSampler;
use App\Services\DataCore\Mapping\Discovery\DiscoveredSchema;
use App\Services\DataCore\Mapping\Discovery\SchemaDiscoveryService;
use App\Services\DataCore\Mapping\Heuristics\EntityProposal;
use App\Services\DataCore\Mapping\Heuristics\EntityProposer;
use App\Services\DataCore\Mapping\Templates\MappingTemplateException;
use App\Services\DataCore\Mapping\Templates\MappingTemplateService;
use App\Services\Imports\ImportEntity;
use Illuminate\Support\Facades\Log;

/**
 * Orquesta el Auto Mapper (V2.5 §4): descubre el esquema de una fuente SQL (solo lectura), propone el mapeo de cada entidad con reglas deterministas y guarda cada
 * propuesta como plantilla `proposed` de la empresa. NO aprueba nada, NO aplica ninguna plantilla y NO inicia ninguna sincronización.
 */
final class AutoMapperService
{
    public function __construct(
        private readonly SchemaDiscoveryService $discovery,
        private readonly EntityProposer $proposer,
        private readonly MappingTemplateService $templates,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Descubrimiento de esquema (metadatos) con auditoría.
     *
     * @throws MappingTemplateException
     */
    public function discover(DataSource $source, ?User $user): DiscoveredSchema
    {
        $started = microtime(true);
        $schema = $this->discovery->discover($source);
        $this->audit->log('mapping.discovery_run', $source, [
            'data_source_id' => $source->id, 'dialect' => $schema->dialect, 'tables' => $schema->tableCount(), 'columns' => $schema->columnCount(), 'truncated' => $schema->truncated,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ], userId: $user?->id);

        return $schema;
    }

    /**
     * @param  list<string>  $entities  entidades de config('mapping.entities')
     * @return array{schema: array<string, mixed>, results: list<array{entity: string, proposal: EntityProposal, template: MappingTemplate, created: bool}>}
     *
     * @throws MappingTemplateException
     */
    public function propose(DataSource $source, array $entities, bool $sample, string $systemKind, ?string $vendor, ?User $user): array
    {
        $started = microtime(true);
        $connector = $this->discovery->connectorFor($source);
        $connection = $connector->open($source);
        try {
            $schema = $this->discovery->readFromConnection($connection);
            if ($schema->truncated) {
                throw new MappingTemplateException('El esquema de la fuente supera el tope de descubrimiento; aumente PREDICTILY_MAPPING_MAX_TABLES/MAX_COLUMNS o acote los permisos de la cuenta de solo lectura.', 'schema_too_large', 422);
            }
            $profiler = $sample && (bool) config('mapping.sampling.enabled', true) ? new ColumnSampler($connection) : null;
            $results = [];
            foreach ($entities as $entity) {
                $proposal = $this->proposer->propose(ImportEntity::from($entity), $schema, $profiler);
                $saved = $this->templates->record($proposal, $source, $systemKind, $vendor, $user);
                $results[] = ['entity' => $entity, 'proposal' => $proposal, 'template' => $saved['template'], 'created' => $saved['created']];
            }
        } finally {
            $connection->close();
        }
        $this->audit->log('mapping.proposals_generated', $source, [
            'data_source_id' => $source->id, 'dialect' => $schema->dialect, 'entities' => $entities, 'sampled' => $profiler !== null, 'tables' => $schema->tableCount(), 'columns' => $schema->columnCount(),
            'template_ids' => array_map(fn (array $r) => $r['template']->id, $results), 'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ], userId: $user?->id);
        Log::info('mapping.proposals_generated', ['data_source_id' => $source->id, 'entities' => count($entities), 'tables' => $schema->tableCount(), 'sampled' => $profiler !== null]);

        return ['schema' => $schema->toArray((int) config('mapping.discovery.response_max_tables', 300), false), 'results' => $results];
    }
}
