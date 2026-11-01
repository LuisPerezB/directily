<?php

declare(strict_types=1);

namespace App\Services\Analytics\Support;

/**
 * Estados del registro de modelos (ADR-V2-07, ampliados en V2.5 · W4 por ADR-V25-07): candidate | validated | approved | production |
 * retired | rejected | failed, con el papel (role) primary | fallback en columna aparte. Los nombres de la etapa 1 siguen aceptándose
 * al filtrar y se derivan al presentar (`legacy_status`):
 *   fallback  = production + role fallback · archived = retired · rejected = validated · candidate/production igual.
 * `rejected` ahora también es un estado real (rechazo humano); el alias heredado `?status=rejected` devuelve los validados no promovidos
 * (significado de la etapa 1) Y los rechazados, y `legacy_status` de un rechazado real es `rejected`.
 */
final class ModelStatus
{
    public const STATES = ['candidate', 'validated', 'approved', 'production', 'retired', 'rejected', 'failed'];

    public const LEGACY = ['fallback', 'archived'];

    public const ROLES = ['primary', 'fallback'];

    /** @return list<string> todos los valores aceptados por el filtro `status` */
    public static function accepted(): array
    {
        return [...self::STATES, ...self::LEGACY];
    }

    /**
     * Condición equivalente de un valor de filtro (nuevo o legado).
     *
     * @return array{status: list<string>, role?: string}
     */
    public static function filter(string $value): array
    {
        return match ($value) {
            'fallback' => ['status' => ['production'], 'role' => 'fallback'],
            'archived' => ['status' => ['retired']],
            'rejected' => ['status' => ['validated', 'rejected']],
            default => ['status' => [$value]],
        };
    }

    public static function legacy(?string $status, ?string $role): ?string
    {
        return match (true) {
            $status === 'production' && $role === 'fallback' => 'fallback',
            $status === 'retired' => 'archived',
            $status === 'validated', $status === 'rejected' => 'rejected',
            default => $status,
        };
    }
}
