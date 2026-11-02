<?php

declare(strict_types=1);

namespace App\Services\Analytics\ModelQuality;

use App\Models\ModelPromotionDecision;
use App\Services\Analytics\Support\ModelCatalog;
use App\Support\Results\JsonMap;

/**
 * Presenta una fila de `model_promotion_decisions` (V2.5 · W3-B, ADR-V25-21) para GET /models/promotion-decisions: la decisión de UNA política
 * (`legacy` o `v25`) en UN alcance (`global`, `class:<clase>`, `horizon:<H>`), si gobernó lo servido (`is_effective`), sus gates con la evidencia
 * que el motor registró y el estado de la aprobación humana separada (W4 · S06).
 */
final class PromotionDecisionPresenter
{
    public const POLICIES = ['legacy', 'v25'];

    public const DECISIONS = ['promote', 'reject', 'keep_champion', 'publish_baseline', 'shadow'];

    public const GATE_STATUSES = ['pass', 'fail', 'not_evaluable', 'not_applicable', 'pending'];

    /** @return array<string, mixed> */
    public static function row(ModelPromotionDecision $d): array
    {
        $metrics = is_array($d->metrics) ? $d->metrics : [];
        $gates = is_array($d->gates) ? $d->gates : [];
        $served = is_string($metrics['served_family'] ?? null) ? $metrics['served_family'] : null;
        $planned = is_string($metrics['planned_family'] ?? null) ? $metrics['planned_family'] : null;

        return [
            'id' => $d->id,
            'core' => $d->core,
            'forecast_run_id' => $d->forecast_run_id,
            'core_run_id' => $d->core_run_id,
            'scope_key' => $d->scope_key,
            'scope' => self::scope($d->scope_key),
            'policy' => $d->policy,
            'policy_version' => $d->policy_version,
            'candidate_family' => $d->candidate_family,
            'candidate_label' => ModelCatalog::label($d->candidate_family),
            'champion_family' => $d->champion_family,
            'champion_label' => $d->champion_family === null ? null : ModelCatalog::label($d->champion_family),
            'candidate_model_registry_id' => $d->candidate_model_registry_id,
            'champion_model_registry_id' => $d->champion_model_registry_id,
            'decision' => $d->decision,
            'is_effective' => (bool) $d->is_effective,
            // Alcances informativos (p. ej. por horizonte): se registran pero no gobiernan lo servido.
            'informational' => (bool) ($metrics['informational'] ?? false),
            // Lo que la política dejó servido; tras un respaldo del entrenamiento final, la familia planeada se conserva aparte.
            'served_family' => $served,
            'served_label' => $served === null ? null : ModelCatalog::label($served),
            'planned_family' => $planned,
            'fallback_applied' => (bool) ($metrics['fallback_applied'] ?? false),
            'gates' => JsonMap::orEmpty($gates),
            'gates_summary' => self::gatesSummary($gates),
            'failed_gates' => self::gatesWith($gates, 'fail'),
            'metrics' => JsonMap::orEmpty($metrics),
            'reason' => $d->reason,
            'approval' => [
                'status' => $d->approved_at !== null ? 'approved' : 'pending',
                'approved_by' => $d->approved_by,
                'approved_at' => $d->approved_at?->toIso8601String(),
            ],
            'decided_at' => $d->decided_at?->toIso8601String(),
            'created_at' => $d->created_at?->toIso8601String(),
        ];
    }

    /** @return array{type: string, value: string|null} */
    public static function scope(string $scopeKey): array
    {
        if (! str_contains($scopeKey, ':')) {
            return ['type' => $scopeKey, 'value' => null];
        }
        [$type, $value] = explode(':', $scopeKey, 2);

        return ['type' => $type, 'value' => $value];
    }

    /**
     * @param  array<string, mixed>  $gates
     * @return array<string, int>
     */
    private static function gatesSummary(array $gates): array
    {
        $counts = array_fill_keys(self::GATE_STATUSES, 0);
        foreach ($gates as $gate) {
            $status = is_array($gate) ? ($gate['status'] ?? null) : null;
            if (is_string($status) && array_key_exists($status, $counts)) {
                $counts[$status]++;
            }
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $gates
     * @return list<string>
     */
    private static function gatesWith(array $gates, string $status): array
    {
        $names = [];
        foreach ($gates as $name => $gate) {
            if (is_array($gate) && ($gate['status'] ?? null) === $status) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }
}
