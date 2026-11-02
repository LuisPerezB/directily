<?php

declare(strict_types=1);

namespace Tests\Feature\Decision;

use App\Contracts\Decision\RecommendationSink;
use App\Models\DecisionRecommendation;
use App\Models\Tenant;
use App\Services\Decision\DecisionGenerator;
use App\Services\Decision\DecisionSettings;
use App\Services\Decision\ScoreFormula;
use App\Services\Radar\CommercialOpportunityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Decision\Support\DecisionFixtures;
use Tests\TestCase;

/**
 * V2.5 · W5-B (decisión del arquitecto, ADR-V25-12): UN productor por tipo de oportunidad. Los contactos y recuperaciones cliente × producto los produce el Radar (W5-A)
 * por RecommendationSink; el Decision Engine genera la recuperación y el riesgo a nivel de cliente y lo derivado de Supply. Si una empresa elige el productor `engine`, los
 * dos comparten la huella de idempotencia: correr ambos, en cualquier orden, nunca duplica una oportunidad. La prioridad es determinista por posición del score en su grupo.
 */
final class DecisionProducersAndPriorityTest extends TestCase
{
    use DecisionFixtures;
    use RefreshDatabase;

    private function engineGenerates(Tenant $tenant): array
    {
        $tenant->refresh();
        $tenant->forceFill(['settings' => array_merge($tenant->effectiveSettings(), ['decision' => ((array) ($tenant->settings['decision'] ?? [])) + ['contact_source' => 'engine']])])->save();

        return app(DecisionGenerator::class)->generate($tenant, now('America/Guayaquil')->toImmutable());
    }

    private function radarProposes(Tenant $tenant): object
    {
        return $this->withinTenant($tenant, fn () => app(CommercialOpportunityService::class)->propose());
    }

    /** @return Collection<int, DecisionRecommendation> */
    private function contacts(Tenant $tenant): Collection
    {
        return $this->withinTenant($tenant, fn () => DecisionRecommendation::query()->where('kind', 'contact')->orderBy('id')->get());
    }

    private function assertNoDuplicates(Tenant $tenant): void
    {
        $contacts = $this->contacts($tenant);
        $natural = $contacts->map(fn ($r) => implode('|', [$r->customer_id, $r->product_id, $r->horizon_days, $r->as_of->toDateString(), $r->source_core_run_id]));
        $this->assertSame($natural->count(), $natural->unique()->count(), 'una sola recomendación por cliente × producto × horizonte × corrida');
        $this->assertSame($contacts->count(), $contacts->pluck('idempotency_key')->unique()->count());
    }

    public function test_running_the_radar_and_the_engine_in_either_order_never_duplicates_an_opportunity(): void
    {
        // Radar primero, motor después.
        $a = $this->decisionWorld();
        $radar = $this->radarProposes($a['tenant']);
        $afterRadar = $this->contacts($a['tenant'])->count();
        $this->assertGreaterThan(0, $afterRadar);
        $this->assertGreaterThan(0, $radar->created);
        $report = $this->engineGenerates($a['tenant']);
        $this->assertArrayNotHasKey('contact', $report['created'], 'lo que el motor seleccionaría ya lo propuso el Radar con la misma huella');
        $this->assertSame($afterRadar, $this->contacts($a['tenant'])->count());
        $this->assertNoDuplicates($a['tenant']);

        // Motor primero, Radar después: el Radar solo agrega lo que el motor no generó (otros horizontes), nada repetido.
        $b = $this->decisionWorld();
        $first = $this->engineGenerates($b['tenant']);
        $engineContacts = $this->contacts($b['tenant'])->count();
        $this->assertSame($first['created']['contact'], $engineContacts);
        $this->radarProposes($b['tenant']);
        $this->assertNoDuplicates($b['tenant']);
        $this->assertSame($afterRadar, $this->contacts($b['tenant'])->count(), 'mismo conjunto final sea cual sea el orden');
        $keysB = $this->contacts($b['tenant'])->map(fn ($r) => [$r->customer_id, $r->product_id, $r->horizon_days])->sort()->values()->all();
        $this->assertCount($afterRadar, $keysB);
        // Repetir ambos no crea nada.
        $this->radarProposes($b['tenant']);
        $this->engineGenerates($b['tenant']);
        $this->assertSame($afterRadar, $this->contacts($b['tenant'])->count());
        $this->assertNoDuplicates($b['tenant']);
    }

