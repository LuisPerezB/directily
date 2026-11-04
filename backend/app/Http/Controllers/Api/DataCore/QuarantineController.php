<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\DataCore;

use App\Http\Controllers\Controller;
use App\Models\QuarantineRecord;
use App\Services\DataCore\Quality\QuarantineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Cuarentena de ingestión (ADR-V25-24; W2-A): documentos retenidos FUERA de Silver con hallazgos REVIEW_REQUIRED o QUARANTINED.
 * Lectura: `data.view`. Liberar y descartar: `imports.manage`. Aislado por empresa (BelongsToTenant: otra empresa recibe 404).
 *
 * - GET /data-quality/quarantine (filtros entity, status, import_job_id, source_sync_id; paginado) y GET /{id} (con su payload mínimo y hallazgos).
 * - POST /{id}/release: revalida con los mapeos de valores vigentes (más los `value_maps` que se envíen) y, si es válido, escribe con
 *   SalesDocumentWriter en modo incremental (conocido desde la liberación) enlazado a Bronze; si no, 422 con los hallazgos que quedan.
 *   Si el documento ya está en Silver con otro contenido y se escribió DESPUÉS de la cuarentena (sin orden de fuente que decida): 409
 *   `newer_version_exists` con la versión vigente; `force = true` lo sobrescribe y queda auditado. Un registro `superseded` (una carga
 *   posterior trajo el documento bien) responde 409 `invalid_state`.
 * - POST /{id}/discard: motivo obligatorio.
 */
final class QuarantineController extends Controller
{
    public function __construct(private readonly QuarantineService $quarantine) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'entity' => ['sometimes', 'string', 'max:30'],
            'status' => ['sometimes', 'string', Rule::in(QuarantineRecord::STATUSES)],
            'import_job_id' => ['sometimes', 'integer'],
            'source_sync_id' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = QuarantineRecord::query()->orderByDesc('id');
        foreach (['entity', 'status', 'import_job_id', 'source_sync_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        $page = $query->paginate((int) ($filters['per_page'] ?? 25));

        return response()->json([
            'data' => $page->getCollection()->map(fn (QuarantineRecord $r) => $this->present($r))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(QuarantineRecord $quarantine): JsonResponse
    {
        $findings = $quarantine->import_job_id === null ? [] : DB::table('data_quality_issues')
            ->where('tenant_id', $quarantine->tenant_id)->where('record_ref', $quarantine->record_key)
            ->whereIn('report_id', DB::table('data_quality_reports')->where('tenant_id', $quarantine->tenant_id)->where('import_job_id', $quarantine->import_job_id)->select('id'))
            ->orderBy('source_row')->orderBy('id')->limit(200)
            ->get(['dq_class', 'code', 'source_row', 'field', 'original_value', 'proposed_value', 'reason', 'status'])->all();

        // La API nunca devuelve la huella HMAC del comprador (`tax_id_hash`): sólo el tipo. La huella queda en el registro para crearlo al liberar.
        $payload = (array) $quarantine->payload;
        $payload['customers'] = (object) array_map(fn ($info) => ['customer_type' => ((array) $info)['customer_type'] ?? 'unknown'], (array) ($payload['customers'] ?? []));

        return response()->json(['data' => $this->present($quarantine) + ['payload' => $payload, 'findings' => $findings]]);
    }

    public function release(Request $request, QuarantineRecord $quarantine): JsonResponse
    {
        $data = $request->validate(['value_maps' => ['sometimes', 'nullable', 'array'], 'force' => ['sometimes', 'boolean']]);
        $result = $this->quarantine->release($quarantine, $request->user(), (array) ($data['value_maps'] ?? []), (bool) ($data['force'] ?? false));

        return response()->json(['data' => $this->present($quarantine->refresh()) + ['write' => $result]]);
    }

    public function discard(Request $request, QuarantineRecord $quarantine): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return response()->json(['data' => $this->present($this->quarantine->discard($quarantine, $request->user(), $data['reason']))]);
    }

    /** @return array<string, mixed> */
    private function present(QuarantineRecord $r): array
    {
        return [
            'id' => $r->id,
            'entity' => $r->entity,
            'record_key' => $r->record_key,
            'status' => $r->status,
            'issue_codes' => $r->issue_codes ?? [],
            'rows' => count((array) (($r->payload ?? [])['rows'] ?? [])),
            'import_job_id' => $r->import_job_id,
            'source_sync_id' => $r->source_sync_id,
            'bronze_object_id' => $r->bronze_object_id,
            'resolution' => ($r->payload ?? [])['resolution'] ?? null,
            'created_at' => $r->created_at?->toIso8601String(),
            'resolved_by' => $r->resolved_by,
            'resolved_at' => $r->resolved_at?->toIso8601String(),
        ];
    }
}
