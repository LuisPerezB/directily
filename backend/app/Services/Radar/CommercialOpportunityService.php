<?php

declare(strict_types=1);

namespace App\Services\Radar;

use App\Contracts\Decision\RecommendationSink;
use App\Models\CoreRun;
use App\Services\Customer\CustomerRunService;
use App\Services\Customer\CustomerS05Presenter;
use App\Services\Radar\Exceptions\RadarException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lado PRODUCTOR del Revenue Radar (V2.5 · W5-A; ADR-V25-12; V25-AGD-001): toma las predicciones vigentes del Customer Core (probabilidad, nº de pedidos E(N),
 * cantidad por pedido, unidades y ventana de recompra) y las convierte en OPORTUNIDADES por relación cliente × producto. No guarda nada por su cuenta: las propone
 * al Decision Engine EXCLUSIVAMENTE por {@see RecommendationSink::propose()} (ADR-V25-13). El lado consumidor (listas, resumen, agenda, seguimiento) es
 * {@see PredictiveSalesAgenda}.
 *
 * Qué se propone (política `radar-v1`, parámetros en config/radar.php, a aprobar en el piloto), por horizonte de la corrida que el plan permite:
 * - `recovery` (cliente × producto): la ventana de recompra de la relación está atrasada (`overdue`: respecto a su patrón, no es abandono) y la relación tiene
 *   historia suficiente (`recovery_min_purchases`).
 * - `contact` (cliente × producto): resto de relaciones con P(≥ 1 compra) ≥ `min_probability`.
 * De cada tipo y horizonte se proponen las `max_per_kind_horizon` mejor valoradas (valor bruto esperado, luego probabilidad, luego ids: orden determinista). Se
 * excluyen los compradores genéricos (consumidor final), los eliminados y los productos inactivos. La recuperación y el riesgo A NIVEL DE CLIENTE (ausencia
 * respecto al patrón propio) los genera el Decision Engine (`predictily:decisions-generate`, W5-B); el Radar los lista pero no los duplica.
 *
 * Qué NO hace (lo resuelve el Decision Engine al recibir la propuesta, sin duplicar su lógica): el costo unitario conocido y su confiabilidad, la contribución, la
 * disponibilidad de inventario al corte (y el límite del valor de un contacto a lo disponible), el costo de la acción, el score y su versión de fórmula, la
 * prioridad, el vencimiento, la asignación al experimento, la etiqueta de atribución, la idempotencia y el evento inicial. El Radar aporta los hechos del Customer Core
 * (`expected_units`, `unit_price` = último precio neto de la relación, probabilidad, ventana, explicación) y NUNCA una contribución ni un número que el motor no pueda
 * rastrear a su fuente.
 */
final class CommercialOpportunityService
{
    /**
     * Claves que la fachada entrega a RecommendationSink::propose(): las de §11 que conoce + `data_snapshot_id`, `expected_units` y `unit_price` (que el Decision Engine usa para
     * derivar importe y contribución) y `uncertainty` (hechos de calibración). Sin tenant_id, idempotency_key, score, prioridad, vencimiento ni contribución.
     */
    public const SINK_FIELDS = [
        'kind', 'as_of', 'horizon_days', 'entity_type', 'customer_id', 'product_id', 'branch_id', 'source_core_run_id', 'source_forecast_run_id', 'data_snapshot_id',
        'model_versions', 'probability', 'interval', 'assumptions', 'constraints', 'expected_units', 'unit_price', 'expected_gross_value', 'uncertainty', 'explanation', 'policy',
        'proposed_by_user_id',   // solo cuando una PERSONA dispara la propuesta (HTTP): queda como proponente y la segregación de funciones se le aplica
    ];

    public function __construct(
        private readonly CustomerRunService $runs,
        private readonly RadarPolicy $policy,
        private readonly TenantContext $context,
    ) {}

    /** Corrida de predicción vigente (producción, completada) sobre la que se construyen las oportunidades. */
    public function currentRun(): ?CoreRun
    {
        return $this->runs->latestPrediction();
    }

