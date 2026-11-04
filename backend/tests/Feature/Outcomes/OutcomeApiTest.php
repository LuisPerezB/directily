<?php

declare(strict_types=1);

namespace Tests\Feature\Outcomes;

use App\Contracts\Decision\RecommendationSink;
use App\Models\DecisionRecommendation;
use App\Services\Outcomes\OutcomeMeasurementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Outcomes\Support\OutcomeFixtures;
use Tests\TestCase;

/**
 * V2.5 · W5-C (ADR-V25-14): API de solo lectura de outcomes y ROI (`decisions.view`). Filtros, paginado, aislamiento entre empresas, el grupo de control oculto a quien
 * no aprueba, y un ROI honesto: «oportunidad potencial» sin control, incremento estimado con intervalo con control, y NULL donde el costo no es confiable.
 */
final class OutcomeApiTest extends TestCase
{
    use OutcomeFixtures;
    use RefreshDatabase;

    private function day(int $daysAgo): string
    {
        return $this->today()->subDays($daysAgo)->toDateString();
    }

    private function iso(int $daysAgo, string $time = '10:00:00'): string
    {
        return $this->day($daysAgo).' '.$time.'-05';
    }

    private function measure(array $w): void
    {
        app(OutcomeMeasurementService::class)->measure($w['tenant']);
    }

    /** @param array<string, mixed> $settings */
    private function configure(array $w, array $settings): void
    {
        $w['tenant']->refresh();
        $w['tenant']->forceFill(['settings' => array_merge($w['tenant']->effectiveSettings(), $settings)])->save();
    }

    /** Mundo con 4 recomendaciones medidas: ejecutada+compra (asociada), no contactada+compra, no contactada sin compra y rechazada. @return array{w: array, recs: array<string, DecisionRecommendation>} */
    private function measuredWorld(): array
    {
        $w = $this->outcomeWorld(40);
        $c = $this->newCustomers($w['tenant'], 4);
        $p1 = $w['cat']['products']['p1'];
        $recs = [
            'associated' => $this->contactFor($w, $c[0]),
            'observed' => $this->contactFor($w, $c[1]),
            'quiet' => $this->contactFor($w, $c[2]),
            'rejected' => $this->contactFor($w, $c[3]),
        ];
        $this->executeAt($w, $recs['associated'], $this->iso(30));
        $this->purchase($w['tenant'], $c[0], $p1, $this->day(20), 2, 8.0, 6.0);
        $this->purchase($w['tenant'], $c[1], $p1, $this->day(20), 3, 8.0, 6.0);
        $this->stepAs($w, (int) $recs['rejected']->id, 'rejected', 'dismiss', ['reason' => 'No aplica']);
        $this->measure($w);

        return compact('w', 'recs');
    }

    // ───────────────────────── lista ─────────────────────────

    public function test_the_list_returns_the_measured_outcomes_with_their_honest_classification_and_evidence(): void
    {
        ['w' => $w, 'recs' => $recs] = $this->measuredWorld();

        $json = $this->actingAsTenantUser($w['viewer'], $w['tenant'])->getJson('/api/v1/decisions/outcomes')->assertOk()
            ->assertJsonCount(4, 'data')->assertJsonPath('meta.total', 4);

        $byRec = collect($json->json('data'))->keyBy('recommendation.id');
        $a = $byRec[$recs['associated']->id];
        $this->assertSame('temporally_associated', $a['classification']);
        $this->assertSame('Asociado temporalmente', $a['classification_label']);
        $this->assertStringContainsString('NO se puede decir que la acción la causó', $a['classification_note']);
        $this->assertSame('contacted', $a['action_kind']);
        $this->assertSame('Contactada', $a['action_label']);
        $this->assertSame('purchase', $a['observed_event']);
        $this->assertEquals(16.0, $a['observed_net_amount']);
        $this->assertEquals(4.0, $a['contribution']);
        $this->assertSame('known', $a['contribution_status']);
        $this->assertSame('contact', $a['recommendation']['kind']);
        $this->assertSame('potential_unproven', $a['recommendation']['attribution_label_at_generation']);
        $this->assertSame($this->day(39), $a['window']['from']);
        $this->assertSame($this->day(10), $a['window']['to']);
        $this->assertSame($this->day(7), $a['window']['knowledge_cutoff']);
        $this->assertSame(64, strlen($a['evidence']['source']['fingerprint']));
        $this->assertArrayHasKey('external_code', $a['recommendation']['customer']);
        $this->assertArrayNotHasKey('experiment_group', $a, 'quien no aprueba no ve el grupo del experimento');
        $this->assertSame('rejected', $byRec[$recs['rejected']->id]['action_kind']);
        $this->assertSame('no_purchase', $byRec[$recs['quiet']->id]['observed_event']);
        $this->assertStringNotContainsString('"name"', json_encode($a['recommendation']['customer']), 'sin datos personales del comprador');
    }

