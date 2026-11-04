<?php

declare(strict_types=1);

namespace Tests\Feature\Decision;

use App\Contracts\Decision\RecommendationSink;
use App\Models\DecisionEvent;
use App\Models\DecisionRecommendation;
use App\Models\User;
use App\Services\Decision\ExperimentAssigner;
use App\Services\Decision\ExperimentRedaction;
use App\Services\Decision\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Decision\Support\DecisionFixtures;
use Tests\TestCase;

/**
 * V2.5 · W5-B (revisión conjunta de W5-A y W5-B): control que no delata su pertenencia (M1, M2), repetición idempotente autorizada y atada al cuerpo (L1), versión
 * obligatoria en toda transición humana (L2), reordenamiento de prioridades que solo toca lo accionable (L3), restricciones como objetos (L5) y auditoría de la
 * configuración con valores anterior y nuevo sin la semilla (L9).
 */
final class DecisionReviewFixesTest extends TestCase
{
    use DecisionFixtures;
    use RefreshDatabase;

    private function sink(): RecommendationSink
    {
        return app(RecommendationSink::class);
    }

    private function propose(array $w, array $override = []): DecisionRecommendation
    {
        return $this->withinTenant($w['tenant'], fn () => $this->sink()->propose($this->contactProposal($w, $override)));
    }

    private function version(User $user, array $w, int $id): int
    {
        return (int) $this->actingAsTenantUser($user, $w['tenant'])->getJson("/api/v1/decisions/{$id}")->assertOk()->json('data.version');
    }

    /** @param array<string, mixed> $body */
    private function transit(User $user, array $w, int $id, array $body): TestResponse
    {
        return $this->actingAsTenantUser($user, $w['tenant'])->postJson("/api/v1/decisions/{$id}/transition", $body);
    }

    /** Experimento 50 % con una semilla que deja a c1 en CONTROL y a c2 en TRATAMIENTO. @return array{control: DecisionRecommendation, treatment: DecisionRecommendation, key: string} */
    private function experiment(array $w): array
    {
        $key = 'contacto-m2-secreto';
        $c1 = ExperimentAssigner::unitKey($w['cat']['customers']['c1']);
        $c2 = ExperimentAssigner::unitKey($w['cat']['customers']['c2']);
        $seed = collect(range(1, 500))->first(fn (int $i) => ExperimentAssigner::uniform("m{$i}", $key, $c1) < 0.5 && ExperimentAssigner::uniform("m{$i}", $key, $c2) >= 0.5);
        $this->assertNotNull($seed);
        $w['tenant']->forceFill(['settings' => array_merge($w['tenant']->effectiveSettings(), ['decision' => ['control_fraction' => 0.5, 'experiment_key' => $key, 'experiment_seed' => "m{$seed}"]])])->save();
        $control = $this->propose($w);
        $treatment = $this->propose($w, ['customer_id' => $w['cat']['customers']['c2']]);
        $this->assertSame(['control', 'treatment'], [$control->experiment_group, $treatment->experiment_group]);

        return ['control' => $control, 'treatment' => $treatment, 'key' => $key];
    }

    // ───────────────────────── M1: el grupo de control no se delata por ningún endpoint ─────────────────────────

