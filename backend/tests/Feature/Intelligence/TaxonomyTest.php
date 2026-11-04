<?php

declare(strict_types=1);

namespace Tests\Feature\Intelligence;

use App\Enums\TenantRole;
use App\Models\Product;
use App\Models\ProductTaxonomyAssignment;
use App\Models\TaxonomyNode;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Intelligence\Exceptions\IntelligenceRuleException;
use App\Services\Intelligence\SectorProfileService;
use App\Services\Intelligence\TaxonomyService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Intelligence\Support\IntelligenceFixtures;
use Tests\TestCase;

/** Taxonomía básica (T13): jerarquía válida, versiones, vínculo con el ERP, procedencia de las asignaciones, historial y aislamiento. */
final class TaxonomyTest extends TestCase
{
    use IntelligenceFixtures, RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private User $analyst;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00:00', 'America/Guayaquil'));
        [$this->tenant, $this->owner] = $this->createTenantWithUser(TenantRole::Owner);
        $this->analyst = $this->addUserToTenant($this->tenant, TenantRole::Analyst);
        $this->actingAsTenantUser($this->analyst, $this->tenant);
    }

    private function node(string $level, string $code, ?int $parent = null, array $extra = []): int
    {
        return (int) $this->postJson('/api/v1/intelligence/taxonomy/nodes', array_merge(['level' => $level, 'code' => $code, 'name' => 'Nodo '.$code, 'parent_id' => $parent], $extra))
            ->assertCreated()->json('data.id');
    }

    /** @return array{sector: int, industry: int, category: int, subcategory: int} */
    private function chain(string $prefix = 'bebidas'): array
    {
        $sector = $this->node('sector', "{$prefix}-s");
        $industry = $this->node('industry', "{$prefix}-i", $sector);
        $category = $this->node('category', "{$prefix}-c", $industry);
        $sub = $this->node('subcategory', "{$prefix}-sc", $category);

        return ['sector' => $sector, 'industry' => $industry, 'category' => $category, 'subcategory' => $sub];
    }

    public function test_a_valid_four_level_hierarchy_can_be_built_with_an_erp_category_link(): void
    {
        $erpCategory = $this->insertCategory($this->tenant, 'Bebidas ERP');

        $sector = $this->node('sector', 'bebidas');
        $industry = $this->node('industry', 'agua-embotellada', $sector);
        $category = $this->node('category', 'agua-sin-gas', $industry, ['erp_category_id' => $erpCategory, 'attributes' => ['presentaciones' => ['500 ml', '1 L']]]);
        $sub = $this->node('subcategory', 'agua-500ml', $category);

        $this->getJson("/api/v1/intelligence/taxonomy/nodes/{$category}")->assertOk()->assertJsonPath('data.erp_category_id', $erpCategory)->assertJsonPath('data.level', 'category')
            ->assertJsonPath('data.attributes.presentaciones.1', '1 L')->assertJsonPath('data.version', 1)->assertJsonPath('data.scope', 'tenant');
        $this->assertSame($category, (int) DB::table('taxonomy_nodes')->where('id', $sub)->value('parent_id'));
        $this->getJson('/api/v1/intelligence/taxonomy/nodes?level=industry')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/intelligence/taxonomy/nodes?parent_id={$category}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'agua-500ml');
    }

    public function test_the_hierarchy_levels_are_enforced(): void
    {
        $sector = $this->node('sector', 'agro');
        $industry = $this->node('industry', 'cultivos', $sector);
        $category = $this->node('category', 'banano', $industry);

        // Una industria sin padre, una categoría directamente bajo el sector, o un sector con padre: jerarquía inválida.
        $this->postJson('/api/v1/intelligence/taxonomy/nodes', ['level' => 'industry', 'code' => 'huerfana', 'name' => 'Huérfana'])->assertStatus(422)->assertJsonPath('code', 'taxonomy_parent_required');
        $this->postJson('/api/v1/intelligence/taxonomy/nodes', ['level' => 'category', 'code' => 'salta-nivel', 'name' => 'Salta nivel', 'parent_id' => $sector])->assertStatus(422)->assertJsonPath('code', 'taxonomy_invalid_hierarchy');
        $this->postJson('/api/v1/intelligence/taxonomy/nodes', ['level' => 'sector', 'code' => 'sector-con-padre', 'name' => 'Nodo X', 'parent_id' => $sector])->assertStatus(422)->assertJsonPath('code', 'taxonomy_invalid_hierarchy');
        // Debajo de una subcategoría no hay más niveles.
        $sub = $this->node('subcategory', 'cavendish', $category);
        $this->postJson('/api/v1/intelligence/taxonomy/nodes', ['level' => 'subcategory', 'code' => 'mas-abajo', 'name' => 'Nodo X', 'parent_id' => $sub])->assertStatus(422)->assertJsonPath('code', 'taxonomy_invalid_hierarchy');
        $this->postJson('/api/v1/intelligence/taxonomy/nodes', ['level' => 'producto', 'code' => 'x', 'name' => 'Nodo X'])->assertStatus(422);
        $this->postJson('/api/v1/intelligence/taxonomy/nodes', ['level' => 'sector', 'code' => 'código inválido!', 'name' => 'Nodo X'])->assertStatus(422);
        $this->postJson('/api/v1/intelligence/taxonomy/nodes', ['level' => 'sector', 'code' => 'agro', 'name' => 'Duplicado'])->assertStatus(409)->assertJsonPath('code', 'taxonomy_code_taken');
        $this->postJson('/api/v1/intelligence/taxonomy/nodes', ['level' => 'industry', 'code' => 'padre-inexistente', 'name' => 'Nodo X', 'parent_id' => 999999])->assertStatus(422)->assertJsonPath('code', 'taxonomy_parent_not_found');
    }

    public function test_a_node_can_be_versioned_without_rewriting_history(): void
    {
        $chain = $this->chain();
        $productId = $this->insertProduct($this->tenant);
        $assignment = $this->postJson('/api/v1/intelligence/taxonomy/assignments', ['product_id' => $productId, 'taxonomy_node_id' => $chain['subcategory'], 'valid_from' => '2026-01-01'])->assertCreated()->json('data');

        $v2 = $this->postJson("/api/v1/intelligence/taxonomy/nodes/{$chain['subcategory']}/versions", ['name' => 'Subcategoría renombrada', 'valid_from' => '2026-11-01'])->assertCreated();
        $v2->assertJsonPath('data.version', 2)->assertJsonPath('data.code', 'bebidas-sc')->assertJsonPath('data.name', 'Subcategoría renombrada')->assertJsonPath('data.valid_from', '2026-11-01')->assertJsonPath('data.valid_to', null);

        // La versión 1 queda cerrada el día anterior y la asignación sigue apuntando a ella (el historial no se reescribe).
        $this->getJson("/api/v1/intelligence/taxonomy/nodes/{$chain['subcategory']}")->assertJsonPath('data.valid_to', '2026-10-31')->assertJsonPath('data.name', 'Nodo bebidas-sc');
        $this->assertSame($chain['subcategory'], DB::table('product_taxonomy_assignments')->where('id', $assignment['id'])->value('taxonomy_node_id'));
        // Solo se versiona la versión más reciente; el código sigue siendo único por versión.
        $this->postJson("/api/v1/intelligence/taxonomy/nodes/{$chain['subcategory']}/versions", ['name' => 'Otra'])->assertStatus(409)->assertJsonPath('code', 'taxonomy_not_latest_version');
        $this->assertSame(2, DB::table('taxonomy_nodes')->where('tenant_id', $this->tenant->id)->where('code', 'bebidas-sc')->count());
        // Hasta el 31 de octubre rige la versión 1; desde el 1 de noviembre, solo la 2.
        $this->getJson('/api/v1/intelligence/taxonomy/nodes?code=bebidas-sc&current_only=1')->assertOk()->assertJsonCount(2, 'data');
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00:00', 'America/Guayaquil'));
        $this->getJson('/api/v1/intelligence/taxonomy/nodes?code=bebidas-sc&current_only=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.version', 2);
    }

    public function test_nodes_with_children_or_assigned_products_cannot_be_deleted(): void
    {
        $chain = $this->chain();
        $productId = $this->insertProduct($this->tenant);
        $this->postJson('/api/v1/intelligence/taxonomy/assignments', ['product_id' => $productId, 'taxonomy_node_id' => $chain['subcategory']])->assertCreated();

        $this->deleteJson("/api/v1/intelligence/taxonomy/nodes/{$chain['sector']}")->assertStatus(409)->assertJsonPath('code', 'taxonomy_node_has_children');
        $this->deleteJson("/api/v1/intelligence/taxonomy/nodes/{$chain['subcategory']}")->assertStatus(409)->assertJsonPath('code', 'taxonomy_node_in_use');
        $lonely = $this->node('sector', 'solitario');
        $this->deleteJson("/api/v1/intelligence/taxonomy/nodes/{$lonely}")->assertOk();
        $this->assertSame(0, DB::table('taxonomy_nodes')->where('id', $lonely)->count());
    }

    public function test_node_edits_are_validated_and_audited(): void
    {
        $chain = $this->chain();
        $this->patchJson("/api/v1/intelligence/taxonomy/nodes/{$chain['category']}", ['name' => 'Nombre nuevo', 'attributes' => ['uso' => 'consumo humano']])->assertOk()->assertJsonPath('data.name', 'Nombre nuevo');
        $this->patchJson("/api/v1/intelligence/taxonomy/nodes/{$chain['category']}", ['valid_from' => '2026-05-01', 'valid_to' => '2026-04-01'])->assertStatus(422)->assertJsonPath('code', 'taxonomy_invalid_validity');
        $this->patchJson("/api/v1/intelligence/taxonomy/nodes/{$chain['category']}", ['erp_category_id' => 987654])->assertStatus(422)->assertJsonPath('code', 'erp_category_not_found');
        $this->assertGreaterThanOrEqual(1, DB::table('audit_logs')->where('action', 'intelligence.taxonomy_node_updated')->count());
        $this->assertSame(4, DB::table('audit_logs')->where('action', 'intelligence.taxonomy_node_created')->count());
    }

    // ── Asignaciones y procedencia ──

    public function test_a_manual_assignment_is_usable_and_the_canonical_sku_is_untouched(): void
    {
        $chain = $this->chain();
        $productId = $this->insertProduct($this->tenant, 'AGUA-500', 'Agua 500 ml');

        $created = $this->postJson('/api/v1/intelligence/taxonomy/assignments', ['product_id' => $productId, 'taxonomy_node_id' => $chain['subcategory'], 'confidence' => 0.9])->assertCreated();
        $created->assertJsonPath('data.provenance', 'manual')->assertJsonPath('data.usable', true)->assertJsonPath('data.pending_review', false)->assertJsonPath('data.node.code', 'bebidas-sc');

        // El SKU, el nombre y el id canónicos del producto no cambian: la taxonomía solo agrega contexto.
        $product = $this->withinTenant($this->tenant, fn () => Product::query()->findOrFail($productId));
        $this->assertSame('AGUA-500', $product->sku);
        $this->assertSame('Agua 500 ml', $product->name);
        $resolved = $this->withinTenant($this->tenant, fn () => app(TaxonomyService::class)->resolveForProduct($productId));
        $this->assertSame($chain['subcategory'], $resolved?->id);
    }

    public function test_an_llm_proposal_is_pending_review_and_never_used_as_truth_until_a_user_verifies_it(): void
    {
        $chain = $this->chain();
        $productId = $this->insertProduct($this->tenant);
        $wrongNode = $this->node('sector', 'clasificacion-equivocada');
        $service = app(TaxonomyService::class);

        // Una propuesta (p. ej. de un LLM; este paquete no llama a ninguno) queda candidata con procedencia.
        $proposal = $this->withinTenant($this->tenant, fn () => $service->proposeAssignment(null, ['product_id' => $productId, 'taxonomy_node_id' => $wrongNode, 'confidence' => 0.55]));
        $this->assertSame('llm_proposed', $proposal->provenance);
        $this->assertNull($proposal->proposed_by);

        $listed = $this->getJson('/api/v1/intelligence/taxonomy/assignments?pending_review=1')->assertOk();
        $listed->assertJsonCount(1, 'data')->assertJsonPath('data.0.pending_review', true)->assertJsonPath('data.0.usable', false)->assertJsonPath('data.0.provenance', 'llm_proposed');
        $this->getJson('/api/v1/intelligence/taxonomy/assignments?usable=1')->assertOk()->assertJsonCount(0, 'data');
        // Aunque sea la única asignación del producto, el motor/pantallas NO la tratan como verdad.
        $this->assertNull($this->withinTenant($this->tenant, fn () => $service->resolveForProduct($productId)));
        $this->assertCount(1, $this->withinTenant($this->tenant, fn () => $service->pendingReview()));

        // Un usuario la verifica: pasa a `verified`, con quién y cuándo, y entonces sí cuenta.
        $this->postJson("/api/v1/intelligence/taxonomy/assignments/{$proposal->id}/verify")->assertOk()
            ->assertJsonPath('data.provenance', 'verified')->assertJsonPath('data.usable', true)->assertJsonPath('data.pending_review', false)->assertJsonPath('data.verified_by', $this->analyst->id);
        $this->assertSame($wrongNode, $this->withinTenant($this->tenant, fn () => $service->resolveForProduct($productId))?->id);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'intelligence.taxonomy_assignment_verified')->count());
        // Solo una propuesta se verifica; una vigente se cierra, no se rechaza.
        $this->postJson("/api/v1/intelligence/taxonomy/assignments/{$proposal->id}/verify")->assertStatus(409)->assertJsonPath('code', 'assignment_not_proposed');
        $this->deleteJson("/api/v1/intelligence/taxonomy/assignments/{$proposal->id}")->assertStatus(409);
    }

    public function test_a_wrong_llm_proposal_can_be_rejected_and_leaves_no_truth_behind(): void
    {
        $chain = $this->chain();
        $productId = $this->insertProduct($this->tenant);
        $manual = $this->postJson('/api/v1/intelligence/taxonomy/assignments', ['product_id' => $productId, 'taxonomy_node_id' => $chain['subcategory']])->assertCreated()->json('data.id');
        $wrong = $this->node('sector', 'otro-sector');
        $proposal = $this->withinTenant($this->tenant, fn () => app(TaxonomyService::class)->proposeAssignment($this->analyst, ['product_id' => $productId, 'taxonomy_node_id' => $wrong]));
        $this->assertSame($this->analyst->id, $proposal->proposed_by);

        // Con una propuesta pendiente, la verdad sigue siendo la asignación manual.
        $this->assertSame($chain['subcategory'], $this->withinTenant($this->tenant, fn () => app(TaxonomyService::class)->resolveForProduct($productId))?->id);
        $this->deleteJson("/api/v1/intelligence/taxonomy/assignments/{$proposal->id}")->assertOk();

        $this->assertSame(0, DB::table('product_taxonomy_assignments')->where('id', $proposal->id)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'intelligence.taxonomy_assignment_rejected')->count());
        $this->assertSame(1, DB::table('product_taxonomy_assignments')->where('id', $manual)->count());
    }

    public function test_assignment_history_is_kept_by_validity_and_overlaps_are_rejected(): void
    {
        $chain = $this->chain();
        $other = $this->node('sector', 'sector-nuevo');
        $productId = $this->insertProduct($this->tenant);
        $first = $this->postJson('/api/v1/intelligence/taxonomy/assignments', ['product_id' => $productId, 'taxonomy_node_id' => $chain['subcategory'], 'valid_from' => '2026-01-01'])->assertCreated()->json('data.id');

        // Una nueva primaria posterior cierra la abierta anterior el día previo (historial por vigencia).
        $second = $this->postJson('/api/v1/intelligence/taxonomy/assignments', ['product_id' => $productId, 'taxonomy_node_id' => $other, 'valid_from' => '2026-09-01'])->assertCreated()->json('data.id');
        $this->assertSame('2026-08-31', DB::table('product_taxonomy_assignments')->where('id', $first)->value('valid_to'));
        $service = app(TaxonomyService::class);
        $this->assertSame($chain['subcategory'], $this->withinTenant($this->tenant, fn () => $service->resolveForProduct($productId, CarbonImmutable::parse('2026-06-15')))?->id);
        $this->assertSame($other, $this->withinTenant($this->tenant, fn () => $service->resolveForProduct($productId, CarbonImmutable::parse('2026-10-15')))?->id);
        $this->assertNull($this->withinTenant($this->tenant, fn () => $service->resolveForProduct($productId, CarbonImmutable::parse('2025-12-31'))));
        $this->getJson("/api/v1/intelligence/taxonomy/assignments?product_id={$productId}&as_of=2026-06-15")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first);

        // Una vigencia que se solapa con la actual (no cerrable) se rechaza.
        $this->postJson('/api/v1/intelligence/taxonomy/assignments', ['product_id' => $productId, 'taxonomy_node_id' => $chain['category'], 'valid_from' => '2026-03-01', 'valid_to' => '2026-04-01'])
            ->assertStatus(409)->assertJsonPath('code', 'overlapping_primary_assignment');
        // Una no primaria (secundaria) puede coexistir.
        $this->postJson('/api/v1/intelligence/taxonomy/assignments', ['product_id' => $productId, 'taxonomy_node_id' => $chain['category'], 'is_primary' => false])->assertCreated();
        // Cerrar una asignación con valid_to conserva el registro.
        $this->patchJson("/api/v1/intelligence/taxonomy/assignments/{$second}", ['valid_to' => '2026-12-31'])->assertOk()->assertJsonPath('data.valid_to', '2026-12-31');
        $this->patchJson("/api/v1/intelligence/taxonomy/assignments/{$second}", ['valid_to' => '2026-01-01'])->assertStatus(422)->assertJsonPath('code', 'taxonomy_invalid_validity');
    }

    public function test_multiple_sector_profiles_with_priority_and_scope(): void
    {
        $owner = $this->owner;
        $categoryId = $this->insertCategory($this->tenant, 'Lácteos');
        $productId = $this->insertProduct($this->tenant);
        $branchId = $this->createBranch($this->tenant, '001', 'Matriz')->id;
        $this->actingAsTenantUser($owner, $this->tenant);

        $this->postJson('/api/v1/intelligence/sector-profiles', ['profile_key' => 'manufacturing', 'priority' => 50])->assertCreated()->assertJsonPath('data.scope', 'tenant');
        $this->postJson('/api/v1/intelligence/sector-profiles', ['profile_key' => 'retail', 'priority' => 10, 'scope' => 'branch', 'scope_ref' => (string) $branchId])->assertCreated();
        $this->postJson('/api/v1/intelligence/sector-profiles', ['profile_key' => 'agro', 'priority' => 20, 'scope' => 'category', 'scope_ref' => (string) $categoryId])->assertCreated();
        $this->postJson('/api/v1/intelligence/sector-profiles', ['profile_key' => 'export', 'priority' => 5, 'scope' => 'product', 'scope_ref' => (string) $productId])->assertCreated();
        $this->postJson('/api/v1/intelligence/sector-profiles', ['profile_key' => 'retail', 'priority' => 99, 'scope' => 'tenant'])->assertCreated();

        $this->getJson('/api/v1/intelligence/sector-profiles')->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('data.0.profile_key', 'export');
        // Duplicados (mismo perfil y alcance) y referencias inexistentes se rechazan.
        $this->postJson('/api/v1/intelligence/sector-profiles', ['profile_key' => 'manufacturing'])->assertStatus(409)->assertJsonPath('code', 'sector_profile_duplicate');
        $this->postJson('/api/v1/intelligence/sector-profiles', ['profile_key' => 'retail', 'scope' => 'branch', 'scope_ref' => '987654'])->assertStatus(422)->assertJsonPath('code', 'scope_ref_not_found');
        $this->postJson('/api/v1/intelligence/sector-profiles', ['profile_key' => 'retail', 'scope' => 'product'])->assertStatus(422)->assertJsonPath('code', 'scope_ref_required');
        $this->postJson('/api/v1/intelligence/sector-profiles', ['profile_key' => 'retail', 'scope' => 'tenant', 'scope_ref' => '1'])->assertStatus(422)->assertJsonPath('code', 'scope_ref_not_allowed');
        $this->postJson('/api/v1/intelligence/sector-profiles', ['profile_key' => 'Mal Formato'])->assertStatus(422);

        // Resolución: el alcance más específico primero y, a igual alcance, la prioridad menor.
        $service = app(SectorProfileService::class);
        $applicable = $this->withinTenant($this->tenant, fn () => $service->applicable($branchId, $categoryId, $productId)->map(fn ($p) => $p->profile_key.':'.$p->scope)->all());
        $this->assertSame(['export:product', 'agro:category', 'retail:branch', 'manufacturing:tenant', 'retail:tenant'], $applicable);
        $onlyTenant = $this->withinTenant($this->tenant, fn () => $service->applicable()->map(fn ($p) => $p->profile_key)->all());
        $this->assertSame(['manufacturing', 'retail'], $onlyTenant);

        // Desactivar o cambiar la prioridad; borrar.
        $id = (int) $this->getJson('/api/v1/intelligence/sector-profiles')->json('data.0.id');
        $this->patchJson("/api/v1/intelligence/sector-profiles/{$id}", ['is_active' => false, 'priority' => 7])->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertSame(['agro:category', 'retail:branch', 'manufacturing:tenant', 'retail:tenant'], $this->withinTenant($this->tenant, fn () => $service->applicable($branchId, $categoryId, $productId)->map(fn ($p) => $p->profile_key.':'.$p->scope)->all()));
        $this->deleteJson("/api/v1/intelligence/sector-profiles/{$id}")->assertOk();
        $this->getJson('/api/v1/intelligence/sector-profiles')->assertOk()->assertJsonCount(4, 'data');
        // Solo propietario/administrador gestionan perfiles; el analista los ve.
        $this->actingAsTenantUser($this->analyst, $this->tenant)->postJson('/api/v1/intelligence/sector-profiles', ['profile_key' => 'retail', 'scope' => 'category', 'scope_ref' => (string) $categoryId])->assertStatus(403);
        $this->getJson('/api/v1/intelligence/sector-profiles')->assertOk();
    }

    // ── Aislamiento entre empresas ──

    public function test_taxonomy_is_isolated_between_companies(): void
    {
        $chainA = $this->chain('empresa-a');
        $productA = $this->insertProduct($this->tenant, 'A-1');
        $assignmentA = $this->postJson('/api/v1/intelligence/taxonomy/assignments', ['product_id' => $productA, 'taxonomy_node_id' => $chainA['subcategory']])->assertCreated()->json('data.id');
        $profileA = $this->actingAsTenantUser($this->owner, $this->tenant)->postJson('/api/v1/intelligence/sector-profiles', ['profile_key' => 'retail'])->assertCreated()->json('data.id');

        [$tenantB, $ownerB] = $this->createTenantWithUser();
        $productB = $this->insertProduct($tenantB, 'B-1');
        $this->actingAsTenantUser($ownerB, $tenantB);

        // La empresa B no ve los nodos, asignaciones ni perfiles de A…
        $this->getJson('/api/v1/intelligence/taxonomy/nodes')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/intelligence/taxonomy/nodes/{$chainA['sector']}")->assertStatus(404);
        $this->getJson('/api/v1/intelligence/taxonomy/assignments')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/intelligence/sector-profiles')->assertOk()->assertJsonCount(0, 'data');
        // … ni puede modificarlos, borrarlos, versionarlos o verificarlos.
        $this->patchJson("/api/v1/intelligence/taxonomy/nodes/{$chainA['sector']}", ['name' => 'Hackeo'])->assertStatus(404);
        $this->deleteJson("/api/v1/intelligence/taxonomy/nodes/{$chainA['sector']}")->assertStatus(404);
        $this->postJson("/api/v1/intelligence/taxonomy/nodes/{$chainA['sector']}/versions", ['name' => 'Nodo X'])->assertStatus(404);
        $this->patchJson("/api/v1/intelligence/taxonomy/assignments/{$assignmentA}", ['valid_to' => '2026-12-31'])->assertStatus(404);
        $this->postJson("/api/v1/intelligence/taxonomy/assignments/{$assignmentA}/verify")->assertStatus(404);
        $this->deleteJson("/api/v1/intelligence/taxonomy/assignments/{$assignmentA}")->assertStatus(404);
        $this->patchJson("/api/v1/intelligence/sector-profiles/{$profileA}", ['priority' => 1])->assertStatus(404);
        $this->deleteJson("/api/v1/intelligence/sector-profiles/{$profileA}")->assertStatus(404);

        // B no puede construir sobre los nodos de A: ni colgar nodos de un padre ajeno, ni asignar sus productos a nodos ajenos, ni usar productos ajenos.
        $this->postJson('/api/v1/intelligence/taxonomy/nodes', ['level' => 'industry', 'code' => 'cuelga-de-a', 'name' => 'Nodo X', 'parent_id' => $chainA['sector']])->assertStatus(422)->assertJsonPath('code', 'taxonomy_parent_not_found');
        $this->postJson('/api/v1/intelligence/taxonomy/assignments', ['product_id' => $productB, 'taxonomy_node_id' => $chainA['subcategory']])->assertStatus(422)->assertJsonPath('code', 'taxonomy_node_not_found');
        $ownNode = $this->node('sector', 'sector-b');
        $this->postJson('/api/v1/intelligence/taxonomy/assignments', ['product_id' => $productA, 'taxonomy_node_id' => $ownNode])->assertStatus(422)->assertJsonPath('code', 'product_not_found');
        $this->postJson('/api/v1/intelligence/sector-profiles', ['profile_key' => 'retail', 'scope' => 'product', 'scope_ref' => (string) $productA])->assertStatus(422)->assertJsonPath('code', 'scope_ref_not_found');
        // Tampoco enlazar con una categoría del ERP de otra empresa.
        $foreignCategory = $this->insertCategory($this->tenant, 'Categoría de A');
        $this->postJson('/api/v1/intelligence/taxonomy/nodes', ['level' => 'sector', 'code' => 'con-erp-ajeno', 'name' => 'Nodo X', 'erp_category_id' => $foreignCategory])->assertStatus(422)->assertJsonPath('code', 'erp_category_not_found');

        // El mismo código puede existir en dos empresas (índice por empresa).
        $this->node('sector', 'empresa-a-s');
        $this->assertSame(2, DB::table('taxonomy_nodes')->where('code', 'empresa-a-s')->count());
    }

    public function test_models_block_cross_company_references_even_if_the_api_is_bypassed(): void
    {
        $chainA = $this->chain('modelo-a');
        [$tenantB] = $this->createTenantWithUser();
        $productB = $this->insertProduct($tenantB, 'B-9');

        $this->withinTenant($tenantB, function () use ($chainA, $productB): void {
            // Un nodo de B con padre de A.
            try {
                TaxonomyNode::query()->create(['parent_id' => $chainA['sector'], 'level' => 'industry', 'code' => 'x', 'name' => 'x']);
                $this->fail('Un nodo no puede colgar de un padre de otra empresa.');
            } catch (IntelligenceRuleException $e) {
                $this->assertSame('taxonomy_parent_not_found', $e->errorCode);
            }
            // Una asignación de un producto de B a un nodo de A.
            try {
                $a = new ProductTaxonomyAssignment;
                $a->fill(['product_id' => $productB, 'taxonomy_node_id' => $chainA['sector'], 'provenance' => 'manual'])->save();
                $this->fail('Una asignación no puede apuntar a un nodo de otra empresa.');
            } catch (IntelligenceRuleException $e) {
                $this->assertSame('taxonomy_node_not_found', $e->errorCode);
            }
        });
        // La FK compuesta de la BD impide asignar un producto de B desde A.
        $this->expectException(QueryException::class);
        DB::table('product_taxonomy_assignments')->insert([
            'tenant_id' => $this->tenant->id, 'product_id' => $productB, 'taxonomy_node_id' => $chainA['sector'], 'provenance' => 'manual', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_public_taxonomy_nodes_are_readable_by_every_company_but_never_written(): void
    {
        $publicId = (int) DB::table('taxonomy_nodes')->insertGetId([
            'tenant_id' => null, 'parent_id' => null, 'level' => 'sector', 'code' => 'publico-agro', 'name' => 'Agro (público)', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->getJson('/api/v1/intelligence/taxonomy/nodes?scope=public')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.scope', 'public');
        $this->getJson("/api/v1/intelligence/taxonomy/nodes/{$publicId}")->assertOk();
        $this->patchJson("/api/v1/intelligence/taxonomy/nodes/{$publicId}", ['name' => 'Hackeo'])->assertStatus(403)->assertJsonPath('code', 'public_catalog_read_only');
        $this->deleteJson("/api/v1/intelligence/taxonomy/nodes/{$publicId}")->assertStatus(403);
        $this->postJson("/api/v1/intelligence/taxonomy/nodes/{$publicId}/versions", ['name' => 'Nodo X'])->assertStatus(403);
        // Una empresa puede colgar su propia industria de un sector público (referencia de solo lectura), y asignar su producto a un nodo público.
        $industry = $this->node('industry', 'industria-propia', $publicId);
        $this->assertSame($publicId, (int) DB::table('taxonomy_nodes')->where('id', $industry)->value('parent_id'));
        $this->assertSame('Agro (público)', DB::table('taxonomy_nodes')->where('id', $publicId)->value('name'));
        $productId = $this->insertProduct($this->tenant);
        $this->postJson('/api/v1/intelligence/taxonomy/assignments', ['product_id' => $productId, 'taxonomy_node_id' => $publicId])->assertCreated();
    }

    public function test_viewers_read_but_cannot_write_the_taxonomy(): void
    {
        $viewer = $this->addUserToTenant($this->tenant, TenantRole::Viewer);
        $this->actingAsTenantUser($viewer, $this->tenant);

        $this->getJson('/api/v1/intelligence/taxonomy/nodes')->assertOk();
        $this->getJson('/api/v1/intelligence/taxonomy/assignments')->assertOk();
        $this->postJson('/api/v1/intelligence/taxonomy/nodes', ['level' => 'sector', 'code' => 'x', 'name' => 'Nodo X'])->assertStatus(403)->assertJsonPath('required_ability', 'catalog.manage');
        $this->postJson('/api/v1/intelligence/taxonomy/assignments', ['product_id' => 1, 'taxonomy_node_id' => 1])->assertStatus(403);
    }
}