    public function test_the_list_filters_paginates_and_sorts(): void
    {
        ['w' => $w, 'recs' => $recs] = $this->measuredWorld();
        $get = fn (string $query) => $this->actingAsTenantUser($w['analyst'], $w['tenant'])->getJson('/api/v1/decisions/outcomes?'.$query);

        $this->assertSame(1, $get('classification=temporally_associated')->assertOk()->json('meta.total'));
        $this->assertSame(3, $get('classification=observed')->json('meta.total'));
        $this->assertSame(4, $get('classification=observed,temporally_associated')->json('meta.total'));
        $this->assertSame(0, $get('classification=incremental_estimated')->json('meta.total'));
        $this->assertSame(0, $get('classification=inventado')->json('meta.total'), 'un valor desconocido no filtra «todo»');
        $this->assertSame(1, $get('action_kind=rejected')->json('meta.total'));
        $this->assertSame(2, $get('observed_event=purchase')->json('meta.total'));
        $this->assertSame(4, $get('kind=contact')->json('meta.total'));
        $this->assertSame(0, $get('kind=replenishment')->json('meta.total'));
        $this->assertSame(4, $get('horizon_days=30')->json('meta.total'));
        $this->assertSame(0, $get('horizon_days=90')->json('meta.total'));
        $this->assertSame(4, $get('from='.$this->day(41).'&to='.$this->day(39))->json('meta.total'));
        $this->assertSame(0, $get('from='.$this->day(10))->json('meta.total'), 'el periodo filtra por el corte de la recomendación');
        $this->assertSame(1, $get('recommendation_id='.$recs['rejected']->id)->json('meta.total'));
        $this->assertSame(1, $get('customer_id='.$recs['observed']->customer_id)->json('meta.total'));
        $this->assertSame(2, $get('contribution_known=1&observed_event=purchase')->json('meta.total'));
        $page = $get('per_page=2&page=2&sort=id');
        $this->assertCount(2, $page->json('data'));
        $this->assertSame(2, $page->json('meta.current_page'));
        $this->assertSame(4, $page->json('meta.total'));
        $sorted = $get('sort=-observed_net_amount')->json('data');
        $this->assertEquals(24.0, $sorted[0]['observed_net_amount']);
        $get('per_page=500')->assertStatus(422);
        $get('from=ayer')->assertStatus(422);
        $get('sort=raro')->assertStatus(422);
        $get('to='.$this->day(30).'&from='.$this->day(20))->assertStatus(422);
    }

    public function test_the_api_is_read_only_and_requires_authentication_and_a_company(): void
    {
        ['w' => $w] = $this->measuredWorld();

        foreach (['postJson', 'putJson', 'patchJson', 'deleteJson'] as $verb) {
            $this->actingAsTenantUser($w['owner'], $w['tenant'])->{$verb}('/api/v1/decisions/outcomes', [])->assertStatus(405);
            $this->actingAsTenantUser($w['owner'], $w['tenant'])->{$verb}('/api/v1/decisions/roi', [])->assertStatus(405);
        }
        $this->assertSame(4, DB::table('decision_outcomes')->count());
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $w = $this->outcomeWorld(40);

        $this->withHeader('X-Tenant-ID', $w['tenant']->uuid)->getJson('/api/v1/decisions/outcomes')->assertStatus(401);
        $this->withHeader('X-Tenant-ID', $w['tenant']->uuid)->getJson('/api/v1/decisions/roi')->assertStatus(401);
    }

    // ───────────────────────── aislamiento ─────────────────────────

    public function test_one_company_never_sees_the_outcomes_or_the_roi_of_another(): void
    {
        ['w' => $a, 'recs' => $recs] = $this->measuredWorld();
        $b = $this->outcomeWorld(40);
        $cb = $this->newCustomers($b['tenant'], 1, 'CLI-B');
        $recB = $this->contactFor($b, $cb[0]);
        $this->measure($b);

        // La lista de B solo trae lo suyo; el id de una recomendación de A no devuelve nada.
        $listB = $this->actingAsTenantUser($b['owner'], $b['tenant'])->getJson('/api/v1/decisions/outcomes')->assertOk();
        $this->assertSame([(int) $recB->id], collect($listB->json('data'))->pluck('recommendation.id')->all());
        $this->actingAsTenantUser($b['owner'], $b['tenant'])->getJson('/api/v1/decisions/outcomes?recommendation_id='.$recs['associated']->id)->assertOk()->assertJsonCount(0, 'data');
        $this->actingAsTenantUser($b['owner'], $b['tenant'])->getJson('/api/v1/decisions/outcomes?customer_id='.$recs['associated']->customer_id)->assertOk()->assertJsonCount(0, 'data');
        // El ROI de B cuenta solo su recomendación.
        $roiB = $this->actingAsTenantUser($b['owner'], $b['tenant'])->getJson('/api/v1/decisions/roi')->assertOk();
        $this->assertSame(1, $roiB->json('data.summary.funnel.identified'));
        $roiA = $this->actingAsTenantUser($a['owner'], $a['tenant'])->getJson('/api/v1/decisions/roi')->assertOk();
        $this->assertSame(4, $roiA->json('data.summary.funnel.identified'));
        // Un usuario de A no puede leer los datos de B cambiando la cabecera de empresa.
        $this->actingAsTenantUser($a['owner'], $b['tenant'])->getJson('/api/v1/decisions/outcomes')->assertNotFound();
        $this->actingAsTenantUser($a['owner'], $b['tenant'])->getJson('/api/v1/decisions/roi')->assertNotFound();
    }

