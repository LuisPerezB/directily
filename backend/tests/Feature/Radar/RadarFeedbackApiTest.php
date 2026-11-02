<?php

declare(strict_types=1);

namespace Tests\Feature\Radar;

use App\Enums\TenantRole;
use App\Models\DecisionEvent;
use App\Models\DecisionRecommendation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Radar\AgendaFeedbackService;
use App\Services\Radar\AgendaStatusMap;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Radar\Support\RadarFixtures;
use Tests\TestCase;

/**
 * V2.5 · W5-A: POST /radar/opportunities/{id}/feedback (V25-AGD-005, -006, -007, -020): seguimiento con los estados heredados, auditable, inmutable e idempotente;
 * mapeo al workflow sin perder historial; roles existentes; aislamiento; sin envío automático de mensajes.
 */
class RadarFeedbackApiTest extends TestCase
{
    use RadarFixtures;
    use RefreshDatabase;

    /** @return array{tenant: Tenant, user: User, cat: array, run: int, id: int} */
    private function company(TenantRole $role = TenantRole::Analyst): array
    {
        $c = $this->radarCompany($role);
        $this->materialize($c['tenant']);
        $c['id'] = (int) DB::table('decision_recommendations')->where('tenant_id', $c['tenant']->id)->where('kind', 'contact')->where('horizon_days', 30)
            ->where('customer_id', $c['cat']['customers']['c1'])->where('product_id', $c['cat']['products']['p1'])->value('id');

        return $c;
    }

    private function api(array $c): static
    {
        return $this->actingAsTenantUser($c['user'], $c['tenant']);
    }

    /** @param array<string, mixed> $body */
    private function feedback(array $c, array $body, ?int $id = null): TestResponse
    {
        return $this->api($c)->postJson('/api/v1/radar/opportunities/'.($id ?? $c['id']).'/feedback', $body);
    }

    private function events(array $c, ?int $id = null): Collection
    {
        // Eventos de seguimiento, transiciones y asignaciones; los comentarios del sistema (resultado de la sincronización con el workflow) se prueban aparte.
        return DB::table('decision_events')->where('tenant_id', $c['tenant']->id)->where('recommendation_id', $id ?? $c['id'])->where('event', '<>', 'comment')->orderBy('id')->get();
    }

    public function test_the_first_feedback_is_201_and_leaves_an_immutable_auditable_event(): void
    {
        $c = $this->company();

        $r = $this->feedback($c, ['state' => 'contactado', 'note' => 'Llamé y quedó en confirmar el pedido', 'idempotency_key' => 'ui-0001-contacto'])->assertCreated();

        $this->assertFalse($r->json('data.replayed'));
        $this->assertSame('contactado', $r->json('data.opportunity.state'));
        $this->assertSame('suggested', $r->json('data.opportunity.status'), 'suggested → in_progress no existe en el workflow (falta asignar y revisar): el seguimiento se registra sin mover el workflow');
        $this->assertSame('pendiente', $r->json('data.event.from_agenda_status'));
        $this->assertSame('contactado', $r->json('data.event.agenda_status'));
        $this->assertSame($c['user']->id, $r->json('data.event.actor_user_id'));
        $this->assertSame('Llamé y quedó en confirmar el pedido', $r->json('data.event.note'));
        $this->assertSame('in_progress', $r->json('data.event.implied_workflow_status'));
        $this->assertSame('radar-agenda-map-v1', $r->json('status_map.version'));

        $events = $this->events($c);
        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('feedback', $event->event);
        $this->assertSame('user', $event->actor_kind);
        $this->assertSame(AgendaFeedbackService::eventKey($c['id'], 'ui-0001-contacto'), $event->idempotency_key);
        $this->assertSame('suggested', $event->from_status);
        $this->assertSame('suggested', $event->to_status);
        $payload = json_decode($event->payload, true);
        // El evento de seguimiento queda tal como se insertó (solo se inserta): el resultado de la sincronización con el workflow va en un SEGUNDO evento.
        $this->assertArrayNotHasKey('workflow_sync', $payload);
        $sync = DB::table('decision_events')->where('recommendation_id', $c['id'])->where('event', 'comment')->get();
        $this->assertCount(1, $sync);
        $syncPayload = json_decode($sync->first()->payload, true);
        $this->assertSame(['workflow_sync', $event->id, 'skipped', 'transition_not_defined', 'suggested'], [$syncPayload['kind'], $syncPayload['feedback_event_id'], $syncPayload['workflow_sync'], $syncPayload['workflow_sync_reason'], $syncPayload['workflow_status_after']]);
        $this->assertSame('skipped', $r->json('data.event.workflow_sync'), 'el seguimiento devuelto trae el resultado de la sincronización');
        $this->assertSame('in_progress', $payload['implied_workflow_status']);
        $this->assertSame('contactado', DB::table('decision_recommendations')->where('id', $c['id'])->value('agenda_status'));
        // Auditoría general: quién, qué y de qué estado a cuál (sin el texto libre de la nota).
        $audit = DB::table('audit_logs')->where('action', 'radar.feedback')->where('auditable_id', $c['id'])->first();
        $this->assertNotNull($audit);
        $this->assertSame($c['user']->id, $audit->user_id);
        $meta = json_decode($audit->metadata, true);
        $this->assertSame(['pendiente', 'contactado', true], [$meta['from'], $meta['to'], $meta['has_note']]);
        $this->assertStringNotContainsString('Llamé', (string) $audit->metadata);
    }

