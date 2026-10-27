<?php

declare(strict_types=1);

namespace App\Services\Forecasting\Support;

use App\Exceptions\PlanLimitExceededException;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Services\Plans\PlanLimitService;
use Illuminate\Database\Eloquent\Model;

/**
 * Punto único de acceso del módulo analítico a los servicios de backend-core
 * (auditoría y límites de plan), para usar exactamente la misma lógica de negocio.
 */
final class CoreServices
{
    /** @param array<string, mixed> $metadata */
    public static function audit(string $action, ?Model $auditable = null, array $metadata = []): void
    {
        app(AuditLogger::class)->log($action, $auditable, $metadata);
    }

    /** @throws PlanLimitExceededException (HTTP 402) */
    public static function assertPlanLimit(Tenant $tenant, string $limit, int $increment = 1): void
    {
        app(PlanLimitService::class)->assertCan($tenant, $limit, $increment);
    }

    /** @throws PlanLimitExceededException (HTTP 402) */
    public static function assertHorizon(Tenant $tenant, int $horizonDays): void
    {
        app(PlanLimitService::class)->assertHorizon($tenant, $horizonDays);
    }

    /** @throws PlanLimitExceededException (HTTP 402) */
    public static function assertFeature(Tenant $tenant, string $feature): void
    {
        app(PlanLimitService::class)->assertFeature($tenant, $feature);
    }

    public static function planAllows(Tenant $tenant, string $feature): bool
    {
        return app(PlanLimitService::class)->allows($tenant, $feature);
    }

    public static function recordUsage(Tenant $tenant, string $metric, int $amount = 1): void
    {
        app(PlanLimitService::class)->recordUsage($tenant, $metric, $amount);
    }

    /** @return list<int> Horizontes permitidos por el plan efectivo (plan + overrides). */
    public static function allowedHorizons(Tenant $tenant): array
    {
        $horizons = app(PlanLimitService::class)->limits($tenant)['horizons'] ?? config('engine.horizons', [7, 30, 90]);

        return array_values(array_map('intval', (array) $horizons));
    }
}
