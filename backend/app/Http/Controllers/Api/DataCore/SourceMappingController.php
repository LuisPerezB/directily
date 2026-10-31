<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\DataCore;

use App\Http\Controllers\Controller;
use App\Models\DataSource;
use App\Models\SourceMapping;
use App\Services\Connectors\Sql\SqlQueryGuard;
use App\Services\Connectors\Sql\SqlSourceException;
use App\Services\DataCore\Sync\CanonicalStager;
use App\Services\DataCore\Sync\SourceMappingService;
use App\Support\Privacy\PiiMasker;
use App\Support\Results\JsonMap;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Consultas autorizadas por fuente SQL (ability datasources.manage en todas las rutas: la consulta describe el esquema
 * del ERP). Una versión nueva nace sin aprobar; solo la aprobada y activa sincroniza.
 */
final class SourceMappingController extends Controller
{
    public function __construct(
        private readonly SourceMappingService $mappings,
        private readonly CanonicalStager $stager,
        private readonly TenantContext $context,
    ) {}

    public function index(DataSource $dataSource): JsonResponse
    {
        $items = SourceMapping::query()->where('data_source_id', $dataSource->id)->with('approver:id,name')->orderBy('entity')->orderByDesc('version')->get();

        return response()->json(['data' => $items->map(fn (SourceMapping $m) => $this->present($m))]);
    }

    public function show(DataSource $dataSource, SourceMapping $mapping): JsonResponse
    {
        $this->belongs($dataSource, $mapping);

        return response()->json(['data' => $this->present($mapping->load('approver:id,name'))]);
    }

    public function store(Request $request, DataSource $dataSource): JsonResponse
    {
        $data = $request->validate([
            'entity' => ['required', 'string', 'max:30'],
            'query' => ['required', 'string', 'max:'.SqlQueryGuard::MAX_LENGTH],
            'field_map' => ['required', 'array', 'min:1'],
            'options' => ['sometimes', 'array'],
        ]);
        $mapping = $this->mappings->create($dataSource, $data, $request->user());

        return response()->json(['data' => $this->present($mapping)], 201);
    }

    /** Ejecuta la consulta (solo lectura, pocas filas) y muestra cómo quedarían los campos canónicos. No importa nada. */
    public function preview(DataSource $dataSource, SourceMapping $mapping): JsonResponse
    {
        $this->belongs($dataSource, $mapping);
        $connector = $this->mappings->connectorFor($dataSource);
        $query = $this->mappings->guard($mapping->query, $connector);
        $tenant = $this->context->require();
        $options = (array) $mapping->options;
        $sourceTz = (string) ($options['source_timezone'] ?? $dataSource->config['source_timezone'] ?? $tenant->timezone ?: 'America/Guayaquil');
        try {
            $connection = $connector->open($dataSource);
            try {
                $preview = $this->stager->preview($connection, $query, $mapping, $tenant, $sourceTz, (int) config('connectors.sql.preview_rows', 20));
            } finally {
                $connection->close();
            }
        } catch (SqlSourceException $e) {
            $exception = ValidationException::withMessages(['query' => [$e->getMessage()]]);
            $exception->response = response()->json(['message' => $e->getMessage(), 'code' => 'source_unavailable', 'errors' => ['query' => [$e->getMessage()]]], 422);
            throw $exception;
        }
        $customerColumn = array_search('customer_code', $preview['headers'], true);
        $rows = array_map(function (array $row) use ($customerColumn) {
            if ($customerColumn !== false && $row[$customerColumn] !== '') {
                $row[$customerColumn] = PiiMasker::mask($row[$customerColumn]);
            }

            return $row;
        }, $preview['rows']);

        return response()->json(['data' => ['headers' => $preview['headers'], 'rows' => $rows, 'rows_returned' => count($rows), 'note' => 'Vista previa de campos canónicos; no se importó nada.']]);
    }

    public function approve(Request $request, DataSource $dataSource, SourceMapping $mapping): JsonResponse
    {
        $this->belongs($dataSource, $mapping);

        return response()->json(['data' => $this->present($this->mappings->approve($mapping, $request->user())->load('approver:id,name'))]);
    }

    public function deactivate(DataSource $dataSource, SourceMapping $mapping): JsonResponse
    {
        $this->belongs($dataSource, $mapping);

        return response()->json(['data' => $this->present($this->mappings->deactivate($mapping))]);
    }

    private function belongs(DataSource $dataSource, SourceMapping $mapping): void
    {
        abort_unless($mapping->data_source_id === $dataSource->id, 404);
    }

    /** @return array<string, mixed> */
    private function present(SourceMapping $m): array
    {
        return [
            'id' => $m->id, 'data_source_id' => $m->data_source_id, 'entity' => $m->entity, 'version' => $m->version,
            'query' => $m->query, 'field_map' => JsonMap::orEmpty($m->field_map), 'options' => JsonMap::orEmpty($m->options),
            'is_active' => $m->is_active, 'approved_at' => $m->approved_at?->toIso8601String(),
            'approved_by' => $m->relationLoaded('approver') && $m->approver ? ['id' => $m->approver->id, 'name' => $m->approver->name] : null,
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }
}