    public function test_the_radar_priorities_equal_the_rank_of_the_score_within_their_group(): void
    {
        $w = $this->decisionWorld();

        $this->radarProposes($w['tenant']);

        $formula = ScoreFormula::fromSettings(DecisionSettings::forTenant($w['tenant']));
        $contacts = $this->contacts($w['tenant']);
        foreach ($contacts->groupBy('horizon_days') as $horizon => $group) {
            $expected = $formula->priorities($group->mapWithKeys(fn ($r) => [$r->idempotency_key => $r->score])->all());
            foreach ($group as $rec) {
                $this->assertSame($expected[$rec->idempotency_key], $rec->priority, "horizonte {$horizon}: prioridad por posición del score");
            }
            $this->assertSame(1, $group->sortByDesc('score')->first()->priority);
        }
        $this->assertGreaterThan(1, $contacts->pluck('priority')->unique()->count());
    }

    /** @return list<array{0: string, 1: string, 2: float}> cliente, producto, unidades esperadas (el score crece con las unidades) */
    private static function items(): array
    {
        return [['c1', 'p1', 10.0], ['c2', 'p1', 5.0], ['c1', 'p2', 30.0], ['c2', 'p2', 2.0]];
    }

    /** @param list<array{0: string, 1: string, 2: float}> $items */
    private function proposeAll(array $w, array $items, int $horizon = 30): void
    {
        foreach ($items as [$c, $p, $units]) {
            $this->withinTenant($w['tenant'], fn () => app(RecommendationSink::class)->propose($this->contactProposal($w, [
                'customer_id' => $w['cat']['customers'][$c], 'product_id' => $w['cat']['products'][$p], 'expected_units' => $units, 'horizon_days' => $horizon,
            ])));
        }
    }

    /** @return array<string, int> prioridad por «cliente|producto» */
    private function prioritiesOf(array $w, int $horizon = 30): array
    {
        $byCustomer = array_flip($w['cat']['customers']);
        $byProduct = array_flip($w['cat']['products']);

        return $this->contacts($w['tenant'])->where('horizon_days', $horizon)->mapWithKeys(fn ($r) => [$byCustomer[$r->customer_id].'|'.$byProduct[$r->product_id] => $r->priority])->all();
    }

    public function test_the_priority_does_not_depend_on_the_order_in_which_items_are_proposed_one_by_one(): void
    {
        $a = $this->decisionWorld();
        $b = $this->decisionWorld();

        $this->proposeAll($a, self::items());
        $this->proposeAll($b, array_reverse(self::items()));

        // Scores: c1|p2 (30 u, costo no confiable ×0,2) = 48 · c1|p1 (10 u × 2) = 20 · c2|p1 = 10 · c2|p2 = 3,2 → posiciones 0, 25, 50, 75 % → 1, 2, 3, 4.
        $expected = ['c1|p2' => 1, 'c1|p1' => 2, 'c2|p1' => 3, 'c2|p2' => 4];
        $this->assertEquals($expected, $this->prioritiesOf($a));
        $this->assertEquals($expected, $this->prioritiesOf($b), 'el mismo conjunto final da las mismas prioridades sea cual sea el orden de llegada');
        // Es el mismo resultado que un lote generado de una vez por el generador (misma función de orden).
        $this->assertSame(1, $this->contacts($a['tenant'])->sortByDesc('score')->first()->priority);
    }

