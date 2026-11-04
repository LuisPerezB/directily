<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Models\Tenant;
use Carbon\CarbonImmutable;

/**
 * Parámetros del Decision Engine por empresa (V2.5 · W5-B), guardados en `tenants.settings.decision`.
 *
 * Todos los valores por defecto son PROVISIONALES: no son umbrales universales, tienen justificación documentada en
 * docs/decision/ECONOMIC_PRIORITIZATION.md y quedan «a aprobar en el piloto» con datos reales de cada empresa.
 * Un valor que la empresa no configuró y que no tiene un sustituto honesto es NULL (p. ej. el costo de contacto): nunca se inventa.
 */
final class DecisionSettings
{
    /** @var array<string, mixed> */
    public const DEFAULTS = [
        // Experimento (ADR-V25-14): 0 = sin experimento. Máximo 0,5 (no se retiene a más de la mitad de las unidades).
        'control_fraction' => 0.0,
        'experiment_key' => 'contact-default',
        'experiment_seed' => null,
        // Horizontes del Customer Core para los que se generan oportunidades (deben existir en la corrida servida).
        'horizons' => [30],
        // Costo de una acción de contacto, en la moneda de la empresa. NULL = no configurado (el score no resta nada y la incertidumbre lo declara).
        'contact_cost' => null,
        // Quién produce los contactos y recuperaciones cliente × producto: `radar` (predictily:radar-materialize, W5-A; ADR-V25-12: el Radar es la fachada sobre el
        // Customer Core y es el ÚNICO productor por defecto) o `engine` (predictily:decisions-generate los genera con su propia selección: alternativa para una empresa sin
        // Radar). Ambos usan la misma huella de idempotencia, así que usar los dos nunca duplica una oportunidad idéntica.
        'contact_source' => 'radar',
        // Alineados con la política radar-v1 (config/radar.php): 0,20 deja fuera lo que el modelo considera improbable; el tope acota el trabajo. A aprobar en el piloto.
        'contact_min_probability' => 0.20,
        'max_contact_per_horizon' => 100,
        // Tope de candidatas por grupo de reposición, inventario-demanda y presupuesto (las de mayor riesgo o faltante primero): acota el costo de generar y el trabajo.
        'max_supply_candidates' => 500,
        // Vigencia en días desde el corte. Contacto = su horizonte; reposición = período de revisión de la empresa.
        'expiry_days' => ['recovery' => 30, 'customer_risk' => 30, 'inventory_mismatch' => 14, 'budget_constraint' => 14, 'scenario' => 14],
        // Acciones sensibles: exigen aprobación (reviewed → approved) antes de ejecutarse. Los contactos no la exigen salvo que la empresa lo pida.
        'approval_required_kinds' => ['replenishment', 'inventory_mismatch', 'budget_constraint', 'scenario'],
        'approval_cost_threshold' => null,
        // Peso de ranking cuando el costo no es confiable (se usa el importe en lugar de la contribución). NO es una cifra económica ni un margen supuesto: es un
        // peso conservador (menor que un margen comercial típico) para que lo de costo desconocido no supere a lo de margen conocido solo por no tener costo.
        'unreliable_cost_weight' => 0.2,
        // Confiabilidad del costo unitario (ventas con costo o historial de compras, lectura as-of).
        'cost_reliability' => ['window_days' => 90, 'min_observations' => 3, 'min_coverage' => 0.8, 'max_cv' => 0.25],
        // Una foto de inventario más vieja que esto no se usa (disponibilidad desconocida, nunca cero).
        'inventory_max_age_days' => 7,
        // «Exceso sin demanda»: demanda esperada de clientes < esta fracción de lo disponible.
        'excess_demand_ratio' => 0.10,
    ];

    /** @param array<string, mixed> $values */
    private function __construct(private readonly array $values, private readonly string $tenantUuid, private readonly string $timezone, private readonly int $reviewPeriodDays, private readonly string $currency) {}

