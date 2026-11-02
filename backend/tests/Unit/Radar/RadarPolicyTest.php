<?php

declare(strict_types=1);

namespace Tests\Unit\Radar;

use App\Services\Radar\RadarPolicy;
use PHPUnit\Framework\TestCase;

/** V2.5 · W5-A: la política `radar-v1` normaliza sus parámetros y los versiona con una huella SHA-256 (los umbrales son hipótesis «a aprobar en el piloto»). */
final class RadarPolicyTest extends TestCase
{
    public function test_defaults_when_nothing_is_configured(): void
    {
        $policy = new RadarPolicy([]);

        $this->assertSame(0.20, $policy->minProbability());
        $this->assertSame(100, $policy->maxPerKindHorizon());
        $this->assertSame(3, $policy->recoveryMinPurchases());
        $this->assertSame(['rules_version', 'min_probability', 'max_per_kind_horizon', 'recovery_min_purchases'], array_keys($policy->parameters()));
    }

    public function test_out_of_range_values_are_clamped_not_trusted(): void
    {
        $policy = new RadarPolicy(['min_probability' => 7, 'max_per_kind_horizon' => -5, 'recovery_min_purchases' => -1]);

        $this->assertSame(1.0, $policy->minProbability());
        $this->assertSame(1, $policy->maxPerKindHorizon());
        $this->assertSame(0, $policy->recoveryMinPurchases());
        $this->assertSame(0.0, (new RadarPolicy(['min_probability' => -3]))->minProbability());
    }

    public function test_the_version_is_a_stable_fingerprint_of_the_parameters(): void
    {
        $a = new RadarPolicy(['min_probability' => 0.2]);
        $b = new RadarPolicy(['recovery_min_purchases' => 3, 'min_probability' => 0.2]);
        $c = new RadarPolicy(['min_probability' => 0.25]);

        $this->assertSame($a->fingerprint(), $b->fingerprint(), 'el orden de entrada no cambia la huella');
        $this->assertSame(64, strlen($a->fingerprint()));
        $this->assertNotSame($a->fingerprint(), $c->fingerprint());
        $this->assertSame('radar-v1:'.substr($a->fingerprint(), 0, 12), $a->version());
        $this->assertLessThanOrEqual(60, strlen($a->version()), 'cabe en decision_recommendations.policy');
        $this->assertSame('radar-v2:'.substr((new RadarPolicy(['rules_version' => 'radar-v2']))->fingerprint(), 0, 12), (new RadarPolicy(['rules_version' => 'radar-v2']))->version());
    }

    public function test_the_kinds_the_radar_lists_and_the_ones_it_produces(): void
    {
        $this->assertSame(['contact', 'recovery', 'customer_risk'], RadarPolicy::KINDS);
        $this->assertSame(['contact', 'recovery'], RadarPolicy::PRODUCED_KINDS, 'el riesgo de cliente y la recuperación de nivel cliente los genera el Decision Engine');
        $this->assertSame('Oportunidad potencial, atribución no demostrada', RadarPolicy::ATTRIBUTION_TEXT);
        foreach (['kind', 'state', 'status', 'score', 'expected_gross_value', 'expected_contribution', 'attribution', 'inventory', 'window', 'expected_orders'] as $key) {
            $this->assertNotEmpty(RadarPolicy::DEFINITIONS[$key], "falta la definición de {$key}");
        }
    }
}
