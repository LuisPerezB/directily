<?php

declare(strict_types=1);

namespace Tests\Feature\Intelligence;

use App\Enums\TenantRole;
use App\Models\ExternalEvent;
use App\Models\FactorApplicabilityRule;
use App\Models\FactorDefinition;
use App\Models\FactorEvaluation;
use App\Models\ProductTaxonomyAssignment;
use App\Models\SharedForecastGrant;
use App\Models\SharedForecastGrantAccess;
use App\Models\SourceVersion;
use App\Models\TaxonomyNode;
use App\Models\TenantSectorProfile;
use App\Services\Intelligence\Exceptions\IntelligenceRuleException;
use App\Services\Intelligence\Exceptions\PublicCatalogReadOnlyException;
use App\Services\Intelligence\PlatformWrite;
use App\Support\Tenancy\Exceptions\TenantNotResolvedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Intelligence\Support\IntelligenceFixtures;
use Tests\TestCase;

/** Modelos de las tablas de la migración 2026_03_04 (ADR-V25-25): aislamiento fail-closed, catálogo público de solo lectura y reglas propias de cada contrato. */
final class IntelligenceModelsTest extends TestCase
{
    use IntelligenceFixtures, RefreshDatabase;

    public function test_every_table_of_the_contract_migration_has_its_model(): void
    {
        $expected = [
            'factor_definitions' => FactorDefinition::class, 'source_versions' => SourceVersion::class, 'external_events' => ExternalEvent::class,
            'taxonomy_nodes' => TaxonomyNode::class, 'product_taxonomy_assignments' => ProductTaxonomyAssignment::class, 'tenant_sector_profiles' => TenantSectorProfile::class,
            'factor_applicability_rules' => FactorApplicabilityRule::class, 'factor_evaluations' => FactorEvaluation::class, 'shared_forecast_grants' => SharedForecastGrant::class,
            'shared_forecast_grant_accesses' => SharedForecastGrantAccess::class,
        ];
        foreach ($expected as $table => $model) {
            $this->assertSame($table, (new $model)->getTable());
        }
    }

