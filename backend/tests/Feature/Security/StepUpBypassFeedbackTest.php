<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\TenantRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Radar\Support\RadarFixtures;
use Tests\Feature\Security\Support\SecurityFixtures;
use Tests\TestCase;

/**
 * V2.5 · W6-A (revisión M1 / prueba M7): el seguimiento de la agenda «descartado» sobre una oportunidad REVISADA se sincroniza con el workflow como un RECHAZO formal
 * (`reject` exige `decisions.approve`). La ruta de seguimiento solo pedía `decisions.manage`: una aprobación/rechazo sin segundo factor fresco por la puerta lateral de la agenda.
 */
final class StepUpBypassFeedbackTest extends TestCase
{
    use RadarFixtures, RefreshDatabase, SecurityFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeClock();
    }

    private function recId(array $c, string $customer, string $kind = 'contact', int $horizon = 30): int
    {
        return (int) DB::table('decision_recommendations')->where('tenant_id', $c['tenant']->id)->where('customer_id', $c['cat']['customers'][$customer])->where('kind', $kind)
            ->where('horizon_days', $horizon)->orderBy('id')->value('id');
    }

    public function test_m7_discarding_a_reviewed_opportunity_rejects_it_formally_and_needs_a_fresh_second_factor(): void
    {
        $c = $this->radarCompany(TenantRole::Analyst);
        $this->materializeAll($c);
        $admin = $this->addUserToTenant($c['tenant'], TenantRole::Admin);
        $mfa = $this->enableMfa($admin);
        $reviewed = $this->recId($c, 'c1');
        DB::table('decision_recommendations')->where('id', $reviewed)->update(['status' => 'reviewed']);
        $suggested = $this->recId($c, 'c2');
        $api = fn () => $this->actingAsTenantUser($admin, $c['tenant']);

        // «Descartado» sobre una REVISADA = rechazo formal: sin segundo factor fresco, 403 y el workflow no cambia.
        $api()->postJson("/api/v1/radar/opportunities/{$reviewed}/feedback", ['state' => 'descartado', 'idempotency_key' => 'k-reviewed-0001'])->assertForbidden()->assertJsonPath('code', 'mfa_step_up_required');
        $this->assertSame('reviewed', DB::table('decision_recommendations')->where('id', $reviewed)->value('status'));
        $this->assertSame(0, DB::table('decision_events')->where('recommendation_id', $reviewed)->count());

        // Descartar una SUGERIDA es `dismiss` (decisions.manage), no una aprobación: no pide segundo factor.
        $api()->postJson("/api/v1/radar/opportunities/{$suggested}/feedback", ['state' => 'descartado', 'idempotency_key' => 'k-suggested-0001', 'note' => 'Sin interés'])->assertSuccessful();
        $this->assertSame('rejected', DB::table('decision_recommendations')->where('id', $suggested)->value('status'));
        // Otros estados de la agenda sobre la revisada tampoco lo piden.
        $api()->postJson("/api/v1/radar/opportunities/{$reviewed}/feedback", ['state' => 'pospuesto', 'idempotency_key' => 'k-reviewed-0002', 'snoozed_until' => now()->addDays(3)->toDateString()])->assertSuccessful();

        // Con el segundo factor fresco, el rechazo formal se aplica.
        $api()->postJson('/api/v1/auth/mfa/step-up', ['code' => $this->freshCode($mfa['secret'])])->assertOk();
        $api()->postJson("/api/v1/radar/opportunities/{$reviewed}/feedback", ['state' => 'descartado', 'idempotency_key' => 'k-reviewed-0003', 'note' => 'Descartada tras revisión'])->assertSuccessful();
        $this->assertSame('rejected', DB::table('decision_recommendations')->where('id', $reviewed)->value('status'));
    }

    public function test_m7_people_without_mfa_discard_reviewed_opportunities_as_before(): void
    {
        $c = $this->radarCompany(TenantRole::Analyst);
        $this->materializeAll($c);
        $admin = $this->addUserToTenant($c['tenant'], TenantRole::Admin);
        $reviewed = $this->recId($c, 'c1');
        DB::table('decision_recommendations')->where('id', $reviewed)->update(['status' => 'reviewed']);

        $this->actingAsTenantUser($admin, $c['tenant'])->postJson("/api/v1/radar/opportunities/{$reviewed}/feedback", ['state' => 'descartado', 'idempotency_key' => 'k-nomfa-00000001', 'note' => 'Descartada'])->assertSuccessful();
        $this->assertSame('rejected', DB::table('decision_recommendations')->where('id', $reviewed)->value('status'));
    }
}
