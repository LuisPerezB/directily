<?php

declare(strict_types=1);

namespace App\Services\Models;

/**
 * Estados del registro de modelos y transiciones permitidas (ADR-V25-07; migración 2026_03_12_000100, CHECK de `status`).
 *
 *   candidate → validated → approved → production → retired
 *   rejected  (terminal; desde candidate, validated o approved)      failed (terminal; legado del motor)
 *
 * Una transición que no esté en este mapa NO existe: la rechaza ModelLifecycle con 409 `model_transition_not_allowed`.
 * Que un modelo pueda ENTRAR en producción además exige haber sido aprobado o ser legado (ModelServingPolicy).
 */
final class ModelState
{
    public const CANDIDATE = 'candidate';

    public const VALIDATED = 'validated';

    public const APPROVED = 'approved';

    public const PRODUCTION = 'production';

    public const RETIRED = 'retired';

    public const REJECTED = 'rejected';

    public const FAILED = 'failed';

    /** @var list<string> */
    public const ALL = [self::CANDIDATE, self::VALIDATED, self::APPROVED, self::PRODUCTION, self::RETIRED, self::REJECTED, self::FAILED];

    /**
     * Transiciones explícitas: estado actual => estados a los que puede pasar.
     * - validated → production y retired → production existen para el legado y la reversión; ModelLifecycle las limita a modelos
     *   aprobados o legados (un candidato publicado jamás llega a producción sin aprobación).
     * - approved → rejected permite retirar una aprobación antes de que el modelo entre en producción.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::CANDIDATE => [self::VALIDATED, self::REJECTED, self::FAILED],
        self::VALIDATED => [self::APPROVED, self::REJECTED, self::RETIRED, self::PRODUCTION],
        self::APPROVED => [self::PRODUCTION, self::REJECTED, self::RETIRED],
        self::PRODUCTION => [self::RETIRED],
        self::RETIRED => [self::PRODUCTION],
        self::REJECTED => [],
        self::FAILED => [],
    ];

    public static function allows(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function isTerminal(string $status): bool
    {
        return (self::TRANSITIONS[$status] ?? []) === [];
    }
}
