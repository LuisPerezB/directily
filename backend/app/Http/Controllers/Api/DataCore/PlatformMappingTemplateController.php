<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\DataCore;

use App\Http\Controllers\Controller;
use App\Http\Requests\DataCore\EditMappingTemplateRequest;
use App\Http\Requests\DataCore\PublicMappingTemplateRequest;
use App\Http\Resources\DataCore\MappingTemplateResource;
use App\Models\MappingTemplate;
use App\Services\DataCore\Mapping\Templates\MappingTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Plantillas PÚBLICAS de la plataforma (solo superadministrador; sin empresa activa y sin acceso a datos ni a plantillas de ninguna empresa). Las crea, edita,
 * aprueba y retira solo la plataforma; una vez aprobadas, todas las empresas las ven (solo lectura) y pueden aplicarlas o bifurcarlas.
 */
final class PlatformMappingTemplateController extends Controller
{
    public function __construct(private readonly MappingTemplateService $templates) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['sometimes', 'in:proposed,approved,retired'], 'entity' => ['sometimes', 'string', 'max:30']]);
        $query = MappingTemplate::platform()->with('approver:id,name')->orderBy('entity')->orderByDesc('version');
        foreach (['status', 'entity'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        return response()->json(['data' => $query->limit(200)->get()->map(fn (MappingTemplate $t) => (new MappingTemplateResource($t))->resolve($request))]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->respond($request, $this->find($id));
    }

    public function store(PublicMappingTemplateRequest $request): JsonResponse
    {
        return $this->respond($request, $this->templates->createPublic($request->validated(), $request->user()), 201);
    }

    public function update(EditMappingTemplateRequest $request, int $id): JsonResponse
    {
        return $this->respond($request, $this->templates->update($this->find($id), $request->changes(), $request->user(), asPlatform: true));
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        return $this->respond($request, $this->templates->approve($this->find($id), $request->user(), asPlatform: true)->load('approver:id,name'));
    }

    public function retire(Request $request, int $id): JsonResponse
    {
        return $this->respond($request, $this->templates->retire($this->find($id), $request->user(), asPlatform: true));
    }

    private function find(int $id): MappingTemplate
    {
        return MappingTemplate::platform()->findOrFail($id);
    }

    private function respond(Request $request, MappingTemplate $template, int $status = 200): JsonResponse
    {
        return response()->json(['data' => (new MappingTemplateResource($template->loadMissing('approver:id,name'), withQuery: true))->resolve($request)], $status);
    }
}
