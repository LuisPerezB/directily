<?php

declare(strict_types=1);

namespace Tests\Feature\Decision;

use App\Contracts\Decision\RecommendationSink;
use App\Models\DecisionEvent;
use App\Models\DecisionRecommendation;
use App\Models\Tenant;
use App\Services\Decision\DecisionExpirer;
use App\Services\Decision\DecisionGenerator;
use App\Services\Decision\Workflow;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Decision\Support\DecisionFixtures;
use Tests\TestCase;

/**
 * V2.5 · W5-B (§11): vencimiento programado. Pasa a `expired` solo lo que aún no tiene una acción en curso, deja el evento del sistema, es idempotente,
 * trabaja por empresa y los comandos están programados. Lo vencido sigue existiendo (se mide en outcomes) y no se resucita al regenerar.
 */
final class DecisionExpiryTest extends TestCase
{
    use DecisionFixtures;
    use RefreshDatabase;

    private function propose(array $w, array $override = []): DecisionRecommendation
    {
        return $this->withinTenant($w['tenant'], fn () => app(RecommendationSink::class)->propose($this->contactProposal($w, $override)));
    }

    private function statusOf(Tenant $tenant, int $id): string
    {
        return $this->withinTenant($tenant, fn () => DecisionRecommendation::query()->findOrFail($id)->status);
    }

    public function test_what_has_no_action_in_progress_expires_when_its_date_passes_and_the_rest_does_not(): void
    {
        $w = $this->decisionWorld();
        $ids = [];
        foreach (['suggested', 'assigned', 'reviewed', 'approved', 'in_progress', 'executed', 'rejected', 'closed'] as $i => $status) {
            $ids[$status] = $this->propose($w, ['horizon_days' => 10 + $i])->id;
            DB::table('decision_recommendations')->where('id', $ids[$status])->update(['status' => $status, 'expires_at' => now()->subHour()]);
        }
        $future = $this->propose($w, ['horizon_days' => 60]);   // vence en el futuro

        $expired = app(DecisionExpirer::class)->expireDue($w['tenant']);

        $this->assertSame(4, $expired);
        foreach (['suggested', 'assigned', 'reviewed', 'approved'] as $status) {
            $this->assertSame('expired', $this->statusOf($w['tenant'], $ids[$status]), "{$status} vence");
        }
        foreach (['in_progress', 'executed', 'rejected', 'closed'] as $status) {
            $this->assertSame($status, $this->statusOf($w['tenant'], $ids[$status]), "{$status} no vence: ya hay una acción o ya terminó");
        }
        $this->assertSame('suggested', $this->statusOf($w['tenant'], $future->id));
        // Cada vencimiento deja su evento del sistema con de→a y el motivo.
        $events = $this->withinTenant($w['tenant'], fn () => DecisionEvent::query()->where('to_status', 'expired')->orderBy('id')->get());
        $this->assertCount(4, $events);
        $this->assertSame(['system'], $events->pluck('actor_kind')->unique()->values()->all());
        $this->assertSame(['suggested', 'assigned', 'reviewed', 'approved'], $events->pluck('from_status')->all());
        $this->assertSame('expired', $events[0]->payload['reason_code']);
    }

    public function test_a_recommendation_whose_expiry_is_exactly_now_expires_and_one_second_earlier_does_not(): void
    {
        $w = $this->decisionWorld();
        $id = $this->propose($w)->id;
        $now = CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC');
        DB::table('decision_recommendations')->where('id', $id)->update(['expires_at' => '2026-10-20 12:00:00+00']);
        $expirer = app(DecisionExpirer::class);

        $this->assertSame(0, $expirer->expireDue($w['tenant'], $now->subSecond(), true), 'un segundo antes aún vigente');
        $this->assertSame(1, $expirer->expireDue($w['tenant'], $now, true), 'en el instante exacto del vencimiento ya venció (<=)');
        $this->assertSame(1, $expirer->expireDue($w['tenant'], $now));
        $this->assertSame('expired', $this->statusOf($w['tenant'], $id));
    }

