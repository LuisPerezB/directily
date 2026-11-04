<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Models\DecisionRecommendation;
use App\Models\Tenant;
use App\Services\Decision\Generators\BudgetConstraintGenerator;
use App\Services\Decision\Generators\CandidateSource;
use App\Services\Decision\Generators\CustomerOpportunityGenerator;
use App\Services\Decision\Generators\InventoryMismatchGenerator;
use App\Services\Decision\Generators\ReplenishmentGenerator;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Generación de recomendaciones de una empresa (V2.5 · W5-B), la que ejecuta `predictily:decisions-generate`. Para cada fuente de candidatos
 * (cliente, reposición, inventario-demanda, presupuesto) puntúa el lote con el DecisionEngine, persiste lo nuevo y ordena cada grupo tocado (prioridad por
 * posición del score). IDEMPOTENTE: ejecutarla otra vez con los mismos insumos no crea nada (misma huella); con insumos nuevos crea la nueva y expira
 * la anterior sin decidir. Una fuente que falla no detiene a las demás. No escribe en el ERP ni envía mensajes.
 *
 * @phpstan-type Report array{tenant_id: int, dry_run: bool, created: array<string, int>, existing: array<string, int>, invalid: int, skipped: array<string, string>, errors: list<string>}
 */
final class DecisionGenerator
{
    public function __construct(private readonly TenantContext $context, private readonly DecisionEngine $engine) {}

    /** @return list<CandidateSource> */
    private function sources(): array
    {
        return [app(CustomerOpportunityGenerator::class), app(ReplenishmentGenerator::class), app(InventoryMismatchGenerator::class), app(BudgetConstraintGenerator::class)];
    }

    /**
     * @return array{tenant_id: int, dry_run: bool, created: array<string, int>, existing: array<string, int>, invalid: int, skipped: array<string, string>, errors: list<string>}
     */
    public function generate(Tenant $tenant, ?CarbonImmutable $now = null, bool $dryRun = false): array
    {
        return $this->context->runAs($tenant, function () use ($tenant, $now, $dryRun): array {
            $settings = DecisionSettings::forTenant($tenant);
            $today = $now ?? CarbonImmutable::now($settings->timezone());
            $report = ['tenant_id' => (int) $tenant->getKey(), 'dry_run' => $dryRun, 'created' => [], 'existing' => [], 'invalid' => 0, 'skipped' => [], 'errors' => []];
            $this->engine->flush();

            foreach ($this->sources() as $source) {
                try {
                    $collected = $source->collect($tenant, $settings, $today);
                } catch (Throwable $e) {
                    Log::error('Decision Engine: fuente de candidatos con error', ['tenant_id' => $tenant->getKey(), 'source' => $source::class, 'error' => $e->getMessage()]);
                    $report['errors'][] = class_basename($source).': '.$e->getMessage();

                    continue;
                }
                $report['skipped'] += $collected['skipped'];
                foreach ($collected['groups'] as $group) {
                    try {
                        $this->processGroup($group, $dryRun, $report);
                    } catch (Throwable $e) {
                        Log::error('Decision Engine: grupo con error', ['tenant_id' => $tenant->getKey(), 'group' => $group['label'], 'error' => $e->getMessage()]);
                        $report['errors'][] = $group['label'].': '.$e->getMessage();
                    }
                }
            }
            $this->engine->flush();

            return $report;
        });
    }

    /**
     * @param  array{kind: string, label: string, as_of: CarbonImmutable, max: int|null, attributes: list<array<string, mixed>>}  $group
     * @param  array<string, mixed>  $report
     */
    private function processGroup(array $group, bool $dryRun, array &$report): void
    {
        if ($group['attributes'] === []) {
            return;
        }
        // El tope se aplica ANTES de valorar: las fuentes entregan los candidatos mejores primero (por un importe aproximado) y solo se valoran el doble del tope; el
        // score final decide cuáles se quedan dentro de ese margen. Así valorar no cuesta lo que cuesta el universo de candidatos.
        $candidates = $group['max'] === null ? $group['attributes'] : array_slice($group['attributes'], 0, $group['max'] * 2);
        $this->engine->prime($group['as_of'], array_values(array_filter(array_map(fn (array $a) => $a['product_id'] ?? null, $candidates))));

        $prepared = [];
        foreach ($candidates as $attributes) {
            try {
                $prepared[] = $this->engine->prepare($attributes);
            } catch (ValidationException) {
                $report['invalid']++;
            }
        }
        // Mejores primero (score desc; sin score al final; empate por huella): el tope por grupo conserva las más valiosas.
        usort($prepared, fn (PreparedProposal $a, PreparedProposal $b) => (($b->score ?? -INF) <=> ($a->score ?? -INF)) ?: strcmp($a->key, $b->key));
        if ($group['max'] !== null) {
            $prepared = array_slice($prepared, 0, $group['max']);
        }
        $existingKeys = $prepared === [] ? [] : DecisionRecommendation::query()->whereIn('idempotency_key', array_map(fn (PreparedProposal $p) => $p->key, $prepared))->pluck('idempotency_key')->all();
        $existingSet = array_flip($existingKeys);

        $label = $group['kind'];
        $groups = [];
        foreach ($prepared as $p) {
            if (isset($existingSet[$p->key])) {
                $report['existing'][$label] = ($report['existing'][$label] ?? 0) + 1;

                continue;
            }
            if (! $dryRun) {
                $rec = $this->engine->persist($p, false);
                if (! $rec->wasRecentlyCreated) {
                    // Había una en curso en el alcance (con seguimiento o trabajo humano): se conserva y solo se registró el refresco.
                    $report['existing'][$label] = ($report['existing'][$label] ?? 0) + 1;

                    continue;
                }
                $groups[implode('|', [$p->columns['kind'], $p->columns['entity_type'], $p->columns['as_of'], $p->columns['horizon_days']])] = $p->columns;
            }
            $report['created'][$label] = ($report['created'][$label] ?? 0) + 1;
        }
        // La prioridad es por posición del score dentro del grupo: se ordena cada grupo tocado UNA vez, con el mismo criterio con que ordena `propose()`.
        foreach ($groups as $c) {
            $this->engine->rerank($c['kind'], $c['entity_type'], $c['as_of'], (int) $c['horizon_days']);
        }
    }
}