    /**
     * Oportunidades de una corrida (la vigente por defecto) sin proponerlas. Lectura pura: sirve para revisar qué se propondría.
     *
     * @param  list<int>|null  $horizons  null = los de la corrida que el plan permite
     * @return list<OpportunityCandidate>
     */
    public function build(?CoreRun $run = null, ?array $horizons = null): array
    {
        $run ??= $this->currentRun();
        if ($run === null || $run->as_of === null) {
            return [];
        }
        $tenant = $this->context->require();
        $usable = array_values(array_intersect(array_map('intval', (array) $run->horizons), $this->runs->allowedHorizons($tenant)));
        if ($horizons !== null) {
            $usable = array_values(array_intersect($usable, array_map('intval', $horizons)));
        }
        sort($usable);
        $limit = $this->policy->maxPerKindHorizon();

        $out = [];
        foreach ($usable as $h) {
            foreach ([RadarPolicy::KIND_RECOVERY => fn (Builder $q) => $this->whereRecovery($q), RadarPolicy::KIND_CONTACT => fn (Builder $q) => $this->whereContact($q)] as $kind => $scope) {
                foreach ($this->pairsQuery($run, $h)->tap($scope)->limit($limit)->get() as $row) {
                    $out[] = $this->candidate($run, $kind, $h, $row);
                }
            }
        }

        return $out;
    }

    /**
     * Propone las oportunidades de la predicción vigente al Decision Engine registrado en el contenedor (W5-B). Sin él no hay a quién proponer: el Radar nunca
     * escribe `decision_recommendations` por su cuenta.
     *
     * @param  list<int>|null  $horizons
     * @param  int|null  $proposedByUserId  la persona que disparó la propuesta (HTTP): queda como proponente (`proposed_by_user_id`) y por tanto NO puede aprobar lo que se
     *                                      propuso; null (comando programado) = propone el sistema
     *
     * @throws RadarException (503 decision_engine_unavailable)
     */
    public function propose(?CoreRun $run = null, ?array $horizons = null, ?int $proposedByUserId = null): MaterializationResult
    {
        if (! app()->bound(RecommendationSink::class)) {
            throw RadarException::sinkUnavailable();
        }

        return $this->materialize(app(RecommendationSink::class), $run, $horizons, $proposedByUserId);
    }

    /**
     * Propone las oportunidades al Decision Engine. Idempotente: con los mismos insumos (tipo, corte, horizonte, entidad, fuente) la implementación devuelve la
     * recomendación existente. Una oportunidad que falla no detiene las demás (se cuenta y se informa).
     *
     * @param  list<int>|null  $horizons
     */
    public function materialize(RecommendationSink $sink, ?CoreRun $run = null, ?array $horizons = null, ?int $proposedByUserId = null): MaterializationResult
    {
        $run ??= $this->currentRun();
        if ($run === null) {
            return new MaterializationResult(null, null, [], 0, 0, 0, 0, [], [], $this->policy->version());
        }
        $candidates = $this->build($run, $horizons);
        $created = $existing = $failed = 0;
        $byKind = [];
        $errors = [];
        $payloads = array_map(fn (OpportunityCandidate $c) => $c->toSinkAttributes() + ($proposedByUserId === null ? [] : ['proposed_by_user_id' => $proposedByUserId]), $candidates);
        // Con un motor que propone por lotes (el Decision Engine), las consultas de apoyo se hacen UNA vez para todo el lote; con cualquier otro sumidero, una por una.
        if (method_exists($sink, 'proposeMany')) {
            $outcomes = $sink->proposeMany($payloads);
        } else {
            $outcomes = [];
            foreach ($payloads as $payload) {
                try {
                    $outcomes[] = ['recommendation' => $sink->propose($payload), 'error' => null];
                } catch (Throwable $e) {
                    $outcomes[] = ['recommendation' => null, 'error' => $e->getMessage()];
                }
            }
        }
        foreach ($candidates as $i => $candidate) {
            $outcome = $outcomes[$i] ?? ['recommendation' => null, 'error' => 'sin resultado'];
            if ($outcome['recommendation'] === null) {
                $failed++;
                Log::warning('Radar: el Decision Engine rechazó una oportunidad', ['kind' => $candidate->kind, 'horizon' => $candidate->horizonDays, 'error' => $outcome['error']]);
                if (count($errors) < 5) {
                    $errors[] = $candidate->kind.' h'.$candidate->horizonDays.': '.mb_substr((string) $outcome['error'], 0, 200);
                }
            } elseif ($outcome['recommendation']->wasRecentlyCreated) {
                $created++;
                $byKind[$candidate->kind] = ($byKind[$candidate->kind] ?? 0) + 1;
            } else {
                $existing++;
            }
        }
        $horizonsUsed = array_values(array_unique(array_map(fn (OpportunityCandidate $c) => $c->horizonDays, $candidates)));
        sort($horizonsUsed);

        return new MaterializationResult((int) $run->id, $run->as_of?->toDateString(), $horizonsUsed, count($candidates), $created, $existing, $failed, $byKind, $errors, $this->policy->version());
    }