    public function test_expiring_twice_is_idempotent(): void
    {
        $w = $this->decisionWorld();
        $id = $this->propose($w)->id;
        DB::table('decision_recommendations')->where('id', $id)->update(['expires_at' => now()->subMinute()]);

        $first = app(DecisionExpirer::class)->expireDue($w['tenant']);
        $second = app(DecisionExpirer::class)->expireDue($w['tenant']);
        $third = app(DecisionExpirer::class)->expireDue($w['tenant']);

        $this->assertSame([1, 0, 0], [$first, $second, $third]);
        $this->assertSame(1, DB::table('decision_events')->where('recommendation_id', $id)->where('to_status', 'expired')->count());
        $this->assertSame(2, DB::table('decision_events')->where('recommendation_id', $id)->count());
    }

    public function test_a_recommendation_changed_by_someone_else_before_expiry_is_skipped_without_error(): void
    {
        $w = $this->decisionWorld();
        $id = $this->propose($w)->id;
        DB::table('decision_recommendations')->where('id', $id)->update(['expires_at' => now()->subMinute()]);
        $workflow = app(Workflow::class);

        // Otro proceso la movió a «in_progress» entre la lectura y el bloqueo: el sistema no la vence.
        DB::table('decision_recommendations')->where('id', $id)->update(['status' => 'in_progress']);
        $result = $this->withinTenant($w['tenant'], fn () => $workflow->systemTransition($id, 'expired', 'expiry', 'La recomendación venció.'));

        $this->assertNull($result);
        $this->assertSame('in_progress', $this->statusOf($w['tenant'], $id));
        $this->assertSame(0, DB::table('decision_events')->where('recommendation_id', $id)->where('to_status', 'expired')->count());
    }

    public function test_an_expired_recommendation_is_still_readable_but_allows_no_transitions(): void
    {
        $w = $this->decisionWorld();
        $id = $this->propose($w)->id;
        DB::table('decision_recommendations')->where('id', $id)->update(['expires_at' => now()->subMinute()]);
        app(DecisionExpirer::class)->expireDue($w['tenant']);

        $api = $this->actingAsTenantUser($w['owner'], $w['tenant']);
        $api->getJson("/api/v1/decisions/{$id}")->assertOk()->assertJsonPath('data.status', 'expired')->assertJsonPath('data.allowed_transitions', []);
        $api->postJson("/api/v1/decisions/{$id}/transition", ['to_status' => 'assigned', 'idempotency_key' => 'k-expired-assign', 'assignee_user_id' => $w['analyst']->id])
            ->assertStatus(409)->assertJsonPath('code', 'decision_transition_not_allowed');
        // Regenerar con los mismos insumos NO resucita lo vencido ni lo duplica.
        $before = DecisionRecommendation::withoutTenantScope()->where('tenant_id', $w['tenant']->id)->count();
        app(DecisionGenerator::class)->generate($w['tenant'], now('America/Guayaquil')->toImmutable());
        $this->assertSame('expired', $this->statusOf($w['tenant'], $id));
        $this->assertSame(1, DecisionRecommendation::withoutTenantScope()->where('tenant_id', $w['tenant']->id)->where('idempotency_key', $this->withinTenant($w['tenant'], fn () => DecisionRecommendation::query()->findOrFail($id)->idempotency_key))->count());
        $this->assertGreaterThanOrEqual($before, DecisionRecommendation::withoutTenantScope()->where('tenant_id', $w['tenant']->id)->count());
    }

