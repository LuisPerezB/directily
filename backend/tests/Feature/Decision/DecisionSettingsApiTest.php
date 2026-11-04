<?php

declare(strict_types=1);

namespace Tests\Feature\Decision;

use App\Contracts\Decision\RecommendationSink;
use App\Services\Decision\DecisionSettings;
use App\Services\Decision\ScoreFormula;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Decision\Support\DecisionFixtures;
use Tests\TestCase;

/**
 * V2.5 · W5-B: parámetros del Decision Engine por empresa. Todo valor por defecto es provisional («a aprobar en el piloto»); la fracción de control vale 0
 * por defecto; solo quien administra la empresa los cambia; la semilla del experimento se registra y no se devuelve en claro.
 */
final class DecisionSettingsApiTest extends TestCase
{
    use DecisionFixtures;
    use RefreshDatabase;

    public function test_the_defaults_are_provisional_and_the_control_fraction_is_zero(): void
    {
        $w = $this->decisionWorld();

        $data = $this->actingAsTenantUser($w['viewer'], $w['tenant'])->getJson('/api/v1/decisions/settings')->assertOk()->json('data');

        $this->assertSame(0.0, (float) $data['settings']['control_fraction']);
        $this->assertSame(0.0, (float) $data['defaults']['control_fraction']);
        $this->assertNull($data['settings']['contact_cost'], 'sin costo de contacto configurado no se inventa uno');
        $this->assertNull($data['settings']['holding_cost_rate_annual'] ?? null);
        $this->assertSame(ScoreFormula::fromSettings(DecisionSettings::forTenant($w['tenant']))->version(), $data['score_formula']['version']);
        $this->assertSame(0.2, $data['score_formula']['parameters']['unreliable_cost_weight']);
        $this->assertStringContainsString('provisionales', $data['note']);
        $this->assertSame('USD', $data['currency']);
    }

    public function test_only_who_manages_the_company_can_change_them_and_the_values_are_validated(): void
    {
        $w = $this->decisionWorld();

        $this->actingAsTenantUser($w['analyst'], $w['tenant'])->putJson('/api/v1/decisions/settings', ['contact_cost' => 2])->assertStatus(403)->assertJsonPath('required_ability', 'settings.manage');
        $this->actingAsTenantUser($w['viewer'], $w['tenant'])->putJson('/api/v1/decisions/settings', ['contact_cost' => 2])->assertStatus(403);
        $api = $this->actingAsTenantUser($w['admin'], $w['tenant']);
        $api->putJson('/api/v1/decisions/settings', ['control_fraction' => 0.8])->assertStatus(422)->assertJsonValidationErrors('control_fraction');
        $api->putJson('/api/v1/decisions/settings', ['horizons' => [31]])->assertStatus(422)->assertJsonValidationErrors('horizons.0');
        $api->putJson('/api/v1/decisions/settings', ['unreliable_cost_weight' => 2])->assertStatus(422)->assertJsonValidationErrors('unreliable_cost_weight');
        $api->putJson('/api/v1/decisions/settings', ['approval_required_kinds' => ['inventado']])->assertStatus(422);
        $api->putJson('/api/v1/decisions/settings', ['contact_cost' => 1.25, 'contact_min_probability' => 0.2])->assertOk()->assertJsonPath('data.settings.contact_cost', 1.25);
        // Una clave que no existe se ignora (no se guarda).
        $api->putJson('/api/v1/decisions/settings', ['clave_inventada' => 9])->assertOk();
        $this->assertArrayNotHasKey('clave_inventada', (array) ($w['tenant']->refresh()->settings['decision'] ?? []));
        $this->assertSame(1.25, (float) $w['tenant']->settings['decision']['contact_cost']);
    }

    public function test_enabling_the_experiment_registers_a_seed_that_is_not_returned_and_is_audited(): void
    {
        $w = $this->decisionWorld();
        $api = $this->actingAsTenantUser($w['admin'], $w['tenant']);

        $response = $api->putJson('/api/v1/decisions/settings', ['control_fraction' => 0.2, 'experiment_key' => 'contacto-prueba'])->assertOk();

        $this->assertEquals(0.2, $response->json('data.settings.control_fraction'));
        $this->assertSame('[registrada]', $response->json('data.settings.experiment_seed'), 'la semilla no se devuelve en claro');
        $seed = $w['tenant']->refresh()->settings['decision']['experiment_seed'];
        $this->assertSame(32, strlen($seed), 'queda registrada en la configuración de la empresa');
        // Un segundo cambio no la reemplaza (la semilla identifica al experimento).
        $api->putJson('/api/v1/decisions/settings', ['control_fraction' => 0.3])->assertOk();
        $this->assertSame($seed, $w['tenant']->refresh()->settings['decision']['experiment_seed']);
        $audit = DB::table('audit_logs')->where('tenant_id', $w['tenant']->id)->where('action', 'decision.settings_updated')->orderBy('id')->get();
        $this->assertCount(2, $audit);
        $this->assertStringNotContainsString($seed, (string) $audit[0]->metadata, 'la bitácora no guarda la semilla');
    }