    public static function forTenant(Tenant $tenant): self
    {
        $stored = (array) ($tenant->effectiveSettings()['decision'] ?? []);
        $values = self::DEFAULTS;
        foreach ($stored as $key => $value) {
            if (! array_key_exists($key, self::DEFAULTS)) {
                continue;
            }
            $values[$key] = is_array($value) && is_array(self::DEFAULTS[$key]) && ! array_is_list(self::DEFAULTS[$key])
                ? array_replace(self::DEFAULTS[$key], $value)
                : $value;
        }

        return new self(
            $values,
            (string) $tenant->uuid,
            $tenant->timezone ?: 'America/Guayaquil',
            max(1, (int) ($tenant->effectiveSettings()['review_period_days'] ?? 7)),
            (string) ($tenant->currency ?: 'USD'),
        );
    }

    /** @return array<string, mixed> valores efectivos (defaults + los de la empresa) */
    public function all(): array
    {
        return $this->values;
    }

    public function controlFraction(): float
    {
        return max(0.0, min(0.5, (float) $this->values['control_fraction']));
    }

    public function experimentKey(): string
    {
        return (string) ($this->values['experiment_key'] ?: self::DEFAULTS['experiment_key']);
    }

    /** Semilla del experimento. Sin una explícita, se deriva (estable) de la empresa y de la clave del experimento; se registra en cada asignación. */
    public function experimentSeed(): string
    {
        $seed = $this->values['experiment_seed'];

        return is_string($seed) && $seed !== '' ? $seed : hash('sha256', 'predictily-experiment:'.$this->tenantUuid.':'.$this->experimentKey());
    }

    /** @return list<int> */
    public function horizons(): array
    {
        $horizons = array_values(array_unique(array_map('intval', (array) $this->values['horizons'])));
        sort($horizons);

        return $horizons === [] ? [30] : $horizons;
    }

    public function contactCost(): ?float
    {
        return $this->values['contact_cost'] === null ? null : (float) $this->values['contact_cost'];
    }

    public function contactSource(): string
    {
        return $this->values['contact_source'] === 'engine' ? 'engine' : 'radar';
    }

    public function contactMinProbability(): float
    {
        return (float) $this->values['contact_min_probability'];
    }

    public function maxContactPerHorizon(): int
    {
        return max(1, (int) $this->values['max_contact_per_horizon']);
    }

    public function maxSupplyCandidates(): int
    {
        return max(1, (int) $this->values['max_supply_candidates']);
    }

    public function unreliableCostWeight(): float
    {
        return (float) $this->values['unreliable_cost_weight'];
    }

    /** @return array{window_days: int, min_observations: int, min_coverage: float, max_cv: float} */
    public function costReliability(): array
    {
        $c = (array) $this->values['cost_reliability'];

        return [
            'window_days' => max(7, (int) $c['window_days']), 'min_observations' => max(1, (int) $c['min_observations']),
            'min_coverage' => (float) $c['min_coverage'], 'max_cv' => (float) $c['max_cv'],
        ];
    }

    public function inventoryMaxAgeDays(): int
    {
        return max(0, (int) $this->values['inventory_max_age_days']);
    }

    public function excessDemandRatio(): float
    {
        return (float) $this->values['excess_demand_ratio'];
    }

    public function approvalCostThreshold(): ?float
    {
        return $this->values['approval_cost_threshold'] === null ? null : (float) $this->values['approval_cost_threshold'];
    }

    /** ¿Exige este tipo aprobación explícita antes de ejecutarse? (el umbral de costo y las restricciones se evalúan en el Workflow) */
    public function kindRequiresApproval(string $kind): bool
    {
        return in_array($kind, (array) $this->values['approval_required_kinds'], true);
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    /** Fin del día de vigencia en la zona horaria de la empresa. contacto = horizonte; reposición = período de revisión; el resto, `expiry_days`. */
    public function expiresAt(string $kind, CarbonImmutable|string $asOf, int $horizonDays): CarbonImmutable
    {
        $days = match ($kind) {
            'contact' => $horizonDays,
            'replenishment' => $this->reviewPeriodDays,
            default => (int) (((array) $this->values['expiry_days'])[$kind] ?? 14),
        };
        $date = $asOf instanceof CarbonImmutable ? $asOf : CarbonImmutable::parse($asOf, $this->timezone);

        // Fin del día en la zona de la empresa, expresado en la zona de la aplicación (Eloquent guarda la hora tal cual está en el objeto Carbon).
        return CarbonImmutable::parse($date->toDateString(), $this->timezone)->addDays(max(1, $days))->endOfDay()->setTimezone((string) config('app.timezone', 'UTC'));
    }
}
