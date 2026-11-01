<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Analytics\Support\AnalyticsFixtures;
use Tests\Feature\Models\Support\ModelRegistryFixtures;
use Tests\TestCase;

/**
 * Serving y reversión SOLO de lo aprobado (ADR-V25-07, V25-REG-*), con la política explícita para los LEGADOS que mantiene la demo y los E2E:
 * la reversión de demanda y la de Customer aceptan modelos aprobados o legados del pipeline propio, rechazan lo no aprobado con 409
 * `rollback_target_not_approved` sin cambiar nada, y cada cambio de estado deja su evento en la misma transacción.
 */
class RollbackApprovalPolicyTest extends TestCase
{
    use AnalyticsFixtures;
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

    // ───────────────────────── Customer Core ─────────────────────────

    public function test_customer_rollback_to_a_legacy_model_keeps_working_and_leaves_auditable_events(): void
    {
        $c = $this->registryCompany();
        $old = $this->customerModel($c['tenant'], 'retired', ['retired_at' => now()->subDay()]);
        $current = $this->customerModel($c['tenant'], 'production');
        $res = $this->actingAsTenantUser($c['analyst'], $c['tenant'])->postJson('/api/v1/models/customer/rollback', ['model_registry_id' => $old])->assertOk();

        $this->assertSame([$current], array_column($res->json('data.retired'), 'id'));
        $this->assertSame(['production', 'retired'], [$this->statusOf($old), $this->statusOf($current)]);
        $restored = $this->eventsOf($old);
        $this->assertSame([[null, 'retired'], ['retired', 'production']], array_map(fn ($e) => [$e['from'], $e['to']], $restored));
        $this->assertSame('legacy_approved_by_migration', $restored[0]['evidence']['legacy_policy'], 'el legado queda marcado en la evidencia');
        $this->assertSame([$c['analyst']->id, 'user', 'customer_rollback', 'h30:probability'], [$restored[1]['actor'], $restored[1]['kind'], $restored[1]['evidence']['trigger'], $restored[1]['evidence']['slot']]);
        $retired = $this->eventsOf($current);
        $this->assertSame(['production', 'retired'], [$retired[1]['from'], $retired[1]['to']]);
        $this->assertSame($current, (int) DB::table('model_registry')->where('id', $old)->value('previous_model_registry_id'));
        // un validado legado (referencia que el motor dejó sin promover) también se puede servir
        $validated = $this->customerModel($c['tenant'], 'validated', ['role' => 'fallback'], true, 'h7:probability');
        $this->actingAsTenantUser($c['analyst'], $c['tenant'])->postJson('/api/v1/models/customer/rollback', ['model_registry_id' => $validated])->assertOk();
        $this->assertSame('production', $this->statusOf($validated));
    }

    public function test_customer_rollback_refuses_unapproved_published_models_without_changing_anything(): void
    {
        $c = $this->registryCompany();
        $current = $this->customerModel($c['tenant'], 'production');
        // publicado y validado, pero sin aprobar
        $validated = $this->publishedCandidate($c['tenant'], $c['publisher'], ['status' => 'validated']);
        // publicado, retirado, con la aprobación borrada (fila manipulada): tampoco
        $retiredUnapproved = $this->publishedCandidate($c['tenant'], $c['publisher'], ['status' => 'retired']);
        $api = fn () => $this->actingAsTenantUser($c['analyst'], $c['tenant']);
        $eventsBefore = DB::table('model_registry_events')->where('tenant_id', $c['tenant']->id)->count();

        foreach ([$validated, $retiredUnapproved] as $id) {
            $r = $api()->postJson('/api/v1/models/customer/rollback', ['model_registry_id' => $id])->assertStatus(409)->assertJsonPath('code', 'rollback_target_not_approved');
            $this->assertSame('not_approved', $r->json('context.basis'));
        }
        // candidato, rechazado y fallido ni siquiera son restaurables
        foreach (['candidate', 'rejected', 'failed'] as $status) {
            $id = $this->customerModel($c['tenant'], $status, ['published_at' => now()]);
            $api()->postJson('/api/v1/models/customer/rollback', ['model_registry_id' => $id])->assertStatus(409)->assertJsonPath('code', 'rollback_target_not_restorable');
        }
        $this->assertSame('production', $this->statusOf($current));
        $this->assertSame(['validated', 'retired'], [$this->statusOf($validated), $this->statusOf($retiredUnapproved)]);
        $this->assertSame($eventsBefore, DB::table('model_registry_events')->where('tenant_id', $c['tenant']->id)->count(), 'un rechazo no deja eventos ni líneas base');
        $this->assertDatabaseMissing('audit_logs', ['action' => 'models.customer_rolled_back']);
    }