    public function test_every_role_with_decisions_view_can_read(): void
    {
        ['w' => $w] = $this->measuredWorld();
        foreach (['viewer', 'analyst', 'admin', 'owner'] as $who) {
            $this->actingAsTenantUser($w[$who], $w['tenant'])->getJson('/api/v1/decisions/outcomes')->assertOk();
            $this->actingAsTenantUser($w[$who], $w['tenant'])->getJson('/api/v1/decisions/roi')->assertOk();
        }
    }

    public function test_the_control_group_is_listed_only_to_those_who_approve(): void
    {
        $w = $this->outcomeWorld(40);
        $this->outcomeSettings($w, ['min_group_size' => 2, 'min_buyers_per_arm' => 1]);
        $c = $this->newCustomers($w['tenant'], 4);
        $treated = $this->contactFor($w, $c[0]);
        $control = $this->contactFor($w, $c[1]);
        $this->assignExperiment($w['tenant'], $treated, 'treatment');
        $this->assignExperiment($w['tenant'], $control, 'control');
        $this->measure($w);

        $viewer = $this->actingAsTenantUser($w['analyst'], $w['tenant'])->getJson('/api/v1/decisions/outcomes')->assertOk();
        $this->assertSame([(int) $treated->id], collect($viewer->json('data'))->pluck('recommendation.id')->all(), 'a quien contacta no se le revela quién quedó en el control');
        $row = $viewer->json('data.0');
        $this->assertArrayNotHasKey('experiment_group', $row);
        // ExperimentRedaction de W5-B: ni grupo, ni clave, ni unidad, ni la estimación por grupos en NINGÚN lugar de la evidencia.
        $this->assertArrayNotHasKey('experiment', $row['evidence']);
        $this->assertArrayNotHasKey('itt', $row['evidence']);
        $this->assertArrayNotHasKey('unit', $row['evidence']);
        $this->assertStringNotContainsString('experiment', json_encode($row['evidence']), 'ni una clave de experimento');
        $this->assertStringNotContainsString('exp-prueba', json_encode($row));
        $this->actingAsTenantUser($w['analyst'], $w['tenant'])->getJson('/api/v1/decisions/outcomes?experiment_key=exp-prueba')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAsTenantUser($w['analyst'], $w['tenant'])->getJson('/api/v1/decisions/outcomes?experiment_group=control')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAsTenantUser($w['analyst'], $w['tenant'])->getJson('/api/v1/decisions/outcomes?experiment_group=treatment')->assertOk()->assertJsonCount(0, 'data');

        $admin = $this->actingAsTenantUser($w['admin'], $w['tenant'])->getJson('/api/v1/decisions/outcomes')->assertOk();
        $this->assertEqualsCanonicalizing([(int) $treated->id, (int) $control->id], collect($admin->json('data'))->pluck('recommendation.id')->all());
        $controlRow = collect($admin->json('data'))->firstWhere('recommendation.id', $control->id);
        $this->assertSame('control', $controlRow['experiment_group']);
        $this->assertSame('control', $controlRow['action_kind']);
        $this->actingAsTenantUser($w['admin'], $w['tenant'])->getJson('/api/v1/decisions/outcomes?experiment_group=control')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAsTenantUser($w['admin'], $w['tenant'])->getJson('/api/v1/decisions/outcomes?experiment_key=exp-prueba')->assertOk()->assertJsonCount(2, 'data');
        $adminControl = collect($admin->json('data'))->firstWhere('recommendation.id', $control->id);
        $this->assertSame('exp-prueba', $adminControl['evidence']['experiment']['key'], 'quien aprueba conserva todo el detalle');
        $this->assertArrayHasKey('itt', $adminControl['evidence']);
    }

    // ───────────────────────── ROI sin control ─────────────────────────

