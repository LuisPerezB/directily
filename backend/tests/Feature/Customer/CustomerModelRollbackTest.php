<?php

declare(strict_types=1);

namespace Tests\Feature\Customer;

use App\Enums\TenantRole;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Customer\Support\CustomerFixtures;
use Tests\TestCase;

/** POST /api/v1/models/customer/rollback: re-sirve una versión validada/retirada íntegra del mismo horizonte y objetivo. */
class CustomerModelRollbackTest extends TestCase
{
    use CustomerFixtures;
    use RefreshDatabase;

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function basicPlanId(): int
    {
        return (int) DB::table('plans')->insertGetId([
            'code' => 'basico', 'name' => 'Básico', 'limits' => json_encode(config('predictily.plans.basico.limits')), 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $over */
    private function model(Tenant $tenant, string $slot, string $status, string $version, array $over = [], bool $withFile = true, string $content = 'artefacto'): int
    {
        $path = null;
        $sha = null;
        if ($withFile) {
            $path = sys_get_temp_dir().'/customer-rollback-'.bin2hex(random_bytes(6)).'.joblib';
            file_put_contents($path, $content.$version);
            $this->files[] = $path;
            $sha = hash_file('sha256', $path);
        }

        return (int) DB::table('model_registry')->insertGetId(array_merge([
            'tenant_id' => $tenant->id, 'version' => $version, 'model_family' => 'customer_'.str_replace(':', '_', $slot), 'strategy_level' => 'B', 'scope' => 'tenant',
            'status' => $status, 'core' => 'customer', 'role' => 'primary', 'artifact_path' => $path, 'artifact_sha256' => $sha,
            'hyperparameters' => json_encode(['slot' => $slot, 'kind' => 'complex', 'ranking_source' => 'popularity']),
            'artifact_compat' => json_encode(['feature_schema_version' => 'cust-fs-2026.10-v1', 'libraries' => ['lightgbm' => '4.7.0']]),
            'retired_at' => $status === 'retired' ? now()->subDay() : null, 'created_at' => now(), 'updated_at' => now(),
        ], $over));
    }

    public function test_rollback_re_serves_a_retired_version_and_retires_the_current_one_in_the_same_slot_only(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $old = $this->model($tenant, 'h30:probability', 'retired', 'v1-h30-prob');
        $current = $this->model($tenant, 'h30:probability', 'production', 'v2-h30-prob');
        $otherSlot = $this->model($tenant, 'h30:quantity', 'production', 'v2-h30-qty');
        $res = $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/models/customer/rollback', ['model_registry_id' => $old])->assertOk();

        $this->assertSame($old, $res->json('data.restored.id'));
        $this->assertSame('production', $res->json('data.restored.status'));
        $this->assertSame('h30:probability', $res->json('data.slot'));
        $this->assertSame(30, $res->json('data.horizon_days'));
        $this->assertTrue($res->json('data.artifact_sha256_verified'));
        $this->assertSame('popularity', $res->json('data.ranking_source'));
        $this->assertSame([$current], array_column($res->json('data.retired'), 'id'));
        $this->assertDatabaseHas('model_registry', ['id' => $old, 'status' => 'production', 'retired_at' => null]);
        $this->assertDatabaseHas('model_registry', ['id' => $current, 'status' => 'retired']);
        $this->assertDatabaseHas('model_registry', ['id' => $otherSlot, 'status' => 'production']);            // otro objetivo: intacto
        $this->assertSame(1, DB::table('model_registry')->where('tenant_id', $tenant->id)->where('status', 'production')->whereRaw("hyperparameters->>'slot' = 'h30:probability'")->count());
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'models.customer_rolled_back']);
    }

    public function test_a_validated_version_without_a_current_production_model_can_be_served(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $validated = $this->model($tenant, 'h7:probability', 'validated', 'v1-h7-prob-reference', ['role' => 'fallback']);
        $res = $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/models/customer/rollback', ['model_registry_id' => $validated])->assertOk();
        $this->assertSame([], $res->json('data.retired'));
        $this->assertDatabaseHas('model_registry', ['id' => $validated, 'status' => 'production']);
    }

    public function test_a_tampered_artifact_is_rejected_and_nothing_changes(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $old = $this->model($tenant, 'h30:probability', 'retired', 'v1');
        $current = $this->model($tenant, 'h30:probability', 'production', 'v2');
        file_put_contents((string) DB::table('model_registry')->where('id', $old)->value('artifact_path'), 'contenido alterado');   // el SHA-256 ya no coincide
        $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/models/customer/rollback', ['model_registry_id' => $old])
            ->assertStatus(409)->assertJsonPath('code', 'rollback_artifact_mismatch');
        $this->assertDatabaseHas('model_registry', ['id' => $old, 'status' => 'retired']);
        $this->assertDatabaseHas('model_registry', ['id' => $current, 'status' => 'production']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'models.customer_rolled_back']);
    }

