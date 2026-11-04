<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\DataCore;

use App\Http\Controllers\Controller;
use App\Http\Requests\DataCore\EditMappingTemplateRequest;
use App\Http\Requests\DataCore\ForkMappingTemplateRequest;
use App\Http\Requests\DataCore\MappingTemplateIndexRequest;
use App\Http\Resources\DataCore\MappingTemplateResource;
use App\Models\DataSource;
use App\Models\MappingTemplate;
use App\Services\Connectors\Sql\SqlSourceException;
use App\Services\DataCore\Mapping\Templates\MappingTemplateService;
use App\Services\DataCore\Mapping\Templates\TemplateApplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Plantillas de mapeo de la empresa y las públicas de la plataforma (de solo lectura para la empresa).
 *
 * Leer, corregir una propuesta, revisar (otra versión), bifurcar una pública y comprobar la deriva: `datasources.manage`. Aprobar y retirar: `settings.manage`
 * (acción humana auditada). Aplicar una plantilla aprobada a una fuente —crea el mapeo SQL que usa el pipeline de importación—: ambas habilidades. Una plantilla
 * pública no se modifica por estas rutas (403 `public_template_read_only`); de otra empresa responde 404.
 */
final class MappingTemplateController extends Controller
{
    public function __construct(private readonly MappingTemplateService $templates, private readonly TemplateApplier $applier) {}

    public function index(MappingTemplateIndexRequest $request): JsonResponse
    {
        $query = MappingTemplate::query()->with('approver:id,name')->orderBy('entity')->orderByDesc('version')->orderByDesc('id');
        match ($request->input('scope', 'all')) {
            'tenant' => $query->whereNotNull('tenant_id'),
            'platform' => $query->whereNull('tenant_id'),
            default => null,
        };
        foreach (['entity', 'status', 'system_kind', 'data_source_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }
        if ($request->filled('vendor')) {
            $query->where('vendor', MappingTemplateService::vendor($request->string('vendor')->toString()));
        }
        $page = $query->paginate((int) $request->input('per_page', 25));

        return response()->json([
            'data' => $page->getCollection()->map(fn (MappingTemplate $t) => (new MappingTemplateResource($t))->resolve($request)),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(Request $request, MappingTemplate $template): JsonResponse
    {
        return response()->json(['data' => (new MappingTemplateResource($template->load('approver:id,name'), withQuery: true))->resolve($request)]);
    }

    public function update(EditMappingTemplateRequest $request, MappingTemplate $template): JsonResponse
    {
        return $this->respond($request, $this->templates->update($template, $request->changes(), $request->user()));
    }

    public function revise(EditMappingTemplateRequest $request, MappingTemplate $template): JsonResponse
    {
        return $this->respond($request, $this->templates->revise($template, $request->changes(), $request->user()), 201);
    }

    public function approve(Request $request, MappingTemplate $template): JsonResponse
    {
        // La aprobación compara la plantilla con el esquema actual de su fuente: un origen inalcanzable responde 422 `source_unavailable`.
        return $this->respond($request, $this->guardSource(fn () => $this->templates->approve($template, $request->user()))->load('approver:id,name'));
    }

    public function retire(Request $request, MappingTemplate $template): JsonResponse
    {
        return $this->respond($request, $this->templates->retire($template, $request->user()));
    }

    public function fork(ForkMappingTemplateRequest $request, MappingTemplate $template): JsonResponse
    {
        $source = $request->filled('data_source_id') ? DataSource::query()->findOrFail((int) $request->input('data_source_id')) : null;

        return $this->respond($request, $this->templates->fork($template, $source, $request->user()), 201);
    }

    /** Compara la plantilla con el esquema actual de la fuente SIN aplicarla ni invalidarla. */
    public function drift(Request $request, DataSource $dataSource, MappingTemplate $template): JsonResponse
    {
        $report = $this->guardSource(fn () => $this->applier->check($template, $dataSource));

        return response()->json(['data' => ['template_id' => $template->id, 'data_source_id' => $dataSource->id, 'status' => $template->status, ...$report]]);
    }

    /** Aplica una plantilla aprobada a la fuente: crea y activa el mapeo SQL (no sincroniza). */
    public function apply(Request $request, DataSource $dataSource, MappingTemplate $template): JsonResponse
    {
        $outcome = $this->guardSource(fn () => $this->applier->apply($template, $dataSource, $request->user()));
        $mapping = $outcome['mapping'];

        return response()->json(['data' => [
            'template_id' => $template->id, 'reused' => $outcome['reused'],
            'source_mapping' => ['id' => $mapping->id, 'data_source_id' => $mapping->data_source_id, 'entity' => $mapping->entity, 'version' => $mapping->version, 'is_active' => $mapping->is_active,
                'approved_at' => $mapping->approved_at?->toIso8601String(), 'query' => $mapping->query],
            'next_step' => 'POST /api/v1/data-sources/'.$dataSource->id.'/sync con {"entity": "'.$mapping->entity.'"}',
        ]], $outcome['reused'] ? 200 : 201);
    }

    private function respond(Request $request, MappingTemplate $template, int $status = 200): JsonResponse
    {
        return response()->json(['data' => (new MappingTemplateResource($template->loadMissing('approver:id,name'), withQuery: true))->resolve($request)], $status);
    }

    private function guardSource(callable $callback): mixed
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