    public function test_without_a_control_group_the_roi_is_potential_unproven_and_never_proven(): void
    {
        ['w' => $w] = $this->measuredWorld();

        $roi = $this->actingAsTenantUser($w['admin'], $w['tenant'])->getJson('/api/v1/decisions/roi')->assertOk()->json('data');

        $this->assertSame('potential_unproven', $roi['roi']['status']);
        $this->assertFalse($roi['roi']['proven']);
        $this->assertNull($roi['roi']['value'], 'sin control no hay cifra de ROI');
        $this->assertSame('Oportunidad potencial, atribución no demostrada', $roi['roi']['label']);
        $this->assertSame(['no_control_group'], $roi['roi']['reasons']);
        $this->assertSame(0, $roi['roi']['causal_claims']);
        $this->assertSame([], $roi['experiments']);
        $this->assertSame('outcomes-roi-v1', $roi['method']['id']);
        $this->assertSame('Asociado temporalmente', $roi['method']['classification']['temporally_associated']);
        $this->assertNotEmpty($roi['limitations']);
        // La contribución asociada a una acción se muestra aparte y con su advertencia; nunca como beneficio.
        $this->assertSame(1, $roi['summary']['associated']['recommendations']);
        $this->assertEquals(4.0, $roi['summary']['associated']['contribution_known']);
        $this->assertStringContainsString('NO es beneficio atribuible', $roi['summary']['associated']['note']);
        $this->assertSame('Oportunidad potencial, atribución no demostrada', $roi['summary']['potential']['label']);
        $funnel = $roi['summary']['funnel'];
        $this->assertSame(4, $funnel['identified']);
        $this->assertSame(4, $funnel['measured']);
        $this->assertSame(1, $funnel['attended']);
        $this->assertEqualsWithDelta(0.25, $funnel['action_rate'], 1e-9);
        $this->assertSame(1, $funnel['responded_after_action']);
        $this->assertEqualsWithDelta(1.0, $funnel['response_rate'], 1e-9);
        $this->assertSame(['forecast' => 0, 'observed' => 3, 'temporally_associated' => 1, 'incremental_estimated' => 0, 'causally_attributable' => 0], $roi['summary']['by_classification']);
        $this->assertSame(2, $roi['summary']['observed']['recommendations_with_purchase']);
        $this->assertSame(2, $roi['summary']['observed']['customers'], 'clientes distintos con compra');
        $this->assertEquals(40.0, $roi['summary']['observed']['net_amount'], 'importe neto observado: 16 + 24');
        $this->assertEquals(10.0, $roi['summary']['observed']['contribution_known'], 'contribución, no ingresos brutos: 4 + 6');
    }

    public function test_the_forecast_state_is_virtual_recommendations_not_yet_measurable_are_reported_as_forecast(): void
    {
        $w = $this->outcomeWorld(60);
        $c = $this->newCustomers($w['tenant'], 3);
        $this->contactFor($w, $c[0], 'p1', ['as_of' => $this->day(34)]);   // medible
        $this->contactFor($w, $c[1], 'p1', ['as_of' => $this->day(32)]);   // ventana cerrada, en espera de documentos tardíos
        $this->contactFor($w, $c[2], 'p1', ['as_of' => $this->day(5)]);    // ventana abierta
        $this->measure($w);

        $roi = $this->actingAsTenantUser($w['viewer'], $w['tenant'])->getJson('/api/v1/decisions/roi')->assertOk()->json('data.summary');

        $this->assertSame(1, $roi['funnel']['measured']);
        $this->assertSame(['window_open' => 1, 'settling' => 1, 'measurable_not_yet_measured' => 0, 'total' => 2], $roi['funnel']['forecast_pending']);
        $this->assertSame(2, $roi['by_classification']['forecast'], 'previsto = aún sin observar; nunca se persiste como resultado');
        $this->assertSame(1, DB::table('decision_outcomes')->count());
    }

    public function test_superseded_recommendations_are_not_counted_as_separate_opportunities(): void
    {
        $w = $this->outcomeWorld(60);
        $c1 = $w['cat']['customers']['c1'];
        $this->contactFor($w, $c1, 'p1', ['as_of' => $this->day(45)]);
        $this->contactFor($w, $c1, 'p1', ['as_of' => $this->day(44)]);
        $this->measure($w);

        $s = $this->actingAsTenantUser($w['viewer'], $w['tenant'])->getJson('/api/v1/decisions/roi')->assertOk()->json('data.summary.funnel');

        $this->assertSame(2, $s['recommendations']);
        $this->assertSame(1, $s['superseded_not_counted']);
        $this->assertSame(1, $s['identified']);
    }

    // ───────────────────────── ROI con control ─────────────────────────

    /** @return array{w: array, treatment: list<DecisionRecommendation>, control: list<DecisionRecommendation>} */
    private function experimentWorld(string $product = 'p1', ?float $contactCost = 2.5, int $contacted = 3): array
    {
        $w = $this->outcomeWorld(40);
        $this->configure($w, ['decision' => $contactCost === null ? [] : ['contact_cost' => $contactCost]]);
        $this->outcomeSettings($w, ['min_group_size' => 5, 'min_buyers_per_arm' => 1]);
        $customers = $this->newCustomers($w['tenant'], 24);
        $out = ['treatment' => [], 'control' => []];
        foreach ($customers as $i => $customerId) {
            $group = $i < 12 ? 'treatment' : 'control';
            $index = $i < 12 ? $i : $i - 12;
            $rec = $this->contactFor($w, $customerId, $product);
            $this->assignExperiment($w['tenant'], $rec, $group);
            if ($index < ($group === 'treatment' ? 6 : 2)) {
                $this->purchase($w['tenant'], $customerId, $w['cat']['products'][$product], $this->day(20), 2, 8.0, $product === 'p1' ? 6.0 : null);
            }
            $out[$group][] = $rec;
        }
        foreach (array_slice($out['treatment'], 0, $contacted) as $rec) {
            $this->executeAt($w, $rec, $this->iso(30));
        }
        $this->measure($w);

        return ['w' => $w] + $out;
    }