    public function test_the_command_expires_per_company_and_supports_dry_run_and_a_single_company(): void
    {
        $a = $this->decisionWorld();
        $b = $this->decisionWorld();
        $idA = $this->propose($a)->id;
        $idB = $this->propose($b)->id;
        DB::table('decision_recommendations')->whereIn('id', [$idA, $idB])->update(['expires_at' => now()->subMinute()]);

        Artisan::call('predictily:decisions-expire', ['--dry-run' => true]);
        $this->assertStringContainsString('vencerían', Artisan::output());
        $this->assertSame('suggested', $this->statusOf($a['tenant'], $idA));
        $this->assertSame('suggested', $this->statusOf($b['tenant'], $idB));

        Artisan::call('predictily:decisions-expire', ['--tenant' => $a['tenant']->id]);
        $this->assertSame('expired', $this->statusOf($a['tenant'], $idA));
        $this->assertSame('suggested', $this->statusOf($b['tenant'], $idB), 'la otra empresa no se toca');

        Artisan::call('predictily:decisions-expire');
        $this->assertSame('expired', $this->statusOf($b['tenant'], $idB));
        Artisan::call('predictily:decisions-expire');
        $this->assertStringContainsString('vencidas: 0', Artisan::output());
    }

    public function test_the_expiry_dates_follow_the_kind_and_the_company_settings(): void
    {
        $w = $this->decisionWorld('2026-11-01');
        $contact = $this->propose($w);
        $replenishment = $this->withinTenant($w['tenant'], fn () => app(RecommendationSink::class)->propose([
            'kind' => 'replenishment', 'as_of' => '2026-11-01', 'horizon_days' => 14, 'entity_type' => 'product_branch', 'product_id' => $w['cat']['products']['p1'], 'branch_id' => $w['cat']['branch'],
        ]));
        $w['tenant']->forceFill(['settings' => array_merge($w['tenant']->effectiveSettings(), ['decision' => ['expiry_days' => ['recovery' => 5]]])])->save();
        $recovery = $this->withinTenant($w['tenant'], fn () => app(RecommendationSink::class)->propose([
            'kind' => 'recovery', 'as_of' => '2026-11-01', 'horizon_days' => 30, 'entity_type' => 'customer', 'customer_id' => $w['cat']['customers']['c2'],
        ]));

        $local = fn (DecisionRecommendation $r) => $r->expires_at->setTimezone('America/Guayaquil')->format('Y-m-d H:i');
        $this->assertSame('2026-12-01 23:59', $local($contact), 'contacto: vence al terminar su horizonte (30 días)');
        $this->assertSame('2026-11-08 23:59', $local($replenishment), 'reposición: vence con el período de revisión de la empresa (7 días)');
        $this->assertSame('2026-11-06 23:59', $local($recovery), 'recuperación: configurable por empresa');
        $this->assertSame(CarbonImmutable::parse('2026-12-01 23:59:59', 'America/Guayaquil')->utc()->toIso8601String(), $contact->expires_at->utc()->toIso8601String());
    }

    public function test_the_commands_are_scheduled_per_company_and_idempotent(): void
    {
        $events = collect(app(Schedule::class)->events());
        $generate = $events->first(fn ($e) => str_contains($e->command, 'predictily:decisions-generate'));
        $expire = $events->first(fn ($e) => str_contains($e->command, 'predictily:decisions-expire'));

        $this->assertNotNull($generate, 'predictily:decisions-generate está programado');
        $this->assertNotNull($expire, 'predictily:decisions-expire está programado');
        $this->assertSame('America/Guayaquil', (string) $generate->timezone);
        $this->assertSame('30 5 * * *', $generate->expression);
        $this->assertSame('25 * * * *', $expire->expression);
        $this->assertTrue($generate->withoutOverlapping && $expire->withoutOverlapping);
        $this->assertTrue($generate->onOneServer && $expire->onOneServer);
        // Después de la predicción de comportamiento (04:20) y del radar (04:50).
        $customer = $events->first(fn ($e) => str_contains($e->command, 'predictily:customer-predict'));
        $this->assertNotNull($customer);
        $this->assertSame('20 4 * * *', $customer->expression);
    }
}