    public function test_a_control_unit_is_a_404_on_every_endpoint_for_non_approvers_and_leaks_nothing_about_its_status_or_version(): void
    {
        $w = $this->decisionWorld();
        $e = $this->experiment($w);
        $id = $e['control']->id;
        $missing = $this->transit($w['analyst'], $w, 987654, ['to_status' => 'assigned', 'idempotency_key' => 'm1-missing-0001', 'assignee_user_id' => $w['analyst']->id, 'expected_version' => 1]);
        $missing->assertStatus(404);

        $bodies = [
            'versión válida' => ['to_status' => 'assigned', 'idempotency_key' => 'm1-valid-00001', 'assignee_user_id' => $w['analyst']->id, 'expected_version' => 1],
            'versión desfasada' => ['to_status' => 'assigned', 'idempotency_key' => 'm1-stale-00001', 'assignee_user_id' => $w['analyst']->id, 'expected_version' => 99999],
            'destino inválido' => ['to_status' => 'closed', 'idempotency_key' => 'm1-badto-00001', 'expected_version' => 1],
            'descartar' => ['to_status' => 'rejected', 'idempotency_key' => 'm1-dismiss-0001', 'reason' => 'Motivo suficiente', 'expected_version' => 1],
        ];
        foreach ($bodies as $label => $body) {
            $r = $this->transit($w['analyst'], $w, $id, $body);
            $r->assertStatus(404);
            $this->assertSame($missing->json(), $r->json(), "{$label}: la respuesta es idéntica a la de un id que no existe (no delata el grupo, el estado ni la versión)");
        }
        $this->actingAsTenantUser($w['analyst'], $w['tenant'])->getJson("/api/v1/decisions/{$id}")->assertStatus(404);
        $this->assertSame(1, DecisionEvent::withoutTenantScope()->where('recommendation_id', $id)->count(), 'ni un intento deja eventos');
        // Quien aprueba sí sabe que es de control y por qué no se actúa.
        $this->transit($w['admin'], $w, $id, $bodies['versión válida'])->assertStatus(409)->assertJsonPath('code', 'control_group_not_actionable');
        // La de tratamiento no cambia para el analista.
        $this->transit($w['analyst'], $w, $e['treatment']->id, ['to_status' => 'assigned', 'idempotency_key' => 'm1-treat-00001', 'assignee_user_id' => $w['analyst']->id, 'expected_version' => $this->version($w['analyst'], $w, $e['treatment']->id)])->assertOk();
    }

    // ───────────────────────── M2: el grupo y la clave del experimento solo los ve quien aprueba ─────────────────────────

    public function test_the_proposal_event_stores_the_experiment_key_and_group(): void
    {
        $w = $this->decisionWorld();
        $e = $this->experiment($w);

        foreach (['control', 'treatment'] as $group) {
            $event = DecisionEvent::withoutTenantScope()->where('recommendation_id', $e[$group]->id)->where('to_status', 'suggested')->firstOrFail();
            $this->assertEquals(['experiment_key' => $e['key'], 'group' => $group], $event->payload['experiment'], 'el evento inicial conserva la asignación: la medición de outcomes la necesita');
            $this->assertSame($group, $e[$group]->experiment_group);
        }
        // Sin experimento (fracción 0) el evento declara que no lo hay.
        $none = $this->decisionWorld();
        $rec = $this->propose($none);
        $this->assertNull(DecisionEvent::withoutTenantScope()->where('recommendation_id', $rec->id)->firstOrFail()->payload['experiment'] ?? null);
    }