    public function test_customer_rollback_serves_approved_published_models_including_ones_retired_after_being_approved(): void
    {
        $c = $this->registryCompany();
        $current = $this->customerModel($c['tenant'], 'production');
        $approvedRetired = $this->publishedCandidate($c['tenant'], $c['publisher'], ['status' => 'retired', 'approved_by' => $c['approver']->id, 'approved_at' => now()->subDays(2)]);
        $approvedNotYetServed = $this->publishedCandidate($c['tenant'], $c['publisher'], ['status' => 'approved', 'approved_by' => $c['approver']->id, 'approved_at' => now()->subDay()], 'h7:probability');

        // lo que ya sirvió se restaura con forecast.run…
        $this->actingAsTenantUser($c['analyst'], $c['tenant'])->postJson('/api/v1/models/customer/rollback', ['model_registry_id' => $approvedRetired])->assertOk()
            ->assertJsonPath('data.restored.status', 'production');
        $this->assertSame('retired', $this->statusOf($current));
        // …lo aprobado que nunca sirvió equivale a promoverlo: exige models.approve (decisión documentada)
        $this->actingAsTenantUser($c['analyst'], $c['tenant'])->postJson('/api/v1/models/customer/rollback', ['model_registry_id' => $approvedNotYetServed])
            ->assertStatus(403)->assertJsonPath('code', 'forbidden')->assertJsonPath('required_ability', 'models.approve');
        $this->assertSame('approved', $this->statusOf($approvedNotYetServed));
        $this->actingAsTenantUser($c['owner'], $c['tenant'])->postJson('/api/v1/models/customer/rollback', ['model_registry_id' => $approvedNotYetServed])->assertOk();
        $this->assertSame('production', $this->statusOf($approvedNotYetServed));
        $last = collect($this->eventsOf($approvedNotYetServed))->last();
        $this->assertSame('approved', $last['evidence']['serving_basis']);
        $this->assertSame('verified', $last['evidence']['gates']['signature']['status'], 'repite las compuertas de la promoción');
    }

    public function test_restoring_a_never_served_approved_model_repeats_the_promotion_gates_and_published_models_are_reverified(): void
    {
        $c = $this->registryCompany();
        $owner = fn () => $this->actingAsTenantUser($c['owner'], $c['tenant']);
        // objeto alterado en el almacén: ni siquiera con models.approve
        $altered = $this->publishedCandidate($c['tenant'], $c['publisher'], ['status' => 'approved', 'approved_by' => $c['approver']->id, 'approved_at' => now()]);
        Storage::disk('local')->put((string) DB::table('model_registry')->where('id', $altered)->value('published_object_key'), 'alterado');
        $owner()->postJson('/api/v1/models/customer/rollback', ['model_registry_id' => $altered])->assertStatus(409)->assertJsonPath('code', 'approval_artifact_mismatch');
        // gate de datos BLOCK declarado
        $blocked = $this->publishedCandidate($c['tenant'], $c['publisher'], ['status' => 'approved', 'approved_by' => $c['approver']->id, 'approved_at' => now(),
            'artifact_compat' => json_encode(['feature_schema_version' => 'cust-fs-2026.10-v1', 'data_gate' => ['outcome' => 'BLOCK']])], 'h7:probability');
        $owner()->postJson('/api/v1/models/customer/rollback', ['model_registry_id' => $blocked])->assertStatus(409)->assertJsonPath('code', 'approval_data_gate_blocked');
        // un publicado aprobado que ya sirvió (retired) también se vuelve a verificar al restaurarlo
        $retired = $this->publishedCandidate($c['tenant'], $c['publisher'], ['status' => 'retired', 'approved_by' => $c['approver']->id, 'approved_at' => now()], 'h14:probability');
        Storage::disk('local')->put((string) DB::table('model_registry')->where('id', $retired)->value('published_object_key'), 'alterado después de retirarse');
        $this->actingAsTenantUser($c['analyst'], $c['tenant'])->postJson('/api/v1/models/customer/rollback', ['model_registry_id' => $retired])->assertStatus(409)->assertJsonPath('code', 'approval_artifact_mismatch');
        foreach ([[$altered, 'approved'], [$blocked, 'approved'], [$retired, 'retired']] as [$id, $status]) {
            $this->assertSame($status, $this->statusOf($id));
        }
    }

