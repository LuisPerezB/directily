<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Support\Privacy\PiiMasker;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bitácora de auditoría (solo inserción). Toma usuario, empresa del contexto, IP y
 * user agent de la petición actual. Los metadatos se sanean (sin PII ni secretos).
 *
 * Acciones usadas: auth.login, auth.failed, auth.logout, auth.registered, tenant.created,
 * tenant.updated, tenant.consent_changed, tenant.deletion_requested, tenant.export_requested,
 * user.added, user.role_changed, user.removed, import.uploaded, import.confirmed,
 * import.rejected, import.completed, import.failed, sales.batch, datasource.created,
 * datasource.token_created, forecast.requested, data.exported, admin.tenant_updated.
 */
final class AuditLogger
{
    public function __construct(private readonly TenantContext $context)
    {
    }

    /** @param array<string, mixed> $metadata */
    public function log(string $action, ?Model $auditable = null, array $metadata = [], ?int $tenantId = null, ?int $userId = null): void
    {
        try {
            $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();
            $tenantId ??= $this->context->id()
                ?? ($auditable !== null && $auditable->getAttribute('tenant_id') !== null ? (int) $auditable->getAttribute('tenant_id') : null);

            AuditLog::query()->forceCreate([
                'tenant_id' => $tenantId,
                'user_id' => $userId ?? auth()->id(),
                'action' => $action,
                'auditable_type' => $auditable !== null ? class_basename($auditable) : null,
                'auditable_id' => $auditable?->getKey(),
                'ip_address' => $request?->ip(),
                'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
                'metadata' => PiiMasker::sanitize($metadata),
            ]);
        } catch (Throwable $e) {
            // La auditoría nunca debe interrumpir la operación principal, pero sí dejar rastro.
            Log::error('No se pudo registrar auditoría', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }
}