    public function test_an_analyst_never_receives_the_experiment_key_group_or_unit_in_any_response_but_an_approver_does(): void
    {
        $w = $this->decisionWorld();
        $e = $this->experiment($w);
        $treatment = $e['treatment']->id;
        $leaks = ['experiment', $e['key'], 'unit_key', '"treatment"', '"control"', 'customer:'.$w['cat']['customers']['c2']];
        $assertClean = function (TestResponse $r, string $where) use ($leaks): void {
            $body = (string) $r->getContent();
            foreach ($leaks as $needle) {
                $this->assertStringNotContainsString($needle, $body, "{$where}: no debe contener «{$needle}»");
            }
        };

        $api = $this->actingAsTenantUser($w['analyst'], $w['tenant']);
        $assertClean($api->getJson("/api/v1/decisions/{$treatment}")->assertOk(), 'GET /decisions/{id}');
        $this->assertSame(['transition'], collect($api->getJson("/api/v1/decisions/{$treatment}")->json('data.events'))->pluck('event')->unique()->values()->all());
        $assertClean($api->getJson('/api/v1/decisions?per_page=100')->assertOk(), 'GET /decisions');
        $assertClean($api->getJson("/api/v1/radar/opportunities/{$treatment}")->assertOk(), 'GET /radar/opportunities/{id} (detalle e historial)');
        $assertClean($api->getJson('/api/v1/radar/opportunities?per_page=100')->assertOk(), 'GET /radar/opportunities');
        $assertClean($api->getJson('/api/v1/radar/agenda?per_page=100')->assertOk(), 'GET /radar/agenda');
        $assertClean($api->getJson('/api/v1/radar/summary')->assertOk(), 'GET /radar/summary');
        $assertClean($api->getJson('/api/v1/customers/'.$w['cat']['customers']['c2'].'/overview')->assertOk(), 'ficha del cliente');
        $this->transit($w['analyst'], $w, $treatment, ['to_status' => 'assigned', 'idempotency_key' => 'm2-assign-00001', 'assignee_user_id' => $w['analyst']->id, 'expected_version' => $this->version($w['analyst'], $w, $treatment)]);
        $assertClean($api->postJson("/api/v1/radar/opportunities/{$treatment}/feedback", ['state' => 'contactado', 'idempotency_key' => 'm2-feedback-0001'])->assertCreated(), 'POST feedback');

        // La ficha del cliente de control no lista su recomendación de control ni a quien aprueba (el Radar la reserva para la medición).
        foreach ([$w['analyst'], $w['admin']] as $user) {
            $ficha = $this->actingAsTenantUser($user, $w['tenant'])->getJson('/api/v1/customers/'.$w['cat']['customers']['c1'].'/overview')->assertOk();
            $this->assertSame([], $ficha->json('data.follow_up.opportunities'), 'la ficha no lista oportunidades de control');
        }

        // Quien aprueba sí lo ve, con su clave y su grupo.
        $admin = $this->actingAsTenantUser($w['admin'], $w['tenant']);
        $detail = $admin->getJson("/api/v1/decisions/{$treatment}")->assertOk();
        $this->assertSame('treatment', $detail->json('data.experiment_group'));
        $this->assertEquals(['experiment_key' => $e['key'], 'group' => 'treatment'], $detail->json('data.events.0.payload.experiment'));
        $this->assertSame('treatment', $admin->getJson("/api/v1/radar/opportunities/{$treatment}")->json('data.experiment_group'));
    }

    public function test_the_redaction_removes_the_experiment_keys_at_any_depth(): void
    {
        $payload = ['action' => 'propose', 'experiment' => ['experiment_key' => 'x', 'group' => 'control'], 'nested' => ['experiment_group' => 'treatment', 'unit_key' => 'customer:1', 'ok' => 1], 'ok' => true];

        $this->assertSame(['action' => 'propose', 'nested' => ['ok' => 1], 'ok' => true], ExperimentRedaction::strip($payload));
    }

    // ───────────────────────── L1: la repetición se autoriza y debe ser la MISMA petición ─────────────────────────

