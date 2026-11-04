<?php

declare(strict_types=1);

namespace Tests\Feature\Decision;

use App\Models\DecisionRecommendation;
use App\Models\ExperimentAssignment;
use App\Models\Tenant;
use App\Services\Decision\DecisionGenerator;
use App\Services\Decision\DecisionSettings;
use App\Services\Decision\ExperimentAssigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Decision\Support\DecisionFixtures;
use Tests\TestCase;

/**
 * V2.5 · W5-B (ADR-V25-14): experimento de contacto. La fracción de control es por empresa y vale 0 por defecto (sin experimento); la asignación
 * tratamiento/control es REPRODUCIBLE con la semilla registrada, queda en `experiment_assignments` al generar y las unidades de control nunca se asignan.
 */
final class DecisionExperimentTest extends TestCase
{
    use DecisionFixtures;
    use RefreshDatabase;

    private const EXTRA_CUSTOMERS = 300;

    /** Genera con `contact_source = engine` (el productor por defecto de los contactos es el Radar) respetando lo ya configurado. */
    private function generate(Tenant $tenant): array
    {
        $tenant->refresh();
        $decision = (array) ($tenant->settings['decision'] ?? []);
        $tenant->forceFill(['settings' => array_merge($tenant->effectiveSettings(), ['decision' => $decision + ['contact_source' => 'engine']])])->save();

        return app(DecisionGenerator::class)->generate($tenant, now('America/Guayaquil')->toImmutable());
    }

    /** @param array<string, mixed> $decision */
    private function configure(Tenant $tenant, array $decision): void
    {
        $tenant->forceFill(['settings' => array_merge($tenant->effectiveSettings(), ['decision' => $decision])])->save();
    }