    public function test_repeating_the_same_request_is_idempotent_200_and_adds_nothing(): void
    {
        $c = $this->company();
        $body = ['state' => 'contactado', 'note' => 'Primer contacto', 'idempotency_key' => 'reintento-seguro-1'];
        $first = $this->feedback($c, $body)->assertCreated();

        $retry = $this->feedback($c, $body)->assertOk();

        $this->assertTrue($retry->json('data.replayed'));
        $this->assertSame('true', $retry->headers->get('Idempotent-Replayed'));
        $this->assertSame($first->json('data.event.id'), $retry->json('data.event.id'));
        $this->assertCount(1, $this->events($c));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'radar.feedback')->count());
        // Aunque el estado ya cambió (y la transición ya no sería válida desde ahí), el reintento exacto sigue devolviendo el resultado original.
        $this->feedback($c, ['state' => 'interesado', 'idempotency_key' => 'otra-clave-001'])->assertCreated();
        $this->feedback($c, $body)->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertCount(2, $this->events($c));
    }

    public function test_the_same_key_with_other_content_is_a_conflict_and_keys_are_per_opportunity(): void
    {
        $c = $this->company();
        $this->feedback($c, ['state' => 'contactado', 'idempotency_key' => 'clave-compartida'])->assertCreated();

        $this->feedback($c, ['state' => 'descartado', 'idempotency_key' => 'clave-compartida'])->assertStatus(409)->assertJsonPath('code', 'idempotency_key_reused');
        $this->feedback($c, ['state' => 'contactado', 'note' => 'otra nota', 'idempotency_key' => 'clave-compartida'])->assertStatus(409);
        $this->assertSame('contactado', DB::table('decision_recommendations')->where('id', $c['id'])->value('agenda_status'));
        // La misma clave en otra oportunidad es independiente (la clave del evento incluye la recomendación).
        $other = (int) DB::table('decision_recommendations')->where('tenant_id', $c['tenant']->id)->where('id', '<>', $c['id'])->value('id');
        $this->feedback($c, ['state' => 'contactado', 'idempotency_key' => 'clave-compartida'], $other)->assertCreated();
    }

    public function test_a_legacy_history_is_kept_complete_and_maps_to_the_workflow(): void
    {
        $c = $this->company();
        $steps = [['contactado', null], ['interesado', 'Pidió cotización'], ['pedido_recibido', 'Orden recibida']];
        foreach ($steps as $i => [$state, $note]) {
            $this->feedback($c, ['state' => $state, 'note' => $note, 'idempotency_key' => "historial-paso-{$i}"])->assertCreated();
        }

        $detail = $this->api($c)->getJson("/api/v1/radar/opportunities/{$c['id']}")->assertOk()->json('data');

        $history = $detail['history'];
        $this->assertSame(['pendiente', 'contactado', 'interesado'], array_column($history, 'from_agenda_status'));
        $this->assertSame(['contactado', 'interesado', 'pedido_recibido'], array_column($history, 'agenda_status'));
        $this->assertSame(['in_progress', 'in_progress', 'executed'], array_column($history, 'implied_workflow_status'));
        $this->assertSame([null, true, null], array_column($history, 'interest'), 'el interés queda en el evento');
        $this->assertSame([null, 'Pidió cotización', 'Orden recibida'], array_column($history, 'note'));
        // 0 pérdidas: cada evento enlaza con el anterior y el último estado es el vigente.
        $this->assertSame('pedido_recibido', $detail['state']);
        $this->assertSame([], $detail['allowed_feedback'], 'pedido recibido es un estado final');
        $chain = array_merge([$history[0]['from_agenda_status']], array_column($history, 'agenda_status'));
        foreach ($history as $i => $event) {
            $this->assertSame($chain[$i], $event['from_agenda_status']);
        }
        $this->assertSame('suggested', $detail['status'], 'la transición real del workflow es de W5-B');
        // Un estado final no admite más seguimiento.
        $this->feedback($c, ['state' => 'contactado', 'idempotency_key' => 'despues-de-final'])->assertStatus(422)->assertJsonPath('code', 'invalid_transition')
            ->assertJsonPath('allowed', []);
    }

    public function test_invalid_transitions_are_rejected_with_the_allowed_destinations(): void
    {
        $c = $this->company();

        $r = $this->feedback($c, ['state' => 'interesado', 'idempotency_key' => 'salto-invalido-1'])->assertStatus(422);

        $this->assertSame('invalid_transition', $r->json('code'));
        $this->assertSame(['contactado', 'pedido_recibido', 'pospuesto', 'descartado'], $r->json('allowed'));
        $this->assertSame('pendiente', $r->json('from'));
        $this->assertCount(0, $this->events($c), 'una transición inválida no deja evento');
        $this->assertNull(DB::table('decision_recommendations')->where('id', $c['id'])->value('agenda_status'));
    }

    public function test_postponing_requires_a_future_reactivation_date_and_hides_the_opportunity_from_the_agenda_until_then(): void
    {
        $c = $this->company();
        $this->travelTo('2026-10-20 12:00:00');   // la agenda exige corte ≤ fecha y la reactivación posterior a hoy: el reloj fijo hace la prueba independiente de cuándo se ejecute
        $today = CarbonImmutable::now('America/Guayaquil')->startOfDay();

        $this->feedback($c, ['state' => 'pospuesto', 'idempotency_key' => 'pospone-sin-fecha'])->assertStatus(422)->assertJsonValidationErrors('snoozed_until');
        $this->feedback($c, ['state' => 'pospuesto', 'snoozed_until' => $today->toDateString(), 'idempotency_key' => 'pospone-hoy-001'])->assertStatus(422)->assertJsonValidationErrors('snoozed_until');
        $this->feedback($c, ['state' => 'pospuesto', 'snoozed_until' => $today->addYears(2)->toDateString(), 'idempotency_key' => 'pospone-lejos-1'])->assertStatus(422);
        $this->feedback($c, ['state' => 'pospuesto', 'snoozed_until' => '2026-02-30', 'idempotency_key' => 'pospone-feb-30'])->assertStatus(422);
        $until = $today->addDays(10)->toDateString();
        $r = $this->feedback($c, ['state' => 'pospuesto', 'snoozed_until' => $until, 'idempotency_key' => 'pospone-valido-1'])->assertCreated();

        $this->assertSame($until, $r->json('data.event.snoozed_until'));
        $this->assertSame($until, $r->json('data.opportunity.follow_up.snoozed_until'));
        $this->assertNull($r->json('data.event.implied_workflow_status'), 'posponer no cambia el workflow');
        $agendaIds = fn (string $date) => collect($this->api($c)->getJson("/api/v1/radar/agenda?date={$date}&per_page=100")->json('data'))->pluck('opportunities')->flatten(1)->pluck('id')->all();
        $this->assertNotContains($c['id'], $agendaIds($today->addDays(9)->toDateString()), 'sigue pospuesta un día antes de la reactivación');
        $this->assertContains($c['id'], $agendaIds($until), 'reaparece en su fecha de reactivación');
        // Desde pospuesto se puede reactivar.
        $this->feedback($c, ['state' => 'pendiente', 'idempotency_key' => 'reactivar-manual'])->assertCreated()->assertJsonPath('data.opportunity.state', 'pendiente');
    }

    public function test_the_validation_of_the_request(): void
    {
        $c = $this->company();

        $this->feedback($c, ['idempotency_key' => 'sin-estado-001'])->assertStatus(422)->assertJsonValidationErrors('state');
        $this->feedback($c, ['state' => 'enviado', 'idempotency_key' => 'estado-inventado'])->assertStatus(422)->assertJsonValidationErrors('state');
        $this->feedback($c, ['state' => 'contactado'])->assertStatus(422)->assertJsonValidationErrors('idempotency_key');
        $this->feedback($c, ['state' => 'contactado', 'idempotency_key' => 'corta'])->assertStatus(422)->assertJsonValidationErrors('idempotency_key');
        $this->feedback($c, ['state' => 'contactado', 'idempotency_key' => 'con espacios y ñ'])->assertStatus(422)->assertJsonValidationErrors('idempotency_key');
        $this->feedback($c, ['state' => 'contactado', 'note' => str_repeat('x', 501), 'idempotency_key' => 'nota-demasiado-larga'])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->assertCount(0, $this->events($c));
    }

    public function test_the_free_text_note_is_masked_for_emails_and_identifiers(): void
    {
        $c = $this->company();

        $r = $this->feedback($c, ['state' => 'contactado', 'note' => 'Escribir a juan.perez@correo.com o llamar al 0991234567; RUC 1790012345001', 'idempotency_key' => 'nota-con-pii-001'])->assertCreated();

        $note = $r->json('data.event.note');
        $this->assertStringNotContainsString('juan.perez@correo.com', $note);
        $this->assertStringNotContainsString('0991234567', $note);
        $this->assertStringNotContainsString('1790012345001', $note);
        $this->assertStringContainsString('[ID]', $note);
        $this->assertSame($note, DB::table('decision_events')->where('recommendation_id', $c['id'])->value('reason'));
    }

    public function test_roles_viewer_cannot_give_feedback_and_the_commercial_roles_can(): void
    {
        $viewer = $this->company(TenantRole::Viewer);
        $this->feedback($viewer, ['state' => 'contactado', 'idempotency_key' => 'viewer-no-puede'])->assertForbidden()->assertJsonPath('required_ability', 'decisions.manage');
        $this->assertCount(0, $this->events($viewer));
        $this->assertNull(DB::table('decision_recommendations')->where('id', $viewer['id'])->value('agenda_status'));

        foreach ([TenantRole::Analyst, TenantRole::Admin, TenantRole::Owner] as $role) {
            $c = $this->company($role);
            $this->feedback($c, ['state' => 'contactado', 'idempotency_key' => 'rol-'.$role->value.'-0001'])->assertCreated();
        }
    }

    public function test_feedback_never_crosses_companies(): void
    {
        $a = $this->company();
        $b = $this->company(TenantRole::Owner);

        // El usuario de B no puede dar seguimiento a una oportunidad de A (ni con su propia cabecera ni con la de A, donde no es miembro).
        $this->feedback($b, ['state' => 'contactado', 'idempotency_key' => 'cruce-b-a-0001'], $a['id'])->assertNotFound();
        $this->actingAsTenantUser($b['user'], $a['tenant'])->postJson("/api/v1/radar/opportunities/{$a['id']}/feedback", ['state' => 'contactado', 'idempotency_key' => 'cruce-b-a-0002'])->assertNotFound();
        $this->assertCount(0, $this->events($a));
        $this->feedback($a, ['state' => 'contactado', 'idempotency_key' => 'propia-a-000001'])->assertCreated();
        $this->assertCount(0, $this->events($b));
    }

    public function test_the_control_group_cannot_receive_feedback(): void
    {
        $c = $this->company();
        DB::table('decision_recommendations')->where('id', $c['id'])->update(['experiment_group' => 'control']);

        $this->feedback($c, ['state' => 'contactado', 'idempotency_key' => 'control-no-se-toca'])->assertNotFound();
        $this->assertCount(0, $this->events($c));
    }

    public function test_feedback_is_refused_once_the_workflow_closed_the_opportunity(): void
    {
        $c = $this->company();
        foreach (['expired', 'rejected', 'measured', 'closed'] as $i => $status) {
            DB::table('decision_recommendations')->where('id', $c['id'])->update(['status' => $status]);
            $r = $this->feedback($c, ['state' => 'contactado', 'idempotency_key' => "workflow-cerrado-{$i}"])->assertStatus(422);
            $this->assertSame('opportunity_closed', $r->json('code'));
            $this->assertSame($status, $r->json('status'));
        }
        // Estados abiertos del workflow sí admiten seguimiento; desde «approved» contactar equivale a iniciar la acción (W5-B: transición `start` con las reglas de siempre).
        DB::table('decision_recommendations')->where('id', $c['id'])->update(['status' => 'approved']);
        $this->feedback($c, ['state' => 'contactado', 'idempotency_key' => 'workflow-abierto-1'])->assertCreated()
            ->assertJsonPath('data.opportunity.status', 'in_progress');
    }

    public function test_a_pending_assignment_maps_to_assigned_and_the_workflow_is_not_touched(): void
    {
        $c = $this->company();
        $this->assertSame('suggested', AgendaStatusMap::impliedWorkflowStatus('pendiente'));
        $this->assertSame('assigned', AgendaStatusMap::impliedWorkflowStatus('pendiente', assigned: true));
        DB::table('decision_recommendations')->where('id', $c['id'])->update(['agenda_status' => 'pospuesto', 'assigned_user_id' => $c['user']->id]);
        DB::table('decision_events')->insert(['tenant_id' => $c['tenant']->id, 'recommendation_id' => $c['id'], 'event' => 'feedback', 'agenda_status' => 'pospuesto', 'actor_kind' => 'user',
            'payload' => json_encode(['snoozed_until' => '2026-12-01']), 'idempotency_key' => str_repeat('a', 64)]);

        $r = $this->feedback($c, ['state' => 'pendiente', 'idempotency_key' => 'reabre-asignada-1'])->assertCreated();

        $this->assertSame('assigned', $r->json('data.event.implied_workflow_status'));
        $this->assertSame('suggested', DB::table('decision_recommendations')->where('id', $c['id'])->value('status'));
    }

    public function test_no_message_is_ever_sent_by_the_radar(): void
    {
        Mail::fake();
        Notification::fake();
        Http::fake();
        $c = $this->company();

        $this->feedback($c, ['state' => 'contactado', 'idempotency_key' => 'sin-envios-0001'])->assertCreated();
        $this->feedback($c, ['state' => 'interesado', 'idempotency_key' => 'sin-envios-0002'])->assertCreated();
        $this->api($c)->getJson('/api/v1/radar/agenda')->assertOk();
        $this->api($c)->postJson('/api/v1/radar/opportunities/materialize')->assertOk();

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_the_api_exposes_no_way_to_edit_or_delete_history_events(): void
    {
        $writes = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/radar/') || str_starts_with($r->uri(), 'api/v1/customers/'))
            ->flatMap(fn ($r) => array_map(fn ($m) => "{$m} {$r->uri()}", array_diff($r->methods(), ['GET', 'HEAD', 'OPTIONS'])))->values()->all();

        $this->assertEqualsCanonicalizing(['POST api/v1/radar/opportunities/materialize', 'POST api/v1/radar/opportunities/{id}/feedback'], $writes);
        $c = $this->company();
        $this->feedback($c, ['state' => 'contactado', 'idempotency_key' => 'inmutable-0001'])->assertCreated();
        $eventId = (int) DB::table('decision_events')->where('recommendation_id', $c['id'])->value('id');
        foreach (['putJson', 'patchJson', 'deleteJson'] as $verb) {
            $this->api($c)->{$verb}("/api/v1/radar/opportunities/{$c['id']}/feedback/{$eventId}")->assertStatus(404);
        }
        $this->assertSame(1, $this->withinTenant($c['tenant'], fn () => DecisionEvent::query()->where('recommendation_id', $c['id'])->where('event', 'feedback')->count()));
        $this->assertSame('contactado', $this->withinTenant($c['tenant'], fn () => DecisionRecommendation::query()->findOrFail($c['id'])->agenda_status));
    }
}
