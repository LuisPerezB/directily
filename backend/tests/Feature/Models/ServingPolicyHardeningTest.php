<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Enums\TenantRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Models\Support\ModelRegistryFixtures;
use Tests\TestCase;

/**
 * Endurecimiento de la política de servicio tras la revisión adversarial de W4-A: la validación humana de un legado candidato nunca lo vuelve
 * servible, «legado» es una definición POSITIVA, el contrato de publicación de la CLI, retirar una aprobación, todos los responsables del
 * entrenamiento y la promoción repetida de un mismo alcance.
 */
class ServingPolicyHardeningTest extends TestCase
{
    use ModelRegistryFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRegistry();
    }

    protected function tearDown(): void
    {
        $this->tearDownRegistry();
        parent::tearDown();
    }

    private function as(array $c, string $role): static
    {
        return $this->actingAsTenantUser($c[$role], $c['tenant']);
    }

    private function url(int $id, string $action = ''): string
    {
        return "/api/v1/models/{$id}".($action !== '' ? "/{$action}" : '');
    }

    private function rollback(array $c, string $role, int $id)
    {
        return $this->as($c, $role)->postJson('/api/v1/models/customer/rollback', ['model_registry_id' => $id]);
    }

    // ───────────────────────── HIGH: validar un legado candidato no lo vuelve servible ─────────────────────────

    public function test_an_analyst_cannot_validate_a_legacy_candidate_and_a_validated_one_is_still_not_servable(): void
    {
        $c = $this->registryCompany();
        $current = $this->customerModel($c['tenant'], 'production');
        $challenger = $this->customerModel($c['tenant'], 'candidate');          // retador que el motor dejó como candidate (corrida enlazada, sin published_*)

        // Reproducción de la revisión: un analista (forecast.run) validaba y luego revertía para servir el retador sin aprobación.
        $this->as($c, 'analyst')->postJson($this->url($challenger, 'validate'))->assertStatus(403)->assertJsonPath('code', 'forbidden')->assertJsonPath('required_ability', 'models.approve');
        $this->as($c, 'viewer')->postJson($this->url($challenger, 'validate'))->assertStatus(403)->assertJsonPath('required_ability', 'forecast.run');
        $this->assertSame('candidate', $this->statusOf($challenger));
        $this->rollback($c, 'analyst', $challenger)->assertStatus(409)->assertJsonPath('code', 'rollback_target_not_restorable');

        // Quien tiene models.approve sí puede validarlo… pero validar NO lo vuelve servible.
        $this->as($c, 'approver')->postJson($this->url($challenger, 'validate'))->assertOk()->assertJsonPath('data.status', 'validated')->assertJsonPath('data.serving_basis', 'not_approved');
        $events = $this->eventsOf($challenger);
        $this->assertArrayNotHasKey('legacy_policy', $events[0]['evidence']);
        $this->assertSame(['candidate', 'validated', 'not_approved'], [$events[1]['from'], $events[1]['to'], $events[1]['evidence']['serving_basis']]);
        foreach (['analyst', 'approver', 'owner'] as $role) {
            $this->rollback($c, $role, $challenger)->assertStatus(409)->assertJsonPath('code', 'rollback_target_not_approved')->assertJsonPath('context.basis', 'not_approved');
        }
        $this->assertSame(['validated', 'production'], [$this->statusOf($challenger), $this->statusOf($current)], 'nada cambió');
        $this->as($c, 'approver')->postJson($this->url($challenger, 'promote'))->assertStatus(409)->assertJsonPath('code', 'model_transition_not_allowed');

        // Solo la aprobación separada lo hace servible.
        $this->as($c, 'approver')->postJson($this->url($challenger, 'approve'))->assertOk()->assertJsonPath('data.serving_basis', 'approved');
        $this->as($c, 'approver')->postJson($this->url($challenger, 'promote'))->assertOk()->assertJsonPath('data.status', 'production');
        $this->assertSame('retired', $this->statusOf($current));
    }

    public function test_a_published_candidate_validated_by_an_analyst_is_not_servable_either(): void
    {
        $c = $this->registryCompany();
        $id = $this->publishedCandidate($c['tenant'], $c['publisher']);
        $this->as($c, 'analyst')->postJson($this->url($id, 'validate'))->assertOk();       // a un publicado sí le basta forecast.run
        $this->rollback($c, 'analyst', $id)->assertStatus(409)->assertJsonPath('code', 'rollback_target_not_approved');
        $this->rollback($c, 'owner', $id)->assertStatus(409)->assertJsonPath('code', 'rollback_target_not_approved');
        $this->assertSame('validated', $this->statusOf($id));
    }

    public function test_a_validation_event_written_by_a_person_makes_even_an_engine_validated_row_not_servable_until_approved(): void
    {
        $c = $this->registryCompany();
        $id = $this->customerModel($c['tenant'], 'validated');
        $this->rollback($c, 'analyst', $this->customerModel($c['tenant'], 'validated', [], true, 'h7:probability'))->assertOk();      // el validado del motor sí es legado
        DB::table('model_registry_events')->insert(['tenant_id' => $c['tenant']->id, 'model_registry_id' => $id, 'from_status' => 'candidate', 'to_status' => 'validated',
            'actor_user_id' => $c['analyst']->id, 'actor_kind' => 'user', 'evidence' => '{}']);
        $this->rollback($c, 'analyst', $id)->assertStatus(409)->assertJsonPath('code', 'rollback_target_not_approved');
        $this->as($c, 'approver')->postJson($this->url($id, 'approve'))->assertOk();
        $this->rollback($c, 'owner', $id)->assertOk();
    }

    // ───────────────────────── MEDIUM: legado = origen positivo; contrato de publicación ─────────────────────────

    public function test_a_model_without_a_positive_pipeline_origin_is_not_legacy_and_cannot_be_restored(): void
    {
        $c = $this->registryCompany();
        $current = $this->customerModel($c['tenant'], 'production');
        $orphan = $this->customerModel($c['tenant'], 'retired', ['core_run_id' => null]);                     // sin corrida, sin trabajo, sin marca
        $this->rollback($c, 'analyst', $orphan)->assertStatus(409)->assertJsonPath('code', 'rollback_target_not_approved')->assertJsonPath('context.basis', 'not_approved');
        $this->assertSame(['retired', 'production'], [$this->statusOf($orphan), $this->statusOf($current)]);
        // con la marca de línea base de la migración (corrida purgada) sí es legado
        DB::table('model_registry_events')->insert(['tenant_id' => $c['tenant']->id, 'model_registry_id' => $orphan, 'to_status' => 'retired', 'actor_kind' => 'system',
            'evidence' => json_encode(['legacy_policy' => 'legacy_approved_by_migration'])]);
        $this->rollback($c, 'analyst', $orphan)->assertOk();
    }

    /** Contrato que la CLI de W4-B debe cumplir al publicar (docs/ml/MODEL_REGISTRY.md §5.6): cualquier incumplimiento parcial exige aprobación igual. */
    public function test_the_cli_publication_contract_requires_approval_even_when_the_cli_forgets_some_marks(): void
    {
        $c = $this->registryCompany();
        $this->assertTrue(Schema::hasColumns('model_registry', ['published_by', 'published_env', 'published_at', 'published_object_key', 'approved_by', 'approved_at', 'rejected_reason']));
        $this->assertTrue(Schema::hasColumns('model_registry_events', ['from_status', 'to_status', 'actor_user_id', 'actor_kind', 'reason', 'evidence']));

        // (a) contrato completo: candidate + published_* + objeto firmado + evento de la CLI → solo llega a producción con validar, aprobar y promover
        $full = $this->publishedCandidate($c['tenant'], $c['publisher']);
        $row = DB::table('model_registry')->where('id', $full)->first();
        $this->assertSame('candidate', $row->status);
        $this->assertStringStartsWith('tenants/'.$c['tenant']->uuid.'/models/customer/', (string) $row->published_object_key);
        $this->assertNotNull($row->published_by);
        $this->assertNotNull($row->published_env);
        $this->assertNotNull($row->published_at);
        $this->as($c, 'approver')->postJson($this->url($full, 'promote'))->assertStatus(409);
        $this->as($c, 'analyst')->postJson($this->url($full, 'validate'))->assertOk();
        $this->as($c, 'approver')->postJson($this->url($full, 'promote'))->assertStatus(409)->assertJsonPath('code', 'model_transition_not_allowed');
        $this->as($c, 'approver')->postJson($this->url($full, 'approve'))->assertOk();
        $this->as($c, 'approver')->postJson($this->url($full, 'promote'))->assertOk()->assertJsonPath('data.status', 'production');

        // (b) la CLI olvidó TODAS las marcas published_* pero dejó su evento (actor_kind = cli): cuenta como publicado
        $eventOnly = $this->customerModel($c['tenant'], 'validated', [], false);
        DB::table('model_registry_events')->insert(['tenant_id' => $c['tenant']->id, 'model_registry_id' => $eventOnly, 'to_status' => 'candidate', 'actor_user_id' => $c['publisher']->id, 'actor_kind' => 'cli', 'evidence' => '{}']);
        $this->rollback($c, 'analyst', $eventOnly)->assertStatus(409)->assertJsonPath('code', 'rollback_target_not_approved');
        $this->as($c, 'approver')->getJson($this->url($eventOnly))->assertOk()->assertJsonPath('data.serving_basis', 'not_approved');
        // quien lo registró como candidato (evento de la CLI con actor) tampoco puede aprobarlo
        $this->as($c, 'publisher')->postJson($this->url($eventOnly, 'approve'))->assertStatus(403)->assertJsonPath('code', 'approval_segregation_of_duties');

        // (c) la CLI fijó solo UNA marca published_*: basta para exigir aprobación
        foreach ([['published_env' => 'staging'], ['published_at' => now()], ['published_by' => $c['publisher']->id]] as $mark) {
            $id = $this->customerModel($c['tenant'], 'retired', $mark, false);
            $this->rollback($c, 'analyst', $id)->assertStatus(409)->assertJsonPath('code', 'rollback_target_not_approved');
        }

        // (d) un publicado SIN published_object_key no puede aprobarse (no hay objeto que verificar)
        $noKey = $this->customerModel($c['tenant'], 'validated', ['published_by' => $c['publisher']->id, 'published_at' => now(), 'published_env' => 'staging'], false);
        $this->as($c, 'approver')->postJson($this->url($noKey, 'approve'))->assertStatus(409)->assertJsonPath('code', 'approval_artifact_not_verifiable');
    }

    // ───────────────────────── LOW 5: retirar una aprobación ─────────────────────────

    public function test_rejecting_an_approved_model_clears_the_approval_and_keeps_the_history_in_the_event(): void
    {
        $c = $this->registryCompany();
        $id = $this->publishedCandidate($c['tenant'], $c['publisher']);
        $decision = $this->promotionDecision($c['tenant'], $id, 'promote');
        DB::table('model_registry')->where('id', $id)->update(['promotion_decision_id' => $decision]);
        $this->as($c, 'analyst')->postJson($this->url($id, 'validate'))->assertOk();
        $this->as($c, 'approver')->postJson($this->url($id, 'approve'))->assertOk();
        $approvedAt = DB::table('model_registry')->where('id', $id)->value('approved_at');
        $this->assertSame($c['approver']->id, (int) DB::table('model_promotion_decisions')->where('id', $decision)->value('approved_by'));

        $this->as($c, 'owner')->postJson($this->url($id, 'reject'), ['reason' => 'Se detectó un sesgo posterior'])->assertOk()
            ->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.approved_by', null)->assertJsonPath('data.approved_at', null)->assertJsonPath('data.serving_basis', 'not_approved');
        $row = DB::table('model_registry')->where('id', $id)->first();
        $this->assertNull($row->approved_by);
        $this->assertNull($row->approved_at);
        $this->assertNull(DB::table('model_promotion_decisions')->where('id', $decision)->value('approved_by'), 'la decisión de política deja de figurar aprobada');
        $last = collect($this->eventsOf($id))->last();
        $this->assertTrue($last['evidence']['withdrew_approval']);
        $this->assertSame($c['approver']->id, $last['evidence']['previous_approval']['approved_by']);
        $this->assertNotNull($last['evidence']['previous_approval']['approved_at']);
        $this->assertTrue(Carbon::parse((string) $approvedAt)->equalTo(Carbon::parse($last['evidence']['previous_approval']['approved_at'])), 'el evento conserva cuándo se aprobó');
        $this->rollback($c, 'owner', $id)->assertStatus(409)->assertJsonPath('code', 'rollback_target_not_restorable');
    }

    // ───────────────────────── LOW 6: todos los responsables del entrenamiento ─────────────────────────

    public function test_every_training_job_linked_to_the_model_or_its_run_makes_its_user_responsible(): void
    {
        $c = $this->registryCompany();
        $second = $this->addUserToTenant($c['tenant'], TenantRole::Admin);
        $third = $this->addUserToTenant($c['tenant'], TenantRole::Admin);
        $fourth = $this->addUserToTenant($c['tenant'], TenantRole::Admin);
        $run = $this->trainedByRun($c['tenant'], $c['trainer']);                      // corrida + trabajo del modelo disparados por `trainer`
        // un reintento de la misma corrida disparado por otra persona
        DB::table('training_jobs')->insert(['tenant_id' => $c['tenant']->id, 'core_run_id' => $run['core_run_id'], 'job_type' => 'forecast_pipeline', 'status' => 'succeeded', 'progress' => 100,
            'attempts' => 1, 'triggered_by' => 'user', 'user_id' => $second->id, 'params' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        // un trabajo que apunta al modelo (training_jobs.model_registry_id) sin pertenecer a su corrida
        $other = $this->trainedByRun($c['tenant'], $third);
        $id = $this->customerModel($c['tenant'], 'validated', ['core_run_id' => $run['core_run_id'], 'training_job_id' => null]);
        DB::table('training_jobs')->where('id', $other['training_job_id'])->update(['core_run_id' => null, 'model_registry_id' => $id]);
        $this->gateReport($c['tenant'], $run['core_run_id'], 'PASS');

        foreach (['trainer' => $c['trainer'], 'second' => $second, 'third' => $third] as $who => $user) {
            $this->actingAsTenantUser($user, $c['tenant'])->postJson($this->url($id, 'approve'))->assertStatus(403)->assertJsonPath('code', 'approval_segregation_of_duties');
            $this->actingAsTenantUser($user, $c['tenant'])->postJson($this->url($id, 'reject'), ['reason' => "Intento de {$who}"])->assertStatus(403);
        }
        $this->assertSame('validated', $this->statusOf($id));
        $this->actingAsTenantUser($fourth, $c['tenant'])->postJson($this->url($id, 'approve'))->assertOk();
    }

    // ───────────────────────── LOW 7: una producción por alcance, también en promociones sucesivas ─────────────────────────

    public function test_successive_promotions_of_the_same_scope_always_leave_a_single_production_model(): void
    {
        $c = $this->registryCompany();
        $first = $this->publishedCandidate($c['tenant'], $c['publisher'], ['status' => 'approved', 'approved_by' => $c['approver']->id, 'approved_at' => now()]);
        $second = $this->publishedCandidate($c['tenant'], $c['publisher'], ['status' => 'approved', 'approved_by' => $c['approver']->id, 'approved_at' => now()]);
        $otherSlot = $this->publishedCandidate($c['tenant'], $c['publisher'], ['status' => 'approved', 'approved_by' => $c['approver']->id, 'approved_at' => now()], 'h7:probability');
        $production = fn (string $slot) => DB::table('model_registry')->where('tenant_id', $c['tenant']->id)->where('status', 'production')->whereRaw("hyperparameters->>'slot' = ?", [$slot])->pluck('id')->map(fn ($i) => (int) $i)->all();

        $this->as($c, 'owner')->postJson($this->url($first, 'promote'))->assertOk();
        $this->as($c, 'owner')->postJson($this->url($otherSlot, 'promote'))->assertOk();
        $this->assertSame([$first], $production('h30:probability'));
        $this->as($c, 'owner')->postJson($this->url($second, 'promote'))->assertOk()->assertJsonPath('data.retired_model_ids', [$first]);
        $this->assertSame([$second], $production('h30:probability'));
        $this->assertSame([$otherSlot], $production('h7:probability'));
        $this->assertSame('retired', $this->statusOf($first));
        // una segunda promoción del mismo modelo ya en producción no duplica nada
        $this->as($c, 'owner')->postJson($this->url($second, 'promote'))->assertStatus(409)->assertJsonPath('code', 'model_transition_not_allowed');
        $this->assertSame([$second], $production('h30:probability'));
        // serializa con corridas y otras reversiones bloqueando la fila de la empresa y las de los modelos (ver ModelApprovalService::promote)
        $source = (string) file_get_contents(base_path('app/Services/Models/ModelApprovalService.php'));
        $this->assertStringContainsString('Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()', $source);
    }

    // ───────────────────────── lista ─────────────────────────

    public function test_the_listing_reports_the_serving_basis_of_every_row_with_a_constant_number_of_queries(): void
    {
        $c = $this->registryCompany();
        $legacy = $this->customerModel($c['tenant'], 'production');
        $human = $this->customerModel($c['tenant'], 'validated');
        DB::table('model_registry_events')->insert(['tenant_id' => $c['tenant']->id, 'model_registry_id' => $human, 'from_status' => 'candidate', 'to_status' => 'validated',
            'actor_user_id' => $c['analyst']->id, 'actor_kind' => 'user', 'evidence' => '{}']);
        $published = $this->publishedCandidate($c['tenant'], $c['publisher']);
        $approved = $this->customerModel($c['tenant'], 'approved', ['approved_at' => now(), 'approved_by' => $c['approver']->id]);

        $rows = collect($this->as($c, 'viewer')->getJson('/api/v1/models')->assertOk()->json('data'))->pluck('serving_basis', 'id');
        $this->assertSame(['legacy_pipeline', 'not_approved', 'not_approved', 'approved'], [$rows[$legacy], $rows[$human], $rows[$published], $rows[$approved]]);

        $count = function () use ($c): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->as($c, 'viewer')->getJson('/api/v1/models')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $small = $count();
        for ($i = 0; $i < 12; $i++) {
            $this->customerModel($c['tenant'], 'validated');
        }
        $this->assertSame($small, $count(), 'las consultas no crecen con el número de filas');
    }
}
