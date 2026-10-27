<?php

declare(strict_types=1);

namespace App\Services\Forecasting\Support;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Puente hacia servicios provistos por backend-core (auditoría y límites de plan).
 *
 * Centraliza la dependencia para que el módulo analítico use exactamente la misma
 * lógica de negocio que el resto de la API (sin duplicarla).
 */
final class CoreServices
{
    private const AUDIT_LOGGER = 'App\\Services\\Audit\\AuditLogger';

    private const PLAN_LIMITS = 'App\\Services\\Plans\\PlanLimitService';

    /** @param array<string, mixed> $metadata */
    public static function audit(string $action, ?Model $auditable = null, array $metadata = []): void
    {
        if (! class_exists(self::AUDIT_LOGGER)) {
            Log::info('audit', ['action' => $action, 'auditable' => $auditable?->getMorphClass(), 'id' => $auditable?->getKey()]);

            return;
        }
        $logger = app(self::AUDIT_LOGGER);
        $logger->log($action, $auditable, $metadata);
    }

    /** Lanza la excepción de plan (402) de backend-core si se excede el límite. */
    public static function assertPlanLimit(Tenant $tenant, string $limit, int $increment = 1): void
    {
        if (! class_exists(self::PLAN_LIMITS)) {
            return;
        }
        app(self::PLAN_LIMITS)->assertCan($tenant, $limit, $increment);
    }

    /** ¿El plan permite la característica? (true si el servicio de planes aún no existe). */
    public static function planAllows(Tenant $tenant, string $feature): bool
    {
        if (! class_exists(self::PLAN_LIMITS)) {
            return true;
        }

        return (bool) app(self::PLAN_LIMITS)->allows($tenant, $feature);
    }

    /**
     * Horizontes permitidos según límites del plan (+ overrides de la empresa).
     *
     * @return list<int>
     */
    public static function allowedHorizons(Tenant $tenant): array
    {
        $default = (array) config('engine.horizons', [7, 30, 90]);
        $limits = array_merge(
            (array) ($tenant->plan?->limits ?? []),
            (array) ($tenant->plan_overrides ?? []),
        );
        $horizons = $limits['horizons'] ?? $default;

        return array_values(array_map('intval', (array) $horizons));
    }
}
