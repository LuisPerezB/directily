<?php

declare(strict_types=1);

namespace Tests\Feature\Radar;

use App\Contracts\Decision\RecommendationSink;
use App\Enums\TenantRole;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Radar\Support\RadarFixtures;
use Tests\TestCase;

/**
 * V2.5 · W5-A: proponer al Decision Engine las oportunidades de la predicción vigente, por la API (POST /radar/opportunities/materialize) y por el comando
 * `predictily:radar-materialize`. Siempre por RecommendationSink; sin él, 503 / fallo explícito y NADA se escribe.
 */
class RadarMaterializeTest extends TestCase
{
    use RadarFixtures;
    use RefreshDatabase;

    private function api(array $c): static
    {
        return $this->actingAsTenantUser($c['user'], $c['tenant']);
    }

    private function recommendations(int $tenantId): int
    {
        return DB::table('decision_recommendations')->where('tenant_id', $tenantId)->count();
    }

    public function test_the_endpoint_proposes_through_the_sink_and_repeating_creates_nothing_new(): void
    {
        $c = $this->radarCompany();
        $sink = $this->fakeSink();

        $first = $this->api($c)->postJson('/api/v1/radar/opportunities/materialize')->assertOk();
        $second = $this->api($c)->postJson('/api/v1/radar/opportunities/materialize')->assertOk();

        $this->assertSame('completed', $first->json('data.status'));
        $this->assertSame([12, 12, 0, 0], [$first->json('data.candidates'), $first->json('data.created'), $first->json('data.existing'), $first->json('data.failed')]);
        $this->assertSame([12, 0, 12], [$second->json('data.candidates'), $second->json('data.created'), $second->json('data.existing')]);
        $this->assertSame($c['run'], $first->json('data.run_id'));
        $this->assertEquals(['contact' => 9, 'recovery' => 3], $first->json('data.created_by_kind'));
        $this->assertMatchesRegularExpression('/^radar-v1:[0-9a-f]{12}$/', $first->json('data.policy'));
        $this->assertSame(24, count($sink->calls));
        $this->assertSame(12, $this->recommendations($c['tenant']->id));
        $audit = DB::table('audit_logs')->where('action', 'radar.materialized')->orderByDesc('id')->first();
        $this->assertSame($c['user']->id, $audit->user_id);
    }

    public function test_only_the_requested_horizons_are_proposed(): void
    {
        $c = $this->radarCompany();
        $this->fakeSink();

        $r = $this->api($c)->postJson('/api/v1/radar/opportunities/materialize', ['horizons' => [30]])->assertOk();

        $this->assertSame(4, $r->json('data.created'));
        $this->assertSame([30], $r->json('data.horizons'));
        $this->api($c)->postJson('/api/v1/radar/opportunities/materialize', ['horizons' => [60]])->assertStatus(422)->assertJsonValidationErrors('horizons.0');
        $this->api($c)->postJson('/api/v1/radar/opportunities/materialize', ['horizons' => 30])->assertStatus(422);
    }

    public function test_without_the_decision_engine_it_answers_503_and_writes_nothing(): void
    {
        $c = $this->radarCompany();
        $this->app->offsetUnset(RecommendationSink::class);

        $this->api($c)->postJson('/api/v1/radar/opportunities/materialize')->assertStatus(503)->assertJsonPath('code', 'decision_engine_unavailable');

        $this->assertSame(0, $this->recommendations($c['tenant']->id));
    }

    public function test_roles_a_viewer_cannot_propose_and_the_other_roles_can(): void
    {
        $viewer = $this->radarCompany(TenantRole::Viewer);
        $this->fakeSink();
        $this->api($viewer)->postJson('/api/v1/radar/opportunities/materialize')->assertForbidden()->assertJsonPath('required_ability', 'forecast.run');
        $this->assertSame(0, $this->recommendations($viewer['tenant']->id));

        foreach ([TenantRole::Analyst, TenantRole::Admin, TenantRole::Owner] as $role) {
            $c = $this->radarCompany($role);
            $this->api($c)->postJson('/api/v1/radar/opportunities/materialize')->assertOk();
        }
    }

    public function test_it_only_proposes_the_active_company_and_respects_the_plan(): void
    {
        $a = $this->radarCompany();
        $b = $this->radarCompany();
        $this->fakeSink();

        $this->api($a)->postJson('/api/v1/radar/opportunities/materialize')->assertOk();

        $this->assertSame(12, $this->recommendations($a['tenant']->id));
        $this->assertSame(0, $this->recommendations($b['tenant']->id), 'la otra empresa no recibe nada');
        $b['tenant']->forceFill(['plan_overrides' => ['features' => ['customer' => false]]])->save();
        $this->api($b)->postJson('/api/v1/radar/opportunities/materialize')->assertStatus(402);
    }

    public function test_a_company_without_a_prediction_gets_no_run_and_nothing_is_proposed(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $sink = $this->fakeSink();

        $r = $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/radar/opportunities/materialize')->assertOk();

        $this->assertSame('no_run', $r->json('data.status'));
        $this->assertSame([], $sink->calls);
    }

    public function test_the_command_proposes_for_every_active_company_idempotently(): void
    {
        $a = $this->radarCompany();
        $b = $this->radarCompany();
        $this->fakeSink();

        $this->artisan('predictily:radar-materialize')->assertExitCode(0);
        $this->artisan('predictily:radar-materialize')->assertExitCode(0)->expectsOutputToContain('0 nuevas, 12 ya existentes');

        $this->assertSame(12, $this->recommendations($a['tenant']->id));
        $this->assertSame(12, $this->recommendations($b['tenant']->id));
    }

    public function test_the_command_can_target_one_company_and_dry_run_proposes_nothing(): void
    {
        $a = $this->radarCompany();
        $b = $this->radarCompany();
        $sink = $this->fakeSink();

        $this->artisan('predictily:radar-materialize', ['--tenant' => $a['tenant']->id, '--dry-run' => true])->assertExitCode(0)->expectsOutputToContain('se propondrían 12');
        $this->assertSame([], $sink->calls);

        $this->artisan('predictily:radar-materialize', ['--tenant' => $a['tenant']->id, '--horizon' => [30]])->assertExitCode(0);
        $this->assertSame(4, $this->recommendations($a['tenant']->id));
        $this->assertSame(0, $this->recommendations($b['tenant']->id));
    }

    public function test_the_command_fails_clearly_without_the_decision_engine_and_skips_companies_without_customer(): void
    {
        $c = $this->radarCompany();
        $this->app->offsetUnset(RecommendationSink::class);

        $this->artisan('predictily:radar-materialize')->assertExitCode(1)->expectsOutputToContain('El motor de decisiones no está disponible');
        $this->assertSame(0, $this->recommendations($c['tenant']->id));

        $this->fakeSink();
        $c['tenant']->forceFill(['plan_overrides' => ['features' => ['customer' => false]]])->save();
        $this->artisan('predictily:radar-materialize')->assertExitCode(0)->expectsOutputToContain('el plan no incluye comportamiento de compra');
        $this->assertSame(0, $this->recommendations($c['tenant']->id));
    }

    public function test_the_command_is_scheduled_after_the_daily_customer_prediction(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'predictily:radar-materialize'));

        $this->assertCount(1, $events);
        $this->assertSame('50 4 * * *', $events->first()->expression, 'a las 04:50, tras predictily:customer-predict (04:20)');
        $this->assertSame('America/Guayaquil', (string) $events->first()->timezone);
    }
}
