<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Enums\TenantRole;

/**
 * La pertenencia a un experimento (grupo de tratamiento o de control, clave del experimento, clave de la unidad) es información del análisis, no del equipo comercial:
 * conocerla permite contaminar la medición (contactar a quien debe quedar sin contacto) y delata al grupo de control. SE GUARDA completa (el evento inicial de la
 * recomendación conserva `payload.experiment`, que la medición de outcomes necesita) y se oculta en la PRESENTACIÓN a quien no puede aprobar (`decisions.approve`).
 */
final class ExperimentRedaction
{
    /** Claves que identifican el experimento, en cualquier nivel de un payload. */
    public const KEYS = ['experiment', 'experiment_group', 'experiment_key', 'unit_key', 'unit_type'];

    public static function canSee(?TenantRole $role): bool
    {
        return $role !== null && $role->can('decisions.approve');
    }

    /**
     * @param  array<string|int, mixed>  $payload
     * @return array<string|int, mixed>
     */
    public static function strip(array $payload): array
    {
        $out = [];
        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array($key, self::KEYS, true)) {
                continue;
            }
            $out[$key] = is_array($value) ? self::strip($value) : $value;
        }

        return $out;
    }
}
