<?php

declare(strict_types=1);

namespace App\Services\Outcomes;

use App\Models\DecisionEvent;
use App\Models\DecisionRecommendation;
use App\Models\ExperimentAssignment;
use App\Services\Decision\ExperimentAssigner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reconstruye, desde lo REGISTRADO al generar (V2.5 · W5-C; ADR-V25-14), a qué experimento y grupo pertenece una recomendación:
 *
 *  - La clave del experimento sale del evento de propuesta (`decision_events`, payload.experiment.experiment_key) cuando el Decision Engine la dejó allí. Si no está,
 *    se infiere de las asignaciones del cliente: la ÚNICA clave con el mismo grupo que trae la recomendación. Si hay varias claves posibles (se cambió la clave
 *    del experimento con asignaciones previas) o ninguna, la recomendación NO entra al análisis: no se adivina (`experiment_key_ambiguous`).
 *  - El grupo se VERIFICA contra `experiment_assignments` (unidad `customer:<id>`): debe coincidir con el de la recomendación y haberse registrado a más tardar al
 *    generarla (con 5 min de margen por desfase de reloj). Sin esa verificación la recomendación NO entra al análisis y la razón queda en la evidencia.
 *  - Una recomendación con grupo pero de un tipo que no participa en el experimento (solo contacto y recuperación) tampoco entra.
 */
final class ExperimentResolver
{
    private const CLOCK_SKEW_SECONDS = 300;

    public const DEFAULT_CHUNK = 5000;

    public function __construct(private readonly int $chunk = self::DEFAULT_CHUNK) {}

    /**
     * @param  Collection<int, DecisionRecommendation>  $recs
     * @return array<int, array<string, mixed>> por id de recomendación con grupo asignado: `verified`, `reason`, `key`, `key_source`, `group`, `unit_key`, `control_fraction`, …
     */
    public function resolve(Collection $recs): array
    {
        $withGroup = $recs->filter(fn (DecisionRecommendation $r) => $r->experiment_group !== null);
        if ($withGroup->isEmpty()) {
            return [];
        }
        // Las consultas por lotes: una empresa grande supera el límite de parámetros de PDO (65 535) con un solo whereIn.
        $recorded = [];
        foreach ($withGroup->pluck('id')->chunk($this->chunk) as $ids) {
            foreach (DecisionEvent::query()->whereIn('recommendation_id', $ids->all())->whereNull('from_status')->where('to_status', 'suggested')->get(['recommendation_id', 'payload']) as $e) {
                $recorded[(int) $e->recommendation_id] = $e->payload['experiment']['experiment_key'] ?? null;
            }
        }
        $units = $withGroup->filter(fn (DecisionRecommendation $r) => $r->customer_id !== null)->map(fn (DecisionRecommendation $r) => ExperimentAssigner::unitKey((int) $r->customer_id))->unique()->values();
        /** @var Collection<string, Collection<int, ExperimentAssignment>> $assignments por unidad */
        $assignments = collect();
        foreach ($units->chunk($this->chunk) as $chunk) {
            $assignments = $assignments->concat(ExperimentAssignment::query()->whereIn('unit_key', $chunk->all())->where('unit_type', 'customer')->get());
        }
        $assignments = $assignments->groupBy('unit_key');

        $out = [];
        foreach ($withGroup as $rec) {
            $unitKey = $rec->customer_id === null ? null : ExperimentAssigner::unitKey((int) $rec->customer_id);
            $own = $unitKey === null ? collect() : $assignments->get($unitKey, collect());
            $key = $recorded[(int) $rec->id] ?? null;
            $keySource = $key === null ? null : 'proposal_event';
            $reason = null;
            if ($key === null) {
                $candidates = $own->where('group', $rec->experiment_group)->pluck('experiment_key')->unique()->values();
                if ($candidates->count() === 1) {
                    $key = (string) $candidates[0];
                    $keySource = 'single_assignment_of_customer';
                } else {
                    $reason = $candidates->isEmpty() ? 'experiment_key_not_recorded' : 'experiment_key_ambiguous';
                }
            }
            $base = ['verified' => false, 'reason' => $reason, 'key' => $key, 'key_source' => $keySource, 'group' => (string) $rec->experiment_group, 'unit_key' => $unitKey];
            if (! in_array($rec->kind, DecisionRecommendation::EXPERIMENT_KINDS, true) || $rec->customer_id === null) {
                $out[(int) $rec->id] = ['reason' => 'kind_not_in_experiment'] + $base;

                continue;
            }
            if ($key === null) {
                $out[(int) $rec->id] = $base;

                continue;
            }
            /** @var ExperimentAssignment|null $a */
            $a = $own->first(fn (ExperimentAssignment $x) => $x->experiment_key === $key);
            $out[(int) $rec->id] = match (true) {
                $a === null => ['reason' => 'assignment_not_found'] + $base,
                $a->group !== $rec->experiment_group => ['reason' => 'assignment_group_mismatch'] + $base,
                $a->assigned_at->getTimestamp() > $rec->created_at->getTimestamp() + self::CLOCK_SKEW_SECONDS => ['reason' => 'assignment_registered_after_generation'] + $base,
                default => [
                    'verified' => true, 'reason' => null, 'control_fraction' => (float) ($a->policy['control_fraction'] ?? 0), 'algorithm' => $a->policy['algorithm'] ?? null,
                    'assigned_at' => $a->assigned_at->toIso8601String(),
                ] + $base,
            };
        }

        return $out;
    }

