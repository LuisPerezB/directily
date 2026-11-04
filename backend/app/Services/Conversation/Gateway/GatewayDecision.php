<?php

declare(strict_types=1);

namespace App\Services\Conversation\Gateway;

/**
 * Decisiones que registra `gateway_tool_calls.decision` (contrato 2026_03_14_000100) y su correspondencia con el estado de cada resultado de herramienta.
 * El detalle fino (invalid_arguments, plan_limit, scope_denied…) queda en `result_summary.status`; aquí solo el veredicto de política.
 */
final class GatewayDecision
{
    public const ALLOWED = 'allowed';

    public const DENIED_ABILITY = 'denied_ability';

    public const DENIED_POLICY = 'denied_policy';

    public const BLOCKED_INJECTION = 'blocked_injection';

    public const ERROR = 'error';

    public const ALL = [self::ALLOWED, self::DENIED_ABILITY, self::DENIED_POLICY, self::BLOCKED_INJECTION, self::ERROR];

    /** Veredicto de política de un estado de resultado (la inyección se decide aparte, sobre el contenido). */
    public static function forStatus(string $status): string
    {
        return match ($status) {
            'ok', 'no_data', 'not_found' => self::ALLOWED,
            'forbidden' => self::DENIED_ABILITY,
            'tool_not_allowed', 'scope_denied', 'channel_denied', 'plan_limit', 'invalid_arguments' => self::DENIED_POLICY,
            default => self::ERROR,
        };
    }
}