    public function test_every_model_with_a_tenant_id_refuses_to_be_queried_without_a_company(): void
    {
        foreach ([FactorDefinition::class, SourceVersion::class, ExternalEvent::class, TaxonomyNode::class, ProductTaxonomyAssignment::class, TenantSectorProfile::class,
            FactorApplicabilityRule::class, FactorEvaluation::class, SharedForecastGrant::class, SharedForecastGrantAccess::class] as $model) {
            try {
                $model::query()->count();
                $this->fail("{$model} respondió sin empresa activa (debe fallar: fail-closed).");
            } catch (TenantNotResolvedException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_source_versions_are_isolated_and_public_ones_are_read_only(): void
    {
        [$a] = $this->createTenantWithUser();
        [$b] = $this->createTenantWithUser();
        PlatformWrite::run(fn () => SourceVersion::query()->create(['source_key' => 'oficial', 'version_label' => '2026-09', 'license' => 'Datos abiertos']));
        $this->withinTenant($a, fn () => SourceVersion::query()->create(['source_key' => 'manual', 'version_label' => 'v1']));
        // Misma clave y versión en otra empresa: permitido (índice único por empresa).
        $this->withinTenant($b, fn () => SourceVersion::query()->create(['source_key' => 'manual', 'version_label' => 'v1']));

        $this->assertSame(['manual', 'oficial'], $this->withinTenant($a, fn () => SourceVersion::query()->orderBy('source_key')->pluck('source_key')->all()));
        $this->assertSame(2, $this->withinTenant($b, fn () => SourceVersion::query()->count()));
        $this->assertSame(1, $this->withinTenant($a, fn () => SourceVersion::query()->own()->count()));
        $this->expectException(PublicCatalogReadOnlyException::class);
        $this->withinTenant($a, fn () => SourceVersion::query()->where('source_key', 'oficial')->firstOrFail()->update(['license' => 'Hackeada']));
    }

    public function test_applicability_rules_follow_the_public_or_own_rule_and_cannot_reference_foreign_definitions(): void
    {
        $this->seedFactorCatalog();
        [$a] = $this->createTenantWithUser();
        [$b] = $this->createTenantWithUser();
        $public = FactorDefinition::publicCatalog()->where('key', 'weather.observed')->firstOrFail();
        $privateOfB = $this->withinTenant($b, fn () => FactorDefinition::query()->create(['family' => 'competition', 'key' => 'competition.b', 'name' => 'B', 'availability_class' => 'STATIC', 'storage' => 'sales']));

        // Una regla de la empresa sobre una definición pública: permitido; sobre la definición privada de otra empresa: no.
        $rule = $this->withinTenant($a, fn () => FactorApplicabilityRule::query()->create(['factor_definition_id' => $public->id, 'effect' => 'not_applicable', 'justification' => 'No aplica a ferretería']));
        $this->assertSame($a->id, $rule->refresh()->tenant_id);
        try {
            $this->withinTenant($a, fn () => FactorApplicabilityRule::query()->create(['factor_definition_id' => $privateOfB->id]));
            $this->fail('Una regla no puede referenciar la definición privada de otra empresa.');
        } catch (IntelligenceRuleException $e) {
            $this->assertSame('factor_definition_not_found', $e->errorCode);
        }
        // La plataforma publica reglas del catálogo; la empresa B no ve la de A y sí la pública.
        PlatformWrite::run(fn () => FactorApplicabilityRule::query()->create(['factor_definition_id' => $public->id, 'effect' => 'applicable', 'justification' => 'Aplica a agro']));
        $this->assertSame(1, $this->withinTenant($b, fn () => FactorApplicabilityRule::query()->count()));
        $this->assertSame(2, $this->withinTenant($a, fn () => FactorApplicabilityRule::query()->count()));
        $this->assertSame(1, $this->withinTenant($a, fn () => FactorApplicabilityRule::query()->own()->count()));
        $this->expectException(PublicCatalogReadOnlyException::class);
        $this->withinTenant($a, fn () => FactorApplicabilityRule::query()->whereNull('tenant_id')->firstOrFail()->delete());
    }

    public function test_factor_evaluations_are_isolated_and_never_promote_with_a_used_holdout(): void
    {
        $this->seedFactorCatalog();
        [$a] = $this->createTenantWithUser();
        [$b] = $this->createTenantWithUser();
        $definition = FactorDefinition::publicCatalog()->where('key', 'weather.observed')->firstOrFail();
        $base = ['factor_definition_id' => $definition->id, 'family' => 'weather', 'factor_set' => ['weather.observed'], 'horizon_days' => 30, 'cutoff' => '2026-10-01',
            'baseline_metrics' => ['wape' => 0.31], 'candidate_metrics' => ['wape' => 0.3], 'cost' => ['api_calls' => 0]];

        $this->withinTenant($a, fn () => FactorEvaluation::query()->create($base + ['holdout_untouched' => true, 'decision' => 'inconclusive']));
        $this->assertSame(1, $this->withinTenant($a, fn () => FactorEvaluation::query()->count()));
        $this->assertSame(0, $this->withinTenant($b, fn () => FactorEvaluation::query()->count()));
        // «promote» exige holdout intacto (regla crítica V2.1 §24.7); «reject» o «inconclusive» sí pueden guardarse con el holdout usado.
        $this->withinTenant($a, fn () => FactorEvaluation::query()->create($base + ['holdout_untouched' => false, 'decision' => 'reject']));
        try {
            $this->withinTenant($a, fn () => FactorEvaluation::query()->create($base + ['holdout_untouched' => false, 'decision' => 'promote']));
            $this->fail('No se puede promover un factor con el holdout usado.');
        } catch (IntelligenceRuleException $e) {
            $this->assertSame('holdout_not_untouched', $e->errorCode);
        }
        $this->withinTenant($a, fn () => FactorEvaluation::query()->create($base + ['holdout_untouched' => true, 'decision' => 'promote']));
        // No puede evaluar la definición privada de otra empresa.
        $foreign = $this->withinTenant($b, fn () => FactorDefinition::query()->create(['family' => 'competition', 'key' => 'competition.privada', 'name' => 'B', 'availability_class' => 'STATIC', 'storage' => 'sales']));
        $this->expectException(IntelligenceRuleException::class);
        $this->withinTenant($a, fn () => FactorEvaluation::query()->create(['factor_definition_id' => $foreign->id] + $base + ['holdout_untouched' => true, 'decision' => 'inconclusive']));
    }

    /** Matriz de roles sobre las rutas de escritura: quién puede qué (la lectura es de `data.view`: todos). */
    public function test_the_role_matrix_of_every_write_route(): void
    {
        $this->seedFactorCatalog();
        [$tenant, $owner] = $this->createTenantWithUser(TenantRole::Owner);
        $users = [
            'viewer' => $this->addUserToTenant($tenant, TenantRole::Viewer), 'analyst' => $this->addUserToTenant($tenant, TenantRole::Analyst),
            'admin' => $this->addUserToTenant($tenant, TenantRole::Admin), 'owner' => $owner,
        ];
        // Las rutas con route-model binding resuelven el recurso ANTES de comprobar la habilidad (orden de middleware de Laravel): se usan recursos reales.
        $now = now();
        $node = DB::table('taxonomy_nodes')->insertGetId(['tenant_id' => $tenant->id, 'level' => 'sector', 'code' => 'matriz', 'name' => 'Matriz', 'version' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $assignment = DB::table('product_taxonomy_assignments')->insertGetId(['tenant_id' => $tenant->id, 'product_id' => $this->insertProduct($tenant), 'taxonomy_node_id' => $node, 'provenance' => 'llm_proposed', 'created_at' => $now, 'updated_at' => $now]);
        $profile = DB::table('tenant_sector_profiles')->insertGetId(['tenant_id' => $tenant->id, 'profile_key' => 'retail', 'created_at' => $now, 'updated_at' => $now]);
        // [método, ruta, cuerpo, roles autorizados]
        $matrix = [
            ['PUT', '/api/v1/intelligence/factors/weather.observed/activation', ['active' => true], ['admin', 'owner']],
            ['POST', '/api/v1/intelligence/events/1/verify', [], ['admin', 'owner']],
            ['POST', '/api/v1/intelligence/events/1/reject', ['reason' => 'abc'], ['admin', 'owner']],
            ['POST', '/api/v1/intelligence/events', [], ['analyst', 'admin', 'owner']],
            ['PATCH', '/api/v1/intelligence/events/1', [], ['analyst', 'admin', 'owner']],
            ['DELETE', '/api/v1/intelligence/events/1', [], ['analyst', 'admin', 'owner']],
            ['POST', '/api/v1/intelligence/taxonomy/nodes', [], ['analyst', 'admin', 'owner']],
            ['PATCH', "/api/v1/intelligence/taxonomy/nodes/{$node}", [], ['analyst', 'admin', 'owner']],
            ['DELETE', "/api/v1/intelligence/taxonomy/nodes/{$node}", [], ['analyst', 'admin', 'owner']],
            ['POST', "/api/v1/intelligence/taxonomy/nodes/{$node}/versions", [], ['analyst', 'admin', 'owner']],
            ['POST', '/api/v1/intelligence/taxonomy/assignments', [], ['analyst', 'admin', 'owner']],
            ['PATCH', "/api/v1/intelligence/taxonomy/assignments/{$assignment}", [], ['analyst', 'admin', 'owner']],
            ['POST', "/api/v1/intelligence/taxonomy/assignments/{$assignment}/verify", [], ['analyst', 'admin', 'owner']],
            ['DELETE', "/api/v1/intelligence/taxonomy/assignments/{$assignment}", [], ['analyst', 'admin', 'owner']],
            ['POST', '/api/v1/intelligence/sector-profiles', [], ['admin', 'owner']],
            ['PATCH', "/api/v1/intelligence/sector-profiles/{$profile}", [], ['admin', 'owner']],
            ['DELETE', "/api/v1/intelligence/sector-profiles/{$profile}", [], ['admin', 'owner']],
        ];
        foreach ($matrix as [$method, $uri, $body, $allowed]) {
            foreach ($users as $role => $user) {
                $status = $this->actingAsTenantUser($user, $tenant)->json($method, $uri, $body)->status();
                if (in_array($role, $allowed, true)) {
                    $this->assertNotSame(403, $status, "{$role} debe poder {$method} {$uri}");
                } else {
                    $this->assertSame(403, $status, "{$role} NO debe poder {$method} {$uri}");
                }
            }
        }
        foreach (['/api/v1/intelligence/factors', '/api/v1/intelligence/events', '/api/v1/intelligence/events/informative-alerts', '/api/v1/intelligence/taxonomy/nodes',
            '/api/v1/intelligence/taxonomy/assignments', '/api/v1/intelligence/sector-profiles'] as $uri) {
            foreach ($users as $role => $user) {
                $this->actingAsTenantUser($user, $tenant)->getJson($uri)->assertOk();
            }
        }
        // Los roles no autorizados no alteraron nada (las mutaciones autorizadas con cuerpo vacío fallaron en validación o no tenían efecto).
        $this->assertSame(0, DB::table('external_events')->count());
        $this->assertSame('Matriz', DB::table('taxonomy_nodes')->where('id', $node)->value('name'));
    }
}
