<?php

declare(strict_types=1);

namespace Tests\Unit\Outcomes;

use App\Models\Tenant;
use App\Services\Outcomes\OutcomeSettings;
use App\Services\Outcomes\OutcomeVocabulary;
use App\Services\Outcomes\OutcomeWindow;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/** V2.5 · W5-C: la ventana de observación es fija desde el horizonte y se mide solo tras cerrar más el margen de espera; los parámetros son por empresa y provisionales. */
final class OutcomeWindowAndSettingsTest extends TestCase
{
    public function test_the_window_is_fixed_from_the_cut_and_the_horizon(): void
    {
        $w = OutcomeWindow::for('2026-09-01', 30, 3);

        $this->assertSame('2026-09-02', $w->from()->toDateString(), 'as_of + 1');
        $this->assertSame('2026-10-01', $w->to()->toDateString(), 'as_of + horizonte');
        $this->assertSame('2026-10-04', $w->cutoff()->toDateString(), 'corte de conocimiento = hasta + margen');
        $this->assertSame(['as_of' => '2026-09-01', 'horizon_days' => 30, 'from' => '2026-09-02', 'to' => '2026-10-01', 'settling_lag_days' => 3, 'knowledge_cutoff' => '2026-10-04'], $w->toArray());
    }

    public function test_it_is_measurable_only_after_the_window_closes_plus_the_lag(): void
    {
        $w = OutcomeWindow::for('2026-09-01', 30, 3);   // hasta 1-oct; corte 4-oct

        $this->assertFalse($w->closedOn(CarbonImmutable::parse('2026-10-01')), 'el último día de la ventana aún no cerró');
        $this->assertTrue($w->closedOn(CarbonImmutable::parse('2026-10-02')));
        $this->assertFalse($w->measurableOn(CarbonImmutable::parse('2026-10-02')), 'cerrada pero en el margen de espera');
        $this->assertFalse($w->measurableOn(CarbonImmutable::parse('2026-10-04')), 'el día del corte aún se pueden estar cargando documentos');
        $this->assertTrue($w->measurableOn(CarbonImmutable::parse('2026-10-05')));
        $this->assertTrue(OutcomeWindow::for('2026-09-01', 30, 0)->measurableOn(CarbonImmutable::parse('2026-10-02')), 'margen 0: el día siguiente al cierre');
    }

    public function test_the_window_accepts_a_datetime_as_the_cut(): void
    {
        $this->assertSame('2026-10-01', OutcomeWindow::for(new \DateTimeImmutable('2026-09-01 15:30:00'), 30, 3)->to()->toDateString());
        $this->assertSame('2026-10-01', OutcomeWindow::for('2026-09-01 00:00:00', 30, 3)->to()->toDateString());
    }

    public function test_settings_default_to_the_provisional_values_and_accept_valid_company_overrides(): void
    {
        $default = OutcomeSettings::forTenant(new Tenant);

        $this->assertSame(3, $default->lagDays());
        $this->assertSame(30, $default->minGroupSize());
        $this->assertEquals(0.95, $default->confidenceLevel());
        $this->assertEqualsWithDelta(1.959964, $default->z(), 1e-6);
        $this->assertSame([], $default->usagePrices(), 'sin precios el uso no se monetiza');
        $this->assertStringContainsString('a aprobar en el piloto', $default->toArray()['status']);

        $own = OutcomeSettings::forTenant(new Tenant(['settings' => ['outcomes' => ['settling_lag_days' => 7, 'min_group_size' => 50, 'confidence_level' => 0.9, 'usage_prices' => ['train_cpu_seconds' => 0.002, 'raro' => -1, 'otro' => 'x']]]]));
        $this->assertSame(7, $own->lagDays());
        $this->assertSame(50, $own->minGroupSize());
        $this->assertEqualsWithDelta(1.644854, $own->z(), 1e-6);
        $this->assertSame(['train_cpu_seconds' => 0.002], $own->usagePrices(), 'un precio negativo o no numérico se descarta');
    }

    public function test_invalid_company_values_are_ignored_and_the_option_overrides_the_lag(): void
    {
        $bad = OutcomeSettings::forTenant(new Tenant(['settings' => ['outcomes' => ['settling_lag_days' => -1, 'min_group_size' => 1, 'confidence_level' => 0.5]]]));

        $this->assertSame(3, $bad->lagDays());
        $this->assertSame(30, $bad->minGroupSize());
        $this->assertEquals(0.95, $bad->confidenceLevel());
        $this->assertSame(10, OutcomeSettings::forTenant(new Tenant(['settings' => ['outcomes' => ['settling_lag_days' => 7]]]), 10)->lagDays());
        $this->assertSame(7, OutcomeSettings::forTenant(new Tenant(['settings' => ['outcomes' => ['settling_lag_days' => 7]]]), 91)->lagDays(), 'una opción fuera de rango se ignora');
    }

    public function test_the_vocabulary_matches_the_database_contract_and_keeps_causal_attribution_unwritten(): void
    {
        $this->assertSame(['forecast', 'observed', 'temporally_associated', 'incremental_estimated', 'causally_attributable'], OutcomeVocabulary::CLASSIFICATIONS);
        $this->assertNotContains(OutcomeVocabulary::CAUSALLY_ATTRIBUTABLE, OutcomeVocabulary::WRITTEN, 'este paquete nunca lo escribe');
        $this->assertNotContains(OutcomeVocabulary::FORECAST, OutcomeVocabulary::WRITTEN, 'lo previsto no se persiste');
        $this->assertNotContains(OutcomeVocabulary::INCREMENTAL_ESTIMATED, OutcomeVocabulary::WRITTEN, 'el incremento es del agregado: no se estampa en las filas');
        $this->assertSame([OutcomeVocabulary::INCREMENTAL_ESTIMATED], OutcomeVocabulary::AGGREGATE_ONLY);
        $this->assertContains('no_sell_through', OutcomeVocabulary::EVENTS);
        $this->assertNotContains('overstock', OutcomeVocabulary::EVENTS, 'el nombre «overstock» se retiró: no diagnostica exceso');
        foreach (OutcomeVocabulary::CLASSIFICATIONS as $c) {
            $this->assertNotEmpty(OutcomeVocabulary::LABELS[$c]);
            $this->assertNotEmpty(OutcomeVocabulary::NOTES[$c]);
        }
        foreach (OutcomeVocabulary::ACTION_KINDS as $a) {
            $this->assertNotEmpty(OutcomeVocabulary::ACTION_LABELS[$a]);
        }
        $this->assertTrue(OutcomeVocabulary::isCustomerKind('contact'));
        $this->assertFalse(OutcomeVocabulary::isCustomerKind('replenishment'));
    }
}
