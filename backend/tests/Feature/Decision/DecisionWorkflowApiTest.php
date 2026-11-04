<?php

declare(strict_types=1);

namespace Tests\Feature\Decision;

use App\Contracts\Decision\RecommendationSink;
use App\Models\DecisionEvent;
use App\Models\DecisionRecommendation;
use App\Models\User;
use App\Services\Decision\DecisionGenerator;
use App\Services\Decision\TransitionRules;
use App\Services\Decision\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Decision\Support\DecisionFixtures;
use Tests\TestCase;

/**
 * V2.5 · W5-B (ADR-V25-13; §11): el workflow de decisiones por la API. Transiciones explícitas con habilidad por rol, segregación de funciones probada con
 * DOS usuarios, idempotencia por `idempotency_key`, bloqueo optimista de revisiones concurrentes, aislamiento entre empresas y TODA transición auditada
 * en `decision_events`.
 */
final class DecisionWorkflowApiTest extends TestCase
{
    use DecisionFixtures;
    use RefreshDatabase;

    /** @param array<string, mixed> $override */
    private function replenishment(array $w, array $override = []): DecisionRecommendation
    {
        return $this->withinTenant($w['tenant'], fn () => app(RecommendationSink::class)->propose($override + [
            'kind' => 'replenishment', 'as_of' => $w['asOf'], 'horizon_days' => 14, 'entity_type' => 'product_branch', 'product_id' => $w['cat']['products']['p1'],
            'branch_id' => $w['cat']['branch'], 'expected_units' => 80.0, 'action_cost' => 480.0,
        ]));
    }

    private function contact(array $w, array $override = []): DecisionRecommendation
    {
        return $this->withinTenant($w['tenant'], fn () => app(RecommendationSink::class)->propose($this->contactProposal($w, $override)));
    }

    private function act(User $user, array $w): static
    {
        return $this->actingAsTenantUser($user, $w['tenant']);
    }

    /**
     * Toda transición humana exige `expected_version`: salvo que la prueba lo fije (`expected_version` explícito, incluso null para probar su ausencia), se manda la versión
     * vigente que leería el cliente justo antes. Para repetir una petición (idempotencia) hay que mandar la MISMA versión: la prueba la fija.
     *
     * @param  array<string, mixed>  $extra
     */
    private function go(User $user, array $w, int $id, string $to, string $key, array $extra = []): TestResponse
    {
        if (! array_key_exists('expected_version', $extra)) {
            $extra['expected_version'] = $this->version($user, $w, $id);
        }

        return $this->act($user, $w)->postJson("/api/v1/decisions/{$id}/transition", ['to_status' => $to, 'idempotency_key' => $key] + $extra);
    }

    private function version(User $user, array $w, int $id): int
    {
        return (int) $this->act($user, $w)->getJson("/api/v1/decisions/{$id}")->assertOk()->json('data.version');
    }

    /** Lleva la recomendación a «reviewed» como el analista indicado (asignada a sí mismo). */
    private function toReviewed(array $w, int $id, User $assignee): void
    {
        $this->go($assignee, $w, $id, 'assigned', 'assign-'.$id, ['assignee_user_id' => $assignee->id])->assertOk();
        $this->go($assignee, $w, $id, 'reviewed', 'review-'.$id, ['expected_version' => $this->version($assignee, $w, $id)])->assertOk();
    }

    // ───────────────────────── recorrido completo y auditoría ─────────────────────────

