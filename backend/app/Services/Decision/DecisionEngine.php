<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Contracts\Decision\RecommendationSink;
use App\Models\Branch;
use App\Models\CoreRun;
use App\Models\Customer;
use App\Models\DecisionEvent;
use App\Models\DecisionRecommendation;
use App\Models\ForecastRun;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Decision Engine (V2.5 · W5-B; ADR-V25-13; §11): único punto que escribe `decision_recommendations`. Implementa RecommendationSink, la interfaz
 * por la que proponen Revenue Radar (W5-A), la reposición y los escenarios. NO entrena modelos ni genera números sin fuente: combina salidas
 * persistidas de Demand, Customer y Supply y reglas de la empresa.
 *
 * `propose()` (idempotente)
 *   1. Valida y normaliza los campos de §11 que aporta quien propone (los ids deben ser de la empresa: de otra se rechazan).
 *   2. Calcula la huella `idempotency_key` = SHA-256(kind, as_of, horizonte, entidad, fuente). Con la misma huella devuelve la existente, sin
 *      duplicar y sin escribir nada.
 *   3. Completa lo que el proponente no trae: economía unitaria as-of (precio, costo y su confiabilidad), disponibilidad, costo de la acción,
 *      score con la versión de su fórmula, prioridad, vencimiento, política, siguiente paso, explicación verificable y supuestos.
 *   4. Persiste en una transacción: la recomendación, la asignación al experimento (solo contacto/recuperación y fracción de control > 0), el
 *      evento inicial y el vencimiento de las anteriores del mismo alcance que nadie ha tocado (insumos nuevos reemplazan a los viejos).
 *
 * Reglas que NO se negocian (probadas): sin costo confiable `expected_contribution` es NULL y `uncertainty.cost` dice por qué; la contribución
 * que traiga el proponente se IGNORA (siempre la deriva el motor); `incremental_estimate` es NULL y la etiqueta `potential_unproven` («oportunidad
 * potencial, atribución no demostrada») mientras no haya evidencia de uplift; nada se escribe en el ERP ni se envía a ningún cliente.
 *
 * Campos que `propose()` acepta (además de los del docblock de la interfaz): `expected_units` (unidades esperadas; permite derivar importe y
 * contribución), `unit_price` (precio supuesto; por defecto el neto reciente as-of), `expires_at`, `owner_user_id`,
 * `proposed_by_user_id` (la propuso una persona: queda sujeta a la segregación de funciones), `agenda_status`, `uncertainty`, `data_snapshot_id`.
 */
final class DecisionEngine implements RecommendationSink
{
    /** Tipos cuyo grupo de prioridad incluye el horizonte (los demás lo ignoran: allí el horizonte es incidental). */
    private const HORIZON_GROUPED_KINDS = ['contact', 'recovery', 'customer_risk'];

    private const NEXT_STEPS = [
        'contact' => 'Contactar al cliente por el canal habitual y registrar el resultado. Predictily no envía mensajes ni escribe en el ERP.',
        'recovery' => 'Revisar el patrón de compra del cliente y contactarlo para entender la ausencia; una ausencia respecto a su patrón no implica abandono.',
        'customer_risk' => 'Revisar la evidencia (frecuencia y gasto) y decidir si corresponde un seguimiento comercial.',
        'replenishment' => 'Revisar la cantidad con el proveedor y, si se aprueba, emitir el pedido en el ERP. Predictily no emite pedidos.',
        'inventory_mismatch' => 'Revisar el inventario frente a la demanda esperada y decidir reposición, redistribución o promoción.',
        'budget_constraint' => 'Revisar el presupuesto de compra del período o priorizar los productos con mayor contribución.',
        'scenario' => 'Comparar el escenario con el plan vigente antes de decidir.',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly Workflow $workflow,
        private readonly ExperimentAssigner $experiments,
        private readonly UnitEconomicsLoader $economics,
        private readonly InventoryAvailabilityLoader $inventory,
    ) {}

    public function propose(array $attributes): DecisionRecommendation
    {
        return $this->persist($this->prepare($attributes));
    }

    public function settings(): DecisionSettings
    {
        return DecisionSettings::forTenant($this->context->require());
    }

