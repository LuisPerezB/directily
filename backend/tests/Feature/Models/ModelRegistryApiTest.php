<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Models\Support\ModelRegistryFixtures;
use Tests\TestCase;

/** GET /models (filtros core, estado, familia) y GET /models/{id} con eventos; aislamiento entre empresas; verificación del objeto publicado. */
class ModelRegistryApiTest extends TestCase
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

    private function ids(array $c, string $query = ''): array
    {
        $ids = collect($this->actingAsTenantUser($c['viewer'], $c['tenant'])->getJson('/api/v1/models'.$query)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        return $ids;
    }

    public function test_the_listing_filters_by_core_status_and_family_and_never_shows_another_company(): void
    {
        $c = $this->registryCompany();
        $other = $this->registryCompany();
        $lgbm = $this->customerModel($c['tenant'], 'production', ['core' => 'demand', 'model_family' => 'lightgbm_global', 'hyperparameters' => '{}'], false);
        $naive = $this->customerModel($c['tenant'], 'production', ['core' => 'demand', 'model_family' => 'naive', 'hyperparameters' => '{}', 'role' => 'fallback'], false);
        $cand = $this->publishedCandidate($c['tenant'], $c['publisher']);
        $approved = $this->customerModel($c['tenant'], 'approved', ['approved_at' => now(), 'approved_by' => $c['approver']->id, 'published_at' => now()], false);
        $rejected = $this->customerModel($c['tenant'], 'rejected', ['rejected_reason' => 'No cumple', 'published_at' => now()], false);
        $retired = $this->customerModel($c['tenant'], 'retired', [], false);
        $foreign = $this->customerModel($other['tenant'], 'production', ['core' => 'demand', 'model_family' => 'lightgbm_global', 'hyperparameters' => '{}'], false);

        $all = [$lgbm, $naive, $cand, $approved, $rejected, $retired];
        sort($all);
        $this->assertSame($all, $this->ids($c));
        $this->assertNotContains($foreign, $this->ids($c));
        $this->assertSame([$lgbm], $this->ids($c, '?family=lightgbm_global'));
        $this->assertSame([$lgbm, $naive], $this->ids($c, '?core=demand'));
        $this->assertSame([$lgbm], $this->ids($c, '?core=demand&status=production&role=primary&family=lightgbm_global'));
        $this->assertSame([$cand], $this->ids($c, '?status=candidate'));
        $this->assertSame([$approved], $this->ids($c, '?status=approved'));
        $this->assertSame([$retired], $this->ids($c, '?status=retired'));
        $this->assertSame([$rejected], $this->ids($c, '?status=rejected&core=customer&family=customer_h30_probability'), 'sin validados: el alias heredado devuelve el rechazado real');
        $this->assertSame([], $this->ids($c, '?family=inexistente'));
        $this->actingAsTenantUser($c['viewer'], $c['tenant'])->getJson('/api/v1/models?family=Bad-Family!')->assertStatus(422)->assertJsonValidationErrors('family');
        $this->actingAsTenantUser($c['viewer'], $c['tenant'])->getJson('/api/v1/models?status=pendiente')->assertStatus(422)->assertJsonValidationErrors('status');
        // la lista de la otra empresa solo trae la suya
        $this->assertSame([$foreign], $this->ids($other));
    }

    public function test_the_legacy_rejected_alias_returns_stage1_validated_models_and_real_rejections_with_legacy_status(): void
    {
        $c = $this->registryCompany();
        $validated = $this->customerModel($c['tenant'], 'validated', [], false);
        $rejected = $this->customerModel($c['tenant'], 'rejected', ['published_at' => now()], false);
        $production = $this->customerModel($c['tenant'], 'production', [], false);
        $rows = collect($this->actingAsTenantUser($c['viewer'], $c['tenant'])->getJson('/api/v1/models?status=rejected')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame([$validated, $rejected], $rows->keys()->sort()->values()->all());
        $this->assertNotContains($production, $rows->keys()->all());
        $this->assertSame('rejected', $rows[$validated]['legacy_status']);
        $this->assertSame('rejected', $rows[$rejected]['legacy_status']);
        $this->assertSame('validated', $rows[$validated]['status']);
    }

    public function test_the_detail_includes_the_lifecycle_the_events_and_the_policy_decision_but_not_the_storage_key(): void
    {
        $c = $this->registryCompany();
        $id = $this->publishedCandidate($c['tenant'], $c['publisher'], ['purpose' => 'Compra a 30 días', 'granularity' => 'customer_sku']);
        $keyA = (string) DB::table('model_registry')->where('id', $id)->value('published_object_key');
        $decision = $this->promotionDecision($c['tenant'], $id, 'promote');
        DB::table('model_registry')->where('id', $id)->update(['promotion_decision_id' => $decision]);
        Storage::disk('local')->delete($keyA);   // el objeto no está en el almacén: no se puede verificar la firma
        $this->actingAsTenantUser($c['analyst'], $c['tenant'])->postJson("/api/v1/models/{$id}/validate")->assertStatus(409)->assertJsonPath('code', 'approval_artifact_not_verifiable');

        $res = $this->actingAsTenantUser($c['viewer'], $c['tenant'])->getJson("/api/v1/models/{$id}")->assertOk();
        $res->assertJsonPath('data.id', $id)->assertJsonPath('data.status', 'candidate')->assertJsonPath('data.purpose', 'Compra a 30 días')
            ->assertJsonPath('data.granularity', 'customer_sku')->assertJsonPath('data.published_by', $c['publisher']->id)->assertJsonPath('data.published_env', 'staging')
            ->assertJsonPath('data.serving_basis', 'not_approved')->assertJsonPath('data.published', true)
            ->assertJsonPath('data.promotion_decision.id', $decision)->assertJsonPath('data.promotion_decision.decision', 'promote')->assertJsonPath('data.promotion_decision.approved_by', null);
        $this->assertSame(['cli'], array_column($res->json('data.events'), 'actor_kind'), 'una validación rechazada no deja más eventos que el de la CLI');
        $this->assertStringNotContainsString('published_object_key', $res->getContent());
        $this->assertStringNotContainsString($keyA, $res->getContent());

        // Tras operar, los eventos aparecen en orden con su actor y evidencia.
        $ok = $this->publishedCandidate($c['tenant'], $c['publisher']);
        $this->actingAsTenantUser($c['analyst'], $c['tenant'])->postJson("/api/v1/models/{$ok}/validate")->assertOk();
        $this->actingAsTenantUser($c['approver'], $c['tenant'])->postJson("/api/v1/models/{$ok}/approve", ['reason' => 'Revisado'])->assertOk();
        $events = $this->actingAsTenantUser($c['viewer'], $c['tenant'])->getJson("/api/v1/models/{$ok}")->assertOk()->json('data.events');
        $this->assertSame([[null, 'candidate'], ['candidate', 'validated'], ['validated', 'approved']], array_map(fn ($e) => [$e['from_status'], $e['to_status']], $events));
        $this->assertSame(['cli', 'user', 'user'], array_column($events, 'actor_kind'));
        $this->assertSame([$c['publisher']->id, $c['analyst']->id, $c['approver']->id], array_column($events, 'actor_user_id'));
        $this->assertSame('Revisado', $events[2]['reason']);
        $this->assertSame('verified', $events[2]['evidence']['gates']['signature']['status']);
    }

    public function test_the_detail_of_a_model_of_another_company_or_a_missing_one_is_404(): void
    {
        $c = $this->registryCompany();
        $other = $this->registryCompany();
        $foreign = $this->customerModel($other['tenant'], 'production', [], false);
        $this->actingAsTenantUser($c['owner'], $c['tenant'])->getJson("/api/v1/models/{$foreign}")->assertStatus(404);
        $this->actingAsTenantUser($c['owner'], $c['tenant'])->getJson('/api/v1/models/99999999')->assertStatus(404);
        $this->actingAsTenantUser($c['owner'], $c['tenant'])->getJson('/api/v1/models/abc')->assertStatus(404);
    }

    // ───────────────────────── objeto publicado en el almacén ─────────────────────────

    /** Un publicado cuya clave se fija a mano (para probar claves ajenas, ausentes o con rutas relativas). */
    private function publishedWithKey(array $c, string $key, string $sha): int
    {
        return $this->publishedCandidate($c['tenant'], $c['publisher'], ['published_object_key' => $key, 'artifact_sha256' => $sha]);
    }

    public function test_the_signature_of_a_published_object_is_verified_from_the_object_store(): void
    {
        $c = $this->registryCompany();
        $id = $this->publishedCandidate($c['tenant'], $c['publisher']);

        $this->actingAsTenantUser($c['analyst'], $c['tenant'])->postJson("/api/v1/models/{$id}/validate")->assertOk();
        $this->actingAsTenantUser($c['approver'], $c['tenant'])->postJson("/api/v1/models/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $signature = collect($this->eventsOf($id))->last()['evidence']['gates']['signature'];
        $this->assertSame(['verified', 'object_store'], [$signature['status'], $signature['source']]);
        // No quedan copias temporales de la verificación.
        $this->assertSame([], glob(sys_get_temp_dir().'/predictily-approval-*') ?: []);
    }

    public function test_a_published_object_that_was_altered_or_is_missing_or_belongs_to_another_company_is_not_approved(): void
    {
        $c = $this->registryCompany();
        $other = $this->registryCompany();
        $approve = fn (int $id) => $this->actingAsTenantUser($c['approver'], $c['tenant'])->postJson("/api/v1/models/{$id}/approve");

        // alterado en el almacén después de publicarse
        $altered = $this->publishedCandidate($c['tenant'], $c['publisher']);
        $this->customerValidated($altered);
        Storage::disk('local')->put((string) DB::table('model_registry')->where('id', $altered)->value('published_object_key'), 'alterado en el almacén');
        $approve($altered)->assertStatus(409)->assertJsonPath('code', 'approval_artifact_mismatch');

        // el objeto no existe
        $gone = $this->publishedWithKey($c, 'tenants/'.$c['tenant']->uuid.'/models/customer/v2/falta.joblib', str_repeat('a', 64));
        $this->customerValidated($gone);
        $approve($gone)->assertStatus(409)->assertJsonPath('code', 'approval_artifact_not_verifiable');

        // una clave con el prefijo de OTRA empresa jamás se lee
        [$foreignKey, $foreignSha] = $this->publishObject($other['tenant'], 'ajeno');
        $foreign = $this->publishedWithKey($c, $foreignKey, $foreignSha);
        $this->customerValidated($foreign);
        $approve($foreign)->assertStatus(409)->assertJsonPath('code', 'approval_object_key_invalid');

        // claves con rutas relativas o fuera de /models/ tampoco
        foreach (['tenants/'.$c['tenant']->uuid.'/models/../bronze/x.csv', 'tenants/'.$c['tenant']->uuid.'/bronze/x.joblib', '/etc/passwd', 'tenants/'.$c['tenant']->uuid.'/models//x.joblib'] as $bad) {
            $badId = $this->publishedWithKey($c, $bad, str_repeat('a', 64));
            $this->customerValidated($badId);
            $approve($badId)->assertStatus(409)->assertJsonPath('code', 'approval_object_key_invalid');
        }
        $this->assertSame(0, DB::table('model_registry')->where('tenant_id', $c['tenant']->id)->where('status', 'approved')->count());
    }

    private function customerValidated(int $id): void
    {
        DB::table('model_registry')->where('id', $id)->update(['status' => 'validated']);
    }
}
