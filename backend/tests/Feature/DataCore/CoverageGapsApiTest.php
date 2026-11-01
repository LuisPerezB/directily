<?php

declare(strict_types=1);

namespace Tests\Feature\DataCore;

use App\Enums\TenantRole;
use App\Models\Branch;
use App\Models\DataCoverageGap;
use App\Models\DataSource;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DataCore\Coverage\CoverageService;
use App\Support\Tenancy\Exceptions\TenantNotResolvedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Huecos internos de cobertura (ADR-V25-23): GET/POST /data-coverage/gaps y DELETE /data-coverage/gaps/{id}. Sus días son
 * `missing_source`, nunca ceros. CRUD, validación (lista blanca, rango, fuente y sucursal de la misma empresa), habilidades y aislamiento.
 */
final class CoverageGapsApiTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private int $branchId;

    private int $sourceId;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->tenant, $this->owner] = $this->createTenantWithUser();
        $this->branchId = $this->createBranch($this->tenant, '001', 'Matriz')->id;
        $this->actingAsTenantUser($this->owner, $this->tenant);
        $this->sourceId = (int) $this->postJson('/api/v1/data-sources', ['type' => 'rest_api', 'name' => 'ERP central'])->assertCreated()->json('data.id');
    }

    /** @return array<string, mixed> */
    private function gap(array $overrides = []): array
    {
        return array_merge(['entity' => 'sales', 'gap_from' => '2026-03-01', 'gap_to' => '2026-03-10'], $overrides);
    }

    public function test_declare_list_filter_and_delete_a_gap(): void
    {
        $created = $this->postJson('/api/v1/data-coverage/gaps', $this->gap([
            'data_source_id' => $this->sourceId, 'branch_id' => $this->branchId, 'reason' => 'integration_outage', 'notes' => 'Caída del conector durante la migración',
        ]))->assertCreated();
        $created->assertJsonPath('data.entity', 'sales')->assertJsonPath('data.gap_from', '2026-03-01')->assertJsonPath('data.gap_to', '2026-03-10')
            ->assertJsonPath('data.days', 10)->assertJsonPath('data.reason', 'integration_outage')->assertJsonPath('data.basis', 'declared')
            ->assertJsonPath('data.data_source_id', $this->sourceId)->assertJsonPath('data.branch_id', $this->branchId)->assertJsonPath('data.created_by', $this->owner->id);
        $id = $created->json('data.id');

        // Sin fuente ni sucursal = toda la empresa; el motivo por defecto es «unknown».
        $companyWide = $this->postJson('/api/v1/data-coverage/gaps', $this->gap(['entity' => 'inventory', 'gap_from' => '2026-05-05', 'gap_to' => '2026-05-05']))->assertCreated();
        $companyWide->assertJsonPath('data.days', 1)->assertJsonPath('data.reason', 'unknown')->assertJsonPath('data.data_source_id', null)->assertJsonPath('data.branch_id', null);

        $this->getJson('/api/v1/data-coverage/gaps')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $id)->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/data-coverage/gaps?entity=inventory')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.entity', 'inventory');
        $this->getJson("/api/v1/data-coverage/gaps?data_source_id={$this->sourceId}&branch_id={$this->branchId}&basis=declared")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/data-coverage/gaps?from=2026-03-10&to=2026-03-20')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);   // se solapa en el extremo
        $this->getJson('/api/v1/data-coverage/gaps?from=2026-03-11&to=2026-05-04')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/data-coverage/gaps?basis=inferred')->assertOk()->assertJsonCount(0, 'data');

        $this->deleteJson("/api/v1/data-coverage/gaps/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('data_coverage_gaps', ['id' => $id]);
        $this->getJson('/api/v1/data-coverage/gaps')->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson("/api/v1/data-coverage/gaps/{$id}")->assertNotFound();

        $actions = DB::table('audit_logs')->where('tenant_id', $this->tenant->id)->whereIn('action', ['data_coverage.gap_declared', 'data_coverage.gap_deleted'])->pluck('action')->all();
        $this->assertEqualsCanonicalizing(['data_coverage.gap_declared', 'data_coverage.gap_declared', 'data_coverage.gap_deleted'], $actions);
    }

    public function test_entity_must_be_in_the_whitelist(): void
    {
        foreach (DataCoverageGap::ENTITIES as $entity) {
            $this->postJson('/api/v1/data-coverage/gaps', $this->gap(['entity' => $entity]))->assertCreated();
        }
        foreach (['calendar', 'externals', 'ventas', '', 'SALES'] as $invalid) {
            $this->postJson('/api/v1/data-coverage/gaps', $this->gap(['entity' => $invalid]))->assertUnprocessable()->assertJsonValidationErrors('entity');
        }
        $this->assertSame(count(DataCoverageGap::ENTITIES), DB::table('data_coverage_gaps')->count());
        $this->assertSame([], array_values(array_diff(DataCoverageGap::ENTITIES, CoverageService::ENTITIES)), 'las entidades de huecos son un subconjunto de las de cobertura');
    }

    public function test_range_and_field_validation(): void
    {
        $this->postJson('/api/v1/data-coverage/gaps', [])->assertUnprocessable()->assertJsonValidationErrors(['entity', 'gap_from', 'gap_to']);
        $this->postJson('/api/v1/data-coverage/gaps', $this->gap(['gap_from' => '2026-03-10', 'gap_to' => '2026-03-09']))->assertUnprocessable()->assertJsonValidationErrors('gap_to');
        $this->postJson('/api/v1/data-coverage/gaps', $this->gap(['gap_from' => '10/03/2026']))->assertUnprocessable()->assertJsonValidationErrors('gap_from');
        $this->postJson('/api/v1/data-coverage/gaps', $this->gap(['gap_to' => 'mañana']))->assertUnprocessable()->assertJsonValidationErrors('gap_to');
        $this->postJson('/api/v1/data-coverage/gaps', $this->gap(['gap_from' => '1980-01-01', 'gap_to' => '1980-01-05']))->assertUnprocessable()->assertJsonValidationErrors('gap_from');
        $this->postJson('/api/v1/data-coverage/gaps', $this->gap(['gap_from' => '2000-01-01', 'gap_to' => '2012-01-01']))->assertUnprocessable()->assertJsonValidationErrors('gap_to'); // > 3660 días
        $this->postJson('/api/v1/data-coverage/gaps', $this->gap(['reason' => 'porque si']))->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson('/api/v1/data-coverage/gaps', $this->gap(['notes' => str_repeat('x', 501)]))->assertUnprocessable()->assertJsonValidationErrors('notes');
        $this->postJson('/api/v1/data-coverage/gaps', $this->gap(['data_source_id' => 'abc']))->assertUnprocessable()->assertJsonValidationErrors('data_source_id');
        // Un solo día y el rango máximo son válidos.
        $this->postJson('/api/v1/data-coverage/gaps', $this->gap(['gap_from' => '2026-03-01', 'gap_to' => '2026-03-01']))->assertCreated();
        $this->postJson('/api/v1/data-coverage/gaps', $this->gap(['gap_from' => '2016-01-01', 'gap_to' => '2025-12-31']))->assertCreated();
        $this->assertSame(2, DB::table('data_coverage_gaps')->count());
    }

    public function test_user_gaps_are_always_declared_and_inferred_cannot_be_forged(): void
    {
        $this->postJson('/api/v1/data-coverage/gaps', $this->gap(['basis' => 'declared']))->assertCreated()->assertJsonPath('data.basis', 'declared');
        $this->postJson('/api/v1/data-coverage/gaps', $this->gap(['basis' => 'inferred']))->assertUnprocessable()->assertJsonValidationErrors('basis');
        $this->assertSame(['declared'], DB::table('data_coverage_gaps')->pluck('basis')->unique()->values()->all());
    }

    public function test_source_and_branch_must_belong_to_the_same_company_and_not_be_deleted(): void
    {
        [$other, $otherOwner] = $this->createTenantWithUser();
        $otherBranch = $this->createBranch($other, '001', 'Matriz B');
        $this->actingAsTenantUser($otherOwner, $other);
        $otherSource = (int) $this->postJson('/api/v1/data-sources', ['type' => 'rest_api', 'name' => 'ERP de B'])->assertCreated()->json('data.id');

        $this->actingAsTenantUser($this->owner, $this->tenant);
        $this->postJson('/api/v1/data-coverage/gaps', $this->gap(['data_source_id' => $otherSource]))->assertUnprocessable()->assertJsonValidationErrors('data_source_id');
        $this->postJson('/api/v1/data-coverage/gaps', $this->gap(['branch_id' => $otherBranch->id]))->assertUnprocessable()->assertJsonValidationErrors('branch_id');
        $this->postJson('/api/v1/data-coverage/gaps', $this->gap(['data_source_id' => 999999, 'branch_id' => 999999]))->assertUnprocessable()->assertJsonValidationErrors(['data_source_id', 'branch_id']);

        // Fuente y sucursal dadas de baja tampoco se pueden referenciar.
        $this->withinTenant($this->tenant, function (): void {
            DataSource::query()->findOrFail($this->sourceId)->delete();
            Branch::query()->findOrFail($this->branchId)->delete();
        });
        $this->postJson('/api/v1/data-coverage/gaps', $this->gap(['data_source_id' => $this->sourceId]))->assertUnprocessable()->assertJsonValidationErrors('data_source_id');
        $this->postJson('/api/v1/data-coverage/gaps', $this->gap(['branch_id' => $this->branchId]))->assertUnprocessable()->assertJsonValidationErrors('branch_id');
        $this->assertSame(0, DB::table('data_coverage_gaps')->count());
    }

    public function test_gaps_are_isolated_between_companies(): void
    {
        $mine = (int) $this->postJson('/api/v1/data-coverage/gaps', $this->gap())->assertCreated()->json('data.id');

        [$other, $otherOwner] = $this->createTenantWithUser();
        $this->actingAsTenantUser($otherOwner, $other);
        $this->getJson('/api/v1/data-coverage/gaps')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        $this->deleteJson("/api/v1/data-coverage/gaps/{$mine}")->assertNotFound();
        $this->assertDatabaseHas('data_coverage_gaps', ['id' => $mine]);

        // La otra empresa declara los suyos sin afectar los de la primera.
        $theirs = (int) $this->postJson('/api/v1/data-coverage/gaps', $this->gap(['gap_from' => '2026-04-01', 'gap_to' => '2026-04-02']))->assertCreated()->json('data.id');
        $this->getJson('/api/v1/data-coverage/gaps')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $theirs);
        $this->actingAsTenantUser($this->owner, $this->tenant);
        $this->getJson('/api/v1/data-coverage/gaps')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine);
        $this->deleteJson("/api/v1/data-coverage/gaps/{$theirs}")->assertNotFound();

        // El modelo es fail-closed: sin empresa activa no consulta.
        $this->expectException(TenantNotResolvedException::class);
        DataCoverageGap::query()->count();
    }

    public function test_abilities_viewer_reads_and_analyst_manages(): void
    {
        $gapId = (int) $this->postJson('/api/v1/data-coverage/gaps', $this->gap())->assertCreated()->json('data.id');

        $viewer = $this->addUserToTenant($this->tenant, TenantRole::Viewer);
        $this->actingAsTenantUser($viewer, $this->tenant);
        $this->getJson('/api/v1/data-coverage/gaps')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/data-coverage/gaps', $this->gap())->assertForbidden()->assertJsonPath('required_ability', 'imports.manage');
        $this->deleteJson("/api/v1/data-coverage/gaps/{$gapId}")->assertForbidden();
        $this->assertDatabaseHas('data_coverage_gaps', ['id' => $gapId]);

        $analyst = $this->addUserToTenant($this->tenant, TenantRole::Analyst);
        $this->actingAsTenantUser($analyst, $this->tenant);
        $created = $this->postJson('/api/v1/data-coverage/gaps', $this->gap(['gap_from' => '2026-06-01', 'gap_to' => '2026-06-03']))->assertCreated();
        $created->assertJsonPath('data.created_by', $analyst->id);
        $this->deleteJson('/api/v1/data-coverage/gaps/'.$created->json('data.id'))->assertNoContent();
    }

    public function test_requires_authentication_and_an_active_company(): void
    {
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['X-Tenant-ID' => $this->tenant->uuid])->getJson('/api/v1/data-coverage/gaps')->assertUnauthorized();
    }
}