    /**
     * Unidades ya contadas en el análisis de un experimento y horizonte (una por cliente: las que llevan `itt_counted` en la evidencia de sus resultados), opcionalmente
     * solo las de cohortes con corte (`as_of`) dentro del periodo.
     *
     * @return list<array<string, mixed>>
     */
    public function countedUnits(int $tenantId, string $experimentKey, int $horizonDays, ?string $asOfFrom = null, ?string $asOfTo = null): array
    {
        $units = [];
        $after = 0;
        do {
            // Por lotes con paginación por clave (id): no se carga toda la evidencia de miles de resultados de una vez, solo el bloque `unit` de cada uno.
            $rows = DB::table('decision_outcomes as o')
                ->join('decision_recommendations as r', fn ($j) => $j->on('r.id', '=', 'o.recommendation_id')->on('r.tenant_id', '=', 'o.tenant_id'))
                ->join(DB::raw('LATERAL jsonb_to_record(o.evidence) AS x(experiment jsonb, unit jsonb)'), DB::raw('true'), '=', DB::raw('true'))
                ->where('o.tenant_id', $tenantId)->where('r.horizon_days', $horizonDays)->where('o.id', '>', $after)
                ->whereRaw("x.experiment->>'key' = ?", [$experimentKey])
                ->whereRaw("x.experiment->>'itt_counted' = 'true'")
                ->when($asOfFrom !== null, fn ($q) => $q->where('r.as_of', '>=', $asOfFrom))
                ->when($asOfTo !== null, fn ($q) => $q->where('r.as_of', '<=', $asOfTo))
                ->orderBy('o.id')->limit($this->chunk)->selectRaw('o.id, r.customer_id, r.experiment_group, x.unit AS unit')->get();
            foreach ($rows as $row) {
                $after = (int) $row->id;
                $unit = (array) json_decode((string) $row->unit, true);
                $units[] = [
                    'customer_id' => (int) $row->customer_id, 'group' => (string) $row->experiment_group, 'converted' => (int) ($unit['converted'] ?? 0),
                    'contribution' => isset($unit['contribution']) ? (float) $unit['contribution'] : null, 'in_population' => (bool) ($unit['in_population'] ?? false),
                    'control_fraction' => (float) ($unit['control_fraction'] ?? 0), 'contaminated' => (bool) ($unit['contaminated'] ?? false),
                ];
            }
        } while (count($rows) === $this->chunk);

        return $units;
    }

    public static function cohortKey(string $experimentKey, string $asOf, int $horizonDays): string
    {
        return $experimentKey.'|'.CarbonImmutable::parse($asOf)->toDateString().'|'.$horizonDays;
    }
}