    // ── Selección ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    private function pairsQuery(CoreRun $run, int $horizon): Builder
    {
        return DB::table('customer_predictions as p')
            ->join('customers as c', fn ($j) => $j->on('c.id', '=', 'p.customer_id')->on('c.tenant_id', '=', 'p.tenant_id'))
            ->join('products as pr', fn ($j) => $j->on('pr.id', '=', 'p.product_id')->on('pr.tenant_id', '=', 'p.tenant_id'))
            ->where('p.tenant_id', (int) $run->tenant_id)->where('p.core_run_id', $run->id)->where('p.horizon_days', $horizon)
            ->whereNotNull('p.purchase_probability')
            ->where('c.is_generic', false)->whereNull('c.deleted_at')
            ->whereNull('pr.deleted_at')->where('pr.is_active', true)
            ->selectRaw(<<<'SQL'
                p.id AS prediction_id, p.customer_id, p.product_id, p.horizon_days, p.purchase_probability, p.calibration_status, p.quantity_if_purchase,
                p.quantity_lo, p.quantity_hi, p.expected_quantity, p.committed_quantity, p.assumed_unit_price, p.expected_amount, p.currency,
                p.median_interval_days, p.days_since_last_purchase, p.n_purchases, p.model_family, p.model_role, p.quality_flags,
                p.expected_orders, p.quantity_per_order, p.expected_units, p.window_p10_days, p.window_p50_days, p.window_p90_days, p.window_status, p.segment
            SQL)
            ->orderByRaw('COALESCE(p.expected_units * p.assumed_unit_price, p.expected_amount) DESC NULLS LAST')
            ->orderByDesc('p.purchase_probability')->orderBy('p.customer_id')->orderBy('p.product_id');
    }

    /** Recuperación: ventana de recompra atrasada con historia suficiente de la relación. */
    private function whereRecovery(Builder $q): void
    {
        $q->where('p.window_status', 'overdue')->whereRaw('COALESCE(p.n_purchases, 0) >= ?', [$this->policy->recoveryMinPurchases()]);
    }

    /** Contacto: probabilidad suficiente y que no sea ya una recuperación (cada relación aparece en un solo tipo por horizonte). */
    private function whereContact(Builder $q): void
    {
        $q->where('p.purchase_probability', '>=', $this->policy->minProbability())
            ->whereRaw("NOT (COALESCE(p.window_status, '') = 'overdue' AND COALESCE(p.n_purchases, 0) >= ?)", [$this->policy->recoveryMinPurchases()]);
    }

    // ── Construcción de la carga para el Decision Engine ─────────────────────────────────────────────────────────────────────────────────────

