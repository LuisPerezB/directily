<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Exceptions\DomainStateException;
use App\Models\ModelRegistry;
use App\Models\ModelRegistryEvent;
use App\Services\Models\ModelLifecycle;
use App\Services\Models\ModelServingPolicy;
use App\Services\Models\ModelState;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\Models\Support\ModelRegistryFixtures;
use Tests\TestCase;

/**
 * Máquina de estados del registro (ADR-V25-07): transiciones explícitas, evento de auditoría en la MISMA transacción, producción solo para lo
 * aprobado o legado, y la política explícita de legados (V25-REG-*).
 */
class ModelLifecycleTest extends TestCase
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

    private function lifecycle(): ModelLifecycle
    {
        return app(ModelLifecycle::class);
    }

    /** @return array{0: array<string, mixed>, 1: ModelRegistry} */
    private function inTenant(callable $fn): mixed
    {
        $c = $this->registryCompany();

        return app(TenantContext::class)->runAs($c['tenant'], fn () => $fn($c));
    }

    public function test_the_transition_table_is_explicit_and_complete(): void
    {
        $this->assertSame(
            ['candidate', 'validated', 'approved', 'production', 'retired', 'rejected', 'failed'],
            ModelState::ALL,
        );
        $allowed = [];
        foreach (ModelState::ALL as $from) {
            foreach (ModelState::ALL as $to) {
                if (ModelState::allows($from, $to)) {
                    $allowed[] = "{$from}>{$to}";
                }
            }
        }
        sort($allowed);
        $expected = ['approved>production', 'approved>rejected', 'approved>retired', 'candidate>failed', 'candidate>rejected', 'candidate>validated',
            'production>retired', 'retired>production', 'validated>approved', 'validated>production', 'validated>rejected', 'validated>retired'];
        sort($expected);
        $this->assertSame($expected, $allowed);
        $this->assertTrue(ModelState::isTerminal('rejected'));
        $this->assertTrue(ModelState::isTerminal('failed'));
        $this->assertFalse(ModelState::isTerminal('retired'));
    }

    public function test_the_database_check_admits_exactly_the_states_of_the_machine(): void
    {
        [$tenant] = $this->createTenantWithUser();
        foreach (ModelState::ALL as $status) {
            $this->assertGreaterThan(0, $this->customerModel($tenant, $status, [], false), "el CHECK debe admitir «{$status}»");
        }
        try {
            DB::transaction(fn () => $this->customerModel($tenant, 'inventado', [], false));
            $this->fail('un estado fuera del CHECK debe rechazarse');
        } catch (QueryException $e) {
            $this->assertStringContainsString('model_registry_status_check', $e->getMessage());
        }
    }

    public function test_every_forbidden_transition_is_rejected_without_changing_anything(): void
    {
        $this->inTenant(function (array $c): void {
            foreach (ModelState::ALL as $from) {
                foreach (ModelState::ALL as $to) {
                    if ($from === $to || ModelState::allows($from, $to)) {
                        continue;
                    }
                    $id = $this->customerModel($c['tenant'], $from, [], false);
                    $model = ModelRegistry::query()->findOrFail($id);
                    try {
                        $this->lifecycle()->transition($model, $to, $c['owner'], ModelRegistryEvent::ACTOR_USER, 'prueba', [], $to === 'approved' ? ['approved_at' => now()] : []);
                        $this->fail("La transición {$from} → {$to} debía rechazarse.");
                    } catch (DomainStateException $e) {
                        $this->assertSame('model_transition_not_allowed', $e->errorCode, "{$from} → {$to}");
                        $this->assertSame($from, $e->context['from']);
                    }
                    $this->assertSame($from, $this->statusOf($id));
                    $this->assertSame(0, DB::table('model_registry_events')->where('model_registry_id', $id)->count(), 'una transición rechazada no deja evento');
                }
            }
        });
    }

    public function test_an_allowed_transition_writes_the_event_with_actor_reason_and_evidence_in_the_same_transaction(): void
    {
        $this->inTenant(function (array $c): void {
            $id = $this->customerModel($c['tenant'], 'candidate', ['published_by' => $c['publisher']->id, 'published_at' => now()], false);
            $model = ModelRegistry::query()->findOrFail($id);
            $this->lifecycle()->transition($model, 'validated', $c['analyst'], ModelRegistryEvent::ACTOR_USER, 'Compuertas superadas', ['gates' => ['signature' => 'ok']]);

            $events = $this->eventsOf($id);
            // línea base (el modelo no tenía eventos) + la transición
            $this->assertCount(2, $events);
            $this->assertSame([null, 'candidate', null, 'system'], [$events[0]['from'], $events[0]['to'], $events[0]['actor'], $events[0]['kind']]);
            $this->assertSame(['candidate', 'validated', $c['analyst']->id, 'user', 'Compuertas superadas'], [$events[1]['from'], $events[1]['to'], $events[1]['actor'], $events[1]['kind'], $events[1]['reason']]);
            $this->assertSame(['signature' => 'ok'], $events[1]['evidence']['gates']);
            $this->assertSame('not_approved', $events[1]['evidence']['serving_basis']);
            $this->assertSame('validated', $this->statusOf($id));
        });
    }

    public function test_the_status_change_and_its_event_roll_back_together(): void
    {
        $this->inTenant(function (array $c): void {
            $id = $this->customerModel($c['tenant'], 'candidate', ['published_by' => $c['publisher']->id, 'published_at' => now()], false);
            try {
                DB::transaction(function () use ($id, $c): void {
                    $this->lifecycle()->transition(ModelRegistry::query()->findOrFail($id), 'validated', $c['analyst'], ModelRegistryEvent::ACTOR_USER);
                    $this->assertSame('validated', $this->statusOf($id));
                    throw new RuntimeException('fallo posterior en la misma transacción');
                });
            } catch (RuntimeException) {
            }
            $this->assertSame('candidate', $this->statusOf($id), 'el cambio de estado se deshace con la transacción');
            $this->assertSame(0, DB::table('model_registry_events')->where('model_registry_id', $id)->count(), 'y también su evento (y la línea base)');
        });
    }

    public function test_a_published_candidate_can_never_enter_production_without_approval(): void
    {
        $this->inTenant(function (array $c): void {
            $candidate = ModelRegistry::query()->findOrFail($this->publishedCandidate($c['tenant'], $c['publisher']));
            // candidate → production ni siquiera existe como transición
            try {
                $this->lifecycle()->transition($candidate, 'production', $c['owner'], ModelRegistryEvent::ACTOR_USER);
                $this->fail('candidate → production no existe');
            } catch (DomainStateException $e) {
                $this->assertSame('model_transition_not_allowed', $e->errorCode);
            }
            // validated → production existe para el legado, pero un modelo publicado sin aprobar se rechaza por la política
            $validated = ModelRegistry::query()->findOrFail($this->publishedCandidate($c['tenant'], $c['publisher'], ['status' => 'validated']));
            try {
                $this->lifecycle()->transition($validated, 'production', $c['owner'], ModelRegistryEvent::ACTOR_USER);
                $this->fail('un publicado sin aprobar no entra en producción');
            } catch (DomainStateException $e) {
                $this->assertSame('model_not_approved', $e->errorCode);
                $this->assertSame('not_approved', $e->context['basis']);
            }
            $this->assertSame('validated', $this->statusOf($validated->id));
            // retired → production tampoco para un publicado sin aprobación (p. ej. fila manipulada)
            $retired = ModelRegistry::query()->findOrFail($this->publishedCandidate($c['tenant'], $c['publisher'], ['status' => 'retired']));
            $this->expectException(DomainStateException::class);
            $this->lifecycle()->transition($retired, 'production', $c['owner'], ModelRegistryEvent::ACTOR_USER);
        });
    }

    public function test_approved_always_records_the_approval_timestamp(): void
    {
        $this->inTenant(function (array $c): void {
            $model = ModelRegistry::query()->findOrFail($this->publishedCandidate($c['tenant'], $c['publisher'], ['status' => 'validated']));
            $this->expectException(\LogicException::class);
            $this->lifecycle()->transition($model, 'approved', $c['approver'], ModelRegistryEvent::ACTOR_USER);
        });
    }

    /** Eventos de auditoría de prueba para simular lo que escriben la CLI, una persona o la migración. */
    private function event(int $modelId, int $tenantId, ?string $from, string $to, string $kind, ?int $actor = null, array $evidence = []): void
    {
        DB::table('model_registry_events')->insert(['tenant_id' => $tenantId, 'model_registry_id' => $modelId, 'from_status' => $from, 'to_status' => $to,
            'actor_user_id' => $actor, 'actor_kind' => $kind, 'evidence' => json_encode($evidence)]);
    }

    public function test_legacy_is_a_positive_definition_that_fails_closed(): void
    {
        $this->inTenant(function (array $c): void {
            $t = $c['tenant'];
            $policy = app(ModelServingPolicy::class);
            $basis = fn (int $id) => $policy->basis(ModelRegistry::query()->findOrFail($id));

            // Origen POSITIVO del pipeline propio (corrida enlazada) en production/validated/retired: legado servible.
            foreach (['production', 'validated', 'retired'] as $status) {
                $this->assertSame('legacy_pipeline', $basis($this->customerModel($t, $status, [], false)), $status);
            }
            // …por el trabajo de entrenamiento enlazado aunque la corrida ya no exista…
            $job = $this->trainedByRun($t, null)['training_job_id'];
            $this->assertSame('legacy_pipeline', $basis($this->customerModel($t, 'validated', ['core_run_id' => null, 'training_job_id' => $job], false)));
            // …o por la línea base con la marca de legado (la migración) cuando la corrida fue purgada (FK en null).
            $marked = $this->customerModel($t, 'production', ['core_run_id' => null], false);
            $this->assertSame('not_approved', $basis($marked), 'sin corrida ni marca no hay origen: falla cerrado');
            $this->event($marked, $t->id, null, 'production', 'system', null, ['legacy_policy' => ModelServingPolicy::LEGACY_MARK]);
            $this->assertSame('legacy_pipeline', $basis($marked));

            // Un candidato (retador sin decidir) jamás es legado servible, aunque venga del pipeline.
            $this->assertSame('not_approved', $basis($this->customerModel($t, 'candidate', [], false)));
            // Sin corrida enlazada, sin trabajo y sin marca: no hay origen positivo (la definición negativa anterior fallaba abierta).
            foreach (['production', 'validated', 'retired'] as $status) {
                $this->assertSame('not_approved', $basis($this->customerModel($t, $status, ['core_run_id' => null], false)), "{$status} sin origen");
            }
            // Publicado (cualquier marca): jamás legado, aunque tenga corrida enlazada.
            foreach ([['published_by' => $c['publisher']->id], ['published_at' => now()], ['published_env' => 'staging'], ['published_object_key' => 'tenants/x/models/customer/v1/a.joblib']] as $mark) {
                $this->assertSame('not_approved', $basis($this->customerModel($t, 'production', $mark, false)), 'publicado sin aprobar: '.json_encode(array_keys($mark)));
            }
            // …y un modelo con un evento de la CLI cuenta como publicado aunque la CLI olvidara las marcas published_*.
            $cli = $this->customerModel($t, 'production', [], false);
            $this->event($cli, $t->id, null, 'candidate', 'cli', $c['publisher']->id);
            $this->assertTrue($policy->isPublished(ModelRegistry::query()->findOrFail($cli)));
            $this->assertSame('not_approved', $basis($cli));
            // Validado por una PERSONA (evento candidate → validated de un usuario): nunca servible hasta su aprobación.
            $human = $this->customerModel($t, 'validated', [], false);
            $this->event($human, $t->id, 'candidate', 'validated', 'user', $c['analyst']->id);
            $this->assertSame('not_approved', $basis($human));
            // Una validación del sistema/motor (sin actor de tipo usuario) no cambia nada.
            $engine = $this->customerModel($t, 'validated', [], false);
            $this->event($engine, $t->id, 'candidate', 'validated', 'system');
            $this->assertSame('legacy_pipeline', $basis($engine));
            // Rechazado y fallido nunca se sirven, ni siquiera conservando la huella de una aprobación retirada.
            $this->assertSame('not_approved', $basis($this->customerModel($t, 'rejected', [], false)));
            $this->assertSame('not_approved', $basis($this->customerModel($t, 'failed', [], false)));
            $this->assertSame('not_approved', $basis($this->customerModel($t, 'rejected', ['approved_by' => $c['approver']->id, 'approved_at' => now()], false)));
            // Aprobado: por approved_at aunque el aprobador haya sido eliminado después (FK en null); la aprobación gana a la validación humana.
            $this->assertSame('approved', $basis($this->customerModel($t, 'approved', ['approved_by' => $c['approver']->id, 'approved_at' => now(), 'published_by' => $c['publisher']->id], false)));
            $this->assertSame('approved', $basis($this->customerModel($t, 'production', ['approved_at' => now(), 'published_at' => now()], false)));
            $approvedAfterHuman = $this->customerModel($t, 'approved', ['approved_at' => now()], false);
            $this->event($approvedAfterHuman, $t->id, 'candidate', 'validated', 'user', $c['analyst']->id);
            $this->assertSame('approved', $basis($approvedAfterHuman));
            // approved_* sin estado de aprobación (validated con huella) no basta.
            $this->assertSame('not_approved', $basis($this->customerModel($t, 'validated', ['approved_at' => now(), 'published_at' => now()], false)));
        });
    }

    public function test_annotate_computes_the_basis_of_a_whole_page_with_one_event_query(): void
    {
        $this->inTenant(function (array $c): void {
            $t = $c['tenant'];
            $legacy = $this->customerModel($t, 'production', [], false);
            $human = $this->customerModel($t, 'validated', [], false);
            $this->event($human, $t->id, 'candidate', 'validated', 'user', $c['analyst']->id);
            $cli = $this->customerModel($t, 'production', ['core_run_id' => null], false);
            $this->event($cli, $t->id, null, 'candidate', 'cli', $c['publisher']->id);
            $models = ModelRegistry::query()->whereIn('id', [$legacy, $human, $cli])->get();

            DB::enableQueryLog();
            app(ModelServingPolicy::class)->annotate($models);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            $this->assertSame(1, $queries);
            $this->assertSame(['legacy_pipeline', 'not_approved', 'not_approved'], $models->sortBy(fn ($m) => array_search($m->id, [$legacy, $human, $cli]))->pluck('servingBasis')->values()->all());
            $this->assertNotContains('servingBasis', array_keys($models->first()->getAttributes()), 'la base calculada no es un atributo persistible');
        });
    }

    public function test_a_legacy_model_gets_a_baseline_event_marked_as_legacy_before_its_first_transition(): void
    {
        $this->inTenant(function (array $c): void {
            $id = $this->customerModel($c['tenant'], 'retired', [], false);
            $this->lifecycle()->transition(ModelRegistry::query()->findOrFail($id), 'production', $c['owner'], ModelRegistryEvent::ACTOR_USER, 'Reversión');
            $events = $this->eventsOf($id);
            $this->assertCount(2, $events);
            $this->assertSame(['legacy_approved_by_migration', 'legacy_pipeline'], [$events[0]['evidence']['legacy_policy'], $events[0]['evidence']['serving_basis']]);
            $this->assertSame([null, 'retired'], [$events[0]['from'], $events[0]['to']]);
            $this->assertSame(['retired', 'production'], [$events[1]['from'], $events[1]['to']]);
            // La línea base es idempotente
            $this->lifecycle()->ensureBaseline(ModelRegistry::query()->findOrFail($id));
            $this->assertCount(2, $this->eventsOf($id));
        });
    }

    public function test_a_candidate_baseline_never_carries_the_legacy_mark_and_human_validation_records_not_approved(): void
    {
        $this->inTenant(function (array $c): void {
            // retador del pipeline (candidate con corrida enlazada) que una persona valida
            $id = $this->customerModel($c['tenant'], 'candidate', [], false);
            $this->lifecycle()->transition(ModelRegistry::query()->findOrFail($id), 'validated', $c['owner'], ModelRegistryEvent::ACTOR_USER, 'Revisado');
            $events = $this->eventsOf($id);
            $this->assertArrayNotHasKey('legacy_policy', $events[0]['evidence'], 'un candidato nunca recibe la marca de legado');
            $this->assertSame('not_approved', $events[0]['evidence']['serving_basis']);
            $this->assertSame(['candidate', 'validated', 'not_approved'], [$events[1]['from'], $events[1]['to'], $events[1]['evidence']['serving_basis']]);
            $this->assertSame('not_approved', app(ModelServingPolicy::class)->basis(ModelRegistry::query()->findOrFail($id)));
            // y entonces tampoco puede entrar en producción por la máquina de estados
            try {
                $this->lifecycle()->transition(ModelRegistry::query()->findOrFail($id), 'production', $c['owner'], ModelRegistryEvent::ACTOR_USER);
                $this->fail('un legado validado por una persona no entra en producción sin aprobación');
            } catch (DomainStateException $e) {
                $this->assertSame('model_not_approved', $e->errorCode);
            }
        });
    }
}
