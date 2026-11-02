<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Models\ExperimentAssignment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Asignación tratamiento/control por cliente (V2.5 · W5-B; ADR-V25-14), registrada AL GENERAR la recomendación en `experiment_assignments`.
 *
 * - Fracción de control por empresa (`decision.control_fraction`, por defecto 0 = sin experimento: no se asigna ni se escribe nada).
 * - REPRODUCIBLE: u = SHA-256(semilla | clave del experimento | unidad) → [0, 1); control si u < fracción. La semilla, la fracción y u quedan
 *   en `policy`, de modo que cualquiera puede recalcular el grupo de una unidad (ver `groupFor`). La unidad es el cliente: un cliente cae
 *   siempre en el mismo grupo dentro de un experimento (sin contaminación entre recomendaciones), y una asignación ya registrada no cambia aunque
 *   la fracción cambie después (se conserva la política con la que se asignó).
 * - Estratificación: DISEÑADA, no implementada (`policy.stratification = null`).
 * Las unidades de control NO se entregan a vendedores: quedan para medición (Workflow::assign las rechaza).
 */
final class ExperimentAssigner
{
    public const ALGORITHM = 'sha256-uniform-v1';

    /**
     * @return array{group: string, experiment_key: string, policy: array<string, mixed>}|null null = sin experimento (fracción 0)
     */
    public function assign(DecisionSettings $settings, int $customerId): ?array
    {
        $fraction = $settings->controlFraction();
        if ($fraction <= 0.0) {
            return null;
        }
        $key = $settings->experimentKey();
        $unitKey = self::unitKey($customerId);

        $existing = ExperimentAssignment::query()->where('experiment_key', $key)->where('unit_type', 'customer')->where('unit_key', $unitKey)->first();
        if ($existing !== null) {
            return ['group' => $existing->group, 'experiment_key' => $key, 'policy' => (array) $existing->policy];
        }

        $seed = $settings->experimentSeed();
        $u = self::uniform($seed, $key, $unitKey);
        $group = $u < $fraction ? 'control' : 'treatment';
        $policy = [
            'algorithm' => self::ALGORITHM, 'control_fraction' => $fraction, 'seed' => $seed, 'u' => round($u, 12),
            'unit' => 'customer', 'stratification' => null,
        ];
        try {
            DB::transaction(fn () => ExperimentAssignment::query()->create([
                'experiment_key' => $key, 'unit_type' => 'customer', 'unit_key' => $unitKey, 'group' => $group, 'policy' => $policy,
            ]));
        } catch (UniqueConstraintViolationException) {
            $existing = ExperimentAssignment::query()->where('experiment_key', $key)->where('unit_type', 'customer')->where('unit_key', $unitKey)->firstOrFail();

            return ['group' => $existing->group, 'experiment_key' => $key, 'policy' => (array) $existing->policy];
        }

        return ['group' => $group, 'experiment_key' => $key, 'policy' => $policy];
    }

    public static function unitKey(int $customerId): string
    {
        return 'customer:'.$customerId;
    }

    /** u ∈ [0, 1) determinista a partir de la semilla registrada. */
    public static function uniform(string $seed, string $experimentKey, string $unitKey): float
    {
        $hash = hash('sha256', $seed.'|'.$experimentKey.'|'.$unitKey);

        return hexdec(substr($hash, 0, 12)) / (16 ** 12);
    }

    /** Recalcula el grupo con la política registrada (auditoría independiente de la asignación). */
    public static function groupFor(array $policy, string $experimentKey, string $unitKey): string
    {
        return self::uniform((string) $policy['seed'], $experimentKey, $unitKey) < (float) $policy['control_fraction'] ? 'control' : 'treatment';
    }
}