    public function test_a_replay_is_authorized_first_and_must_carry_the_same_body_and_user(): void
    {
        $w = $this->decisionWorld();
        $id = $this->propose($w, ['kind' => 'replenishment', 'entity_type' => 'product_branch', 'customer_id' => null, 'branch_id' => $w['cat']['branch'], 'horizon_days' => 14])->id;
        $this->transit($w['owner'], $w, $id, ['to_status' => 'assigned', 'idempotency_key' => 'l1-assign-00001', 'assignee_user_id' => $w['analyst']->id, 'expected_version' => $this->version($w['owner'], $w, $id)])->assertOk();
        $this->transit($w['analyst'], $w, $id, ['to_status' => 'reviewed', 'idempotency_key' => 'l1-review-00001', 'expected_version' => $this->version($w['analyst'], $w, $id)])->assertOk();
        $v = $this->version($w['owner'], $w, $id);
        $approve = ['to_status' => 'approved', 'idempotency_key' => 'l1-approve-0001', 'expected_version' => $v];
        $this->transit($w['owner'], $w, $id, $approve)->assertOk();

        // Misma petición, mismo usuario: repetición.
        $this->transit($w['owner'], $w, $id, $approve)->assertOk()->assertJsonPath('meta.idempotent_replay', true);
        // Un usuario sin la habilidad con la que se hizo NO recibe el resultado (se autoriza antes de devolver nada).
        $this->transit($w['analyst'], $w, $id, $approve)->assertStatus(403)->assertJsonPath('required_ability', 'decisions.approve');
        // La misma clave con otro cuerpo (motivo, versión, asignado) o desde otro usuario es otra petición: 409, no la repetición.
        $this->transit($w['owner'], $w, $id, $approve + ['reason' => 'Ahora con motivo'])->assertStatus(409)->assertJsonPath('code', 'idempotency_key_reused');
        $this->transit($w['owner'], $w, $id, ['expected_version' => $v + 1] + $approve)->assertStatus(409)->assertJsonPath('code', 'idempotency_key_reused');
        $this->transit($w['admin'], $w, $id, $approve)->assertStatus(409)->assertJsonPath('code', 'idempotency_key_reused');
        $this->assertSame('approved', DecisionRecommendation::withoutTenantScope()->findOrFail($id)->status);
        $this->assertSame(1, DecisionEvent::withoutTenantScope()->where('recommendation_id', $id)->where('to_status', 'approved')->count());
    }

    // ───────────────────────── L2: la versión es obligatoria en TODA transición humana ─────────────────────────

    /** @return array<string, array{0: string, 1: string, 2: string}> estado de partida, destino y texto */
    public static function humanTransitions(): array
    {
        return [
            'asignar' => ['suggested', 'assigned', 'assign'], 'reasignar' => ['assigned', 'assigned', 'reassign'], 'revisar' => ['assigned', 'reviewed', 'review'],
            'aprobar' => ['reviewed', 'approved', 'approve'], 'rechazar' => ['reviewed', 'rejected', 'reject'], 'descartar' => ['suggested', 'rejected', 'dismiss'],
            'iniciar sin aprobación' => ['reviewed', 'in_progress', 'start_without_approval'], 'iniciar' => ['approved', 'in_progress', 'start'],
            'ejecutar' => ['in_progress', 'executed', 'execute'], 'cerrar' => ['measured', 'closed', 'close'],
        ];
    }

    #[DataProvider('humanTransitions')]
    public function test_every_human_transition_requires_the_expected_version(string $from, string $to, string $action): void
    {
        $w = $this->decisionWorld();
        $id = $this->propose($w)->id;
        DB::table('decision_recommendations')->where('id', $id)->update(['status' => $from, 'assigned_user_id' => $w['analyst']->id]);
        $body = ['to_status' => $to, 'idempotency_key' => "l2-{$action}-0001", 'assignee_user_id' => $w['analyst2']->id, 'reason' => 'Motivo suficiente'];

        $this->transit($w['owner'], $w, $id, $body)->assertStatus(422)->assertJsonValidationErrors('expected_version');
        $this->assertSame($from, DecisionRecommendation::withoutTenantScope()->findOrFail($id)->status, "{$action} sin versión no cambia nada");
        $stale = $this->transit($w['owner'], $w, $id, $body + ['expected_version' => 2000000000]);
        $this->assertSame(409, $stale->status());
        $this->assertSame('decision_version_conflict', $stale->json('code'));
        // Con la versión vigente ya no es un error de versión: la regla de la tabla y la segregación son las que mandan.
        $ok = $this->transit($w['owner'], $w, $id, ['idempotency_key' => "l2-{$action}-0002"] + $body + ['expected_version' => $this->version($w['owner'], $w, $id)]);
        $this->assertNotContains($ok->json('code'), ['decision_version_conflict']);
        $this->assertArrayNotHasKey('expected_version', (array) $ok->json('errors'));
    }

