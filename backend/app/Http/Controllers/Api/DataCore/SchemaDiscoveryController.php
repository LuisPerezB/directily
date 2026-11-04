<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\DataCore;

use App\Http\Controllers\Controller;
use App\Http\Requests\DataCore\ProposeMappingRequest;
use App\Http\Resources\DataCore\MappingTemplateResource;
use App\Models\DataSource;
use App\Services\Connectors\Sql\SqlSourceException;
use App\Services\DataCore\Mapping\AutoMapperService;
use App\Services\DataCore\Mapping\Templates\MappingTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Descubrimiento de esquema y propuesta del Auto Mapper (habilidad datasources.manage: describe el esquema del ERP del cliente).
 *
 * - POST /data-sources/{id}/schema/discover  metadatos del catálogo (tablas, columnas, claves, filas estimadas) de una fuente SQL; solo lectura, sin valores.
 * - POST /data-sources/{id}/mapping-proposals  propone el mapeo de cada entidad y lo guarda como plantilla `proposed`. NO aprueba ni sincroniza nada.
 */
final class SchemaDiscoveryController extends Controller
{
    public function __construct(private readonly AutoMapperService $mapper) {}

    public function discover(Request $request, DataSource $dataSource): JsonResponse
    {
        $request->validate(['include_columns' => ['sometimes', 'boolean']]);
        $schema = $this->run(fn () => $this->mapper->discover($dataSource, $request->user()));

        return response()->json(['data' => $schema->toArray((int) config('mapping.discovery.response_max_tables', 300), $request->boolean('include_columns'))]);
    }

    public function propose(ProposeMappingRequest $request, DataSource $dataSource): JsonResponse
    {
        $data = $request->validated();
        $entities = array_values($data['entities'] ?? (array) config('mapping.entities'));
        // El sistema y el proveedor salen de la fuente declarada si no se indican.
        $systemKind = (string) ($data['system_kind'] ?? $dataSource->system_kind ?? 'erp');
        $vendor = array_key_exists('vendor', $data) ? $data['vendor'] : $dataSource->vendor;
        $outcome = $this->run(fn () => $this->mapper->propose($dataSource, $entities, (bool) ($data['sample'] ?? true), in_array($systemKind, (array) config('mapping.system_kinds'), true) ? $systemKind : 'other', MappingTemplateService::vendor($vendor), $request->user()));

        return response()->json(['data' => [
            'schema' => $outcome['schema'],
            'proposals' => array_map(fn (array $r) => [
                'entity' => $r['entity'],
                'created' => $r['created'],
                'complete' => $r['proposal']->complete,
                'missing_required' => $r['proposal']->missingRequired(),
                'warnings' => $r['proposal']->warnings,
                'template' => (new MappingTemplateResource($r['template'], withQuery: true))->resolve($request),
            ], $outcome['results']),
        ]], 201);
    }

    /** Traduce los fallos de conexión del origen a 422 `source_unavailable` (mismo contrato que la vista previa de un mapeo), sin filtrar la red interna. */
    private function run(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (SqlSourceException $e) {
            $exception = ValidationException::withMessages(['data_source' => [$e->getMessage()]]);
            $exception->response = response()->json(['message' => $e->getMessage(), 'code' => 'source_unavailable', 'errors' => ['data_source' => [$e->getMessage()]]], 422);
            throw $exception;
        }
    }
}