    /** Carga por lotes la economía unitaria y la disponibilidad de un conjunto de productos (evita una consulta por recomendación). */
    public function prime(CarbonImmutable $asOf, array $productIds): void
    {
        $tenant = $this->context->require();
        $settings = DecisionSettings::forTenant($tenant);
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        $this->economics->prime($tenant, $settings, $asOf, $ids);
        $this->inventory->prime($tenant, $settings, $asOf, $ids);
    }

    public function flush(): void
    {
        $this->economics->flush();
        $this->inventory->flush();
        $this->known = [];
    }

    /** @var array<string, true> ids que ya se comprobó que existen en la empresa activa (clase|id), para no consultar una vez por candidata */
    private array $known = [];

    /**
     * Precarga de una vez qué clientes, productos y sucursales existen en la empresa activa.
     *
     * @param  array{customer: list<int>, product: list<int>, branch: list<int>}  $ids
     */
    private function primeIds(array $ids): void
    {
        foreach (['customer' => Customer::class, 'product' => Product::class, 'branch' => Branch::class] as $what => $model) {
            $wanted = array_values(array_unique($ids[$what]));
            foreach (array_chunk($wanted, 1000) as $chunk) {
                foreach ($model::query()->whereKey($chunk)->pluck('id') as $id) {
                    $this->known[$model.'|'.(int) $id] = true;
                }
            }
        }
    }

    /** @param class-string<Model> $model */
    private function idKnown(string $model, int $id): bool
    {
        return isset($this->known[$model.'|'.$id]) || ($model::query()->whereKey($id)->exists() && ($this->known[$model.'|'.$id] = true));
    }

