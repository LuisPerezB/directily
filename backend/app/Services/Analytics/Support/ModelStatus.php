<?php

declare(strict_types=1);

namespace App\Services\Analytics\Support;

/**
 * Estados del registro de modelos (ADR-V2-07): candidate | validated | production | retired | failed,
 * con el papel (role) primary | fallback en columna aparte. Los nombres de la etapa 1 siguen aceptándose
 * al filtrar y se derivan al presentar (`legacy_status`):
 *   fallback  = production + role fallback · archived = retired · rejected = validated · candidate/production igual.
 */
final class ModelStatus
{
    public const STATES = ['candidate', 'validated', 'production', 'retired', 'failed'];

    public const LEGACY = ['fallback', 'archived', 'rejected'];

    public const ROLES = ['primary', 'fallback'];

    /** @return list<string> todos los valores aceptados por el filtro `status` */
    public static function accepted(): array
    {
        return [...self::STATES, ...self::LEGACY];
    }

    /**
     * Condición equivalente de un valor de filtro (nuevo o legado).
     *
     * @return array{status: string, role?: string}
     */
    public static function filter(string $value): array
    {
        return match ($value) {
            'fallback' => ['status' => 'production', 'role' => 'fallback'],
            'archived' => ['status' => 'retired'],
            'rejected' => ['status' => 'validated'],
            default => ['status' => $value],
        };
    }

    public static function legacy(?string $status, ?string $role): ?string
    {
        return match (true) {
            $status === 'production' && $role === 'fallback' => 'fallback',
            $status === 'retired' => 'archived',
            $status === 'validated' => 'rejected',
            default => $status,
        };
    }
}
