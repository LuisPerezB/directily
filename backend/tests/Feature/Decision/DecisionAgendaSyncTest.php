<?php

declare(strict_types=1);

namespace Tests\Feature\Decision;

use App\Contracts\Decision\RecommendationSink;
use App\Enums\TenantRole;
use App\Models\DecisionRecommendation;
use App\Models\User;
use App\Services\Decision\Workflow;
use App\Services\Radar\AgendaFeedbackService;
use App\Services\Radar\AgendaStatusMap;
use App\Services\Radar\Exceptions\RadarException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Decision\Support\DecisionFixtures;
use Tests\TestCase;

/**
 * V2.5 · W5-B ← W5-A: el seguimiento de la agenda (`radar-agenda-map-v1`) mueve el workflow con la transición que implica, COMO CUALQUIER OTRA: mismas habilidades, segregación,
 * aprobación de acciones sensibles y bloqueo optimista. Si el workflow no admite la transición desde su estado actual, el seguimiento se registra igual, el workflow no se
 * mueve y el motivo queda en el propio evento. El seguimiento del vendedor asignado jamás cuenta como aprobación.
 */
final class DecisionAgendaSyncTest extends TestCase
{
    use DecisionFixtures;
    use RefreshDatabase;

    private function contact(array $w): DecisionRecommendation
    {
        return $this->withinTenant($w['tenant'], fn () => app(RecommendationSink::class)->propose($this->contactProposal($w)));
    }

    private function replenishment(array $w): DecisionRecommendation
    {
        return $this->withinTenant($w['tenant'], fn () => app(RecommendationSink::class)->propose([
            'kind' => 'replenishment', 'as_of' => $w['asOf'], 'horizon_days' => 14, 'entity_type' => 'product_branch', 'product_id' => $w['cat']['products']['p1'],
            'branch_id' => $w['cat']['branch'], 'expected_units' => 80.0,
        ]));
    }

    private function setStatus(int $id, string $status, ?int $assignee = null): void
    {
        DB::table('decision_recommendations')->where('id', $id)->update(['status' => $status, 'assigned_user_id' => $assignee]);
    }

    private static int $n = 0;

    private function feedback(array $w, User $user, int $id, string $state, ?string $note = null, ?string $key = null): TestResponse
    {
        self::$n++;

        return $this->actingAsTenantUser($user, $w['tenant'])->postJson("/api/v1/radar/opportunities/{$id}/feedback", array_filter([
            'state' => $state, 'note' => $note, 'idempotency_key' => $key ?? 'agenda-sync-'.self::$n.'-'.$id,
            'snoozed_until' => $state === 'pospuesto' ? now()->addDays(10)->toDateString() : null,
        ]));
    }

    private function statusOf(int $id): string
    {
        return (string) DB::table('decision_recommendations')->where('id', $id)->value('status');
    }

    /**
     * Payload del último evento de seguimiento MÁS el resultado de su sincronización con el workflow, que va en un segundo evento (`comment`, `kind = workflow_sync`): el
     * evento de seguimiento se inserta una vez y no se modifica.
     *
     * @return array<string, mixed>
     */
    private function lastFeedback(int $id): array
    {
        $feedback = DB::table('decision_events')->where('recommendation_id', $id)->where('event', 'feedback')->orderByDesc('id')->first();
        $sync = DB::table('decision_events')->where('recommendation_id', $id)->where('event', 'comment')->orderByDesc('id')->get()
            ->first(fn ($e) => (json_decode((string) $e->payload, true)['feedback_event_id'] ?? null) === $feedback->id);

        return (array) json_decode((string) $feedback->payload, true) + (array) json_decode((string) ($sync->payload ?? '[]'), true);
    }

    private function transitionsOf(int $id): array
    {
        return DB::table('decision_events')->where('recommendation_id', $id)->where('event', 'transition')->orderBy('id')->get(['from_status', 'to_status', 'actor_user_id', 'payload'])
            ->map(fn ($e) => [$e->from_status, $e->to_status])->all();
    }

