<?php

declare(strict_types=1);

namespace App\Services\Outcomes;

use App\Models\Tenant;

/**
 * Parámetros de la medición de outcomes por empresa (V2.5 · W5-C): los de `config/outcomes.php` más lo que la empresa fije en `tenants.settings.outcomes`.
 * Todos son provisionales («a aprobar en el piloto»); un valor fuera de rango se ignora (vale el de la configuración), nunca se corrige en silencio a otra cifra.
 */
final class OutcomeSettings
{
    /** z de dos colas para los niveles de confianza admitidos. */
    public const Z = ['0.8' => 1.2815515655446004, '0.9' => 1.6448536269514722, '0.95' => 1.959963984540054, '0.99' => 2.5758293035489004];

    /** @param array<string, mixed> $values */
    private function __construct(private readonly array $values, private readonly string $timezone, private readonly string $currency, private readonly string $tenantUuid) {}

    public static function forTenant(Tenant $tenant, ?int $lagOverride = null): self
    {
        $floors = (array) config('outcomes.floors', []);
        $floorGroup = max(2, (int) ($floors['min_group_size'] ?? 20));
        $floorBuyers = max(1, (int) ($floors['min_buyers_per_arm'] ?? 5));
        $floorResamples = max(100, (int) ($floors['bootstrap_resamples'] ?? 500));
        $base = [
            'settling_lag_days' => (int) config('outcomes.settling_lag_days', 3),
            'confidence_level' => (float) config('outcomes.confidence_level', 0.95),
            'min_group_size' => max($floorGroup, (int) config('outcomes.min_group_size', 30)),
            'min_buyers_per_arm' => max($floorBuyers, (int) config('outcomes.min_buyers_per_arm', 10)),
            'bootstrap_resamples' => max($floorResamples, (int) config('outcomes.bootstrap_resamples', 2000)),
            'usage_prices' => (array) config('outcomes.usage_prices', []),
        ];
        $stored = (array) ($tenant->effectiveSettings()['outcomes'] ?? []);
        $lag = $stored['settling_lag_days'] ?? null;
        if (is_numeric($lag) && (int) $lag >= 0 && (int) $lag <= 90) {
            $base['settling_lag_days'] = (int) $lag;
        }
        $level = $stored['confidence_level'] ?? null;
        if (is_numeric($level) && isset(self::Z[self::levelKey((float) $level)])) {
            $base['confidence_level'] = (float) $level;
        }
        $min = $stored['min_group_size'] ?? null;
        if (is_numeric($min) && (int) $min >= $floorGroup && (int) $min <= 100000) {
            $base['min_group_size'] = (int) $min;
        }
        $buyers = $stored['min_buyers_per_arm'] ?? null;
        if (is_numeric($buyers) && (int) $buyers >= $floorBuyers && (int) $buyers <= 100000) {
            $base['min_buyers_per_arm'] = (int) $buyers;
        }
        $resamples = $stored['bootstrap_resamples'] ?? null;
        if (is_numeric($resamples) && (int) $resamples >= $floorResamples && (int) $resamples <= 20000) {
            $base['bootstrap_resamples'] = (int) $resamples;
        }
        if (isset($stored['usage_prices']) && is_array($stored['usage_prices'])) {
            $base['usage_prices'] = array_filter($stored['usage_prices'], fn ($p, $m) => is_string($m) && is_numeric($p) && (float) $p >= 0, ARRAY_FILTER_USE_BOTH);
        }
        if ($lagOverride !== null && $lagOverride >= 0 && $lagOverride <= 90) {
            $base['settling_lag_days'] = $lagOverride;
        }
        if (! isset(self::Z[self::levelKey((float) $base['confidence_level'])])) {
            $base['confidence_level'] = 0.95;
        }

        return new self($base, $tenant->timezone ?: 'America/Guayaquil', (string) ($tenant->currency ?: 'USD'), (string) ($tenant->uuid ?? ''));
    }

    private static function levelKey(float $level): string
    {
        return rtrim(rtrim(number_format($level, 2, '.', ''), '0'), '.');
    }

    public function lagDays(): int
    {
        return (int) $this->values['settling_lag_days'];
    }

    public function confidenceLevel(): float
    {
        return (float) $this->values['confidence_level'];
    }

    public function z(): float
    {
        return self::Z[self::levelKey($this->confidenceLevel())];
    }

    public function minGroupSize(): int
    {
        return (int) $this->values['min_group_size'];
    }

    public function minBuyersPerArm(): int
    {
        return (int) $this->values['min_buyers_per_arm'];
    }

    public function bootstrapResamples(): int
    {
        return (int) $this->values['bootstrap_resamples'];
    }

    public function bootstrapMaxDraws(): int
    {
        return max(1000000, (int) config('outcomes.bootstrap_max_draws', 40000000));
    }

    /**
     * Semilla DETERMINISTA del bootstrap de un experimento y horizonte: los 32 primeros bits de SHA-256(«outcomes-bootstrap-v1|empresa|experimento|horizonte»). Los mismos
     * datos dan siempre el mismo intervalo; cambiar de experimento u horizonte cambia la semilla. Se registra en la evidencia.
     */
    public function bootstrapSeed(string $experimentKey, int $horizonDays): int
    {
        return (int) hexdec(substr(hash('sha256', 'outcomes-bootstrap-v1|'.$this->tenantUuid.'|'.$experimentKey.'|'.$horizonDays), 0, 8));
    }

    /** @return array<string, float> precio por unidad de cada métrica de uso; vacío = el uso de plataforma no se monetiza */
    public function usagePrices(): array
    {
        return array_map('floatval', (array) $this->values['usage_prices']);
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function methodVersion(): string
    {
        return (string) config('outcomes.method_version', 'outcomes-v1');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'method_version' => $this->methodVersion(), 'settling_lag_days' => $this->lagDays(), 'confidence_level' => $this->confidenceLevel(),
            'min_group_size' => $this->minGroupSize(), 'min_buyers_per_arm' => $this->minBuyersPerArm(), 'bootstrap_resamples' => $this->bootstrapResamples(),
            'usage_prices_configured' => array_keys($this->usagePrices()),
            'status' => 'Valores provisionales: a aprobar en el piloto con el retraso real de ingestión y el tamaño de cada experimento.',
        ];
    }
}