    public function test_a_sensitive_recommendation_walks_the_whole_workflow_and_every_transition_is_audited(): void
    {
        $w = $this->decisionWorld();
        $rec = $this->replenishment($w);
        $id = $rec->id;

        $this->go($w['analyst'], $w, $id, 'assigned', 'k-assign-1', ['assignee_user_id' => $w['analyst']->id])->assertOk()->assertJsonPath('data.status', 'assigned')->assertJsonPath('data.assigned_user_id', $w['analyst']->id);
        $this->go($w['analyst'], $w, $id, 'reviewed', 'k-review-1', ['expected_version' => $this->version($w['analyst'], $w, $id)])->assertOk()->assertJsonPath('data.status', 'reviewed');
        $this->go($w['admin'], $w, $id, 'approved', 'k-approve-1', ['expected_version' => $this->version($w['admin'], $w, $id), 'reason' => 'Presupuesto vigente'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->go($w['analyst'], $w, $id, 'in_progress', 'k-start-1')->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->go($w['analyst'], $w, $id, 'executed', 'k-exec-1')->assertOk()->assertJsonPath('data.status', 'executed');
        // «measured» lo pone el sistema (W5-C); una persona no puede declararlo.
        $this->go($w['admin'], $w, $id, 'measured', 'k-measure-1')->assertStatus(409)->assertJsonPath('code', 'decision_transition_not_allowed');
        $this->withinTenant($w['tenant'], fn () => app(Workflow::class)->systemTransition($id, 'measured', 'outcome-1', 'Outcome medido.', ['outcome' => 'test']));
        $this->go($w['analyst'], $w, $id, 'closed', 'k-close-1')->assertOk()->assertJsonPath('data.status', 'closed');

        $events = $this->withinTenant($w['tenant'], fn () => DecisionEvent::query()->where('recommendation_id', $id)->orderBy('id')->get());
        $this->assertSame(
            [[null, 'suggested'], ['suggested', 'assigned'], ['assigned', 'reviewed'], ['reviewed', 'approved'], ['approved', 'in_progress'], ['in_progress', 'executed'], ['executed', 'measured'], ['measured', 'closed']],
            $events->map(fn (DecisionEvent $e) => [$e->from_status, $e->to_status])->all(),
            'cada transición deja exactamente un evento, en orden',
        );
        $actors = $events->map(fn (DecisionEvent $e) => $e->actor_user_id)->all();
        $this->assertSame([null, $w['analyst']->id, $w['analyst']->id, $w['admin']->id, $w['analyst']->id, $w['analyst']->id, null, $w['analyst']->id], $actors);
        $this->assertSame(['system', 'user', 'user', 'user', 'user', 'user', 'system', 'user'], $events->pluck('actor_kind')->all());
        $this->assertSame('Presupuesto vigente', $events[3]->reason);
        $this->assertSame(8, $events->pluck('idempotency_key')->unique()->count(), 'cada evento lleva su clave de idempotencia');
        // También quedan en la bitácora general de auditoría.
        $this->assertGreaterThanOrEqual(6, DB::table('audit_logs')->where('tenant_id', $w['tenant']->id)->where('action', 'like', 'decision.%')->count());
        // Un estado terminal ya no admite transiciones.
        $this->go($w['owner'], $w, $id, 'executed', 'k-late-1')->assertStatus(409);
    }

    public function test_generating_and_walking_the_workflow_never_sends_messages_calls_the_erp_or_queues_work(): void
    {
        Http::fake();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        $w = $this->decisionWorld();
        $forecast = $this->forecastWithRecommendations($w['tenant'], $w['cat'], $w['asOf']);
        $this->withinTenant($w['tenant'], fn () => $this->supplyPlan($w['tenant'], $w['cat'], $w['asOf'], $forecast));
        app(DecisionGenerator::class)->generate($w['tenant'], now('America/Guayaquil')->toImmutable());
        $id = $this->withinTenant($w['tenant'], fn () => DecisionRecommendation::query()->where('kind', 'replenishment')->orderBy('id')->firstOrFail()->id);

        $this->toReviewed($w, $id, $w['analyst']);
        $this->go($w['owner'], $w, $id, 'approved', 'k-no-side-effects-1', ['expected_version' => $this->version($w['owner'], $w, $id)])->assertOk();
        $this->go($w['analyst'], $w, $id, 'in_progress', 'k-no-side-effects-2')->assertOk();
        $this->go($w['analyst'], $w, $id, 'executed', 'k-no-side-effects-3')->assertOk();

        Http::assertNothingSent();
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
        // «Ejecutar» es un registro: el estado cambia, nada más.
        $this->assertSame('executed', DecisionRecommendation::withoutTenantScope()->findOrFail($id)->status);
    }

    public function test_the_transition_table_is_closed_and_every_pair_outside_it_is_refused_with_409(): void
    {
        $w = $this->decisionWorld();
        $id = $this->replenishment($w)->id;

        // suggested solo puede ir a assigned o rejected (descartar); nada más, ni saltando pasos.
        foreach (['reviewed', 'approved', 'in_progress', 'executed', 'closed', 'suggested'] as $i => $to) {
            $this->go($w['owner'], $w, $id, $to, "k-bad-{$i}-xx", ['expected_version' => $this->version($w['owner'], $w, $id)])->assertStatus(409)
                ->assertJsonPath('code', 'decision_transition_not_allowed');
        }
        $this->assertSame('suggested', DecisionRecommendation::withoutTenantScope()->findOrFail($id)->status);
        $this->assertSame(1, DecisionEvent::withoutTenantScope()->where('recommendation_id', $id)->count(), 'ni una transición fallida deja evento');
        // La tabla declara exactamente las transiciones del ADR-V25-13.
        $pairs = collect(TransitionRules::RULES)->flatMap(fn ($r) => array_map(fn ($f) => "$f>{$r['to']}", $r['from']))->sort()->values()->all();
        $this->assertEqualsCanonicalizing([
            'suggested>assigned', 'assigned>assigned', 'assigned>reviewed', 'reviewed>approved', 'reviewed>rejected', 'suggested>rejected', 'assigned>rejected',
            'approved>in_progress', 'reviewed>in_progress', 'in_progress>executed', 'executed>measured', 'measured>closed',
            'suggested>expired', 'assigned>expired', 'reviewed>expired', 'approved>expired',
        ], $pairs);
    }

    public function test_allowed_transitions_are_listed_per_user_with_the_reason_when_blocked(): void
    {
        $w = $this->decisionWorld();
        $id = $this->replenishment($w, ['proposed_by_user_id' => $w['admin']->id])->id;
        $this->toReviewed($w, $id, $w['analyst']);

        $byAnalyst = collect($this->act($w['analyst'], $w)->getJson("/api/v1/decisions/{$id}")->assertOk()->json('data.allowed_transitions'))->keyBy('action');
        $this->assertFalse($byAnalyst['approve']['allowed']);
        $this->assertSame('forbidden', $byAnalyst['approve']['blocked_by']);
        $this->assertTrue($byAnalyst['approve']['requires']['expected_version']);
        $this->assertFalse($byAnalyst['start_without_approval']['allowed'], 'la reposición es sensible: exige aprobación');
        $this->assertSame('decision_approval_required', $byAnalyst['start_without_approval']['blocked_by']);

        $byAdmin = collect($this->act($w['admin'], $w)->getJson("/api/v1/decisions/{$id}")->json('data.allowed_transitions'))->keyBy('action');
        $this->assertFalse($byAdmin['approve']['allowed'], 'la propuso el administrador');
        $this->assertSame('decision_segregation_of_duties', $byAdmin['approve']['blocked_by']);

        $byOwner = collect($this->act($w['owner'], $w)->getJson("/api/v1/decisions/{$id}")->json('data.allowed_transitions'))->keyBy('action');
        $this->assertTrue($byOwner['approve']['allowed']);
        $this->assertTrue($byOwner['reject']['requires']['reason']);
    }

    // ───────────────────────── segregación de funciones (dos usuarios) ─────────────────────────

    public function test_the_proposer_cannot_approve_their_own_action_but_another_approver_can(): void
    {
        $w = $this->decisionWorld();
        $id = $this->replenishment($w, ['proposed_by_user_id' => $w['admin']->id])->id;   // la propone el administrador
        $this->toReviewed($w, $id, $w['analyst']);
        $version = $this->version($w['admin'], $w, $id);

        // El administrador tiene la habilidad decisions.approve, pero él la propuso: 403 por segregación.
        $this->go($w['admin'], $w, $id, 'approved', 'k-self-approve', ['expected_version' => $version])->assertStatus(403)
            ->assertJsonPath('code', 'decision_segregation_of_duties')->assertJsonPath('context.roles', ['proposer']);
        $this->assertSame('reviewed', DecisionRecommendation::withoutTenantScope()->findOrFail($id)->status);
        // Rechazar formalmente es una decisión con la misma segregación.
        $this->go($w['admin'], $w, $id, 'rejected', 'k-self-reject', ['expected_version' => $version, 'reason' => 'No me gusta'])->assertStatus(403)->assertJsonPath('code', 'decision_segregation_of_duties');
        // Otra persona con la habilidad (el propietario) sí puede.
        $this->go($w['owner'], $w, $id, 'approved', 'k-other-approve', ['expected_version' => $version])->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_whoever_must_execute_the_action_cannot_approve_it_and_whoever_approved_cannot_execute_it(): void
    {
        $w = $this->decisionWorld();
        $id = $this->replenishment($w)->id;
        $this->toReviewed($w, $id, $w['admin']);   // el administrador es el asignado (ejecutor) y tiene decisions.approve
        $version = $this->version($w['admin'], $w, $id);

        $this->go($w['admin'], $w, $id, 'approved', 'k-exec-approves', ['expected_version' => $version])->assertStatus(403)
            ->assertJsonPath('code', 'decision_segregation_of_duties')->assertJsonPath('context.roles', ['executor']);
        $this->go($w['owner'], $w, $id, 'approved', 'k-owner-approves', ['expected_version' => $version])->assertOk();
        // Quien aprobó (el propietario) no ejecuta lo que aprobó.
        $this->go($w['owner'], $w, $id, 'in_progress', 'k-approver-starts')->assertStatus(403)->assertJsonPath('code', 'decision_segregation_of_duties')->assertJsonPath('context.roles', ['approver']);
        // Ni se puede reasignar la ejecución al aprobador.
        $this->go($w['analyst'], $w, $id, 'in_progress', 'k-not-assignee')->assertStatus(403)->assertJsonPath('required_ability', 'decisions.approve');
        $this->go($w['admin'], $w, $id, 'in_progress', 'k-assignee-starts')->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->go($w['owner'], $w, $id, 'executed', 'k-approver-executes')->assertStatus(403)->assertJsonPath('context.roles', ['approver']);
        $this->go($w['admin'], $w, $id, 'executed', 'k-assignee-executes')->assertOk();
    }

    public function test_an_agenda_feedback_event_is_not_mistaken_for_the_approval(): void
    {
        $w = $this->decisionWorld();
        $id = $this->replenishment($w)->id;
        $this->toReviewed($w, $id, $w['analyst2']);   // el analista 2 es el asignado
        $this->go($w['owner'], $w, $id, 'approved', 'k-approve-feedback', ['expected_version' => $this->version($w['owner'], $w, $id)])->assertOk();
        // El seguimiento de la agenda (W5-A) del asignado deja un evento con to_status = approved; no es una aprobación suya.
        $this->withinTenant($w['tenant'], fn () => DecisionEvent::query()->create([
            'recommendation_id' => $id, 'event' => 'feedback', 'from_status' => 'approved', 'to_status' => 'approved', 'agenda_status' => 'contactado',
            'actor_user_id' => $w['analyst2']->id, 'actor_kind' => 'user', 'payload' => [], 'idempotency_key' => hash('sha256', 'radar.feedback-test'),
        ]));

        $this->go($w['analyst2'], $w, $id, 'in_progress', 'k-assignee-starts-after-feedback')->assertOk()->assertJsonPath('data.status', 'in_progress');
        // Y quien de verdad aprobó sigue sin poder ejecutar.
        $this->go($w['owner'], $w, $id, 'executed', 'k-approver-executes-after-feedback')->assertStatus(403)->assertJsonPath('context.roles', ['approver']);
    }

    public function test_the_assignee_must_be_a_member_with_manage_ability(): void
    {
        $w = $this->decisionWorld();
        $other = $this->decisionWorld();
        $id = $this->replenishment($w)->id;

        $this->go($w['owner'], $w, $id, 'assigned', 'k-assign-nonmember')->assertStatus(422)->assertJsonValidationErrors('assignee_user_id');
        $this->go($w['owner'], $w, $id, 'assigned', 'k-assign-foreign', ['assignee_user_id' => $other['analyst']->id])->assertStatus(422)->assertJsonValidationErrors('assignee_user_id');
        $this->go($w['owner'], $w, $id, 'assigned', 'k-assign-viewer', ['assignee_user_id' => $w['viewer']->id])->assertStatus(422)->assertJsonValidationErrors('assignee_user_id');
        $this->go($w['owner'], $w, $id, 'assigned', 'k-assign-ok-1', ['assignee_user_id' => $w['analyst']->id])->assertOk();
        // Reasignar mientras sigue «assigned» deja un evento de asignación (no de estado).
        $this->go($w['owner'], $w, $id, 'assigned', 'k-reassign-1', ['assignee_user_id' => $w['analyst2']->id])->assertOk()->assertJsonPath('data.assigned_user_id', $w['analyst2']->id);
        $last = DecisionEvent::withoutTenantScope()->where('recommendation_id', $id)->orderByDesc('id')->first();
        $this->assertSame(['assignment', 'assigned', 'assigned'], [$last->event, $last->from_status, $last->to_status]);
    }

    // ───────────────────────── acciones sensibles ─────────────────────────

    public function test_a_sensitive_action_cannot_start_without_approval_but_a_contact_can(): void
    {
        $w = $this->decisionWorld();
        $sensitive = $this->replenishment($w)->id;
        $contact = $this->contact($w)->id;
        $this->toReviewed($w, $sensitive, $w['analyst']);
        $this->toReviewed($w, $contact, $w['analyst']);

        $this->go($w['analyst'], $w, $sensitive, 'in_progress', 'k-skip-approval')->assertStatus(409)->assertJsonPath('code', 'decision_approval_required');
        $this->assertSame('reviewed', DecisionRecommendation::withoutTenantScope()->findOrFail($sensitive)->status);
        // El contacto no pide aprobación (Predictily tampoco envía el mensaje: la persona lo hace fuera).
        $this->go($w['analyst'], $w, $contact, 'in_progress', 'k-contact-start')->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->act($w['analyst'], $w)->getJson("/api/v1/decisions/{$sensitive}")->assertJsonPath('data.requires_approval', true);
        $this->act($w['analyst'], $w)->getJson("/api/v1/decisions/{$contact}")->assertJsonPath('data.requires_approval', false);
    }

    public function test_the_company_can_require_approval_for_contacts_or_above_a_cost_threshold(): void
    {
        $w = $this->decisionWorld();
        $w['tenant']->forceFill(['settings' => array_merge($w['tenant']->effectiveSettings(), ['decision' => ['approval_required_kinds' => ['contact']]])])->save();
        $contact = $this->contact($w)->id;
        $this->toReviewed($w, $contact, $w['analyst']);
        $this->go($w['analyst'], $w, $contact, 'in_progress', 'k-contact-needs-approval')->assertStatus(409)->assertJsonPath('code', 'decision_approval_required');

        $w2 = $this->decisionWorld();
        $w2['tenant']->forceFill(['settings' => array_merge($w2['tenant']->effectiveSettings(), ['decision' => ['approval_required_kinds' => [], 'approval_cost_threshold' => 100]])])->save();
        $cheap = $this->contact($w2)->id;     // sin costo de acción
        $costly = $this->contact($w2, ['product_id' => $w2['cat']['products']['p2'], 'action_cost' => 250.0])->id;
        $this->toReviewed($w2, $cheap, $w2['analyst']);
        $this->toReviewed($w2, $costly, $w2['analyst']);
        $this->go($w2['analyst'], $w2, $cheap, 'in_progress', 'k-cheap-start')->assertOk();
        $this->go($w2['analyst'], $w2, $costly, 'in_progress', 'k-costly-start')->assertStatus(409)->assertJsonPath('code', 'decision_approval_required');
    }

    public function test_a_recommendation_over_budget_requires_approval_even_if_its_kind_does_not(): void
    {
        $w = $this->decisionWorld();
        $w['tenant']->forceFill(['settings' => array_merge($w['tenant']->effectiveSettings(), ['decision' => ['approval_required_kinds' => []]])])->save();
        $id = $this->contact($w, ['constraints' => [['code' => 'over_budget']]])->id;
        $this->toReviewed($w, $id, $w['analyst']);

        $this->go($w['analyst'], $w, $id, 'in_progress', 'k-over-budget')->assertStatus(409)->assertJsonPath('code', 'decision_approval_required');
    }

    // ───────────────────────── habilidades por rol ─────────────────────────

    public function test_abilities_by_role_are_enforced_on_every_transition(): void
    {
        $w = $this->decisionWorld();
        $id = $this->replenishment($w)->id;

        // Consulta: ve, pero no actúa.
        $this->act($w['viewer'], $w)->getJson("/api/v1/decisions/{$id}")->assertOk();
        $this->go($w['viewer'], $w, $id, 'assigned', 'k-viewer-assign', ['assignee_user_id' => $w['analyst']->id])->assertStatus(403)->assertJsonPath('required_ability', 'decisions.manage');
        // Analista: gestiona pero no aprueba.
        $this->toReviewed($w, $id, $w['analyst']);
        $this->go($w['analyst'], $w, $id, 'approved', 'k-analyst-approve', ['expected_version' => $this->version($w['analyst'], $w, $id)])->assertStatus(403)->assertJsonPath('required_ability', 'decisions.approve');
        $this->go($w['analyst'], $w, $id, 'rejected', 'k-analyst-reject', ['expected_version' => $this->version($w['analyst'], $w, $id), 'reason' => 'Motivo suficiente'])->assertStatus(403)->assertJsonPath('required_ability', 'decisions.approve');
        // Sin sesión.
        $this->app['auth']->forgetGuards();
        $this->withHeader('X-Tenant-ID', $w['tenant']->uuid)->postJson("/api/v1/decisions/{$id}/transition", ['to_status' => 'closed', 'idempotency_key' => 'k-anon-xxxx'])->assertStatus(401);
    }

    public function test_dismissing_requires_a_reason_and_rejecting_requires_a_reason_too(): void
    {
        $w = $this->decisionWorld();
        $id = $this->contact($w)->id;
        $this->go($w['analyst'], $w, $id, 'rejected', 'k-dismiss-noreason')->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->go($w['analyst'], $w, $id, 'rejected', 'k-dismiss-reason', ['reason' => 'El cliente ya compró por otro canal'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $event = DecisionEvent::withoutTenantScope()->where('recommendation_id', $id)->orderByDesc('id')->first();
        $this->assertSame(['dismiss', 'El cliente ya compró por otro canal'], [$event->payload['action'], $event->reason]);

        $id2 = $this->replenishment($w)->id;
        $this->toReviewed($w, $id2, $w['analyst']);
        $this->go($w['owner'], $w, $id2, 'rejected', 'k-reject-noreason', ['expected_version' => $this->version($w['owner'], $w, $id2)])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->go($w['owner'], $w, $id2, 'rejected', 'k-reject-reason', ['expected_version' => $this->version($w['owner'], $w, $id2), 'reason' => 'Fuera de presupuesto'])->assertOk()->assertJsonPath('data.status', 'rejected');
    }

    // ───────────────────────── idempotencia ─────────────────────────

    public function test_repeating_a_transition_with_the_same_key_has_no_new_effects(): void
    {
        $w = $this->decisionWorld();
        $id = $this->replenishment($w)->id;
        $body = ['assignee_user_id' => $w['analyst']->id, 'expected_version' => $this->version($w['owner'], $w, $id)];   // un reintento manda EXACTAMENTE lo mismo

        $first = $this->go($w['owner'], $w, $id, 'assigned', 'retry-key-0001', $body)->assertOk();
        $this->assertFalse($first->json('meta.idempotent_replay'));
        $again = $this->go($w['owner'], $w, $id, 'assigned', 'retry-key-0001', $body)->assertOk();
        $again2 = $this->go($w['owner'], $w, $id, 'assigned', 'retry-key-0001', $body)->assertOk();

        $this->assertTrue($again->json('meta.idempotent_replay'));
        $this->assertTrue($again2->json('meta.idempotent_replay'));
        $this->assertSame($first->json('meta.event_id'), $again->json('meta.event_id'), 'el mismo evento');
        $this->assertSame('assigned', $again->json('data.status'));
        $this->assertSame(2, DecisionEvent::withoutTenantScope()->where('recommendation_id', $id)->count(), 'creación + una sola asignación');
        // El reintento de una transición que ya tuvo éxito responde igual aunque el estado haya avanzado después.
        $this->go($w['analyst'], $w, $id, 'reviewed', 'retry-key-0002', ['expected_version' => $this->version($w['analyst'], $w, $id)])->assertOk();
        $this->go($w['owner'], $w, $id, 'assigned', 'retry-key-0001', $body)->assertOk()->assertJsonPath('meta.idempotent_replay', true)->assertJsonPath('data.status', 'reviewed');
        $this->assertSame(3, DecisionEvent::withoutTenantScope()->where('recommendation_id', $id)->count());
    }

    public function test_a_key_already_used_for_another_target_is_refused_and_keys_are_independent_per_recommendation(): void
    {
        $w = $this->decisionWorld();
        $a = $this->replenishment($w)->id;
        $b = $this->contact($w)->id;

        $this->go($w['owner'], $w, $a, 'assigned', 'shared-key-001', ['assignee_user_id' => $w['analyst']->id])->assertOk();
        $this->go($w['owner'], $w, $a, 'rejected', 'shared-key-001', ['reason' => 'Motivo suficiente'])->assertStatus(409)->assertJsonPath('code', 'idempotency_key_reused');
        // La misma clave sobre otra recomendación es otra operación.
        $this->go($w['owner'], $w, $b, 'assigned', 'shared-key-001', ['assignee_user_id' => $w['analyst']->id])->assertOk()->assertJsonPath('meta.idempotent_replay', false);
        $this->assertSame('assigned', DecisionRecommendation::withoutTenantScope()->findOrFail($a)->status);
    }

    public function test_the_idempotency_key_is_required_and_validated(): void
    {
        $w = $this->decisionWorld();
        $id = $this->contact($w)->id;

        $this->act($w['owner'], $w)->postJson("/api/v1/decisions/{$id}/transition", ['to_status' => 'assigned', 'assignee_user_id' => $w['analyst']->id])->assertStatus(422)->assertJsonValidationErrors('idempotency_key');
        $this->go($w['owner'], $w, $id, 'assigned', 'corta')->assertStatus(422)->assertJsonValidationErrors('idempotency_key');
        $this->go($w['owner'], $w, $id, 'assigned', 'clave con espacios y ñ')->assertStatus(422)->assertJsonValidationErrors('idempotency_key');
        $this->act($w['owner'], $w)->postJson("/api/v1/decisions/{$id}/transition", ['to_status' => 'volar', 'idempotency_key' => 'k-valida-001'])->assertStatus(422)->assertJsonValidationErrors('to_status');
    }

    // ───────────────────────── concurrencia ─────────────────────────

    public function test_two_concurrent_reviews_do_not_overwrite_each_other_the_stale_one_is_refused_with_409(): void
    {
        $w = $this->decisionWorld();
        $id = $this->replenishment($w)->id;
        $this->toReviewed($w, $id, $w['analyst']);
        // Los dos aprobadores abren la recomendación y leen la MISMA versión.
        $seenByOwner = $this->version($w['owner'], $w, $id);
        $seenByAdmin = $this->version($w['admin'], $w, $id);
        $this->assertSame($seenByOwner, $seenByAdmin);

        $this->go($w['owner'], $w, $id, 'approved', 'k-owner-approves-1', ['expected_version' => $seenByOwner])->assertOk();
        // El administrador decide sobre lo que vio: la recomendación ya cambió.
        $stale = $this->go($w['admin'], $w, $id, 'rejected', 'k-admin-rejects-1', ['expected_version' => $seenByAdmin, 'reason' => 'Lo vi antes de la aprobación']);

        $stale->assertStatus(409)->assertJsonPath('code', 'decision_version_conflict')->assertJsonPath('context.expected_version', $seenByAdmin)->assertJsonPath('context.status', 'approved');
        $this->assertGreaterThan($seenByAdmin, $stale->json('context.current_version'));
        $this->assertSame('approved', DecisionRecommendation::withoutTenantScope()->findOrFail($id)->status, 'la decisión desfasada no pisó la aprobación');
        $this->assertSame(0, DecisionEvent::withoutTenantScope()->where('recommendation_id', $id)->where('to_status', 'rejected')->count());
        // Con la versión actual puede volver a decidir lo que corresponda (rechazar una ya aprobada no está permitido: estado, no versión).
        $current = $this->version($w['admin'], $w, $id);
        $this->go($w['admin'], $w, $id, 'rejected', 'k-admin-rejects-2', ['expected_version' => $current, 'reason' => 'Intento con la versión vigente'])->assertStatus(409)->assertJsonPath('code', 'decision_transition_not_allowed');
    }

    public function test_every_transition_locks_the_row_inside_a_transaction_before_deciding(): void
    {
        $w = $this->decisionWorld();
        $id = $this->contact($w)->id;
        $api = $this->act($w['owner'], $w);
        $version = $this->version($w['owner'], $w, $id);

        DB::enableQueryLog();
        $api->postJson("/api/v1/decisions/{$id}/transition", ['to_status' => 'assigned', 'idempotency_key' => 'k-lock-proof-1', 'assignee_user_id' => $w['analyst']->id, 'expected_version' => $version])->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query')->map(fn ($q) => strtolower((string) $q));
        DB::disableQueryLog();

        $lock = $queries->search(fn ($q) => str_contains($q, 'from "decision_recommendations"') && str_contains($q, 'for update'));
        $update = $queries->search(fn ($q) => str_starts_with($q, 'update "decision_recommendations"'));
        $this->assertNotFalse($lock, 'SELECT … FOR UPDATE sobre la recomendación');
        $this->assertNotFalse($update);
        $this->assertLessThan($update, $lock, 'se bloquea ANTES de decidir y escribir: dos decisiones simultáneas se serializan');
        $this->assertNotFalse($queries->search(fn ($q) => str_contains($q, 'max("id")') && str_contains($q, 'decision_events')), 'la versión se lee bajo el bloqueo');
    }

    public function test_review_approve_and_reject_require_the_expected_version(): void
    {
        $w = $this->decisionWorld();
        $id = $this->replenishment($w)->id;
        $this->go($w['analyst'], $w, $id, 'assigned', 'k-a-0000001', ['assignee_user_id' => $w['analyst']->id])->assertOk();

        $this->go($w['analyst'], $w, $id, 'reviewed', 'k-r-0000001', ['expected_version' => null])->assertStatus(422)->assertJsonValidationErrors('expected_version');
        $this->go($w['analyst'], $w, $id, 'reviewed', 'k-r-0000002', ['expected_version' => 1])->assertStatus(409)->assertJsonPath('code', 'decision_version_conflict');
        $this->assertSame('assigned', DecisionRecommendation::withoutTenantScope()->findOrFail($id)->status);
    }

    public function test_concurrent_assignments_are_serialized_by_the_row_lock_and_the_state_check(): void
    {
        $w = $this->decisionWorld();
        $id = $this->contact($w)->id;
        // Dos usuarios asignan «a la vez»: el segundo ya no encuentra la recomendación en «suggested» (compare-and-swap por estado bajo bloqueo de fila).
        $this->go($w['owner'], $w, $id, 'assigned', 'k-first-assign', ['assignee_user_id' => $w['analyst']->id])->assertOk();
        $this->go($w['admin'], $w, $id, 'assigned', 'k-second-assign', ['assignee_user_id' => $w['analyst2']->id])->assertOk()->assertJsonPath('data.assigned_user_id', $w['analyst2']->id);
        $this->go($w['admin'], $w, $id, 'rejected', 'k-second-dismiss', ['reason' => 'Ya no aplica'])->assertOk();
        $this->go($w['owner'], $w, $id, 'reviewed', 'k-late-review', ['expected_version' => 1])->assertStatus(409);
    }

    // ───────────────────────── aislamiento entre empresas ─────────────────────────

    public function test_a_recommendation_of_another_company_is_invisible_and_untouchable(): void
    {
        $a = $this->decisionWorld();
        $b = $this->decisionWorld();
        $idA = $this->replenishment($a)->id;
        $this->replenishment($b);

        $this->act($b['owner'], $b)->getJson("/api/v1/decisions/{$idA}")->assertStatus(404);
        $this->go($b['owner'], $b, $idA, 'assigned', 'k-cross-tenant-1', ['assignee_user_id' => $b['analyst']->id, 'expected_version' => 1])->assertStatus(404);
        $this->go($b['owner'], $b, $idA, 'rejected', 'k-cross-tenant-2', ['reason' => 'Motivo suficiente', 'expected_version' => 1])->assertStatus(404);
        $list = $this->act($b['owner'], $b)->getJson('/api/v1/decisions')->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertNotContains($idA, array_column($list, 'id'));
        $this->assertSame('suggested', DecisionRecommendation::withoutTenantScope()->findOrFail($idA)->status);
        $this->assertSame(1, DecisionEvent::withoutTenantScope()->where('recommendation_id', $idA)->count());
        // Un usuario de la empresa A no puede usar el encabezado de la empresa B.
        $this->actingAsTenantUser($a['owner'], $b['tenant'])->getJson('/api/v1/decisions')->assertStatus(404);
        // La clave de idempotencia de una empresa no choca con la de otra.
        $this->go($a['owner'], $a, $idA, 'assigned', 'same-key-0001', ['assignee_user_id' => $a['analyst']->id])->assertOk();
        $idB = $this->withinTenant($b['tenant'], fn () => DecisionRecommendation::query()->firstOrFail()->id);
        $this->go($b['owner'], $b, $idB, 'assigned', 'same-key-0001', ['assignee_user_id' => $b['analyst']->id])->assertOk()->assertJsonPath('meta.idempotent_replay', false);
    }

    // ───────────────────────── lista, detalle y resumen ─────────────────────────

    public function test_the_list_filters_sorts_and_paginates(): void
    {
        $w = $this->decisionWorld();
        $a = $this->replenishment($w);
        $b = $this->contact($w);
        $c = $this->contact($w, ['product_id' => $w['cat']['products']['p2']]);
        DB::table('decision_recommendations')->where('id', $a->id)->update(['priority' => 1]);
        DB::table('decision_recommendations')->where('id', $b->id)->update(['priority' => 2, 'status' => 'assigned', 'assigned_user_id' => $w['analyst']->id]);
        $api = $this->act($w['analyst'], $w);

        $listed = collect($api->getJson('/api/v1/decisions?kind=replenishment')->assertOk()->json('data'))->first();
        $this->assertTrue($listed['requires_approval'], 'la lista trae lo necesario para actuar sin abrir el detalle');
        $this->assertSame(['assign', 'dismiss'], array_column(array_filter($listed['allowed_transitions'], fn ($t) => $t['allowed']), 'action'));
        $this->assertIsInt($listed['version']);
        $this->assertEqualsCanonicalizing([$a->id], array_column($api->getJson('/api/v1/decisions?kind=replenishment')->assertOk()->json('data'), 'id'));
        $this->assertEqualsCanonicalizing([$b->id], array_column($api->getJson('/api/v1/decisions?status=assigned')->json('data'), 'id'));
        $this->assertEqualsCanonicalizing([$b->id], array_column($api->getJson('/api/v1/decisions?mine=1')->json('data'), 'id'));
        $this->assertEqualsCanonicalizing([$a->id, $c->id], array_column($api->getJson('/api/v1/decisions?status=suggested')->json('data'), 'id'));
        $this->assertEqualsCanonicalizing([$a->id], array_column($api->getJson('/api/v1/decisions?requires_approval=1')->json('data'), 'id'));
        $this->assertEqualsCanonicalizing([$b->id, $c->id], array_column($api->getJson('/api/v1/decisions?requires_approval=0')->json('data'), 'id'));
        $this->assertSame([$a->id, $b->id, $c->id], array_column($api->getJson('/api/v1/decisions?sort=priority')->json('data'), 'id'));
        $this->assertSame($a->id, $api->getJson('/api/v1/decisions?per_page=1&sort=priority')->assertJsonPath('meta.total', 3)->json('data.0.id'));
        $api->getJson('/api/v1/decisions?sort=inventado')->assertStatus(422);
        $api->getJson('/api/v1/decisions?status=suggested;DROP')->assertStatus(422);
    }

    public function test_an_unknown_status_filter_returns_nothing_and_the_list_does_not_query_per_row(): void
    {
        $w = $this->decisionWorld();
        $first = $this->contact($w);
        $api = $this->act($w['analyst'], $w);
        $api->getJson('/api/v1/decisions?status=inventado')->assertOk()->assertJsonCount(0, 'data');
        $api->getJson('/api/v1/decisions?kind=inventado')->assertOk()->assertJsonCount(0, 'data');

        $count = function () use ($api): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $api->getJson('/api/v1/decisions?per_page=50')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $one = $count();
        // Más filas en estados que obligan a consultar el aprobador (aprobadas) y con relaciones distintas.
        foreach ([['p2', 30], ['p1', 7], ['p2', 7], ['p1', 90], ['p2', 90]] as $i => [$p, $h]) {
            $rec = $this->contact($w, ['product_id' => $w['cat']['products'][$p], 'horizon_days' => $h]);
            DB::table('decision_recommendations')->where('id', $rec->id)->update(['status' => $i % 2 === 0 ? 'approved' : 'reviewed']);
        }
        $this->assertSame($one, $count(), 'las consultas de la lista no crecen con el número de filas');
        $this->assertGreaterThan(0, $first->id);
    }

    public function test_the_detail_exposes_the_complete_contract_without_pii_and_the_summary_counts(): void
    {
        $w = $this->decisionWorld();
        $id = $this->contact($w)->id;
        $this->replenishment($w);

        $data = $this->act($w['analyst'], $w)->getJson("/api/v1/decisions/{$id}")->assertOk()->json('data');
        foreach (['id', 'kind', 'as_of', 'horizon_days', 'entity_type', 'customer', 'product', 'source', 'model_versions', 'probability', 'interval', 'assumptions', 'constraints',
            'availability', 'currency', 'action_cost', 'expected_gross_value', 'expected_contribution', 'incremental_estimate', 'attribution_label', 'attribution_note', 'score',
            'score_formula_version', 'uncertainty', 'explanation', 'policy', 'priority', 'status', 'expires_at', 'next_step', 'version', 'requires_approval', 'allowed_transitions',
            'events', 'proposed_by'] as $field) {
            $this->assertArrayHasKey($field, $data, "falta {$field} del contrato");
        }
        $this->assertSame('potential_unproven', $data['attribution_label']);
        $this->assertStringContainsString('atribución no demostrada', $data['attribution_note']);
        $this->assertNull($data['incremental_estimate']);
        $this->assertArrayNotHasKey('experiment_group', $data, 'el grupo de experimento solo lo ve quien aprueba');
        $this->assertArrayNotHasKey('idempotency_key', $data);
        $this->assertSame(['id', 'external_code'], array_keys($data['customer']));   // sin nombre del cliente
        $this->assertCount(1, $data['events']);

        $summary = $this->act($w['analyst'], $w)->getJson('/api/v1/decisions/summary')->assertOk()->json('data');
        $this->assertSame(2, $summary['by_status']['suggested']);
        $this->assertSame(1, $summary['by_kind']['replenishment']);
        $this->assertSame(2, $summary['by_attribution_label']['potential_unproven']);
        $this->assertSame(2, $summary['awaiting_assignment']);
        $this->assertArrayNotHasKey('control_group_units', $summary);
        $this->assertStringContainsString('no son ingresos recuperados', $summary['attribution_note']);
    }
}
