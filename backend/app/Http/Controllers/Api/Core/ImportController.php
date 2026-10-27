<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Core;

use App\Http\Controllers\Controller;
use App\Http\Requests\Core\ImportMappingRequest;
use App\Http\Requests\Core\StoreImportRequest;
use App\Http\Resources\Core\ImportJobResource;
use App\Models\ImportError;
use App\Models\ImportJob;
use App\Services\Imports\Fields\FieldDefinition;
use App\Services\Imports\Fields\ImportFieldRegistry;
use App\Services\Imports\ImportEntity;
use App\Services\Imports\ImportService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ImportController extends Controller
{
    public function __construct(
        private readonly ImportService $imports,
        private readonly ImportFieldRegistry $fields,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['sometimes', 'string', 'max:20'],
            'entity_type' => ['sometimes', Rule::in(ImportEntity::values())],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = ImportJob::query()->with('user:id,name')->latest('id');
        foreach (['status', 'entity_type'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->string($filter)->toString());
            }
        }
        $page = $query->paginate((int) $request->input('per_page', 20));
        $page->getCollection()->transform(function (ImportJob $job) {
            $resource = new ImportJobResource($job);
            $resource->withQuality = false;

            return $resource;
        });

        return ImportJobResource::collection($page);
    }

    public function store(StoreImportRequest $request): JsonResponse
    {
        $job = $this->imports->upload(
            $this->context->require(),
            $request->user(),
            $request->file('file'),
            ImportEntity::from($request->string('entity_type')->toString()),
            $request->integer('data_source_id') ?: null,
        );

        return (new ImportJobResource($job->load('user:id,name')))->response()->setStatusCode(201);
    }

    public function show(ImportJob $import): ImportJobResource
    {
        return new ImportJobResource($import->load('user:id,name'));
    }

    public function mapping(ImportMappingRequest $request, ImportJob $import): JsonResponse
    {
        $job = $this->imports->setMapping($import, $request->validated('mapping'), $request->options());

        return (new ImportJobResource($job->refresh()->load('user:id,name')))->response()->setStatusCode(202);
    }

    public function confirm(ImportJob $import): JsonResponse
    {
        $job = $this->imports->confirm($import);

        return (new ImportJobResource($job->refresh()->load('user:id,name')))->response()->setStatusCode(202);
    }

    public function reject(ImportJob $import): ImportJobResource
    {
        return new ImportJobResource($this->imports->reject($import)->load('user:id,name'));
    }

    public function errors(Request $request, ImportJob $import): JsonResponse
    {
        $request->validate(['severity' => ['sometimes', Rule::in(['error', 'warning'])], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $query = ImportError::query()->where('import_job_id', $import->id)->orderBy('row_number')->orderBy('id');
        if ($request->filled('severity')) {
            $query->where('severity', $request->string('severity')->toString());
        }
        $page = $query->paginate((int) $request->input('per_page', 50));

        return response()->json([
            'data' => $page->getCollection()->map(fn (ImportError $e) => [
                'row_number' => $e->row_number,
                'column' => $e->column,
                'error_code' => $e->error_code,
                'severity' => $e->severity,
                'message' => $e->message,
                'raw_value' => $e->raw_value,
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function errorsCsv(ImportJob $import): StreamedResponse
    {
        $filename = 'errores-importacion-'.$import->id.'.csv';

        // El callback corre DESPUÉS de los middleware (el TenantContext ya se limpió):
        // se filtra explícitamente por la empresa del import ya autorizado.
        $tenantId = $import->tenant_id;

        return response()->streamDownload(function () use ($import, $tenantId): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['fila', 'columna', 'codigo', 'severidad', 'mensaje', 'valor'], ',', '"', '');
            ImportError::withoutTenantScope()->where('tenant_id', $tenantId)->where('import_job_id', $import->id)->orderBy('row_number')->orderBy('id')
                ->lazyById(1000)->each(function (ImportError $e) use ($out): void {
                    fputcsv($out, [$e->row_number, $e->column, $e->error_code, $e->severity, $e->message, $e->raw_value], ',', '"', '');
                });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** GET /import-templates/{entity_type} — CSV vacío con encabezados canónicos. */
    public function template(string $entityType): StreamedResponse
    {
        $entity = ImportEntity::tryFrom($entityType) ?? abort(404);
        $headers = array_map(fn (FieldDefinition $f) => $f->key, $this->fields->fields($entity));

        return response()->streamDownload(function () use ($headers): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers, ',', '"', '');
            fclose($out);
        }, "plantilla-{$entity->value}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** GET /import-fields/{entity_type} — definición de campos para el asistente. */
    public function fieldDefinitions(string $entityType): JsonResponse
    {
        $entity = ImportEntity::tryFrom($entityType) ?? abort(404);

        return response()->json(['data' => array_map(fn (FieldDefinition $f) => $f->toArray(), $this->fields->fields($entity))]);
    }
}