    public function test_with_a_control_group_the_roi_reports_the_itt_estimate_net_of_contact_costs_with_its_interval(): void
    {
        ['w' => $w] = $this->experimentWorld();

        $roi = $this->actingAsTenantUser($w['admin'], $w['tenant'])->getJson('/api/v1/decisions/roi')->assertOk()->json('data');

        $this->assertCount(1, $roi['experiments']);
        $e = $roi['experiments'][0];
        $this->assertSame('exp-prueba', $e['experiment_key']);
        $this->assertSame('estimated', $e['status']);
        $this->assertEquals(['treatment' => 12, 'control' => 12, 'treatment_converted' => 6, 'control_converted' => 2], $e['sample']);
        $this->assertEqualsWithDelta(16.0, $e['incremental']['contribution'], 1e-9);
        // Percentil exacto de la distribución bootstrap (D = 4·(K_T − K_C)/12, K_T ~ Bin(12; 0,5), K_C ~ Bin(12; 1/6), calculado aparte): [0; 32] en el total; retícula de 4.
        $this->assertEqualsWithDelta(0.0, $e['incremental']['ci_low'], 4.5);
        $this->assertEqualsWithDelta(32.0, $e['incremental']['ci_high'], 4.5);
        $this->assertSame('bootstrap_percentile', $e['contribution']['interval']['method']);
        $this->assertEqualsWithDelta(7.5, $e['contact_cost']['total'], 1e-9, '3 contactados × 2,5');
        $this->assertSame(['repeat_customer' => ['treatment' => 0, 'control' => 0], 'unverified_assignment' => ['treatment' => 0, 'control' => 0], 'conflicting_groups' => ['treatment' => 0, 'control' => 0]], $e['dropped_units']);
        $this->assertSame('ok', $e['sample_ratio']['status']);
        $r = $roi['roi'];
        $this->assertSame('incremental_estimated', $r['status']);
        $this->assertFalse($r['proven'], 'ni con ITT se presenta como probado');
        $this->assertSame(0, $r['causal_claims']);
        $this->assertEqualsWithDelta(8.5, $r['net_incremental_contribution']['estimate'], 1e-9, 'incremento 16 − costo de contacto 7,5');
        $this->assertEqualsWithDelta(-7.5, $r['net_incremental_contribution']['ci_low'], 4.5);
        $this->assertEqualsWithDelta(24.5, $r['net_incremental_contribution']['ci_high'], 4.5);
        $this->assertFalse($r['net_incremental_contribution']['significant'], 'el intervalo incluye 0: no se afirma un beneficio');
        $this->assertEqualsWithDelta(1.1333, $r['roi_ratio']['estimate'], 1e-4);
        $this->assertStringContainsString('incluye 0', $r['message']);
        $this->assertContains('platform_usage_not_monetized', $r['reasons']);
        $this->assertNull($r['net_after_platform_usage']);
        // Ninguna fila se estampa «incremental estimado»: el incremento es del agregado.
        $this->assertSame(0, $roi['summary']['by_classification']['incremental_estimated']);
        $this->assertSame(0, $roi['summary']['by_classification']['causally_attributable']);
        $this->assertSame(12, $roi['summary']['funnel']['control_held_out']);
        $this->assertEqualsWithDelta(2 / 12, $roi['summary']['control_baseline']['purchase_rate'], 1e-4);
        $this->assertSame(12, $roi['summary']['funnel']['actionable']);
    }