    /** Normaliza, enriquece y puntúa SIN escribir. */
    public function prepare(array $attributes): PreparedProposal
    {
        $tenant = $this->context->require();
        $settings = DecisionSettings::forTenant($tenant);
        $formula = ScoreFormula::fromSettings($settings);
        $in = $this->validated($attributes, $tenant);
        $asOf = CarbonImmutable::parse($in['as_of'], $settings->timezone())->startOfDay();
        $kind = $in['kind'];

        // ── Economía unitaria y disponibilidad (todo as-of, nada recalculado) ──
        $eco = $in['product_id'] !== null ? $this->economics->forProduct($tenant, $settings, $asOf, $in['product_id']) : null;
        $availability = $in['availability'] !== []
            ? $in['availability']
            : ($in['product_id'] !== null && $in['branch_id'] === null
                ? $this->inventory->forProduct($tenant, $settings, $asOf, $in['product_id'])
                : ['status' => 'unknown', 'reason' => 'availability_not_provided']);
        $availableUnits = ($availability['status'] ?? null) === 'known' && isset($availability['available']) ? max(0.0, (float) $availability['available']) : null;

        $units = $in['expected_units'];
        $price = $in['unit_price'] ?? $eco?->price;
        // Solo el contacto se limita a lo disponible (no se vende lo que no hay). En reposición, faltante y presupuesto las unidades YA son las que faltan.
        $capped = $kind === 'contact' && $units !== null && $availableUnits !== null && $availableUnits < $units;
        $sellable = $units === null ? null : ($capped ? $availableUnits : $units);
        $costReliable = $eco?->costReliable === true;
        $contributionBasis = null;
        if ($units !== null && $price !== null) {
            $gross = round($sellable * $price, 4);
            $contribution = $costReliable ? round($sellable * ($price - (float) $eco->cost), 4) : null;
            $contributionBasis = 'units_x_(price-cost)';
        } else {
            $gross = $in['expected_gross_value'];
            $ratio = $eco?->marginRatio();
            $contribution = $gross !== null && $ratio !== null ? round($gross * $ratio, 4) : null;
            $contributionBasis = $contribution === null ? null : 'gross_x_unit_margin_ratio';
        }
        if (! $costReliable) {
            $contribution = null;
        }

        $constraints = $in['constraints'];
        if ($capped) {
            $constraints[] = ['code' => $availableUnits <= 0.0 ? 'out_of_stock' : 'stock_below_expected_units', 'available_units' => $availableUnits, 'expected_units' => $units,
                'note' => 'El valor esperado se limita a lo disponible al corte.'];
        }
        $actionCost = $in['action_cost'] ?? (in_array($kind, ['contact', 'recovery', 'customer_risk'], true) ? $settings->contactCost() : null);
        $evaluation = $formula->evaluate($kind, $contribution, $gross, $costReliable, $actionCost);

        $uncertainty = $in['uncertainty'] + [
            'cost' => $eco === null
                ? ['reliable' => false, 'source' => null, 'reasons' => ['no_product_scope'], 'note' => 'La recomendación no tiene un producto al que asociar un costo unitario: no se estima contribución.']
                : ['reliable' => $eco->costReliable, 'source' => $eco->costSource, 'reasons' => $eco->costReasons, 'stats' => $eco->stats],
            'availability' => ['status' => $availability['status'] ?? 'unknown', 'capped_units' => $capped],
            'value' => ['basis' => $evaluation['basis'], 'cost_unreliable' => $evaluation['cost_unreliable'], 'weight' => $evaluation['weight']],
            'attribution' => 'Sin evidencia de uplift (grupo de control analizado): oportunidad potencial, atribución no demostrada.',
        ];
        if ($contribution === null) {
            $uncertainty['expected_contribution_null_because'] = $eco === null ? ['no_product_scope'] : ($costReliable ? ['no_units_or_price'] : $eco->costReasons);
        }
        if ($actionCost === null && in_array($kind, ['contact', 'recovery', 'customer_risk'], true)) {
            $uncertainty['action_cost'] = ['status' => 'not_configured', 'note' => 'Costo de contacto no configurado para la empresa: el score no resta ningún costo.'];
        }

        $explanation = array_merge($in['explanation'], [[
            'code' => 'value_calculation', 'source' => 'decision_engine:'.ScoreFormula::ID,
            'label' => 'Cálculo del valor esperado a partir de insumos persistidos (sin PII)',
            'value' => array_filter([
                'expected_units' => $units, 'sellable_units' => $sellable, 'available_units' => $availableUnits, 'unit_price' => $price,
                'unit_cost' => $costReliable ? $eco->cost : null, 'cost_source' => $costReliable ? $eco->costSource : null,
                'expected_gross_value' => $gross, 'expected_contribution' => $contribution, 'contribution_basis' => $contributionBasis,
                'action_cost' => $actionCost, 'value_used' => $evaluation['value'], 'value_basis' => $evaluation['basis'], 'weight' => $evaluation['weight'],
                'score' => $evaluation['score'],
            ], fn ($v) => $v !== null),
        ]]);

        $key = self::idempotencyKey($kind, $in['as_of'], $in['horizon_days'], [$in['entity_type'], $in['customer_id'], $in['product_id'], $in['branch_id']],
            [$in['source_core_run_id'], $in['source_forecast_run_id']]);

        $columns = [
            'kind' => $kind, 'as_of' => $in['as_of'], 'horizon_days' => $in['horizon_days'], 'entity_type' => $in['entity_type'],
            'customer_id' => $in['customer_id'], 'product_id' => $in['product_id'], 'branch_id' => $in['branch_id'],
            'source_core_run_id' => $in['source_core_run_id'], 'source_forecast_run_id' => $in['source_forecast_run_id'], 'data_snapshot_id' => $in['data_snapshot_id'],
            'model_versions' => $in['model_versions'], 'probability' => $in['probability'], 'interval' => $in['interval'], 'assumptions' => $in['assumptions'],
            'constraints' => $constraints, 'availability' => $availability, 'action_cost' => $actionCost,
            'expected_gross_value' => $gross, 'expected_contribution' => $contribution, 'incremental_estimate' => null, 'incremental_evidence' => null,
            'attribution_label' => 'potential_unproven', 'score' => $evaluation['score'], 'score_formula_version' => $formula->version(),
            'uncertainty' => $uncertainty, 'explanation' => $explanation, 'policy' => $in['policy'] ?? $kind.':'.ScoreFormula::ID.'-r'.ScoreFormula::REVISION,
            'priority' => $evaluation['score'] === null ? 5 : 3, 'owner_user_id' => $in['owner_user_id'],
            'status' => 'suggested', 'agenda_status' => $in['agenda_status'], 'proposed_by_kind' => $in['proposed_by_user_id'] === null ? 'system' : 'user',
            'proposed_by_user_id' => $in['proposed_by_user_id'],
            'expires_at' => $in['expires_at'] ?? $settings->expiresAt($kind, $asOf, $in['horizon_days']),
            'next_step' => $in['next_step'] ?? self::NEXT_STEPS[$kind], 'idempotency_key' => $key,
        ];

        return new PreparedProposal($columns, $key, $evaluation['score'], $in['proposed_by_user_id'], [
            'formula_version' => $formula->version(), 'idempotency_key' => $key, 'cost_reliable' => $costReliable, 'value_basis' => $evaluation['basis'],
        ]);
    }