    public function test_customer_rollback_is_isolated_between_companies_in_registry_and_events(): void
    {
        $a = $this->registryCompany();
        $b = $this->registryCompany();
        $modelA = $this->customerModel($a['tenant'], 'retired');
        $currentB = $this->customerModel($b['tenant'], 'production');
        $this->actingAsTenantUser($b['analyst'], $b['tenant'])->postJson('/api/v1/models/customer/rollback', ['model_registry_id' => $modelA])->assertStatus(404);
        $this->assertSame('retired', $this->statusOf($modelA));
        $this->assertSame('production', $this->statusOf($currentB));
        $this->assertSame(0, DB::table('model_registry_events')->count());
    }

    // ───────────────────────── Demanda ─────────────────────────

    /** Tres ejecuciones de producción (r1 y r2 retiradas, r3 vigente); un modelo LightGBM por ejecución, legado del pipeline. */
    private function threeRuns(): array
    {
        $c = $this->registryCompany();
        $cat = $this->seedCatalog($c['tenant']);
        $runs = [];
        $models = [];
        foreach ([1, 2, 3] as $i) {
            $runs[$i] = $this->seedForecastRun($c['tenant'], $cat, '2026-09-30', ['version' => $i, 'is_current' => $i === 3, 'horizon' => 7, 'with_evaluations' => false, 'with_recommendations' => false]);
            DB::table('model_registry')->where('forecast_run_id', $runs[$i]->id)->update(['status' => $i === 3 ? 'production' : 'retired']);
            $models[$i] = (int) DB::table('model_registry')->where('forecast_run_id', $runs[$i]->id)->value('id');
        }

        return [$c, array_values($runs), array_values($models)];
    }

    public function test_demand_rollback_to_legacy_models_keeps_working_and_audits_every_transition(): void
    {
        [$c, $runs, $models] = $this->threeRuns();
        $this->actingAsTenantUser($c['analyst'], $c['tenant'])->postJson('/api/v1/models/demand/rollback', ['run_id' => $runs[1]->id])->assertOk()
            ->assertJsonPath('data.restored_model_ids', [$models[1]])->assertJsonPath('data.retired_model_ids', [$models[2]]);
        $this->assertSame(['production', 'retired'], [$this->statusOf($models[1]), $this->statusOf($models[2])]);
        $restored = $this->eventsOf($models[1]);
        $this->assertSame([[null, 'retired'], ['retired', 'production']], array_map(fn ($e) => [$e['from'], $e['to']], $restored));
        $this->assertSame('legacy_approved_by_migration', $restored[0]['evidence']['legacy_policy']);
        $this->assertSame(['demand_rollback', $runs[2]->id, $runs[1]->id], [$restored[1]['evidence']['trigger'], $restored[1]['evidence']['from_run_id'], $restored[1]['evidence']['to_run_id']]);
        $this->assertSame($c['analyst']->id, $restored[1]['actor']);
        $this->assertSame(['production', 'retired'], [$this->eventsOf($models[2])[1]['from'], $this->eventsOf($models[2])[1]['to']]);
        // se sigue pudiendo retroceder y el historial se acumula
        $this->actingAsTenantUser($c['analyst'], $c['tenant'])->postJson('/api/v1/models/demand/rollback', ['run_id' => $runs[0]->id])->assertOk();
        $this->assertCount(2, $this->eventsOf($models[0]));
    }