    public function test_whoever_cannot_approve_gets_no_control_or_experiment_information_anywhere_in_the_roi(): void
    {
        ['w' => $w] = $this->experimentWorld();
        $admin = $this->actingAsTenantUser($w['admin'], $w['tenant'])->getJson('/api/v1/decisions/roi?group_by=owner')->assertOk()->json('data');
        $this->assertSame(12, $admin['summary']['funnel']['control_held_out']);
        $this->assertArrayHasKey('control', $admin['summary']['by_action_kind']);

        foreach (['viewer', 'analyst'] as $who) {
            $roi = $this->actingAsTenantUser($w[$who], $w['tenant'])->getJson('/api/v1/decisions/roi?group_by=owner')->assertOk()->json('data');

            $this->assertArrayNotHasKey('experiments', $roi, 'ni muestras, ni fracciones de control, ni contaminación');
            $this->assertArrayNotHasKey('control_held_out', $roi['summary']['funnel']);
            $this->assertArrayNotHasKey('control', $roi['summary']['by_action_kind']);
            $this->assertArrayNotHasKey('control_baseline', $roi['summary']);
            $this->assertSame(12, $roi['summary']['funnel']['identified'], 'las filas del control no entran en ningún conteo');
            $this->assertSame(12, $roi['summary']['funnel']['actionable']);
            foreach ($roi['breakdown']['rows'] as $row) {
                $this->assertArrayNotHasKey('control_held_out', $row['funnel']);
                $this->assertArrayNotHasKey('control', $row['by_action_kind']);
                $this->assertArrayNotHasKey('control_baseline', $row);
            }
            // El ROI muestra el estado y el método, sin datos por grupo.
            $this->assertSame('incremental_estimated', $roi['roi']['status']);
            $this->assertFalse($roi['roi']['proven']);
            foreach (['sample', 'conversion', 'sample_ratio', 'incremental_contribution', 'net_incremental_contribution', 'roi_ratio', 'contact_cost', 'reasons', 'experiment_key'] as $key) {
                $this->assertArrayNotHasKey($key, $roi['roi'], $key);
            }
            $this->assertSame('outcomes-roi-v1', $roi['method']['id']);
            $json = json_encode($roi);
            $this->assertStringNotContainsString('exp-prueba', $json);
            $this->assertStringNotContainsString('control_fraction', $json);
            $this->assertStringNotContainsString('contamination', $json);
        }
        $this->actingAsTenantUser($w['viewer'], $w['tenant'])->getJson('/api/v1/decisions/roi?experiment_key=exp-prueba')->assertOk()->assertJsonPath('data.summary.funnel.identified', 0);
    }

    public function test_a_known_cost_of_platform_usage_enters_the_net_only_when_prices_are_configured(): void
    {
        ['w' => $w] = $this->experimentWorld();
        DB::table('tenant_usage_records')->insert([
            ['tenant_id' => $w['tenant']->id, 'period' => $this->day(5), 'metric' => 'train_cpu_seconds', 'quantity' => 1000, 'unit' => 's', 'source' => 'core_run', 'source_ref' => '1', 'created_at' => now()],
            ['tenant_id' => $w['tenant']->id, 'period' => $this->day(5), 'metric' => 'storage_bytes', 'quantity' => 5000, 'unit' => 'B', 'source' => 'bronze', 'source_ref' => 'x', 'created_at' => now()],
        ]);

        $without = $this->actingAsTenantUser($w['admin'], $w['tenant'])->getJson('/api/v1/decisions/roi')->assertOk()->json('data');
        $this->assertNull($without['costs']['platform_usage']['total_cost'], 'sin precios no se convierte en dinero');
        $this->assertSame('no_price_model', $without['costs']['platform_usage']['reason']);
        $this->assertCount(2, $without['costs']['platform_usage']['metrics']);
        $this->assertNull($without['roi']['net_after_platform_usage']);

        $this->outcomeSettings($w, ['usage_prices' => ['train_cpu_seconds' => 0.001]]);
        $with = $this->actingAsTenantUser($w['admin'], $w['tenant'])->getJson('/api/v1/decisions/roi')->assertOk()->json('data');
        $this->assertEqualsWithDelta(1.0, $with['costs']['platform_usage']['total_cost'], 1e-9);
        $this->assertSame(['storage_bytes'], $with['costs']['platform_usage']['unpriced_metrics']);
        $this->assertEqualsWithDelta(7.5, $with['roi']['net_after_platform_usage']['estimate'], 1e-9, '8,5 − 1,0 de uso con precio configurado');
    }

    public function test_an_unknown_contact_cost_makes_the_net_unknown_not_zero(): void
    {
        ['w' => $w] = $this->experimentWorld('p1', null);

        $r = $this->actingAsTenantUser($w['admin'], $w['tenant'])->getJson('/api/v1/decisions/roi')->assertOk()->json('data.roi');

        $this->assertSame('incremental_estimated', $r['status']);
        $this->assertNull($r['net_incremental_contribution']);
        $this->assertNull($r['roi_ratio']);
        $this->assertContains('contact_cost_unknown', $r['reasons']);
        $this->assertEqualsWithDelta(16.0, $r['incremental_contribution']['contribution'], 1e-9, 'el incremento sí se informa; el neto no se inventa');
    }