    /**
     * Persiste una recomendación preparada (idempotente por huella). Con `$rerank` (por defecto) recalcula la prioridad de su grupo al crearla; el
     * generador por lotes lo apaga y ordena cada grupo UNA vez al final.
     *
     * Si el alcance (tipo, entidad, horizonte, cliente, producto, sucursal) ya tiene una recomendación EN CURSO ({@see DecisionRecommendation::IN_FLIGHT_AGENDA}), no
     * se crea una sucesora ni se expira esa: se devuelve la que está en curso (`wasRecentlyCreated = false`) y se agrega un comentario de refresco (`decision_events`,
     * event `comment`, `payload.kind = refresh`) con la huella de la nueva fuente y los valores refrescados, de modo que el linaje se conserva. Su propio `expires_at`
     * sigue mandando; cuando termine o venza, la siguiente corrida crea una nueva.
     *
     * @param  array<string, DecisionRecommendation>  $preloaded  recomendaciones ya cargadas por huella (la carga por lotes evita una consulta por candidata)
     */
    public function persist(PreparedProposal $p, bool $rerank = true, array $preloaded = []): DecisionRecommendation
    {
        $existing = $preloaded[$p->key] ?? DecisionRecommendation::query()->where('idempotency_key', $p->key)->first();
        if ($existing !== null) {
            return $existing;
        }
        $settings = $this->settings();

        try {
            return DB::transaction(function () use ($p, $settings, $rerank): DecisionRecommendation {
                $inFlight = $this->inFlightIn($p->columns);
                if ($inFlight !== null) {
                    $this->recordRefresh($inFlight, $p);

                    return $inFlight;
                }
                $columns = $p->columns;
                $assignment = null;
                if (in_array($p->kind(), DecisionRecommendation::EXPERIMENT_KINDS, true) && $columns['customer_id'] !== null) {
                    $assignment = $this->experiments->assign($settings, (int) $columns['customer_id']);
                    $columns['experiment_group'] = $assignment['group'] ?? null;
                }
                $rec = DecisionRecommendation::query()->create($columns);
                // La asignación al experimento SE GUARDA en el evento inicial (la medición de outcomes de W5-C la necesita para el análisis por intención de tratamiento);
                // la presentación la oculta a quien no puede aprobar (ver ExperimentRedaction).
                $this->workflow->recordProposal($rec, $p->proposerId, $p->eventPayload + ['experiment' => $assignment === null ? null : [
                    'experiment_key' => $assignment['experiment_key'], 'group' => $assignment['group'],
                ]]);
                $this->supersede($rec);
                if ($rerank) {
                    $this->rerank($rec->kind, $rec->entity_type, $rec->as_of->toDateString(), $rec->horizon_days);
                }

                return $rec->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            // Otro proceso creó la misma recomendación entre la comprobación y la inserción: es la misma huella, se devuelve esa.
            return DecisionRecommendation::query()->where('idempotency_key', $p->key)->first() ?? throw new \RuntimeException('Conflicto de unicidad sin la recomendación esperada.');
        }
    }

    /**
     * Propone un lote (p. ej. las oportunidades de una corrida del Radar) con consultas POR LOTE en vez de por candidata: carga de una vez la economía unitaria y la
     * disponibilidad de los productos, comprueba de una vez qué huellas ya existen y reordena cada grupo una sola vez al final. Una candidata inválida no detiene a
     * las demás. Mismo resultado que llamar a `propose()` una por una (las pruebas lo verifican).
     *
     * @param  list<array<string, mixed>>  $batch
     * @return list<array{recommendation: DecisionRecommendation|null, error: string|null}> en el mismo orden
     */
    public function proposeMany(array $batch): array
    {
        $tenant = $this->context->require();
        $settings = DecisionSettings::forTenant($tenant);
        $byDate = [];
        $ids = ['customer' => [], 'product' => [], 'branch' => []];
        foreach ($batch as $a) {
            if (isset($a['product_id']) && isset($a['as_of'])) {
                $byDate[(string) $a['as_of']][] = (int) $a['product_id'];
            }
            foreach (['customer', 'product', 'branch'] as $what) {
                if (isset($a[$what.'_id']) && $a[$what.'_id'] !== '') {
                    $ids[$what][] = (int) $a[$what.'_id'];
                }
            }
        }
        $this->primeIds($ids);
        foreach ($byDate as $date => $productIds) {
            try {
                $this->prime(CarbonImmutable::parse($date, $settings->timezone())->startOfDay(), $productIds);
            } catch (\Throwable) {
                // una fecha inválida se reporta candidata por candidata en prepare()
            }
        }

        $out = [];
        $prepared = [];
        foreach ($batch as $i => $attributes) {
            try {
                $prepared[$i] = $this->prepare($attributes);
                $out[$i] = ['recommendation' => null, 'error' => null];
            } catch (ValidationException $e) {
                $out[$i] = ['recommendation' => null, 'error' => collect($e->errors())->flatten()->first()];
            }
        }
        $existing = [];
        foreach (array_chunk(array_map(fn (PreparedProposal $p) => $p->key, $prepared), 1000) as $keys) {
            foreach (DecisionRecommendation::query()->whereIn('idempotency_key', $keys)->get() as $found) {
                $existing[$found->idempotency_key] = $found;
            }
        }
        $groups = [];
        foreach ($prepared as $i => $p) {
            try {
                $rec = $this->persist($p, false, $existing);
            } catch (\Throwable $e) {
                $out[$i]['error'] = mb_substr($e->getMessage(), 0, 200);   // una candidata que falla no detiene a las demás

                continue;
            }
            $out[$i]['recommendation'] = $rec;
            if ($rec->wasRecentlyCreated) {
                $groups[implode('|', [$p->columns['kind'], $p->columns['entity_type'], $p->columns['as_of'], $p->columns['horizon_days']])] = $p->columns;
            }
        }
        foreach ($groups as $c) {
            $this->rerank($c['kind'], $c['entity_type'], $c['as_of'], (int) $c['horizon_days']);
        }
        ksort($out);

        return array_values($out);
    }

    /**
     * Prioridad 1–5 por la POSICIÓN del score dentro de su grupo (misma regla que {@see ScoreFormula::priorities()}, calculada en la base con una sola sentencia
     * que recorre el grupo sin cargarlo en memoria). Grupo = (tipo, entidad, corte) y, en los tipos de cliente (contacto, recuperación, riesgo), también el horizonte;
     * en reposición, inventario y presupuesto el horizonte es incidental (plazo + revisión) y el grupo es todo el corte.
     *
     * SOLO toca lo accionable: recomendaciones `suggested` sin seguimiento de agenda en curso y fuera del grupo de control. Lo que está en curso, vencido, rechazado o
     * terminado conserva su prioridad y NO cambia su `updated_at` (la sentencia no lo toca: reordenar no es una acción humana ni genera evento). Las filas del grupo se
     * bloquean (`FOR UPDATE`) mientras se reordena, de modo que dos propuestas simultáneas no se pisan; el resultado es DETERMINISTA: depende del conjunto final de
     * scores del grupo (empate por huella), no del orden de llegada.
     *
     * @return int cuántas filas cambiaron de prioridad
     */
    public function rerank(string $kind, string $entityType, string $asOf, ?int $horizon): int
    {
        $bands = array_map('floatval', (array) ScoreFormula::fromSettings($this->settings())->parameters()['priority_bands']);
        $grouped = in_array($kind, self::HORIZON_GROUPED_KINDS, true);
        $inFlightAgenda = "'".implode("','", DecisionRecommendation::IN_FLIGHT_AGENDA)."'";
        $cases = '';
        foreach ($bands as $i => $_) {
            $cases .= 'WHEN r.rn::float8 / r.n::float8 < ? THEN '.($i + 1).' ';
        }
        $sql = 'WITH pool AS (
                    SELECT id, score, idempotency_key FROM decision_recommendations
                    WHERE tenant_id = ? AND kind = ? AND entity_type = ? AND as_of = ?::date '.($grouped ? 'AND horizon_days = ? ' : '')."
                      AND status = 'suggested' AND experiment_group IS DISTINCT FROM 'control' AND (agenda_status IS NULL OR agenda_status NOT IN ({$inFlightAgenda}))
                    ORDER BY id FOR UPDATE
                 ), ranked AS (
                    SELECT id, CASE WHEN score IS NULL OR score <= 0 THEN NULL
                                    ELSE ROW_NUMBER() OVER (PARTITION BY (score IS NOT NULL AND score > 0) ORDER BY score DESC, idempotency_key COLLATE \"C\") - 1 END AS rn,
                           COUNT(*) FILTER (WHERE score > 0) OVER () AS n
                    FROM pool
                 ), target AS (
                    SELECT r.id, CASE WHEN r.rn IS NULL THEN 5 ELSE (CASE {$cases} ELSE 5 END) END AS priority FROM ranked r
                 )
                 UPDATE decision_recommendations d SET priority = t.priority FROM target t WHERE d.id = t.id AND d.tenant_id = ? AND d.priority <> t.priority";
        $bindings = [$this->context->require()->getKey(), $kind, $entityType, $asOf];
        if ($grouped) {
            $bindings[] = $horizon;
        }

        return DB::update($sql, [...$bindings, ...$bands, $this->context->require()->getKey()]);
    }

    /**
     * SHA-256(kind, as_of, horizonte, entidad, fuente) con JSON canónico: mismo orden y mismos tipos siempre.
     *
     * @param  array{0: string, 1: int|null, 2: int|null, 3: int|null}  $entity  tipo de entidad, cliente, producto, sucursal
     * @param  array{0: int|null, 1: int|null}  $source  corrida de core y corrida de pronóstico (la huella de datos —data_snapshot_id— se deriva de la corrida: se
     *                                                   guarda en la recomendación pero no entra en la clave, para que dos proponentes de la misma corrida coincidan)
     */
    public static function idempotencyKey(string $kind, string $asOf, int $horizonDays, array $entity, array $source): string
    {
        return hash('sha256', ScoreFormula::canonicalJson(['kind' => $kind, 'as_of' => $asOf, 'horizon_days' => $horizonDays, 'entity' => $entity, 'source' => $source]));
    }

    /**
     * Las anteriores del mismo alcance (tipo, entidad, horizonte) con corte igual o anterior que NADIE ha tocado (`suggested` sin seguimiento en curso) expiran: los
     * insumos nuevos las reemplazan. Si hubiera alguna EN CURSO, `persist()` no habría llegado aquí (no se crea sucesora): esto solo reemplaza lo que no se trabajó.
     */
    private function supersede(DecisionRecommendation $new): void
    {
        $ids = $this->sameScope(DecisionRecommendation::query()->where('id', '<>', $new->id), $new->only(['kind', 'entity_type', 'horizon_days', 'customer_id', 'product_id', 'branch_id']))
            ->where('status', 'suggested')->where('as_of', '<=', $new->as_of->toDateString())->orderBy('id')->pluck('id');
        foreach ($ids as $id) {
            $this->workflow->systemTransition((int) $id, 'expired', 'superseded:'.$new->id, 'Reemplazada por una recomendación calculada con insumos más nuevos.', [
                'reason_code' => 'superseded', 'superseded_by' => $new->id,
            ]);
        }
    }

    /**
     * La recomendación EN CURSO del alcance, si la hay (bloqueada para que el refresco no choque con una transición simultánea). A lo sumo una por alcance.
     *
     * @param  array<string, mixed>  $columns
     */
    private function inFlightIn(array $columns): ?DecisionRecommendation
    {
        return $this->sameScope(DecisionRecommendation::query()->inFlight(), $columns)->orderBy('id')->lockForUpdate()->first();
    }

    /**
     * Comentario de linaje en la recomendación en curso: qué fuente nueva se propuso y con qué valores. La recomendación NO cambia (sus cifras son las de cuando se
     * trabajó); idempotente por (en curso, huella propuesta). No cuenta para la versión del bloqueo optimista.
     */
    private function recordRefresh(DecisionRecommendation $inFlight, PreparedProposal $p): void
    {
        $key = hash('sha256', 'decision.refresh:'.$inFlight->id.':'.$p->key);
        if (DecisionEvent::query()->where('idempotency_key', $key)->exists()) {
            return;
        }
        $c = $p->columns;
        DecisionEvent::query()->create([
            'recommendation_id' => $inFlight->id, 'event' => 'comment', 'from_status' => $inFlight->status, 'to_status' => $inFlight->status, 'actor_user_id' => null,
            'actor_kind' => 'system', 'reason' => 'Insumos más nuevos propuestos mientras la recomendación está en curso: se conserva y se registra el refresco.',
            'payload' => [
                'kind' => 'refresh', 'proposed_idempotency_key' => $p->key, 'as_of' => $c['as_of'],
                'source' => ['core_run_id' => $c['source_core_run_id'], 'forecast_run_id' => $c['source_forecast_run_id'], 'data_snapshot_id' => $c['data_snapshot_id']],
                'values' => [
                    'probability' => $c['probability'], 'expected_gross_value' => $c['expected_gross_value'], 'expected_contribution' => $c['expected_contribution'],
                    'action_cost' => $c['action_cost'], 'score' => $p->score, 'score_formula_version' => $c['score_formula_version'],
                ],
            ],
            'idempotency_key' => $key,
        ]);
    }

    /**
     * @param  Builder<DecisionRecommendation>  $query
     * @param  array<string, mixed>  $scope
     * @return Builder<DecisionRecommendation>
     */
    private function sameScope($query, array $scope)
    {
        $query->where('kind', $scope['kind'])->where('entity_type', $scope['entity_type'])->where('horizon_days', $scope['horizon_days']);
        foreach (['customer_id', 'product_id', 'branch_id'] as $column) {
            $scope[$column] === null ? $query->whereNull($column) : $query->where($column, $scope[$column]);
        }

        return $query;
    }

    // ───────────────────────── validación ─────────────────────────

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    private function validated(array $a, Tenant $tenant): array
    {
        $errors = [];
        $kind = $a['kind'] ?? null;
        if (! is_string($kind) || ! in_array($kind, DecisionRecommendation::KINDS, true)) {
            $errors['kind'] = 'El tipo debe ser uno de: '.implode(', ', DecisionRecommendation::KINDS).'.';
        }
        $entityType = $a['entity_type'] ?? null;
        if (! is_string($entityType) || ! in_array($entityType, DecisionRecommendation::ENTITY_TYPES, true)) {
            $errors['entity_type'] = 'La entidad debe ser una de: '.implode(', ', DecisionRecommendation::ENTITY_TYPES).'.';
        }
        $asOf = null;
        try {
            $asOf = isset($a['as_of']) ? CarbonImmutable::parse($a['as_of'] instanceof \DateTimeInterface ? $a['as_of']->format('Y-m-d') : (string) $a['as_of'])->toDateString() : null;
        } catch (\Throwable) {
        }
        if ($asOf === null) {
            $errors['as_of'] = 'La fecha de corte (as_of) es obligatoria (AAAA-MM-DD).';
        }
        $horizon = $a['horizon_days'] ?? null;
        if (! is_numeric($horizon) || (int) $horizon < 1 || (int) $horizon > 730) {
            $errors['horizon_days'] = 'El horizonte debe estar entre 1 y 730 días.';
        }
        $ids = [];
        foreach (['customer_id' => Customer::class, 'product_id' => Product::class, 'branch_id' => Branch::class] as $field => $model) {
            $ids[$field] = isset($a[$field]) && $a[$field] !== '' ? (int) $a[$field] : null;
            if ($ids[$field] !== null && ! $this->idKnown($model, $ids[$field])) {
                $errors[$field] = 'No existe en la empresa activa.';
            }
        }
        $needs = ['customer_product' => ['customer_id', 'product_id'], 'customer' => ['customer_id'], 'product_branch' => ['product_id', 'branch_id'], 'product' => ['product_id']];
        foreach ($needs[$entityType] ?? [] as $field) {
            if ($ids[$field] === null && ! isset($errors[$field])) {
                $errors[$field] = "La entidad «{$entityType}» exige {$field}.";
            }
        }
        $sources = [];
        foreach (['source_core_run_id' => CoreRun::class, 'source_forecast_run_id' => ForecastRun::class] as $field => $model) {
            $sources[$field] = isset($a[$field]) && $a[$field] !== '' ? (int) $a[$field] : null;
            if ($sources[$field] !== null && ! $this->idKnown($model, $sources[$field])) {
                $errors[$field] = 'La corrida no existe en la empresa activa.';
            }
        }
        $snapshot = isset($a['data_snapshot_id']) && $a['data_snapshot_id'] !== '' ? (int) $a['data_snapshot_id'] : null;
        $probability = $a['probability'] ?? null;
        if ($probability !== null && (! is_numeric($probability) || (float) $probability < 0 || (float) $probability > 1)) {
            $errors['probability'] = 'La probabilidad debe estar entre 0 y 1.';
        }
        $numbers = [];
        foreach (['action_cost', 'expected_gross_value', 'expected_units', 'unit_price'] as $field) {
            $numbers[$field] = null;
            if (isset($a[$field]) && $a[$field] !== '') {
                if (! is_numeric($a[$field]) || ! is_finite((float) $a[$field]) || ((float) $a[$field] < 0)) {
                    $errors[$field] = 'Debe ser un número mayor o igual que cero.';
                } else {
                    $numbers[$field] = (float) $a[$field];
                }
            }
        }
        $arrays = [];
        foreach (['model_versions' => [], 'interval' => [], 'assumptions' => [], 'constraints' => [], 'availability' => [], 'explanation' => [], 'uncertainty' => []] as $field => $default) {
            $value = $a[$field] ?? $default;
            if (! is_array($value)) {
                $errors[$field] = 'Debe ser un arreglo.';
                $value = $default;
            }
            $arrays[$field] = $value;
        }
        // Las restricciones son SIEMPRE objetos con `code` (contrato de la API): un texto suelto se convierte en una nota con código `note`.
        $arrays['constraints'] = array_values(array_map(fn ($c) => is_array($c) ? $c : ['code' => 'note', 'note' => (string) $c], $arrays['constraints']));
        $users = [];
        foreach (['owner_user_id', 'proposed_by_user_id'] as $field) {
            $users[$field] = isset($a[$field]) && $a[$field] !== '' ? (int) $a[$field] : null;
            if ($users[$field] !== null && ! DB::table('tenant_users')->where('tenant_id', $tenant->getKey())->where('user_id', $users[$field])->exists()) {
                $errors[$field] = 'La persona no es miembro de la empresa activa.';
            }
        }
        $agenda = $a['agenda_status'] ?? null;
        if ($agenda !== null && ! in_array($agenda, DecisionRecommendation::AGENDA_STATUSES, true)) {
            $errors['agenda_status'] = 'Estado de agenda no válido.';
        }
        $expires = null;
        if (isset($a['expires_at']) && $a['expires_at'] !== '') {
            try {
                $expires = CarbonImmutable::parse($a['expires_at']);
            } catch (\Throwable) {
                $errors['expires_at'] = 'Fecha de vencimiento no válida.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'kind' => $kind, 'entity_type' => $entityType, 'as_of' => $asOf, 'horizon_days' => (int) $horizon,
            'customer_id' => $ids['customer_id'], 'product_id' => $ids['product_id'], 'branch_id' => $ids['branch_id'],
            'source_core_run_id' => $sources['source_core_run_id'], 'source_forecast_run_id' => $sources['source_forecast_run_id'], 'data_snapshot_id' => $snapshot,
            'probability' => $probability === null ? null : (float) $probability,
            'agenda_status' => $agenda, 'expires_at' => $expires, 'policy' => isset($a['policy']) ? mb_substr((string) $a['policy'], 0, 60) : null,
            'next_step' => isset($a['next_step']) ? (string) $a['next_step'] : null,
        ] + $numbers + $arrays + $users;
    }
}
