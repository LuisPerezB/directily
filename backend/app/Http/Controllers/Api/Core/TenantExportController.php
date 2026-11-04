<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Core;

use App\Enums\CommercialRole;
use App\Http\Controllers\Controller;
use App\Jobs\Privacy\ExportTenantData;
use App\Models\PersonalAccessToken;
use App\Models\TenantExport;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Exportación completa de datos (LOPDP: acceso y portabilidad). */
final class TenantExportController extends Controller
{
    public function __construct(private readonly TenantContext $context, private readonly AuditLogger $audit) {}

    public function store(Request $request): JsonResponse
    {
        $tenant = $this->context->require();
        $running = TenantExport::query()->whereIn('status', ['queued', 'running'])->where('created_at', '>=', now()->subHour())->exists();
        if ($running) {
            return response()->json(['message' => 'Ya hay una exportación en curso.', 'code' => 'export_in_progress'], 409);
        }
        $export = TenantExport::query()->create(['user_id' => $request->user()->id, 'status' => 'queued']);
        $this->audit->log('tenant.export_requested', $export);
        ExportTenantData::dispatch($tenant->id, $export->id)->onQueue('default');

        return response()->json(['data' => ['export_id' => $export->id, 'status' => $export->refresh()->status]], 202);
    }

    public function show(int $export): JsonResponse
    {
        $record = TenantExport::query()->findOrFail($export);
        $downloadable = $record->status === 'completed' && $record->expires_at?->isFuture();

        return response()->json(['data' => [
            'export_id' => $record->id,
            'status' => $downloadable || $record->status !== 'completed' ? $record->status : 'expired',
            'file_size' => $record->file_size,
            'summary' => $record->summary,
            'expires_at' => $record->expires_at?->toIso8601String(),
            'error_message' => $record->error_message,
            'download_url' => $downloadable
                ? URL::temporarySignedRoute('tenant.export.download', now()->addMinutes((int) config('predictily.privacy.export_link_minutes', 60)), ['export' => $record->id])
                : null,
        ]]);
    }

    /**
     * Ruta firmada; además exige que el usuario autenticado sea quien solicitó la exportación Y que SIGA siendo miembro de la empresa con la habilidad `tenant.export_all`
     * (V2.5 · W6-A: el enlace firmado vive una hora y esta ruta no pasa por `tenant`; quien fue quitado o degradado desde que lo pidió no descarga los datos de la empresa), y que un
     * token ligado a otra empresa o sin esa habilidad no la use.
     */
    public function download(Request $request, int $export): StreamedResponse|JsonResponse
    {
        $record = TenantExport::withoutTenantScope()->find($export);
        $user = $request->user();
        $notFound = fn () => response()->json(['message' => 'Recurso no encontrado.', 'code' => 'not_found'], 404);
        if ($record === null || $record->user_id !== $user->id || $record->status !== 'completed' || ! $record->expires_at?->isFuture()) {
            return $notFound();
        }
        [$commercial, $unexpected] = CommercialRole::parseStored($user->membershipIn((int) $record->tenant_id)?->commercial_role);
        $stored = $user->roleIn((int) $record->tenant_id);
        $role = $stored !== null ? CommercialRole::effectiveAccessRole($stored, $commercial, $unexpected) : null;
        $token = $user->currentAccessToken();
        $tokenOk = ! $token instanceof PersonalAccessToken || ! $token->exists
            || (($token->tenant_id === null || (int) $token->tenant_id === (int) $record->tenant_id) && $token->can('tenant.export_all'));
        if ($role === null || ! $role->can('tenant.export_all') || ! $tokenOk) {
            return $notFound();
        }
        $this->audit->log('data.exported', $record, ['kind' => 'tenant_export'], $record->tenant_id);

        return Storage::disk((string) config('predictily.imports.disk', 'local'))->download($record->path, basename($record->path));
    }
}