    public function test_demand_rollback_refuses_a_run_with_unapproved_published_models_and_changes_nothing(): void
    {
        [$c, $runs, $models] = $this->threeRuns();
        // la ejecución 1 tiene un modelo publicado (con su objeto firmado en el almacén) sin aprobar junto al legado
        [$key, $sha] = $this->publishObject($c['tenant'], 'demand-r1', ['core' => 'demand', 'run_id' => $runs[1]->id], 'demand');
        DB::table('model_registry')->where('id', $models[1])->update(['published_by' => $c['publisher']->id, 'published_at' => now(), 'published_env' => 'staging',
            'published_object_key' => $key, 'artifact_sha256' => $sha]);
        $before = DB::table('model_registry')->where('tenant_id', $c['tenant']->id)->orderBy('id')->pluck('status', 'id')->all();

        $this->actingAsTenantUser($c['analyst'], $c['tenant'])->postJson('/api/v1/models/demand/rollback', ['run_id' => $runs[1]->id])
            ->assertStatus(409)->assertJsonPath('code', 'rollback_target_not_approved')->assertJsonPath('context.model_ids', [$models[1]]);
        $this->assertSame($before, DB::table('model_registry')->where('tenant_id', $c['tenant']->id)->orderBy('id')->pluck('status', 'id')->all());
        $this->assertTrue((bool) DB::table('forecast_runs')->where('id', $runs[2]->id)->value('is_current'));
        $this->assertSame(0, DB::table('model_registry_events')->where('tenant_id', $c['tenant']->id)->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'models.demand_rolled_back']);

        // aprobado por otra persona, pero el objeto publicado fue alterado: se vuelve a verificar al restaurar
        DB::table('model_registry')->where('id', $models[1])->update(['approved_by' => $c['approver']->id, 'approved_at' => now()]);
        Storage::disk('local')->put($key, 'alterado en el almacén');
        $this->actingAsTenantUser($c['analyst'], $c['tenant'])->postJson('/api/v1/models/demand/rollback', ['run_id' => $runs[1]->id])
            ->assertStatus(409)->assertJsonPath('code', 'approval_artifact_mismatch');
        $this->assertSame($before, DB::table('model_registry')->where('tenant_id', $c['tenant']->id)->orderBy('id')->pluck('status', 'id')->all());

        // íntegro y aprobado por otra persona: ya se puede restaurar
        $contents = 'artefacto-publicado-demand-r1';
        Storage::disk('local')->put($key, $contents);
        $this->actingAsTenantUser($c['analyst'], $c['tenant'])->postJson('/api/v1/models/demand/rollback', ['run_id' => $runs[1]->id])->assertOk();
        $this->assertSame('production', $this->statusOf($models[1]));
        $this->assertSame('approved', collect($this->eventsOf($models[1]))->last()['evidence']['serving_basis']);
    }

    public function test_demand_rollback_is_isolated_between_companies(): void
    {
        [$a, $runsA, $modelsA] = $this->threeRuns();
        [$b, $runsB] = $this->threeRuns();
        $this->actingAsTenantUser($b['analyst'], $b['tenant'])->postJson('/api/v1/models/demand/rollback', ['run_id' => $runsA[0]->id])->assertStatus(404);
        $this->actingAsTenantUser($b['analyst'], $b['tenant'])->postJson('/api/v1/models/demand/rollback', ['run_id' => $runsB[1]->id])->assertOk();
        $this->assertSame('production', $this->statusOf($modelsA[2]));
        $this->assertSame(0, DB::table('model_registry_events')->where('tenant_id', $a['tenant']->id)->count());
        $this->assertGreaterThan(0, DB::table('model_registry_events')->where('tenant_id', $b['tenant']->id)->count());
    }

    public function test_the_stage1_rollback_error_codes_are_unchanged(): void
    {
        [$c, $runs] = $this->threeRuns();
        $api = fn () => $this->actingAsTenantUser($c['analyst'], $c['tenant']);
        $api()->postJson('/api/v1/models/demand/rollback', ['run_id' => $runs[2]->id])->assertStatus(409)->assertJsonPath('code', 'rollback_already_current');
        $api()->postJson('/api/v1/models/demand/rollback', ['run_id' => 99999999])->assertStatus(404);
        $api()->postJson('/api/v1/models/demand/rollback', [])->assertStatus(422);
        $api()->postJson('/api/v1/models/demand/rollback', ['run_id' => $runs[1]->id])->assertOk();
        $api()->postJson('/api/v1/models/demand/rollback', ['run_id' => $runs[2]->id])->assertStatus(409)->assertJsonPath('code', 'rollback_target_not_previous');
    }
}
