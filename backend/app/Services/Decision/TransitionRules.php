<?php

declare(strict_types=1);

namespace App\Services\Decision;

/**
 * Tabla explícita de transiciones del workflow de decisiones (V2.5 · W5-B; ADR-V25-13; §11). Fuente única: la usan Workflow, la API
 * (`allowed_transitions`) y la documentación. Una transición que no está aquí NO existe.
 *
 *   suggested ──assign──▶ assigned ──review──▶ reviewed ──approve──▶ approved ──start──▶ in_progress ──execute──▶ executed ──measure*──▶ measured ──close──▶ closed
 *                │  ▲        │                    │  └──────────────reject──▶ rejected          ▲
 *                │  └reassign┘                    └─start (sin aprobación, solo si no es sensible)┘
 *                └──────────dismiss (suggested | assigned)──▶ rejected          (*) measure y expire: solo el sistema
 *   suggested | assigned | reviewed | approved ──expire*──▶ expired
 *
 * `ability`: habilidad del rol del usuario (TenantRole). `system`: la transición la hace el sistema, nunca una persona.
 * `version`: exige `expected_version` (bloqueo optimista) en TODA transición humana: así una segunda asignación, un inicio o un cierre no pisan en silencio lo que otra
 * persona hizo entre la lectura y la acción. `reason`: motivo obligatorio.
 */
final class TransitionRules
{
    /**
     * @var list<array{action: string, from: list<string>, to: string, ability: string|null, system: bool, version: bool, reason: bool, event: string}>
     */
    public const RULES = [
        ['action' => 'assign', 'from' => ['suggested'], 'to' => 'assigned', 'ability' => 'decisions.manage', 'system' => false, 'version' => true, 'reason' => false, 'event' => 'transition'],
        ['action' => 'reassign', 'from' => ['assigned'], 'to' => 'assigned', 'ability' => 'decisions.manage', 'system' => false, 'version' => true, 'reason' => false, 'event' => 'assignment'],
        ['action' => 'review', 'from' => ['assigned'], 'to' => 'reviewed', 'ability' => 'decisions.manage', 'system' => false, 'version' => true, 'reason' => false, 'event' => 'transition'],
        ['action' => 'approve', 'from' => ['reviewed'], 'to' => 'approved', 'ability' => 'decisions.approve', 'system' => false, 'version' => true, 'reason' => false, 'event' => 'transition'],
        ['action' => 'reject', 'from' => ['reviewed'], 'to' => 'rejected', 'ability' => 'decisions.approve', 'system' => false, 'version' => true, 'reason' => true, 'event' => 'transition'],
        ['action' => 'dismiss', 'from' => ['suggested', 'assigned'], 'to' => 'rejected', 'ability' => 'decisions.manage', 'system' => false, 'version' => true, 'reason' => true, 'event' => 'transition'],
        ['action' => 'start', 'from' => ['approved'], 'to' => 'in_progress', 'ability' => 'decisions.manage', 'system' => false, 'version' => true, 'reason' => false, 'event' => 'transition'],
        ['action' => 'start_without_approval', 'from' => ['reviewed'], 'to' => 'in_progress', 'ability' => 'decisions.manage', 'system' => false, 'version' => true, 'reason' => false, 'event' => 'transition'],
        ['action' => 'execute', 'from' => ['in_progress'], 'to' => 'executed', 'ability' => 'decisions.manage', 'system' => false, 'version' => true, 'reason' => false, 'event' => 'transition'],
        ['action' => 'measure', 'from' => ['executed'], 'to' => 'measured', 'ability' => null, 'system' => true, 'version' => false, 'reason' => false, 'event' => 'transition'],
        ['action' => 'close', 'from' => ['measured'], 'to' => 'closed', 'ability' => 'decisions.manage', 'system' => false, 'version' => true, 'reason' => false, 'event' => 'transition'],
        ['action' => 'expire', 'from' => ['suggested', 'assigned', 'reviewed', 'approved'], 'to' => 'expired', 'ability' => null, 'system' => true, 'version' => false, 'reason' => false, 'event' => 'transition'],
    ];

    /** Transiciones que ejecuta una persona (la acción empresarial real ocurre fuera de Predictily: nunca se escribe en el ERP). */
    public const EXECUTION_ACTIONS = ['start', 'start_without_approval', 'execute'];

    public const MIN_REASON_LENGTH = 5;

    /** @return array{action: string, from: list<string>, to: string, ability: string|null, system: bool, version: bool, reason: bool, event: string}|null */
    public static function find(string $from, string $to): ?array
    {
        foreach (self::RULES as $rule) {
            if ($rule['to'] === $to && in_array($from, $rule['from'], true)) {
                return $rule;
            }
        }

        return null;
    }

    /** @return list<array{action: string, from: list<string>, to: string, ability: string|null, system: bool, version: bool, reason: bool, event: string}> */
    public static function from(string $status): array
    {
        return array_values(array_filter(self::RULES, fn (array $r) => in_array($status, $r['from'], true)));
    }
}