    public function test_a_second_assignment_cannot_silently_overwrite_what_another_person_did(): void
    {
        $w = $this->decisionWorld();
        $id = $this->propose($w)->id;
        $this->transit($w['owner'], $w, $id, ['to_status' => 'assigned', 'idempotency_key' => 'l2-first-assign1', 'assignee_user_id' => $w['analyst']->id, 'expected_version' => $this->version($w['owner'], $w, $id)])->assertOk();
        $seenByAdmin = $this->version($w['admin'], $w, $id);
        // Otra persona reasigna entre la lectura del administrador y su acción.
        $this->transit($w['owner'], $w, $id, ['to_status' => 'assigned', 'idempotency_key' => 'l2-second-assign', 'assignee_user_id' => $w['analyst2']->id, 'expected_version' => $seenByAdmin])->assertOk();

        $this->transit($w['admin'], $w, $id, ['to_status' => 'assigned', 'idempotency_key' => 'l2-third-assign1', 'assignee_user_id' => $w['analyst']->id, 'expected_version' => $seenByAdmin])
            ->assertStatus(409)->assertJsonPath('code', 'decision_version_conflict');
        $this->assertSame($w['analyst2']->id, DecisionRecommendation::withoutTenantScope()->findOrFail($id)->assigned_user_id, 'la reasignación de la otra persona no se pisó');
    }

    // ───────────────────────── L3: reordenar prioridades solo toca lo accionable ─────────────────────────

    public function test_reordering_priorities_never_touches_in_flight_control_or_closed_rows(): void
    {
        $w = $this->decisionWorld();
        $old = '2020-01-01 00:00:00';
        $inFlight = $this->propose($w, ['customer_id' => $w['cat']['customers']['c1'], 'expected_units' => 3.0])->id;
        $control = $this->propose($w, ['customer_id' => $w['cat']['customers']['c2'], 'expected_units' => 2.0])->id;
        $closed = $this->propose($w, ['customer_id' => $w['cat']['customers']['c1'], 'product_id' => $w['cat']['products']['p2'], 'expected_units' => 1.0])->id;
        DB::table('decision_recommendations')->where('id', $inFlight)->update(['status' => 'assigned', 'priority' => 4, 'updated_at' => $old]);
        DB::table('decision_recommendations')->where('id', $control)->update(['experiment_group' => 'control', 'priority' => 4, 'updated_at' => $old]);
        DB::table('decision_recommendations')->where('id', $closed)->update(['status' => 'rejected', 'priority' => 4, 'updated_at' => $old]);
        $events = DecisionEvent::withoutTenantScope()->count();

        // Una propuesta mejor del mismo grupo (tipo, entidad, corte, horizonte) reordena SOLO lo accionable.
        $better = $this->propose($w, ['customer_id' => $w['cat']['customers']['c2'], 'product_id' => $w['cat']['products']['p2'], 'expected_units' => 50.0]);

        $this->assertSame(1, $better->priority, 'es la única accionable del grupo');
        foreach ([$inFlight, $control, $closed] as $id) {
            $row = DB::table('decision_recommendations')->where('id', $id)->first();
            $this->assertSame(4, (int) $row->priority, 'conserva su prioridad');
            $this->assertSame($old, substr((string) $row->updated_at, 0, 19), 'reordenar no toca updated_at del trabajo en curso');
        }
        $this->assertSame($events + 1, DecisionEvent::withoutTenantScope()->count(), 'reordenar no genera eventos (solo el evento inicial de la nueva)');
    }

    // ───────────────────────── L5: las restricciones son objetos ─────────────────────────