    public function test_an_artifact_not_visible_from_the_backend_is_accepted_and_reported_as_not_verified(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $old = $this->model($tenant, 'h30:probability', 'retired', 'v1', ['artifact_path' => '/volumen/del/motor/no-visible.joblib', 'artifact_sha256' => str_repeat('a', 64)]);
        $res = $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/models/customer/rollback', ['model_registry_id' => $old])->assertOk();
        $this->assertNull($res->json('data.artifact_sha256_verified'));          // el motor verifica el SHA-256 al cargarlo
    }

    public function test_invalid_targets_are_rejected_with_a_reason(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $api = $this->actingAsTenantUser($user, $tenant);
        $production = $this->model($tenant, 'h30:probability', 'production', 'v-prod');
        $failed = $this->model($tenant, 'h30:probability', 'failed', 'v-failed');
        $candidate = $this->model($tenant, 'h30:probability', 'candidate', 'v-candidate');
        $noSlot = $this->model($tenant, 'h30:probability', 'validated', 'v-noslot', ['hyperparameters' => json_encode(['kind' => 'complex'])]);
        $noArtifact = $this->model($tenant, 'h30:probability', 'validated', 'v-noart', [], false);
        $incompatible = $this->model($tenant, 'h30:probability', 'validated', 'v-incompat', ['artifact_compat' => json_encode(['feature_schema_version' => 'cust-fs-1999'])]);
        foreach ([[$production, 'rollback_already_current'], [$failed, 'rollback_target_not_restorable'], [$candidate, 'rollback_target_not_restorable'],
            [$noSlot, 'rollback_target_without_slot'], [$noArtifact, 'rollback_target_without_artifact'], [$incompatible, 'rollback_target_incompatible']] as [$id, $code]) {
            $api->postJson('/api/v1/models/customer/rollback', ['model_registry_id' => $id])->assertStatus(409)->assertJsonPath('code', $code);
        }
        $api->postJson('/api/v1/models/customer/rollback', [])->assertStatus(422)->assertJsonValidationErrors('model_registry_id');
        $api->postJson('/api/v1/models/customer/rollback', ['model_registry_id' => 99999999])->assertStatus(404);
        $this->assertDatabaseHas('model_registry', ['id' => $production, 'status' => 'production']);
    }

    public function test_the_plan_horizons_are_respected(): void
    {
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Owner, ['plan_id' => $this->basicPlanId()]);
        $h90 = $this->model($tenant, 'h90:probability', 'retired', 'v1-h90');
        $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/models/customer/rollback', ['model_registry_id' => $h90])
            ->assertStatus(402)->assertJsonPath('code', 'plan_limit_exceeded');
        $this->assertDatabaseHas('model_registry', ['id' => $h90, 'status' => 'retired']);
    }

    public function test_permissions_and_company_isolation(): void
    {
        [$a, $ua] = $this->createTenantWithUser();
        [$b, $ub] = $this->createTenantWithUser();
        $viewer = $this->addUserToTenant($a, TenantRole::Viewer);
        $ofB = $this->model($b, 'h30:probability', 'retired', 'vb-1');
        $ofA = $this->model($a, 'h30:probability', 'retired', 'va-1');
        $demand = (int) DB::table('model_registry')->insertGetId([
            'tenant_id' => $a->id, 'version' => 'va-demand', 'model_family' => 'naive', 'strategy_level' => 'A', 'scope' => 'tenant', 'status' => 'retired', 'core' => 'demand',
            'role' => 'primary', 'hyperparameters' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAsTenantUser($viewer, $a)->postJson('/api/v1/models/customer/rollback', ['model_registry_id' => $ofA])->assertStatus(403);
        $this->actingAsTenantUser($ua, $a)->postJson('/api/v1/models/customer/rollback', ['model_registry_id' => $ofB])->assertStatus(404);   // modelo de otra empresa
        $this->actingAsTenantUser($ua, $a)->postJson('/api/v1/models/customer/rollback', ['model_registry_id' => $demand])->assertStatus(404);  // modelo de otro core
        $this->assertDatabaseHas('model_registry', ['id' => $ofB, 'status' => 'retired']);
        $this->actingAsTenantUser($ub, $b)->postJson('/api/v1/models/customer/rollback', ['model_registry_id' => $ofB])->assertOk();
        $this->assertDatabaseHas('model_registry', ['id' => $ofA, 'status' => 'retired']);                                                     // A no se tocó
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/models/customer/rollback', ['model_registry_id' => $ofA])->assertStatus(401);
    }
}