    public function test_contacting_a_reviewed_non_sensitive_opportunity_starts_the_action_then_interest_changes_nothing_and_the_order_executes_it(): void
    {
        $w = $this->decisionWorld();
        $id = $this->contact($w)->id;
        $this->setStatus($id, 'reviewed', $w['analyst']->id);

        $r = $this->feedback($w, $w['analyst'], $id, 'contactado', 'Llamé al cliente')->assertCreated();

        $this->assertSame('in_progress', $this->statusOf($id));
        $this->assertSame('in_progress', $r->json('data.opportunity.status'), 'la respuesta ya trae el workflow actualizado');
        $payload = $this->lastFeedback($id);
        $this->assertSame('applied', $payload['workflow_sync']);
        $this->assertSame('in_progress', $payload['workflow_status_after']);
        $transition = DB::table('decision_events')->where('id', $payload['workflow_event_id'])->first();
        $this->assertSame(['transition', 'reviewed', 'in_progress', $w['analyst']->id], [$transition->event, $transition->from_status, $transition->to_status, $transition->actor_user_id]);
        $this->assertSame('start_without_approval', json_decode($transition->payload, true)['action']);
        $this->assertGreaterThanOrEqual(1, DB::table('audit_logs')->where('action', 'decision.start_without_approval')->count());

        // interesado también implica in_progress: ya está ahí → sin cambio, sin otra transición.
        $this->feedback($w, $w['analyst'], $id, 'interesado')->assertCreated();
        $this->assertSame('unchanged', $this->lastFeedback($id)['workflow_sync']);
        $this->assertSame(1, DB::table('decision_events')->where('recommendation_id', $id)->where('event', 'transition')->where('to_status', 'in_progress')->count());

        $this->feedback($w, $w['analyst'], $id, 'pedido_recibido')->assertCreated();
        $this->assertSame('executed', $this->statusOf($id));
        $this->assertSame('applied', $this->lastFeedback($id)['workflow_sync']);
        $this->assertSame([[null, 'suggested'], ['reviewed', 'in_progress'], ['in_progress', 'executed']], array_map(
            fn ($t) => $t, DB::table('decision_events')->where('recommendation_id', $id)->where('event', 'transition')->orderBy('id')->get(['from_status', 'to_status'])->map(fn ($e) => [$e->from_status, $e->to_status])->all(),
        ));
        // Los tres seguimientos quedan íntegros (historial sin pérdida).
        $this->assertSame(['contactado', 'interesado', 'pedido_recibido'], DB::table('decision_events')->where('recommendation_id', $id)->where('event', 'feedback')->orderBy('id')->pluck('agenda_status')->all());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function notDefinedStates(): array
    {
        return ['sugerida' => ['suggested', 'contactado'], 'asignada' => ['assigned', 'contactado'], 'revisada → ejecutada' => ['reviewed', 'pedido_recibido'], 'aprobada → ejecutada' => ['approved', 'pedido_recibido']];
    }

    #[DataProvider('notDefinedStates')]
    public function test_when_the_implied_transition_does_not_exist_from_the_current_state_the_feedback_is_recorded_and_the_workflow_does_not_move(string $status, string $state): void
    {
        $w = $this->decisionWorld();
        $id = $this->contact($w)->id;
        $this->setStatus($id, $status, $w['analyst']->id);

        $this->feedback($w, $w['analyst'], $id, $state, 'Seguimiento')->assertCreated()->assertJsonPath('data.opportunity.status', $status);

        $this->assertSame($status, $this->statusOf($id));
        $payload = $this->lastFeedback($id);
        $this->assertSame(['skipped', 'transition_not_defined', $status], [$payload['workflow_sync'], $payload['workflow_sync_reason'], $payload['workflow_status_after']]);
        $this->assertSame($state, DB::table('decision_recommendations')->where('id', $id)->value('agenda_status'), 'el estado heredado sí cambió: nada se pierde');
        $this->assertSame(0, DB::table('decision_events')->where('recommendation_id', $id)->where('event', 'transition')->where('to_status', '<>', 'suggested')->count());
    }

    public function test_a_sensitive_action_that_is_still_reviewed_cannot_be_started_from_the_agenda(): void
    {
        $w = $this->decisionWorld();
        $w['tenant']->forceFill(['settings' => array_merge($w['tenant']->effectiveSettings(), ['decision' => ['approval_required_kinds' => ['contact']]])])->save();
        $id = $this->contact($w)->id;
        $this->setStatus($id, 'reviewed', $w['analyst']->id);

        $this->feedback($w, $w['analyst'], $id, 'contactado')->assertCreated();

        $payload = $this->lastFeedback($id);
        $this->assertSame(['skipped', 'decision_approval_required'], [$payload['workflow_sync'], $payload['workflow_sync_reason']]);
        $this->assertSame('reviewed', $this->statusOf($id), 'contactar al cliente no aprueba nada');
        $this->assertSame(0, DB::table('decision_events')->where('recommendation_id', $id)->where('to_status', 'approved')->count());
    }

    public function test_the_feedback_of_the_assigned_seller_never_counts_as_approval_and_the_approver_cannot_execute_through_the_agenda(): void
    {
        $w = $this->decisionWorld();
        $id = $this->replenishment($w)->id;
        // Flujo real por la API del workflow: analista 2 asignado, revisada, aprobada por el propietario.
        $version = fn (User $u) => (int) $this->actingAsTenantUser($u, $w['tenant'])->getJson("/api/v1/decisions/{$id}")->json('data.version');
        $api = fn (User $u, string $to, string $key, array $extra = []) => $this->actingAsTenantUser($u, $w['tenant'])->postJson(
            "/api/v1/decisions/{$id}/transition", ['to_status' => $to, 'idempotency_key' => $key, 'expected_version' => $version($u)] + $extra,
        );
        $api($w['owner'], 'assigned', 'sync-assign-001', ['assignee_user_id' => $w['analyst2']->id])->assertOk();
        $api($w['analyst2'], 'reviewed', 'sync-review-001', ['expected_version' => $version($w['analyst2'])])->assertOk();
        $api($w['owner'], 'approved', 'sync-approve-001', ['expected_version' => $version($w['owner'])])->assertOk();
        // La reposición es una acción del Decision Engine: el Radar no la lista, así que el seguimiento se aplica al servicio directamente (misma ruta que usa la agenda).
        $service = app(AgendaFeedbackService::class);
        $apply = fn (User $u, string $state, string $key) => $this->withinTenant($w['tenant'], fn () => DB::transaction(function () use ($service, $id, $u, $state, $key) {
            DB::table('decision_recommendations')->where('id', $id)->update(['kind' => 'contact']);   // el Radar solo atiende contacto/recuperación/riesgo
            $result = $service->apply($id, $u, $state, null, $key);
            DB::table('decision_recommendations')->where('id', $id)->update(['kind' => 'replenishment']);

            return $result;
        }));

        // Quien aprobó (propietario) da seguimiento «contactado»: no puede ejecutar lo que aprobó.
        $r = $apply($w['owner'], 'contactado', 'sync-owner-contact-1');
        $this->assertSame('approved', $this->statusOf($id));
        $this->assertSame(['skipped', 'decision_segregation_of_duties'], [$r['workflow_sync']['workflow_sync'], $r['workflow_sync']['workflow_sync_reason']]);
        // Un analista que no es el asignado ni supervisor tampoco.
        $r = $apply($w['analyst'], 'interesado', 'sync-analyst-contact-1');
        $this->assertSame(['skipped', 'forbidden', 'decisions.approve'], [$r['workflow_sync']['workflow_sync'], $r['workflow_sync']['workflow_sync_reason'], $r['workflow_sync']['workflow_required_ability']]);
        $this->assertSame('approved', $this->statusOf($id));
        // El asignado (que no aprobó) sí inicia la acción; su seguimiento NO se confunde con la aprobación ni la altera.
        $r = $apply($w['analyst2'], 'contactado', 'sync-assignee-contact-1');
        $this->assertSame('applied', $r['workflow_sync']['workflow_sync']);
        $this->assertSame('in_progress', $this->statusOf($id));
        $approvals = DB::table('decision_events')->where('recommendation_id', $id)->where('event', 'transition')->where('to_status', 'approved')->pluck('actor_user_id')->all();
        $this->assertSame([$w['owner']->id], $approvals, 'la única aprobación es la del propietario');
        // Y quien aprobó sigue sin poder registrar la ejecución por la agenda.
        try {
            $apply($w['owner'], 'pedido_recibido', 'sync-owner-order-1');
            $this->fail('Debió rechazarse: quien aprobó no ejecuta, y «pedido recibido» equivale a ejecutar.');
        } catch (RadarException $e) {
            $this->assertSame(['decision_segregation_of_duties', 403], [$e->errorCode, $e->status]);
        }
        $this->assertSame('in_progress', $this->statusOf($id));
        // El asignado lo registra por el workflow (la agenda ya quedó en un estado final por el seguimiento del propietario).
        $api($w['analyst2'], 'executed', 'sync-assignee-exec-001')->assertOk()->assertJsonPath('data.status', 'executed');
    }

    public function test_dropping_an_opportunity_follows_the_roles_dismiss_before_review_and_formal_rejection_after(): void
    {
        $w = $this->decisionWorld();
        $a = $this->contact($w)->id;
        $this->feedback($w, $w['analyst'], $a, 'descartado', 'El cliente ya compró por otro canal')->assertCreated();
        $this->assertSame('rejected', $this->statusOf($a));
        $event = DB::table('decision_events')->where('recommendation_id', $a)->where('event', 'transition')->orderByDesc('id')->first();
        $this->assertSame(['dismiss', 'El cliente ya compró por otro canal'], [json_decode($event->payload, true)['action'], $event->reason]);

        // Sin nota suficiente, el motivo dice de dónde viene.
        $b = $this->withinTenant($w['tenant'], fn () => app(RecommendationSink::class)->propose($this->contactProposal($w, ['product_id' => $w['cat']['products']['p2']])))->id;
        $other = $this->withinTenant($w['tenant'], fn () => app(RecommendationSink::class)->propose($this->contactProposal($w, ['horizon_days' => 7])));
        $this->feedback($w, $w['analyst'], $other->id, 'descartado')->assertCreated();
        $this->assertSame('Descartada desde la agenda comercial.', DB::table('decision_events')->where('recommendation_id', $other->id)->where('event', 'transition')->orderByDesc('id')->value('reason'));

        // Revisada: rechazar es una decisión formal. El analista no puede: el seguimiento se RECHAZA (403) y no cambia nada —ni el estado de la agenda ni el workflow—, para que la
        // oportunidad no desaparezca de las listas mientras el workflow sigue en «reviewed».
        $this->setStatus($b, 'reviewed', $w['analyst']->id);
        $before = DB::table('decision_events')->where('recommendation_id', $b)->count();
        $this->feedback($w, $w['analyst'], $b, 'descartado', 'No corresponde')->assertStatus(403)->assertJsonPath('code', 'forbidden')->assertJsonPath('required_ability', 'decisions.approve');
        $this->assertSame('reviewed', $this->statusOf($b));
        $this->assertNull(DB::table('decision_recommendations')->where('id', $b)->value('agenda_status'), 'la agenda no cambió');
        $this->assertSame($before, DB::table('decision_events')->where('recommendation_id', $b)->count(), 'ni un evento');
        $list = collect($this->actingAsTenantUser($w['analyst'], $w['tenant'])->getJson('/api/v1/radar/opportunities?per_page=100')->json('data'))->pluck('id');
        $this->assertContains($b, $list->all(), 'sigue visible en la lista de trabajo');
        // «Pedido recibido» sobre una acción en curso que no es suya tampoco se puede cerrar por la agenda.
        $this->setStatus($b, 'in_progress', $w['admin']->id);
        $this->feedback($w, $w['analyst'], $b, 'pedido_recibido')->assertStatus(403)->assertJsonPath('required_ability', 'decisions.approve');
        $this->assertSame('in_progress', $this->statusOf($b));
    }

    public function test_a_formal_rejection_from_the_agenda_by_an_approver_uses_the_current_version_and_respects_segregation(): void
    {
        $w = $this->decisionWorld();
        $id = $this->contact($w)->id;
        $this->setStatus($id, 'reviewed', $w['analyst']->id);
        $this->feedback($w, $w['admin'], $id, 'descartado', 'Se descarta tras la revisión')->assertCreated();
        $this->assertSame('rejected', $this->statusOf($id));
        $this->assertSame('applied', $this->lastFeedback($id)['workflow_sync']);
        $this->assertSame('reject', json_decode((string) DB::table('decision_events')->where('recommendation_id', $id)->where('event', 'transition')->orderByDesc('id')->value('payload'), true)['action']);

        // El asignado con habilidad de aprobar tampoco rechaza formalmente la que debe ejecutar.
        $id2 = $this->withinTenant($w['tenant'], fn () => app(RecommendationSink::class)->propose($this->contactProposal($w, ['horizon_days' => 7])))->id;
        $this->setStatus($id2, 'reviewed', $w['admin']->id);
        $this->feedback($w, $w['admin'], $id2, 'descartado', 'No me toca')->assertStatus(403)->assertJsonPath('code', 'decision_segregation_of_duties');
        $this->assertSame('reviewed', $this->statusOf($id2));
        $this->assertNull(DB::table('decision_recommendations')->where('id', $id2)->value('agenda_status'), 'rechazado de antemano: la agenda no cambió');
    }

    public function test_a_stale_version_is_detected_by_the_optimistic_lock(): void
    {
        $w = $this->decisionWorld();
        $id = $this->contact($w)->id;
        $this->setStatus($id, 'reviewed', $w['analyst']->id);
        $workflow = app(Workflow::class);

        $stale = $this->withinTenant($w['tenant'], fn () => $workflow->applyImplied($id, 'rejected', $w['admin'], TenantRole::Admin, 'agenda-feedback:stale-1', ['expected_version' => 1, 'reason' => 'Motivo suficiente']));

        $this->assertSame(['skipped', 'decision_version_conflict'], [$stale['sync'], $stale['reason']]);
        $this->assertSame('reviewed', $this->statusOf($id));
        $current = $this->withinTenant($w['tenant'], fn () => $workflow->versionOf($id));
        $ok = $this->withinTenant($w['tenant'], fn () => $workflow->applyImplied($id, 'rejected', $w['admin'], TenantRole::Admin, 'agenda-feedback:ok-1', ['expected_version' => $current, 'reason' => 'Motivo suficiente']));
        $this->assertSame(['applied', 'rejected'], [$ok['sync'], $this->statusOf($id)]);
    }

    public function test_postponing_changes_nothing_in_the_workflow_and_a_repeated_feedback_does_not_repeat_the_transition(): void
    {
        $w = $this->decisionWorld();
        $id = $this->contact($w)->id;
        $this->setStatus($id, 'reviewed', $w['analyst']->id);

        $this->feedback($w, $w['analyst'], $id, 'pospuesto')->assertCreated();
        $this->assertSame(['not_applicable', 'reviewed'], [$this->lastFeedback($id)['workflow_sync'], $this->statusOf($id)]);

        $first = $this->feedback($w, $w['analyst'], $id, 'contactado', null, 'misma-clave-0001')->assertCreated();
        $replay = $this->feedback($w, $w['analyst'], $id, 'contactado', null, 'misma-clave-0001')->assertOk();
        $this->assertTrue($replay->json('data.replayed'));
        $this->assertSame($first->json('data.event.id'), $replay->json('data.event.id'));
        $this->assertSame('in_progress', $this->statusOf($id));
        $this->assertSame(1, DB::table('decision_events')->where('recommendation_id', $id)->where('event', 'transition')->where('to_status', 'in_progress')->count(), 'un solo evento de transición');
        $this->assertSame(2, DB::table('decision_events')->where('recommendation_id', $id)->where('event', 'feedback')->count());
    }

    public function test_the_version_covers_the_agenda_feedback_so_a_stale_review_is_refused(): void
    {
        $w = $this->decisionWorld();
        $id = $this->replenishment($w)->id;
        $this->setStatus($id, 'assigned', $w['analyst']->id);
        $seen = (int) $this->actingAsTenantUser($w['analyst'], $w['tenant'])->getJson("/api/v1/decisions/{$id}")->json('data.version');
        DB::table('decision_events')->insert(['tenant_id' => $w['tenant']->id, 'recommendation_id' => $id, 'event' => 'feedback', 'from_status' => 'assigned', 'to_status' => 'assigned',
            'agenda_status' => 'pospuesto', 'actor_user_id' => $w['analyst']->id, 'actor_kind' => 'user', 'payload' => '{}', 'idempotency_key' => str_repeat('d', 64)]);

        $this->actingAsTenantUser($w['analyst'], $w['tenant'])->postJson("/api/v1/decisions/{$id}/transition", ['to_status' => 'reviewed', 'idempotency_key' => 'sync-stale-review-1', 'expected_version' => $seen])
            ->assertStatus(409)->assertJsonPath('code', 'decision_version_conflict');
    }

    public function test_the_agenda_status_map_and_the_workflow_agree_on_every_legacy_state(): void
    {
        $implied = collect(AgendaStatusMap::STATES)->mapWithKeys(fn ($s) => [$s => AgendaStatusMap::impliedWorkflowStatus($s)])->all();
        $this->assertSame(['pendiente' => 'suggested', 'contactado' => 'in_progress', 'interesado' => 'in_progress', 'pedido_recibido' => 'executed', 'pospuesto' => null, 'descartado' => 'rejected'], $implied);
        $this->assertSame('radar-agenda-map-v1', AgendaStatusMap::VERSION);
        foreach (array_filter($implied) as $legacy => $status) {
            $this->assertContains($status, DecisionRecommendation::STATUSES, "{$legacy} → {$status} es un estado del workflow");
        }
    }
}