    public function test_the_experiment_seed_is_never_published_by_the_tenant_endpoints(): void
    {
        $w = $this->decisionWorld();
        $this->actingAsTenantUser($w['admin'], $w['tenant'])->putJson('/api/v1/decisions/settings', ['control_fraction' => 0.2, 'experiment_seed' => 'semilla-secreta-0009'])->assertOk();

        foreach ([$w['viewer'], $w['analyst'], $w['admin'], $w['owner']] as $user) {
            $body = (string) $this->actingAsTenantUser($user, $w['tenant'])->getJson('/api/v1/tenant')->assertOk()->getContent();
            $this->assertStringNotContainsString('semilla-secreta-0009', $body, 'GET /tenant no publica la semilla del experimento');
            $this->assertStringContainsString('[registrada]', $body);
        }
        $patch = (string) $this->actingAsTenantUser($w['admin'], $w['tenant'])->patchJson('/api/v1/tenant', ['settings' => ['service_level' => 0.9]])->assertOk()->getContent();
        $this->assertStringNotContainsString('semilla-secreta-0009', $patch);
        $this->assertStringNotContainsString('semilla-secreta-0009', (string) $this->actingAsTenantUser($w['viewer'], $w['tenant'])->getJson('/api/v1/decisions/settings')->getContent());
        // El motor la sigue usando: está guardada y no se perdió por ocultarla.
        $this->assertSame('semilla-secreta-0009', $w['tenant']->refresh()->settings['decision']['experiment_seed']);
    }

    public function test_changing_other_company_settings_keeps_the_decision_settings(): void
    {
        $w = $this->decisionWorld();
        $this->actingAsTenantUser($w['admin'], $w['tenant'])->putJson('/api/v1/decisions/settings', ['contact_cost' => 3])->assertOk();

        $this->actingAsTenantUser($w['admin'], $w['tenant'])->patchJson('/api/v1/tenant', ['settings' => ['service_level' => 0.9]])->assertOk();

        $this->assertSame(3.0, (float) $w['tenant']->refresh()->settings['decision']['contact_cost']);
        $this->assertSame(0.9, (float) $w['tenant']->settings['service_level']);
    }

    public function test_settings_change_what_the_engine_does_and_do_not_leak_between_companies(): void
    {
        $a = $this->decisionWorld();
        $b = $this->decisionWorld();
        $this->actingAsTenantUser($a['admin'], $a['tenant'])->putJson('/api/v1/decisions/settings', ['contact_cost' => 4.0, 'unreliable_cost_weight' => 0.4])->assertOk();

        $a['tenant']->refresh();   // la petición cargó su propia instancia; esta es la del contexto de la prueba
        $recA = $this->withinTenant($a['tenant'], fn () => app(RecommendationSink::class)->propose($this->contactProposal($a, ['product_id' => $a['cat']['products']['p2']])));
        $recB = $this->withinTenant($b['tenant'], fn () => app(RecommendationSink::class)->propose($this->contactProposal($b, ['product_id' => $b['cat']['products']['p2']])));

        $this->assertEquals(4.0, $recA->action_cost);
        $this->assertNull($recB->action_cost);
        $this->assertEqualsWithDelta($recA->expected_gross_value * 0.4 - 4.0, $recA->score, 1e-6);
        $this->assertEqualsWithDelta($recB->expected_gross_value * 0.2, $recB->score, 1e-6);
        $this->assertNotSame($recA->score_formula_version, $recB->score_formula_version, 'otra versión de fórmula por otros parámetros');
        // GET de la otra empresa no muestra la configuración de A.
        $this->assertNull($this->actingAsTenantUser($b['viewer'], $b['tenant'])->getJson('/api/v1/decisions/settings')->json('data.settings.contact_cost'));
    }
}
