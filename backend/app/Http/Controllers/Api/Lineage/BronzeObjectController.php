<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Lineage;

use App\Http\Controllers\Controller;
use App\Http\Resources\Lineage\BronzeObjectResource;
use App\Models\BronzeObject;
use App\Services\Audit\AuditLogger;
use App\Services\DataCore\Bronze\BronzeIntegrityException;
use App\Services\DataCore\Bronze\BronzeStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Objetos Bronze de la empresa activa (ADR-V25-04 y ADR-V25-24).
 * - GET lineage/bronze y /{object}: METADATOS (`data.view`); nunca la clave del almacén ni el contenido.
 * - GET lineage/bronze/{object}/content: contenido original íntegro, solo propietario/administrador (`datasources.manage`).
 *   Verifica el SHA-256 al leer, siempre se entrega como adjunto binario y cada descarga (o intento fallido) queda en la bitácora.
 * Aislado por empresa: el global scope de BelongsToTenant hace que un objeto de otra empresa responda 404.
 */
final class BronzeObjectController extends Controller
{
    public function __construct(private readonly BronzeStore $store, private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'entity' => ['sometimes', 'string', 'regex:/^[a-z_]{2,30}\z/'],
            'kind' => ['sometimes', 'string', Rule::in(BronzeObject::KINDS)],
            'status' => ['sometimes', 'string', Rule::in(BronzeObject::STATUSES)],
            'data_source_id' => ['sometimes', 'integer'],
            'sha256' => ['sometimes', 'string', 'regex:/^[a-f0-9]{64}\z/'],
            'received_from' => ['sometimes', 'date_format:Y-m-d'],
            'received_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:received_from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);
        $query = BronzeObject::query()->orderByDesc('received_at')->orderByDesc('id');
        foreach (['entity', 'kind', 'status', 'data_source_id', 'sha256'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (isset($filters['received_from'])) {
            $query->where('received_at', '>=', $filters['received_from']);
        }
        if (isset($filters['received_to'])) {
            $query->where('received_at', '<', date('Y-m-d', strtotime($filters['received_to'].' +1 day')));
        }
        $page = $query->paginate((int) ($filters['per_page'] ?? 50));

        return response()->json([
            'data' => BronzeObjectResource::collection($page->getCollection())->resolve($request),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(BronzeObject $object): BronzeObjectResource
    {
        return new BronzeObjectResource($object);
    }

    public function content(BronzeObject $object): StreamedResponse|JsonResponse
    {
        if ($object->status === 'purged') {
            return response()->json(['message' => 'El contenido de este objeto fue purgado por retención.', 'code' => 'bronze_purged'], 410);
        }
        try {
            $contents = $this->store->read($object);
        } catch (BronzeIntegrityException $e) {
            $this->audit->log('bronze.integrity_failed', $object, ['entity' => $object->entity, 'sha256_prefix' => substr($object->sha256, 0, 12), 'reason' => $e->getMessage()]);

            return response()->json(['message' => 'No se pudo verificar la integridad del objeto Bronze.', 'code' => 'bronze_integrity'], 409);
        }
        $this->audit->log('bronze.downloaded', $object, ['entity' => $object->entity, 'sha256_prefix' => substr($object->sha256, 0, 12), 'size_bytes' => strlen($contents)]);

        // Nombre generado por el servidor (nunca `original_name`, que viene del cliente) y siempre binario descargable.
        $extension = substr(basename($object->object_key), 64 + 1);
        $filename = sprintf('%s-%s%s', $object->entity, substr($object->sha256, 0, 12), $extension !== '' ? '.'.$extension : '');

        return response()->streamDownload(function () use ($contents): void {
            echo $contents;
        }, $filename, [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) strlen($contents),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'X-Content-SHA256' => $object->sha256,
        ]);
    }
}
