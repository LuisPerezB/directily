<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Models\DecisionRecommendation;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Vencimiento programado de recomendaciones (V2.5 · W5-B), lo que ejecuta `predictily:decisions-expire`. Pasa a `expired` (con su evento de
 * auditoría del sistema) toda recomendación aún sin acción en curso (suggested, assigned, reviewed, approved) cuyo `expires_at` ya pasó. Una
 * recomendación en ejecución (in_progress) o posterior NO vence: ya hay una acción humana. IDEMPOTENTE: el estado y la clave del evento impiden
 * repetir; si otro proceso la movió entre la lectura y el bloqueo, se omite sin error. Las vencidas se siguen midiendo (outcomes de W5-C).
 */
final class DecisionExpirer
{
    public function __construct(private readonly TenantContext $context, private readonly Workflow $workflow) {}

    /** @return int cuántas recomendaciones venció (con `$dryRun`, cuántas vencería) */
    public function expireDue(Tenant $tenant, ?CarbonImmutable $now = null, bool $dryRun = false): int
    {
        $now ??= CarbonImmutable::now();

        return $this->context->runAs($tenant, function () use ($now, $dryRun): int {
            $query = DecisionRecommendation::query()->whereIn('status', DecisionRecommendation::PRE_ACTION_STATUSES)->whereNotNull('expires_at')->where('expires_at', '<=', $now);
            if ($dryRun) {
                return $query->count();
            }
            $expired = 0;
            foreach ((clone $query)->orderBy('id')->pluck('id') as $id) {
                $result = $this->workflow->systemTransition((int) $id, 'expired', 'expiry', 'La recomendación venció sin una acción en curso.', [
                    'reason_code' => 'expired', 'expired_at' => $now->toIso8601String(),
                ]);
                if ($result !== null && ! $result->replayed) {
                    $expired++;
                }
            }

            return $expired;
        });
    }
}