    /** Agrega compradores con una predicción de 30 días para p1 (los reales de una empresa grande). */
    private function manyCustomers(array $w): void
    {
        $base = (array) DB::table('customer_predictions')->where('core_run_id', $w['run'])->where('horizon_days', 30)->where('customer_id', $w['cat']['customers']['c1'])
            ->where('product_id', $w['cat']['products']['p1'])->first();
        unset($base['id']);
        $customers = [];
        $predictions = [];
        for ($i = 0; $i < self::EXTRA_CUSTOMERS; $i++) {
            $customers[] = ['tenant_id' => $w['tenant']->id, 'external_code' => sprintf('CLI-X%04d', $i), 'customer_type' => 'company', 'is_generic' => false, 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('customers')->insert($customers);
        $ids = DB::table('customers')->where('tenant_id', $w['tenant']->id)->where('external_code', 'like', 'CLI-X%')->orderBy('id')->pluck('id');
        foreach ($ids as $id) {
            $predictions[] = ['customer_id' => $id] + $base;
        }
        DB::table('customer_predictions')->insert($predictions);
    }

    /** @return array<int, string> grupo por cliente según experiment_assignments */
    private function assignedGroups(Tenant $tenant): array
    {
        return $this->withinTenant($tenant, fn () => ExperimentAssignment::query()->orderBy('id')->get()->mapWithKeys(
            fn (ExperimentAssignment $a) => [(int) substr($a->unit_key, strlen('customer:')) => $a->group],
        )->all());
    }

    public function test_by_default_there_is_no_experiment_and_nothing_is_assigned(): void
    {
        $w = $this->decisionWorld();

        $this->generate($w['tenant']);

        $this->assertSame(0.0, DecisionSettings::forTenant($w['tenant'])->controlFraction());
        $this->assertSame(0, DB::table('experiment_assignments')->count());
        $recs = $this->withinTenant($w['tenant'], fn () => DecisionRecommendation::query()->get());
        $this->assertGreaterThan(0, $recs->count());
        $this->assertSame([null], $recs->pluck('experiment_group')->unique()->values()->all());
    }

    public function test_assignment_is_recorded_when_generating_with_the_seed_and_policy_and_follows_the_fraction(): void
    {
        $w = $this->decisionWorld();
        $this->manyCustomers($w);
        $this->configure($w['tenant'], ['control_fraction' => 0.3, 'experiment_key' => 'contacto-2026-11', 'experiment_seed' => 'semilla-registrada-0001', 'max_contact_per_horizon' => 2000]);

        $this->generate($w['tenant']);

        $assignments = $this->withinTenant($w['tenant'], fn () => ExperimentAssignment::query()->get());
        $this->assertGreaterThan(self::EXTRA_CUSTOMERS, $assignments->count(), 'una asignación por cliente con recomendación de contacto o recuperación');
        $this->assertSame($assignments->count(), $assignments->unique('unit_key')->count(), 'una sola asignación por unidad y experimento');
        foreach ($assignments as $a) {
            $this->assertSame('contacto-2026-11', $a->experiment_key);
            $this->assertSame('customer', $a->unit_type);
            $this->assertSame('semilla-registrada-0001', $a->policy['seed'], 'la semilla queda registrada');
            $this->assertEquals(0.3, $a->policy['control_fraction']);
            $this->assertSame(ExperimentAssigner::ALGORITHM, $a->policy['algorithm']);
            // Cualquiera puede recalcular el grupo con la política registrada.
            $this->assertSame($a->group, ExperimentAssigner::groupFor($a->policy, $a->experiment_key, $a->unit_key));
        }
        $share = $assignments->where('group', 'control')->count() / $assignments->count();
        $this->assertEqualsWithDelta(0.3, $share, 0.1, 'la proporción de control se acerca a la fracción configurada (los ids de cliente varían entre ejecuciones: margen de ~3,8 errores estándar)');
        // La recomendación lleva el grupo de su cliente.
        $groups = $this->assignedGroups($w['tenant']);
        foreach ($this->withinTenant($w['tenant'], fn () => DecisionRecommendation::query()->whereIn('kind', ['contact', 'recovery'])->get()) as $rec) {
            $this->assertSame($groups[$rec->customer_id], $rec->experiment_group);
        }
        // Un mismo cliente cae en el mismo grupo en todas sus recomendaciones (sin contaminación entre ellas).
        $byCustomer = $this->withinTenant($w['tenant'], fn () => DecisionRecommendation::query()->whereIn('kind', ['contact', 'recovery'])->get())->groupBy('customer_id');
        foreach ($byCustomer as $recs) {
            $this->assertCount(1, $recs->pluck('experiment_group')->unique());
        }
    }

    public function test_the_assignment_is_reproducible_from_the_recorded_seed(): void
    {
        $w = $this->decisionWorld();
        $this->manyCustomers($w);
        $this->configure($w['tenant'], ['control_fraction' => 0.25, 'experiment_seed' => 'otra-semilla-0002', 'max_contact_per_horizon' => 2000]);

        $this->generate($w['tenant']);
        $first = $this->assignedGroups($w['tenant']);
        // Se borra todo lo generado y se vuelve a generar con la misma semilla: mismos grupos para los mismos clientes.
        DB::table('decision_recommendations')->delete();
        DB::table('experiment_assignments')->delete();
        $this->generate($w['tenant']);
        $second = $this->assignedGroups($w['tenant']);

        $this->assertNotEmpty($first);
        $this->assertSame($first, $second);
        // Con otra semilla el reparto cambia (no es una constante).
        DB::table('decision_recommendations')->delete();
        DB::table('experiment_assignments')->delete();
        $this->configure($w['tenant'], ['control_fraction' => 0.25, 'experiment_seed' => 'semilla-distinta-0003', 'max_contact_per_horizon' => 2000]);
        $this->generate($w['tenant']);
        $this->assertNotSame($first, $this->assignedGroups($w['tenant']));
    }

    public function test_without_an_explicit_seed_a_stable_one_is_derived_and_recorded(): void
    {
        $w = $this->decisionWorld();
        $this->configure($w['tenant'], ['control_fraction' => 0.4]);

        $this->generate($w['tenant']);

        $policy = $this->withinTenant($w['tenant'], fn () => ExperimentAssignment::query()->firstOrFail()->policy);
        $this->assertSame(hash('sha256', 'predictily-experiment:'.$w['tenant']->uuid.':contact-default'), $policy['seed']);
        $this->assertSame(64, strlen($policy['seed']));
    }

    public function test_control_units_are_never_assigned_to_a_seller_nor_shown_to_non_approvers(): void
    {
        $w = $this->decisionWorld();
        $this->manyCustomers($w);
        $this->configure($w['tenant'], ['control_fraction' => 0.5, 'experiment_seed' => 'semilla-control-0004', 'max_contact_per_horizon' => 2000]);
        $this->generate($w['tenant']);
        $control = $this->withinTenant($w['tenant'], fn () => DecisionRecommendation::query()->where('experiment_group', 'control')->orderBy('id')->firstOrFail());
        $treatment = $this->withinTenant($w['tenant'], fn () => DecisionRecommendation::query()->where('experiment_group', 'treatment')->orderBy('id')->firstOrFail());

        // Asignar una de control está prohibido, también para quien aprueba (el administrador).
        $assign = function ($user, int $id) use ($w) {
            $version = (int) $this->actingAsTenantUser($user, $w['tenant'])->getJson("/api/v1/decisions/{$id}")->assertOk()->json('data.version');

            return $this->actingAsTenantUser($user, $w['tenant'])->postJson("/api/v1/decisions/{$id}/transition",
                ['to_status' => 'assigned', 'idempotency_key' => 'assign-'.$id.'-'.$user->id, 'assignee_user_id' => $w['analyst']->id, 'expected_version' => $version]);
        };
        $assign($w['admin'], $control->id)->assertStatus(409)->assertJsonPath('code', 'control_group_not_actionable');
        $assign($w['owner'], $control->id)->assertStatus(409)->assertJsonPath('code', 'control_group_not_actionable');
        $this->assertSame('suggested', $this->withinTenant($w['tenant'], fn () => DecisionRecommendation::query()->findOrFail($control->id)->status));
        $this->assertNull($this->withinTenant($w['tenant'], fn () => DecisionRecommendation::query()->findOrFail($control->id)->assigned_user_id));
        $this->assertSame(0, DB::table('decision_events')->where('recommendation_id', $control->id)->where('to_status', '<>', 'suggested')->count(), 'ni un intento deja eventos');
        // La de tratamiento sí se asigna.
        $assign($w['admin'], $treatment->id)->assertOk();

        // Quien no aprueba no la ve: ni en el detalle (404) ni en la lista, ni aunque pida include_control.
        $this->actingAsTenantUser($w['analyst'], $w['tenant'])->getJson("/api/v1/decisions/{$control->id}")->assertStatus(404);
        $ids = collect($this->getJson('/api/v1/decisions?per_page=100&include_control=1')->assertOk()->json('data'))->pluck('id');
        $this->assertNotContains($control->id, $ids->all());
        $this->assertContains($treatment->id, $ids->all());
        // Quien aprueba la ve solo si lo pide, y ve su grupo.
        $this->actingAsTenantUser($w['admin'], $w['tenant']);
        $default = collect($this->getJson('/api/v1/decisions?per_page=100')->json('data'))->pluck('id');
        $this->assertNotContains($control->id, $default->all(), 'por defecto la lista nunca incluye al grupo de control');
        $with = collect($this->getJson("/api/v1/decisions?include_control=1&customer_id={$control->customer_id}")->json('data'))->keyBy('id');
        $this->assertSame('control', $with[$control->id]['experiment_group']);
        $this->assertSame([], collect($this->getJson("/api/v1/decisions?customer_id={$control->customer_id}")->json('data'))->where('id', $control->id)->all());
        $this->getJson("/api/v1/decisions/{$control->id}")->assertOk()->assertJsonPath('data.experiment_group', 'control');
        $this->assertGreaterThan(0, $this->getJson('/api/v1/decisions/summary')->json('data.control_group_units'));
        // El alcance «accionable» (para la agenda de vendedores de W5-A) excluye a control.
        $this->assertSame(0, $this->withinTenant($w['tenant'], fn () => DecisionRecommendation::query()->actionable()->where('experiment_group', 'control')->count()));
    }

    public function test_an_assignment_already_recorded_does_not_change_when_the_fraction_changes_later(): void
    {
        $w = $this->decisionWorld();
        $this->manyCustomers($w);
        $this->configure($w['tenant'], ['control_fraction' => 0.2, 'experiment_seed' => 'semilla-estable-0005', 'max_contact_per_horizon' => 2000]);
        $this->generate($w['tenant']);
        $before = $this->assignedGroups($w['tenant']);

        $this->configure($w['tenant'], ['control_fraction' => 0.5, 'experiment_seed' => 'semilla-estable-0005', 'max_contact_per_horizon' => 2000]);
        DB::table('decision_recommendations')->delete();
        $this->generate($w['tenant']);

        $this->assertSame($before, $this->assignedGroups($w['tenant']), 'ITT: la asignación original manda');
    }

    public function test_only_customer_contact_actions_enter_the_experiment_and_the_fraction_is_capped(): void
    {
        $w = $this->decisionWorld();
        $forecast = $this->forecastWithRecommendations($w['tenant'], $w['cat'], $w['asOf']);
        $this->withinTenant($w['tenant'], fn () => $this->supplyPlan($w['tenant'], $w['cat'], $w['asOf'], $forecast));
        $this->configure($w['tenant'], ['control_fraction' => 0.5]);
        $this->generate($w['tenant']);

        $byKind = $this->withinTenant($w['tenant'], fn () => DecisionRecommendation::query()->get())->groupBy('kind');
        $this->assertTrue($byKind->has('replenishment') && $byKind->has('budget_constraint') && $byKind->has('contact'));
        foreach (['replenishment', 'budget_constraint', 'customer_risk', 'inventory_mismatch'] as $kind) {
            foreach ($byKind->get($kind, collect()) as $rec) {
                $this->assertNull($rec->experiment_group, "{$kind} no participa en el experimento");
            }
        }
        // Nunca más de la mitad en control, aunque se configure más (la API tampoco lo admite).
        $this->configure($w['tenant'], ['control_fraction' => 0.9]);
        $this->assertSame(0.5, DecisionSettings::forTenant($w['tenant'])->controlFraction());
    }

    public function test_assignments_are_isolated_between_companies(): void
    {
        $a = $this->decisionWorld();
        $b = $this->decisionWorld();
        $this->configure($a['tenant'], ['control_fraction' => 0.5, 'experiment_seed' => 'semilla-a-0006']);

        $this->generate($a['tenant']);
        $this->generate($b['tenant']);

        $this->assertGreaterThan(0, count($this->assignedGroups($a['tenant'])));
        $this->assertSame([], $this->assignedGroups($b['tenant']), 'la otra empresa no tiene experimento');
        $this->assertSame([$a['tenant']->id], DB::table('experiment_assignments')->pluck('tenant_id')->unique()->values()->all());
    }
}