    public function test_a_better_score_arriving_later_pushes_the_earlier_ones_down_and_groups_are_independent(): void
    {
        $w = $this->decisionWorld();
        $this->proposeAll($w, [['c1', 'p1', 10.0]]);
        $this->assertEquals(['c1|p1' => 1], $this->prioritiesOf($w), 'sola en su grupo es la primera');

        $this->proposeAll($w, [['c1', 'p2', 30.0]]);
        $this->assertEquals(['c1|p1' => 3, 'c1|p2' => 1], $this->prioritiesOf($w), '50 % del grupo por encima → prioridad 3');

        // Otro horizonte es otro grupo (en los tipos de cliente): no se ve afectado ni afecta.
        $this->proposeAll($w, [['c2', 'p1', 1.0]], 90);
        $this->assertEquals(['c2|p1' => 1], $this->prioritiesOf($w, 90));
        $this->assertEquals(['c1|p1' => 3, 'c1|p2' => 1], $this->prioritiesOf($w));
        // Las vencidas dejan de contar para el orden del grupo.
        $loser = $this->contacts($w['tenant'])->first(fn ($r) => $r->horizon_days === 30 && $r->product_id === $w['cat']['products']['p2']);
        DB::table('decision_recommendations')->where('id', $loser->id)->update(['status' => 'expired']);
        $this->proposeAll($w, [['c2', 'p2', 2.0]]);
        $this->assertEquals(['c1|p1' => 1, 'c2|p2' => 3], array_intersect_key($this->prioritiesOf($w), ['c1|p1' => 1, 'c2|p2' => 1]));
    }

    public function test_a_score_without_value_is_the_lowest_priority_and_the_batch_generator_agrees_with_propose(): void
    {
        $w = $this->decisionWorld();
        // Producto sin ventas ni precio: sin importe ni contribución → score nulo.
        $noPrice = $this->withinTenant($w['tenant'], fn () => DB::table('products')->insertGetId(['tenant_id' => $w['tenant']->id, 'sku' => 'SIN-PRECIO', 'name' => 'Sin ventas', 'unit' => 'unidad', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]));
        $this->proposeAll($w, [['c1', 'p1', 10.0]]);
        $rec = $this->withinTenant($w['tenant'], fn () => app(RecommendationSink::class)->propose($this->contactProposal($w, ['product_id' => $noPrice, 'unit_price' => null, 'expected_units' => null])));
        $this->assertNull($rec->score);
        $this->assertSame(5, $rec->priority);

        // El generador por lotes ordena igual que propose() una por una: mismo conjunto, mismas prioridades.
        $batch = $this->decisionWorld();
        $this->engineGenerates($batch['tenant']);
        $single = $this->decisionWorld();
        foreach ($this->contacts($batch['tenant']) as $r) {
            $this->withinTenant($single['tenant'], fn () => app(RecommendationSink::class)->propose($this->contactProposal($single, [
                'customer_id' => $single['cat']['customers'][array_search($r->customer_id, $batch['cat']['customers'], true)],
                'product_id' => $single['cat']['products'][array_search($r->product_id, $batch['cat']['products'], true)],
                'expected_units' => collect($r->explanation)->firstWhere('code', 'value_calculation')['value']['expected_units'],
                'unit_price' => collect($r->explanation)->firstWhere('code', 'value_calculation')['value']['unit_price'],
            ])));
        }
        $key = fn (array $w, $r) => array_search($r->customer_id, $w['cat']['customers'], true).'|'.array_search($r->product_id, $w['cat']['products'], true);
        $this->assertEquals(
            $this->contacts($batch['tenant'])->mapWithKeys(fn ($r) => [$key($batch, $r) => $r->priority])->sortKeys()->all(),
            $this->contacts($single['tenant'])->mapWithKeys(fn ($r) => [$key($single, $r) => $r->priority])->sortKeys()->all(),
        );
    }
}
