<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Models\Support\ModelRegistryFixtures;
use Tests\TestCase;

/**
 * Aprobación separada (ADR-V25-07; V25-REG-*): habilidad models.approve, SEGREGACIÓN DE FUNCIONES con usuarios reales de la empresa (aprobador ≠
 * publicador / quien disparó el entrenamiento), firma verificada, gate de datos ≠ BLOCK y decisión de promoción de W3 completada.
 */
class ModelApprovalApiTest extends TestCase
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

    /** Autentica de nuevo al usuario (los helpers que validan modelos actúan como analista y cambiarían al usuario activo). */
    private function as(array $c, string $role): static
    {
        return $this->actingAsTenantUser($c[$role], $c['tenant']);
    }

    private function url(int $id, string $action = ''): string
    {
        return "/api/v1/models/{$id}".($action !== '' ? "/{$action}" : '');
    }

    /** Un candidato publicado por `publisher`, ya validado por el analista. */
    private function validatedModel(array $c, array $over = [], string $slot = 'h30:probability'): int
    {
        $id = $this->publishedCandidate($c['tenant'], $c['publisher'], $over, $slot);
        $this->actingAsTenantUser($c['analyst'], $c['tenant'])->postJson($this->url($id, 'validate'))->assertOk()->assertJsonPath('data.status', 'validated');

        return $id;
    }

    // ───────────────────────── habilidad ─────────────────────────

    public function test_models_approve_belongs_to_owner_and_admin_only(): void
    {
        $c = $this->registryCompany();
        $id = $this->validatedModel($c);
        foreach (['analyst', 'viewer'] as $role) {
            foreach (['approve', 'promote'] as $action) {
                $this->actingAsTenantUser($c[$role], $c['tenant'])->postJson($this->url($id, $action))
                    ->assertStatus(403)->assertJsonPath('code', 'forbidden')->assertJsonPath('required_ability', 'models.approve');
            }
            $this->actingAsTenantUser($c[$role], $c['tenant'])->postJson($this->url($id, 'reject'), ['reason' => 'No cumple los umbrales'])
                ->assertStatus(403)->assertJsonPath('required_ability', 'models.approve');
        }
        $this->assertSame('validated', $this->statusOf($id), 'sin la habilidad nada cambia');
        // El propietario y el administrador la tienen.
        $this->actingAsTenantUser($c['owner'], $c['tenant'])->postJson($this->url($id, 'approve'))->assertOk();
    }

    public function test_validate_needs_forecast_run_and_a_viewer_cannot_do_it(): void
    {
        $c = $this->registryCompany();
        $id = $this->publishedCandidate($c['tenant'], $c['publisher']);
        $this->actingAsTenantUser($c['viewer'], $c['tenant'])->postJson($this->url($id, 'validate'))->assertStatus(403)->assertJsonPath('required_ability', 'forecast.run');
        $this->actingAsTenantUser($c['analyst'], $c['tenant'])->postJson($this->url($id, 'validate'))->assertOk()->assertJsonPath('data.status', 'validated');
        $this->actingAsTenantUser($c['analyst'], $c['tenant'])->postJson($this->url($id, 'validate'))
            ->assertStatus(409)->assertJsonPath('code', 'model_transition_not_allowed');
    }

    // ───────────────────────── segregación de funciones ─────────────────────────

    public function test_the_publisher_cannot_approve_or_reject_their_own_model_but_another_admin_can(): void
    {
        $c = $this->registryCompany();
        $id = $this->validatedModel($c);

        $r = $this->actingAsTenantUser($c['publisher'], $c['tenant'])->postJson($this->url($id, 'approve'))
            ->assertStatus(403)->assertJsonPath('code', 'approval_segregation_of_duties');
        $this->assertEqualsCanonicalizing(['published_by', 'registered_by'], $r->json('context.roles'), 'publicó y lo registró como candidato (evento de la CLI)');
        $this->actingAsTenantUser($c['publisher'], $c['tenant'])->postJson($this->url($id, 'reject'), ['reason' => 'Lo retiro yo mismo'])
            ->assertStatus(403)->assertJsonPath('code', 'approval_segregation_of_duties');
        $this->assertSame('validated', $this->statusOf($id));
        $this->assertNull(DB::table('model_registry')->where('id', $id)->value('approved_by'));

        $this->actingAsTenantUser($c['approver'], $c['tenant'])->postJson($this->url($id, 'approve'), ['reason' => 'Revisadas las métricas del holdout'])
            ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.approved_by', $c['approver']->id)->assertJsonPath('data.serving_basis', 'approved');
        $this->assertNotNull(DB::table('model_registry')->where('id', $id)->value('approved_at'));
    }

    public function test_whoever_triggered_the_training_cannot_approve_it_either(): void
    {
        $c = $this->registryCompany();
        $run = $this->trainedByRun($c['tenant'], $c['trainer']);
        $this->gateReport($c['tenant'], $run['core_run_id'], 'PASS');
        // entrenado por `trainer`, publicado por `publisher`: ninguno de los dos puede aprobar
        $id = $this->validatedModel($c, ['core_run_id' => $run['core_run_id'], 'training_job_id' => $run['training_job_id'], 'artifact_compat' => json_encode(['feature_schema_version' => 'cust-fs-2026.10-v1'])]);

        $r = $this->actingAsTenantUser($c['trainer'], $c['tenant'])->postJson($this->url($id, 'approve'))->assertStatus(403)->assertJsonPath('code', 'approval_segregation_of_duties');
        $this->assertContains('training_job.user_id', $r->json('context.roles'));
        $this->assertContains('core_run.user_id', $r->json('context.roles'));
        $this->actingAsTenantUser($c['trainer'], $c['tenant'])->postJson($this->url($id, 'reject'), ['reason' => 'Mi entrenamiento no sirvió'])->assertStatus(403);
        $this->actingAsTenantUser($c['publisher'], $c['tenant'])->postJson($this->url($id, 'approve'))->assertStatus(403);
        $this->assertSame('validated', $this->statusOf($id));

        // Una tercera persona con la habilidad sí puede; la política de la ejecución (gate PASS) es la evidencia.
        $this->actingAsTenantUser($c['approver'], $c['tenant'])->postJson($this->url($id, 'approve'))->assertOk();
        $event = collect($this->eventsOf($id))->last();
        $this->assertSame('PASS', $event['evidence']['gates']['data_gate']['outcome']);
        $this->assertSame('run_report', $event['evidence']['gates']['data_gate']['source']);
    }

    public function test_a_scheduled_training_without_a_human_trigger_has_no_responsible_user(): void
    {
        $c = $this->registryCompany();
        $run = $this->trainedByRun($c['tenant'], null);
        $this->gateReport($c['tenant'], $run['core_run_id'], 'WARN');
        $id = $this->customerModel($c['tenant'], 'validated', ['core_run_id' => $run['core_run_id'], 'training_job_id' => $run['training_job_id']]);
        // un modelo del pipeline (legado) que un administrador decide aprobar explícitamente: no hay responsable humano que lo impida
        $this->actingAsTenantUser($c['publisher'], $c['tenant'])->postJson($this->url($id, 'approve'))->assertOk()->assertJsonPath('data.serving_basis', 'approved');
    }

    public function test_the_approver_must_be_a_user_of_the_company_and_other_companies_cannot_decide(): void
    {
        $c = $this->registryCompany();
        $other = $this->registryCompany();
        $id = $this->validatedModel($c);
        // El administrador de OTRA empresa (con models.approve en la suya) no ve el modelo: 404 y nada cambia.
        foreach (['approve', 'promote'] as $action) {
            $this->actingAsTenantUser($other['approver'], $other['tenant'])->postJson($this->url($id, $action))->assertStatus(404);
        }
        $this->actingAsTenantUser($other['approver'], $other['tenant'])->postJson($this->url($id, 'reject'), ['reason' => 'No es de mi empresa'])->assertStatus(404);
        $this->actingAsTenantUser($other['analyst'], $other['tenant'])->postJson($this->url($id, 'validate'))->assertStatus(404);
        $this->actingAsTenantUser($other['viewer'], $other['tenant'])->getJson($this->url($id))->assertStatus(404);
        $this->assertSame('validated', $this->statusOf($id));
        // Y un usuario de la empresa A no puede operar con el encabezado de la B.
        $this->actingAsTenantUser($c['approver'], $other['tenant'])->postJson($this->url($id, 'approve'))->assertStatus(404);
    }

    // ───────────────────────── compuerta de firma ─────────────────────────

    public function test_approval_requires_a_verified_signature_and_never_accepts_the_unsigned_legacy_flag(): void
    {
        $c = $this->registryCompany();
        $approve = fn (int $id) => $this->actingAsTenantUser($c['approver'], $c['tenant'])->postJson($this->url($id, 'approve'));
        $key = fn (int $id) => (string) DB::table('model_registry')->where('id', $id)->value('published_object_key');

        // sin firma (sobre marcado sin firma)
        $unsigned = $this->validatedModel($c);
        $this->resignPublished($unsigned, unsigned: true);
        $approve($unsigned)->assertStatus(409)->assertJsonPath('code', 'approval_artifact_unsigned');
        // el indicador de legado del servicio NO permite aprobar un artefacto sin firma
        config(['engine.artifact_signing.allow_unsigned' => true]);
        $approve($unsigned)->assertStatus(409)->assertJsonPath('code', 'approval_artifact_unsigned');
        config(['engine.artifact_signing.allow_unsigned' => false]);

        // bytes alterados después de firmar
        $tampered = $this->validatedModel($c);
        Storage::disk('local')->put($key($tampered), 'otro contenido');
        $approve($tampered)->assertStatus(409)->assertJsonPath('code', 'approval_artifact_mismatch');

        // firmado por una clave desconocida (atacante)
        $untrusted = $this->validatedModel($c);
        $pair = sodium_crypto_sign_keypair();
        $this->resignPublished($untrusted, [], sodium_crypto_sign_secretkey($pair), 'clave-atacante');
        $approve($untrusted)->assertStatus(409)->assertJsonPath('code', 'approval_artifact_signature_invalid')->assertJsonPath('context.reason', 'artifact_untrusted_key');

        // manifiesto firmado de OTRA empresa
        $foreign = $this->validatedModel($c);
        $this->resignPublished($foreign, ['tenant_id' => $c['tenant']->id + 999]);
        $approve($foreign)->assertStatus(409)->assertJsonPath('code', 'approval_artifact_signature_invalid')->assertJsonPath('context.reason', 'artifact_tenant_mismatch');

        // manifiesto firmado de otro core
        $otherCore = $this->validatedModel($c);
        $this->resignPublished($otherCore, ['core' => 'demand']);
        $approve($otherCore)->assertStatus(409)->assertJsonPath('context.reason', 'artifact_core_mismatch');

        // el objeto desapareció del almacén: no se puede verificar → no se aprueba
        $invisible = $this->validatedModel($c);
        Storage::disk('local')->delete($key($invisible));
        $approve($invisible)->assertStatus(409)->assertJsonPath('code', 'approval_artifact_not_verifiable');

        // sin huella SHA-256 registrada
        $noSha = $this->validatedModel($c);
        DB::table('model_registry')->where('id', $noSha)->update(['artifact_sha256' => null]);
        $approve($noSha)->assertStatus(409)->assertJsonPath('code', 'approval_artifact_without_checksum');

        foreach ([$unsigned, $tampered, $untrusted, $foreign, $otherCore, $invisible, $noSha] as $id) {
            $this->assertSame('validated', $this->statusOf($id));
            $this->assertNull(DB::table('model_registry')->where('id', $id)->value('approved_at'));
        }
        // El válido sí pasa y deja la firma en la evidencia.
        $ok = $this->validatedModel($c);
        $approve($ok)->assertOk();
        $evidence = collect($this->eventsOf($ok))->last()['evidence']['gates']['signature'];
        $this->assertSame(['verified', 'object_store', 'test-ephemeral'], [$evidence['status'], $evidence['source'], $evidence['key_id']]);
    }

    /** Un modelo del pipeline propio que se aprueba de forma explícita se verifica sobre el archivo visible en ENGINE_ARTIFACTS_DIR. */
    public function test_a_pipeline_model_is_verified_from_the_artifacts_directory_when_approved_explicitly(): void
    {
        $c = $this->registryCompany();
        $file = fn (int $id) => $this->artifactsDir.'/'.DB::table('model_registry')->where('id', $id)->value('artifact_path');
        $approve = fn (int $id) => $this->actingAsTenantUser($c['approver'], $c['tenant'])->postJson($this->url($id, 'approve'));

        $tampered = $this->customerModel($c['tenant'], 'validated');
        file_put_contents($file($tampered), 'cambiado');
        $approve($tampered)->assertStatus(409)->assertJsonPath('code', 'approval_artifact_mismatch');

        $invisible = $this->customerModel($c['tenant'], 'validated');
        unlink($file($invisible));
        $approve($invisible)->assertStatus(409)->assertJsonPath('code', 'approval_artifact_not_verifiable');

        $unsigned = $this->customerModel($c['tenant'], 'validated');
        $this->signArtifactFile($file($unsigned), $c['tenant']->id, ['core' => 'customer'], unsigned: true);
        config(['engine.artifact_signing.allow_unsigned' => true]);   // el legado se sirve con el indicador, pero NO se aprueba
        $approve($unsigned)->assertStatus(409)->assertJsonPath('code', 'approval_artifact_unsigned');
        config(['engine.artifact_signing.allow_unsigned' => false]);

        $ok = $this->customerModel($c['tenant'], 'validated');
        $approve($ok)->assertOk()->assertJsonPath('data.serving_basis', 'approved');
        $this->assertSame('artifacts_dir', collect($this->eventsOf($ok))->last()['evidence']['gates']['signature']['source']);
    }

    public function test_the_signature_is_checked_again_at_approval_time_even_if_it_was_valid_at_validation(): void
    {
        $c = $this->registryCompany();
        $id = $this->validatedModel($c);
        Storage::disk('local')->put((string) DB::table('model_registry')->where('id', $id)->value('published_object_key'), 'reemplazado tras validar');
        $this->actingAsTenantUser($c['approver'], $c['tenant'])->postJson($this->url($id, 'approve'))->assertStatus(409)->assertJsonPath('code', 'approval_artifact_mismatch');
        $this->assertSame('validated', $this->statusOf($id));
    }

    /** LOW 4: un modelo publicado sin la clave de su objeto falla cerrado: no se verifica otro archivo en su lugar. */
    public function test_a_published_model_without_its_object_key_cannot_be_approved(): void
    {
        $c = $this->registryCompany();
        $approve = fn (int $id) => $this->actingAsTenantUser($c['approver'], $c['tenant'])->postJson($this->url($id, 'approve'));
        // con un artefacto visible en ENGINE_ARTIFACTS_DIR (no es lo que se serviría)
        $withPath = $this->customerModel($c['tenant'], 'validated', ['published_by' => $c['publisher']->id, 'published_at' => now(), 'published_env' => 'staging']);
        $approve($withPath)->assertStatus(409)->assertJsonPath('code', 'approval_artifact_not_verifiable')->assertJsonPath('context.reason', 'published_without_object_key');
        // sin ningún artefacto registrado
        $bare = $this->customerModel($c['tenant'], 'validated', ['published_by' => $c['publisher']->id, 'published_at' => now()], false);
        $approve($bare)->assertStatus(409)->assertJsonPath('code', 'approval_artifact_not_verifiable');
        // y tampoco se valida (la compuerta es la misma)
        $candidate = $this->customerModel($c['tenant'], 'candidate', ['published_by' => $c['publisher']->id, 'published_at' => now(), 'core_run_id' => null], false);
        $this->actingAsTenantUser($c['analyst'], $c['tenant'])->postJson($this->url($candidate, 'validate'))->assertStatus(409)->assertJsonPath('code', 'approval_artifact_not_verifiable');
        foreach ([$withPath, $bare] as $id) {
            $this->assertSame('validated', $this->statusOf($id));
        }
        $this->assertSame('candidate', $this->statusOf($candidate));
    }

    public function test_a_pipeline_model_without_an_artifact_has_a_not_applicable_signature_gate(): void
    {
        $c = $this->registryCompany();
        $id = $this->customerModel($c['tenant'], 'validated', [], false);   // estadístico que se reajusta en cada corrida: nada que firmar
        $this->actingAsTenantUser($c['approver'], $c['tenant'])->postJson($this->url($id, 'approve'))->assertOk();
        $this->assertSame('not_applicable', collect($this->eventsOf($id))->last()['evidence']['gates']['signature']['status']);
    }

    // ───────────────────────── gate de datos (W2-C) ─────────────────────────

    public function test_a_blocked_or_missing_data_gate_prevents_approval(): void
    {
        $c = $this->registryCompany();
        $approve = fn (int $id) => $this->actingAsTenantUser($c['approver'], $c['tenant'])->postJson($this->url($id, 'approve'));

        // BLOCK en el informe de la ejecución
        $run = $this->trainedByRun($c['tenant'], null);
        $this->gateReport($c['tenant'], $run['core_run_id'], 'BLOCK');
        $blocked = $this->customerModel($c['tenant'], 'validated', ['core_run_id' => $run['core_run_id']], false);
        $approve($blocked)->assertStatus(409)->assertJsonPath('code', 'approval_data_gate_blocked');

        // sin informe ni resultado declarado: sin evidencia no se aprueba
        $missing = $this->customerModel($c['tenant'], 'validated', ['artifact_compat' => json_encode(['feature_schema_version' => 'cust-fs-2026.10-v1'])], false);
        $approve($missing)->assertStatus(409)->assertJsonPath('code', 'approval_data_gate_missing');

        // un BLOCK declarado por quien publicó tampoco pasa
        $declaredBlock = $this->customerModel($c['tenant'], 'validated', ['artifact_compat' => json_encode(['feature_schema_version' => 'cust-fs-2026.10-v1', 'data_gate' => ['outcome' => 'BLOCK']])], false);
        $approve($declaredBlock)->assertStatus(409)->assertJsonPath('code', 'approval_data_gate_blocked');

        // el informe de la ejecución manda sobre lo declarado: PASS declarado + BLOCK real = BLOCK
        $run2 = $this->trainedByRun($c['tenant'], null);
        $this->gateReport($c['tenant'], $run2['core_run_id'], 'BLOCK');
        $conflict = $this->customerModel($c['tenant'], 'validated', ['core_run_id' => $run2['core_run_id']], false);   // artifact_compat declara PASS
        $approve($conflict)->assertStatus(409)->assertJsonPath('code', 'approval_data_gate_blocked');

        // DEGRADE y WARN no bloquean (≠ BLOCK)
        foreach (['DEGRADE', 'WARN', 'PASS'] as $outcome) {
            $r = $this->trainedByRun($c['tenant'], null);
            $this->gateReport($c['tenant'], $r['core_run_id'], $outcome);
            $ok = $this->customerModel($c['tenant'], 'validated', ['core_run_id' => $r['core_run_id']], false);
            $approve($ok)->assertOk();
            $this->assertSame($outcome, collect($this->eventsOf($ok))->last()['evidence']['gates']['data_gate']['outcome']);
        }
        foreach ([$blocked, $missing, $declaredBlock, $conflict] as $id) {
            $this->assertSame('validated', $this->statusOf($id));
        }
    }

    public function test_the_data_gate_report_of_another_company_is_never_used(): void
    {
        $a = $this->registryCompany();
        $b = $this->registryCompany();
        $runB = $this->trainedByRun($b['tenant'], null);
        $this->gateReport($b['tenant'], $runB['core_run_id'], 'PASS');
        // El modelo de A apunta (por error o manipulación) a una corrida con el id de la de B: el global scope no ve ese informe.
        $id = $this->customerModel($a['tenant'], 'validated', ['artifact_compat' => json_encode(['feature_schema_version' => 'cust-fs-2026.10-v1'])], false);
        DB::table('model_registry')->where('id', $id)->update(['core_run_id' => null]);
        $this->actingAsTenantUser($a['approver'], $a['tenant'])->postJson($this->url($id, 'approve'))->assertStatus(409)->assertJsonPath('code', 'approval_data_gate_missing');
    }

    // ───────────────────────── decisión de promoción (W3) ─────────────────────────

    public function test_approval_completes_the_w3_promotion_decision_and_a_policy_rejection_cannot_be_overridden(): void
    {
        $c = $this->registryCompany();
        $id = $this->validatedModel($c);
        $decision = $this->promotionDecision($c['tenant'], $id, 'promote');
        DB::table('model_registry')->where('id', $id)->update(['promotion_decision_id' => $decision]);

        $this->actingAsTenantUser($c['approver'], $c['tenant'])->postJson($this->url($id, 'approve'))->assertOk()
            ->assertJsonPath('data.promotion_decision.id', $decision)->assertJsonPath('data.promotion_decision.approved_by', $c['approver']->id);
        $row = DB::table('model_promotion_decisions')->where('id', $decision)->first();
        $this->assertSame($c['approver']->id, (int) $row->approved_by);
        $this->assertNotNull($row->approved_at);
        $this->assertSame('promote', collect($this->eventsOf($id))->last()['evidence']['gates']['policy_decision']['decision']);

        // La política rechazó al candidato: la aprobación humana no anula los gates.
        $rejected = $this->validatedModel($c);
        $rejectedDecision = $this->promotionDecision($c['tenant'], $rejected, 'reject');
        DB::table('model_registry')->where('id', $rejected)->update(['promotion_decision_id' => $rejectedDecision]);
        $this->actingAsTenantUser($c['approver'], $c['tenant'])->postJson($this->url($rejected, 'approve'))->assertStatus(409)->assertJsonPath('code', 'approval_policy_rejected');
        $this->assertNull(DB::table('model_promotion_decisions')->where('id', $rejectedDecision)->value('approved_at'));
    }

    // ───────────────────────── rechazo ─────────────────────────

    public function test_reject_requires_a_reason_records_it_and_is_terminal(): void
    {
        $c = $this->registryCompany();
        $id = $this->validatedModel($c);
        $api = $this->actingAsTenantUser($c['approver'], $c['tenant']);

        $api->postJson($this->url($id, 'reject'), [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $api->postJson($this->url($id, 'reject'), ['reason' => '   '])->assertStatus(422)->assertJsonValidationErrors('reason');
        $api->postJson($this->url($id, 'reject'), ['reason' => 'x'])->assertStatus(422);
        $this->assertSame('validated', $this->statusOf($id));

        $api->postJson($this->url($id, 'reject'), ['reason' => 'El holdout empeora en el segmento de bajo volumen'])
            ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.rejected_reason', 'El holdout empeora en el segmento de bajo volumen')
            ->assertJsonPath('data.serving_basis', 'not_approved');
        $last = collect($this->eventsOf($id))->last();
        $this->assertSame(['validated', 'rejected', $c['approver']->id, 'El holdout empeora en el segmento de bajo volumen'], [$last['from'], $last['to'], $last['actor'], $last['reason']]);

        // terminal: no se aprueba, no se promueve, no se vuelve a rechazar
        $api->postJson($this->url($id, 'approve'))->assertStatus(409)->assertJsonPath('code', 'model_transition_not_allowed');
        $api->postJson($this->url($id, 'promote'))->assertStatus(409)->assertJsonPath('code', 'model_transition_not_allowed');
        $api->postJson($this->url($id, 'reject'), ['reason' => 'Otra vez'])->assertStatus(409);
        $this->assertSame('rejected', $this->statusOf($id));
    }

    public function test_an_approval_can_be_withdrawn_before_production_but_a_production_model_cannot_be_rejected(): void
    {
        $c = $this->registryCompany();
        $id = $this->validatedModel($c);
        $this->as($c, 'approver')->postJson($this->url($id, 'approve'))->assertOk();
        $this->as($c, 'approver')->postJson($this->url($id, 'reject'), ['reason' => 'Se detectó un sesgo posterior'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertTrue(collect($this->eventsOf($id))->last()['evidence']['withdrew_approval']);

        $live = $this->validatedModel($c);
        $this->as($c, 'approver')->postJson($this->url($live, 'approve'))->assertOk();
        $this->as($c, 'approver')->postJson($this->url($live, 'promote'))->assertOk()->assertJsonPath('data.status', 'production');
        $this->as($c, 'approver')->postJson($this->url($live, 'reject'), ['reason' => 'Ya en producción'])->assertStatus(409)->assertJsonPath('code', 'model_transition_not_allowed');
    }

    // ───────────────────────── promoción a producción ─────────────────────────

    public function test_a_candidate_or_unapproved_model_is_never_promoted_or_served(): void
    {
        $c = $this->registryCompany();
        $candidate = $this->publishedCandidate($c['tenant'], $c['publisher']);
        $validated = $this->validatedModel($c);
        foreach ([$candidate, $validated] as $id) {
            $this->as($c, 'approver')->postJson($this->url($id, 'promote'))->assertStatus(409)->assertJsonPath('code', 'model_transition_not_allowed');
        }
        $this->assertSame(['candidate', 'validated'], [$this->statusOf($candidate), $this->statusOf($validated)]);
        $this->assertSame(0, DB::table('model_registry')->where('tenant_id', $c['tenant']->id)->where('status', 'production')->count());
        // El listado marca lo no aprobado como tal.
        $row = collect($this->as($c, 'approver')->getJson('/api/v1/models?status=candidate')->assertOk()->json('data'))->firstWhere('id', $candidate);
        $this->assertSame('not_approved', $row['serving_basis']);
    }

    public function test_promote_puts_the_approved_model_in_production_and_retires_the_previous_one_of_its_slot_only(): void
    {
        $c = $this->registryCompany();
        $current = $this->customerModel($c['tenant'], 'production', [], true, 'h30:probability');          // legado vigente del slot
        $otherSlot = $this->customerModel($c['tenant'], 'production', [], true, 'h30:quantity');            // otro objetivo: intacto
        $id = $this->validatedModel($c);
        $this->as($c, 'approver')->postJson($this->url($id, 'approve'))->assertOk();

        $res = $this->as($c, 'approver')->postJson($this->url($id, 'promote'), ['reason' => 'Mejora comprobada en el piloto'])->assertOk();
        $res->assertJsonPath('data.status', 'production')->assertJsonPath('data.retired_model_ids', [$current])->assertJsonPath('data.previous_model_registry_id', $current);
        $this->assertSame('retired', $this->statusOf($current));
        $this->assertSame('production', $this->statusOf($otherSlot));
        $this->assertSame(1, DB::table('model_registry')->where('tenant_id', $c['tenant']->id)->where('status', 'production')->whereRaw("hyperparameters->>'slot' = 'h30:probability'")->count());
        $this->assertNotNull(DB::table('model_registry')->where('id', $id)->value('promoted_at'));
        $this->assertNotNull(DB::table('model_registry')->where('id', $current)->value('retired_at'));

        // el retiro del anterior y la entrada del nuevo quedan en la misma historia de eventos
        $this->assertSame(['production', 'retired'], [$this->eventsOf($current)[1]['from'], $this->eventsOf($current)[1]['to']]);
        $this->assertSame($id, $this->eventsOf($current)[1]['evidence']['replaced_by']);
        $this->assertSame(['approved', 'production'], [collect($this->eventsOf($id))->last()['from'], collect($this->eventsOf($id))->last()['to']]);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $c['tenant']->id, 'action' => 'models.promoted']);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $c['tenant']->id, 'action' => 'models.approved']);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $c['tenant']->id, 'action' => 'models.validated']);
    }

    public function test_promote_respects_the_plan_horizons_and_rechecks_the_signature(): void
    {
        $c = $this->registryCompany();
        // Plan Básico: sin horizonte 90 para el Customer Core
        $planId = (int) DB::table('plans')->insertGetId(['code' => 'basico', 'name' => 'Básico', 'limits' => json_encode(config('predictily.plans.basico.limits')), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        Tenant::query()->whereKey($c['tenant']->id)->update(['plan_id' => $planId]);
        $h90 = $this->validatedModel($c, [], 'h90:probability');
        $this->as($c, 'approver')->postJson($this->url($h90, 'approve'))->assertOk();
        $this->as($c, 'approver')->postJson($this->url($h90, 'promote'))->assertStatus(402)->assertJsonPath('code', 'plan_limit_exceeded');
        $this->assertSame('approved', $this->statusOf($h90));

        // El archivo cambia después de aprobar: la promoción lo detecta (aprobado ≠ servible a ciegas)
        $id = $this->validatedModel($c);
        $this->as($c, 'approver')->postJson($this->url($id, 'approve'))->assertOk();
        Storage::disk('local')->put((string) DB::table('model_registry')->where('id', $id)->value('published_object_key'), 'cambiado tras aprobar');
        $this->as($c, 'approver')->postJson($this->url($id, 'promote'))->assertStatus(409)->assertJsonPath('code', 'approval_artifact_mismatch');
        $this->assertSame('approved', $this->statusOf($id));
    }

    public function test_promote_refuses_an_approval_given_by_someone_responsible_for_the_model(): void
    {
        $c = $this->registryCompany();
        $id = $this->validatedModel($c);
        $this->actingAsTenantUser($c['approver'], $c['tenant'])->postJson($this->url($id, 'approve'))->assertOk();
        // fila manipulada: el aprobador figura también como quien publicó
        DB::table('model_registry')->where('id', $id)->update(['published_by' => $c['approver']->id]);
        $this->actingAsTenantUser($c['owner'], $c['tenant'])->postJson($this->url($id, 'promote'))
            ->assertStatus(403)->assertJsonPath('code', 'approval_segregation_of_duties');
        $this->assertSame('approved', $this->statusOf($id));
    }

    public function test_an_approved_model_with_a_deleted_approver_stays_approved(): void
    {
        $c = $this->registryCompany();
        $id = $this->validatedModel($c);
        $this->actingAsTenantUser($c['approver'], $c['tenant'])->postJson($this->url($id, 'approve'))->assertOk();
        DB::table('model_registry')->where('id', $id)->update(['approved_by' => null]);   // la FK es nullOnDelete: el usuario se eliminó
        $this->actingAsTenantUser($c['owner'], $c['tenant'])->postJson($this->url($id, 'promote'))->assertOk()->assertJsonPath('data.status', 'production')->assertJsonPath('data.serving_basis', 'approved');
    }

    // ───────────────────────── compatibilidad del Customer Core ─────────────────────────

    public function test_customer_models_need_a_valid_slot_and_a_compatible_feature_schema(): void
    {
        $c = $this->registryCompany();
        $api = $this->actingAsTenantUser($c['analyst'], $c['tenant']);
        $noSlot = $this->publishedCandidate($c['tenant'], $c['publisher'], ['hyperparameters' => json_encode(['kind' => 'complex'])]);
        $api->postJson($this->url($noSlot, 'validate'))->assertStatus(409)->assertJsonPath('code', 'model_without_slot');
        $h60 = $this->publishedCandidate($c['tenant'], $c['publisher'], [], 'h60:probability');   // 60 no es un horizonte del Customer Core
        $api->postJson($this->url($h60, 'validate'))->assertStatus(409)->assertJsonPath('code', 'model_without_slot');
        $incompatible = $this->publishedCandidate($c['tenant'], $c['publisher'], ['artifact_compat' => json_encode(['feature_schema_version' => 'cust-fs-1999', 'data_gate' => ['outcome' => 'PASS']])]);
        $api->postJson($this->url($incompatible, 'validate'))->assertStatus(409)->assertJsonPath('code', 'model_incompatible');
        foreach ([$noSlot, $h60, $incompatible] as $id) {
            $this->assertSame('candidate', $this->statusOf($id));
        }
    }
}
