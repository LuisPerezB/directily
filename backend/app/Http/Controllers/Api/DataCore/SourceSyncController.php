<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\DataCore;

use App\Http\Controllers\Controller;
use App\Jobs\DataCore\SyncDataSource;
use App\Models\DataSource;
use App\Models\SourceSync;
use App\Services\DataCore\Sync\SourceMappingService;
use App\Services\DataCore\Sync\SourceSyncService;
use App\Support\Results\JsonMap;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Sincronizaciones de fuentes SQL (ability datasources.manage). El trabajo corre en la cola «imports» con la empresa explícita. */
final class SourceSyncController extends Controller
{
    public function __construct(private readonly SourceSyncService $syncs, private readonly TenantContext $context) {}

    public function store(Request $request, DataSource $dataSource): JsonResponse
    {
        $data = $request->validate([
            'entity' => ['required', Rule::in(SourceMappingService::ENTITIES)],
            'mode' => ['sometimes', Rule::in(['full', 'incremental'])],
        ]);
        $sync = $this->syncs->request($dataSource, $data['entity'], $data['mode'] ?? 'incremental', $request->user());
        SyncDataSource::dispatch($this->context->require()->id, $sync->id)->onQueue('imports');

        return response()->json(['data' => $this->present($sync->refresh())], 202);
    }

    public function index(Request $request, DataSource $dataSource): JsonResponse
    {
        $request->validate(['entity' => ['sometimes', 'string', 'max:30'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $query = SourceSync::query()->where('data_source_id', $dataSource->id)->latest('id');
        if ($request->filled('entity')) {
            $query->where('entity', $request->string('entity')->toString());
        }
        $page = $query->paginate((int) $request->input('per_page', 20));

        return response()->json([
            'data' => $page->getCollection()->map(fn (SourceSync $s) => $this->present($s)),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(SourceSync $sync): JsonResponse
    {
        return response()->json(['data' => $this->present($sync)]);
    }

    /** @return array<string, mixed> */
    private function present(SourceSync $s): array
    {
        return [
            'id' => $s->id, 'data_source_id' => $s->data_source_id, 'entity' => $s->entity, 'mapping_version' => $s->mapping_version,
            'mode' => $s->mode, 'status' => $s->status, 'triggered_by' => $s->triggered_by,
            'since' => $s->since?->toIso8601String(), 'watermark' => $s->watermark?->toIso8601String(),
            'rows_extracted' => $s->rows_extracted, 'pages' => $s->pages, 'import_job_id' => $s->import_job_id,
            'stats' => JsonMap::orEmpty($s->stats), 'error_message' => $s->error_message,
            'started_at' => $s->started_at?->toIso8601String(), 'finished_at' => $s->finished_at?->toIso8601String(),
            'created_at' => $s->created_at?->toIso8601String(),
        ];
    }
}