    public function test_constraints_are_always_objects_with_a_code(): void
    {
        $w = $this->decisionWorld();

        $rec = $this->propose($w, ['constraints' => ['Texto suelto de una fachada', ['code' => 'moq', 'value' => 50]]]);

        $this->assertSame([['code' => 'note', 'note' => 'Texto suelto de una fachada'], ['code' => 'moq', 'value' => 50]], $rec->constraints);
        foreach ($this->actingAsTenantUser($w['viewer'], $w['tenant'])->getJson("/api/v1/decisions/{$rec->id}")->json('data.constraints') as $c) {
            $this->assertIsArray($c);
            $this->assertArrayHasKey('code', $c);
        }
    }

    // ───────────────────────── L9: la auditoría de la configuración dice qué cambió ─────────────────────────

    public function test_the_settings_audit_records_old_and_new_values_and_never_the_seed(): void
    {
        $w = $this->decisionWorld();
        $api = $this->actingAsTenantUser($w['admin'], $w['tenant']);

        $api->putJson('/api/v1/decisions/settings', ['approval_required_kinds' => ['replenishment'], 'approval_cost_threshold' => 250.5, 'contact_cost' => 2, 'control_fraction' => 0.2, 'experiment_seed' => 'semilla-secreta-l9'])->assertOk();
        $api->putJson('/api/v1/decisions/settings', ['approval_cost_threshold' => 400, 'contact_cost' => 2])->assertOk();   // contact_cost no cambia

        $rows = DB::table('audit_logs')->where('tenant_id', $w['tenant']->id)->where('action', 'decision.settings_updated')->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $first = json_decode($rows[0]->metadata, true)['changes'];
        $this->assertEquals(['old' => ['replenishment', 'inventory_mismatch', 'budget_constraint', 'scenario'], 'new' => ['replenishment']], $first['approval_required_kinds']);
        $this->assertEquals(['old' => null, 'new' => 250.5], $first['approval_cost_threshold']);
        $this->assertEquals(['old' => 0, 'new' => 0.2], $first['control_fraction']);
        $this->assertSame(['changed' => true], $first['experiment_seed']);
        $second = json_decode($rows[1]->metadata, true)['changes'];
        $this->assertEquals(['old' => 250.5, 'new' => 400], $second['approval_cost_threshold']);
        $this->assertArrayNotHasKey('contact_cost', $second, 'lo que no cambió no se registra como cambio');
        foreach ($rows as $row) {
            $this->assertStringNotContainsString('semilla-secreta-l9', (string) $row->metadata);
        }
        // Una semilla generada por el servidor tampoco se registra (solo que se generó).
        $other = $this->decisionWorld();
        $this->actingAsTenantUser($other['admin'], $other['tenant'])->putJson('/api/v1/decisions/settings', ['control_fraction' => 0.3])->assertOk();
        $generated = json_decode((string) DB::table('audit_logs')->where('tenant_id', $other['tenant']->id)->where('action', 'decision.settings_updated')->value('metadata'), true)['changes'];
        $this->assertSame(['generated' => true], $generated['experiment_seed']);
        $this->assertStringNotContainsString((string) $other['tenant']->refresh()->settings['decision']['experiment_seed'], (string) DB::table('audit_logs')->where('tenant_id', $other['tenant']->id)->value('metadata'));
    }

    // ───────────────────────── el refresco no cambia la versión ─────────────────────────

    public function test_the_version_ignores_system_comments(): void
    {
        $w = $this->decisionWorld();
        $id = $this->propose($w)->id;
        $v = $this->version($w['analyst'], $w, $id);
        $this->withinTenant($w['tenant'], fn () => DecisionEvent::query()->create([
            'recommendation_id' => $id, 'event' => 'comment', 'from_status' => 'suggested', 'to_status' => 'suggested', 'actor_kind' => 'system',
            'payload' => ['kind' => 'refresh'], 'idempotency_key' => hash('sha256', 'v-comment'),
        ]));

        $this->assertSame($v, $this->version($w['analyst'], $w, $id));
        $this->withinTenant($w['tenant'], fn () => $this->assertSame($v, app(Workflow::class)->versionOf($id)));
    }
}