    public function test_with_an_unreliable_cost_only_the_conversion_is_estimated_and_no_money_figure_is_published(): void
    {
        ['w' => $w] = $this->experimentWorld('p2');

        $roi = $this->actingAsTenantUser($w['admin'], $w['tenant'])->getJson('/api/v1/decisions/roi')->assertOk()->json('data');

        $e = $roi['experiments'][0];
        $this->assertSame('estimated', $e['status']);
        $this->assertEqualsWithDelta(6 / 12 - 2 / 12, $e['conversion']['difference'], 1e-5);
        $this->assertSame('insufficient_sample', $e['contribution']['status']);
        $this->assertSame('contribution_population_below_min_group_size', $e['contribution']['reason']);
        $this->assertSame(['treatment' => 12, 'control' => 12], $e['contribution']['excluded_unreliable_cost']);
        $this->assertNull($e['incremental']);
        $this->assertSame('incremental_conversion_only', $roi['roi']['status']);
        $this->assertNull($roi['roi']['net_incremental_contribution']);
        $this->assertNull($roi['roi']['incremental_contribution']);
        $this->assertFalse($roi['roi']['proven']);
        // Las filas de compra en p2 tienen contribución NULL con su motivo.
        $rows = $this->actingAsTenantUser($w['analyst'], $w['tenant'])->getJson('/api/v1/decisions/outcomes?observed_event=purchase')->json('data');
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertNull($row['contribution']);
            $this->assertSame('unknown', $row['contribution_status']);
            $this->assertContains('no_cost_in_sales_lines', $row['contribution_reasons']);
        }
        $this->assertSame(0, $this->actingAsTenantUser($w['analyst'], $w['tenant'])->getJson('/api/v1/decisions/outcomes?contribution_known=1&observed_event=purchase')->json('meta.total'));
        $this->assertEquals(0.0, $roi['summary']['observed']['contribution_known']);
        $this->assertSame(6, $roi['summary']['observed']['pairs_with_unknown_contribution'], 'las 6 compras de tratamiento (el control no entra en lo accionable)');
        $this->assertSame(6, $roi['summary']['observed']['per_recommendation']['rows_with_unknown_contribution']);
    }

    public function test_the_origin_of_the_data_is_declared_and_demo_or_simulated_results_are_never_shown_as_real(): void
    {
        ['w' => $w] = $this->measuredWorld();
        $get = fn () => $this->actingAsTenantUser($w['viewer'], $w['tenant'])->getJson('/api/v1/decisions/roi')->assertOk()->json('data');

        $undeclared = $get();
        $this->assertNull($undeclared['data_origin']['value']);
        $this->assertFalse($undeclared['data_origin']['declared']);
        $this->assertStringContainsString('no se supone que sean reales', $undeclared['data_origin']['note']);
        $this->assertStringContainsString('Origen de los datos no verificado', $undeclared['roi']['data_origin_warning'], 'sin declarar, el ROI también lo avisa');

        $this->configure($w, ['data_origin' => 'demo']);
        $demo = $get();
        $this->assertSame('demo', $demo['data_origin']['value']);
        $this->assertStringContainsString('NO son resultados reales', $demo['roi']['data_origin_warning']);

        $this->configure($w, ['data_origin' => 'real']);
        $real = $get();
        $this->assertSame('real', $real['data_origin']['value']);
        $this->assertArrayNotHasKey('data_origin_warning', $real['roi']);
        $this->configure($w, ['data_origin' => 'inventado']);
        $unknown = $get();
        $this->assertNull($unknown['data_origin']['value'], 'un valor desconocido no se toma por real');
        $this->assertStringContainsString('no verificado', $unknown['roi']['data_origin_warning']);
    }

    // ───────────────────────── periodo y oportunidad potencial ─────────────────────────

    public function test_the_roi_defaults_to_the_last_180_days_of_the_cut_and_caps_the_explicit_range_at_730(): void
    {
        $w = $this->outcomeWorld(400);
        $c = $this->newCustomers($w['tenant'], 3);
        $recent = $this->contactFor($w, $c[0], 'p1', ['as_of' => $this->day(40)]);
        $old = $this->contactFor($w, $c[1], 'p1', ['as_of' => $this->day(300)]);
        $this->measure($w);
        $this->assertSame(2, DB::table('decision_outcomes')->count());
        $get = fn (string $q = '') => $this->actingAsTenantUser($w['admin'], $w['tenant'])->getJson('/api/v1/decisions/roi'.($q === '' ? '' : '?'.$q));

        $default = $get()->assertOk()->json('data');
        $this->assertSame(1, $default['summary']['funnel']['identified'], 'solo lo de los últimos 180 días del corte');
        $this->assertTrue($default['filters']['period_defaulted']);
        $this->assertSame($this->day(180), $default['filters']['from']);
        $this->assertSame($this->day(0), $default['filters']['to']);
        $this->assertSame(180, $default['filters']['period_days']);
        $this->assertSame(180, $default['filters']['period_default_days']);
        $this->assertSame(730, $default['filters']['period_max_days']);

        $explicit = $get('from='.$this->day(320).'&to='.$this->day(280))->assertOk()->json('data');
        $this->assertSame(1, $explicit['summary']['funnel']['identified']);
        $this->assertFalse($explicit['filters']['period_defaulted']);
        $both = $get('from='.$this->day(320))->assertOk()->json('data');
        $this->assertSame(2, $both['summary']['funnel']['identified'], 'con «desde» explícito y «hasta» por omisión (hoy)');
        $this->assertTrue($both['filters']['period_defaulted']);

        $get('from='.$this->day(730).'&to='.$this->day(0))->assertOk();   // 730 días exactos: se admite
        $get('from='.$this->day(731).'&to='.$this->day(0))->assertStatus(422)->assertJsonValidationErrors(['to']);
        $get('from='.$this->day(900))->assertStatus(422);
        $get('to='.$this->day(1000))->assertOk();   // «hasta» explícito con «desde» por omisión (180 días antes)
        $this->assertSame($recent->id, $recent->id);
        $this->assertNotNull($old);
    }

    public function test_the_potential_is_reported_per_kind_and_horizon_and_never_as_one_total_across_incompatible_kinds(): void
    {
        $w = $this->outcomeWorld(40);
        $c1 = $w['cat']['customers']['c1'];
        $this->contactFor($w, $c1);
        $this->contactFor($w, $c1, 'p1', ['horizon_days' => 7]);
        $this->contactFor($w, $c1, 'p1', ['kind' => 'recovery']);
        $this->withinTenant($w['tenant'], fn () => app(RecommendationSink::class)->propose([
            'kind' => 'replenishment', 'as_of' => $w['asOf'], 'horizon_days' => 14, 'entity_type' => 'product_branch', 'product_id' => $w['cat']['products']['p1'],
            'branch_id' => $w['cat']['branch'], 'expected_units' => 80.0, 'action_cost' => 480.0,
        ]));

        $potential = $this->actingAsTenantUser($w['admin'], $w['tenant'])->getJson('/api/v1/decisions/roi')->assertOk()->json('data.summary.potential');

        $cells = collect($potential['cells'])->keyBy(fn ($c) => $c['kind'].'|'.$c['horizon_days']);
        $this->assertEqualsCanonicalizing(['contact|30', 'contact|7', 'recovery|30', 'replenishment|14'], $cells->keys()->all(), 'una celda por tipo y horizonte');
        $this->assertSame(1, $cells['contact|30']['recommendations']);
        $this->assertGreaterThan(0, $cells['replenishment|14']['expected_contribution']);
        $this->assertArrayNotHasKey('expected_contribution', $potential, 'ningún total entre tipos incompatibles');
        $this->assertStringContainsString('no se suman tipos ni horizontes', $potential['note']);
    }

    // ───────────────────────── filtros y desgloses del ROI ─────────────────────────

    public function test_the_roi_filters_and_breaks_down_by_kind_horizon_segment_and_owner(): void
    {
        ['w' => $w, 'recs' => $recs] = $this->measuredWorld();
        $segment = (int) DB::table('customer_segments')->insertGetId(['tenant_id' => $w['tenant']->id, 'code' => 'ORO', 'name' => 'Mayoristas', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('customers')->where('id', $recs['associated']->customer_id)->update(['segment_id' => $segment]);
        DB::table('decision_recommendations')->where('id', $recs['associated']->id)->update(['assigned_user_id' => $w['analyst']->id]);
        $get = fn (string $q) => $this->actingAsTenantUser($w['viewer'], $w['tenant'])->getJson('/api/v1/decisions/roi?'.$q);

        $kind = $get('group_by=kind')->assertOk()->json('data.breakdown');
        $this->assertSame('kind', $kind['group_by']);
        $this->assertSame(['contact'], array_column($kind['rows'], 'key'));
        $this->assertSame(4, $kind['rows'][0]['funnel']['identified']);
        $horizon = $get('group_by=horizon')->json('data.breakdown');
        $this->assertSame('30 días', $horizon['rows'][0]['label']);
        $segmentRows = collect($get('group_by=segment')->json('data.breakdown.rows'))->keyBy('key');
        $this->assertSame('Mayoristas', $segmentRows[(string) $segment]['label']);
        $this->assertSame(1, $segmentRows[(string) $segment]['funnel']['identified']);
        $this->assertSame('Sin segmento', $segmentRows['none']['label']);
        $this->assertSame(3, $segmentRows['none']['funnel']['identified']);
        $owner = collect($get('group_by=owner')->json('data.breakdown.rows'))->keyBy('key');
        $this->assertSame(1, $owner[(string) $w['analyst']->id]['funnel']['identified']);
        $this->assertSame('Sin responsable', $owner['unassigned']['label']);
        $this->assertSame(3, $owner['unassigned']['funnel']['identified']);

        $this->assertSame(0, $get('kind=replenishment')->assertOk()->json('data.summary.funnel.identified'));
        $this->assertSame(0, $get('horizon_days=90')->json('data.summary.funnel.identified'));
        $this->assertSame(4, $get('kind=contact&horizon_days=30&from='.$this->day(41).'&to='.$this->day(39))->json('data.summary.funnel.identified'));
        $this->assertSame(0, $get('from='.$this->day(10))->json('data.summary.funnel.identified'));
        $this->assertSame(0, $get('kind=inventado')->json('data.summary.funnel.identified'), 'un tipo desconocido no se convierte en «todos»');
        $get('group_by=raro')->assertStatus(422);
        $get('from=hoy')->assertStatus(422);
    }
}