    private function candidate(CoreRun $run, string $kind, int $horizon, object $r): OpportunityCandidate
    {
        $s05 = CustomerS05Presenter::fields($r);   // la ventana solo trae cifras con estado estimated u overdue (regla de W3-C/W3-D)
        $price = $r->assumed_unit_price !== null ? (float) $r->assumed_unit_price : null;
        $units = $r->expected_units !== null ? (float) $r->expected_units : null;
        $unitsBasis = 's05_expected_units';
        if ($units === null && $r->expected_quantity !== null) {
            $units = (float) $r->expected_quantity;   // corrida sin S05: esperanza de UN solo evento en el horizonte (supuesto declarado)
            $unitsBasis = 'single_event_expected_quantity';
        }
        $gross = $units !== null && $price !== null ? round($units * $price, 4) : ($r->expected_amount !== null ? round((float) $r->expected_amount, 4) : null);
        $source = "customer_predictions (corrida {$run->id})";

        $explanation = [['code' => 'purchase_probability', 'label' => 'Probabilidad de al menos una compra en el horizonte', 'value' => (float) $r->purchase_probability, 'source' => $source]];
        foreach ([
            'expected_orders' => ['Pedidos esperados en el horizonte (modelo de conteo)', $s05['expected_orders']],
            'quantity_per_order' => ['Cantidad esperada por pedido', $s05['quantity_per_order']],
            'expected_units' => ['Unidades esperadas (pedidos × cantidad por pedido)', $s05['expected_units']],
            'days_since_last_purchase' => ['Días desde la última compra de la relación', $r->days_since_last_purchase !== null ? (int) $r->days_since_last_purchase : null],
            'median_interval_days' => ['Mediana de intervalos entre compras de la relación (días)', $r->median_interval_days !== null ? (float) $r->median_interval_days : null],
            'n_purchases' => ['Compras previas de la relación', $r->n_purchases !== null ? (int) $r->n_purchases : null],
        ] as $code => [$label, $value]) {
            if ($value !== null) {
                $explanation[] = ['code' => $code, 'label' => $label, 'value' => $value, 'source' => $source];
            }
        }
        $explanation[] = ['code' => 'window', 'label' => 'Ventana probable de recompra (días desde el corte; nunca un día exacto)', 'value' => $s05['window'], 'source' => $source];
        $explanation[] = ['code' => 'units_basis', 'label' => 'Base de las unidades esperadas', 'value' => $unitsBasis, 'source' => 'derivado'];

        $assumptions = [
            "Probabilidad del Customer Core (corrida {$run->id}); no es una promesa de compra.",
            $unitsBasis === 's05_expected_units'
                ? 'Unidades esperadas = pedidos esperados × cantidad por pedido, bajo independencia entre ambos (supuesto documentado del modelo).'
                : 'Corrida sin cantidades de S05: se usa la esperanza de un solo evento en el horizonte (probabilidad × cantidad condicionada).',
            'El precio es el último precio neto de la relación (supuesto del Customer Core), sin impuestos; el valor no es una venta confirmada.',
            'Oportunidad potencial, atribución no demostrada: no implica ventas causadas por el contacto.',
        ];
        // Las restricciones son SIEMPRE objetos con `code` (contrato de la API y de OpenAPI): nunca un texto suelto.
        $constraints = [];
        if ($s05['window']['status'] === null || in_array($s05['window']['status'], ['insufficient_data', 'censored'], true)) {
            $constraints[] = [
                'code' => 'window_without_figures', 'window_status' => $s05['window']['status'],
                'note' => 'Ventana de recompra sin cifras (estado: '.($s05['window']['status'] ?? 'sin estimar').'): no se promete un día.',
            ];
        }

        $attributes = [
            'kind' => $kind, 'as_of' => $run->as_of->toDateString(), 'horizon_days' => $horizon, 'entity_type' => 'customer_product',
            'customer_id' => (int) $r->customer_id, 'product_id' => (int) $r->product_id, 'branch_id' => null,
            'source_core_run_id' => (int) $run->id, 'source_forecast_run_id' => null, 'data_snapshot_id' => $run->data_snapshot_id !== null ? (int) $run->data_snapshot_id : null,
            'model_versions' => [
                'customer_core' => [
                    'run_id' => (int) $run->id, 'run_version' => (int) $run->version, 'config_version' => $run->config_version, 'horizon_models' => ($run->model_versions[(string) $horizon] ?? null),
                ],
                'prediction_model' => ['family' => $r->model_family, 'role' => $r->model_role],
                'radar_policy' => $this->policy->version(),
            ],
            'probability' => (float) $r->purchase_probability,
            'interval' => [
                'window_days' => ['status' => $s05['window']['status'], 'p10' => $s05['window']['p10_days'], 'p50' => $s05['window']['p50_days'], 'p90' => $s05['window']['p90_days']],
                'quantity_if_purchase' => ['lo' => $r->quantity_lo !== null ? (float) $r->quantity_lo : null, 'hi' => $r->quantity_hi !== null ? (float) $r->quantity_hi : null, 'level' => 0.8],
            ],
            'assumptions' => $assumptions,
            'constraints' => $constraints,
            'expected_units' => $units,
            'unit_price' => $price,
            'expected_gross_value' => $gross,
            'uncertainty' => [
                'calibration_status' => $r->calibration_status, 'quality_flags' => json_decode((string) ($r->quality_flags ?? '[]'), true) ?: [], 'window_status' => $s05['window']['status'],
            ],
            'explanation' => $explanation,
            'policy' => $this->policy->version(),
        ];

        return new OpportunityCandidate($kind, $horizon, (int) $r->customer_id, (int) $r->product_id, $attributes);
    }
}
