<?php

declare(strict_types=1);

namespace App\Services\Decision\Generators;

use App\Models\Tenant;
use App\Services\Customer\CustomerRunService;
use App\Services\Customer\CustomerS05Presenter;
use App\Services\Decision\DecisionSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Oportunidades de cliente a partir de la última corrida de predicción del Customer Core (V2.5 · W5-B), sin recalcular nada:
 *
 *  - `contact` (cliente × producto, por horizonte configurado): filas de `customer_predictions` con probabilidad (la abstención —NULL— no genera
 *    recomendación) de compradores no genéricos. Valor = E(nº de pedidos) × contribución condicionada por pedido; sin el modelo de conteo se usa la
 *    probabilidad como cota inferior de E(N) (`orders_basis = probability_lower_bound`) y la cantidad condicionada a ≥ 1 compra.
 *  - `recovery` (cliente): `inactive_by_pattern` en `customer_activity` (ausencia respecto al patrón propio, NO abandono).
 *  - `customer_risk` (cliente): `at_risk`, o descenso de comportamiento con significancia, de quien aún compra. Informativa: no se contacta.
 *    Sin historial suficiente (`insufficient_history`) no se genera nada: no se inventa un riesgo.
 * El valor en riesgo de recuperación/riesgo es la CAÍDA OBSERVADA del gasto neto entre las dos ventanas del Customer Core (importe, no contribución:
 * el margen por cliente no se estima aquí).
 */
final class CustomerOpportunityGenerator implements CandidateSource
{
    public function collect(Tenant $tenant, DecisionSettings $settings, CarbonImmutable $today): array
    {
        $run = app(CustomerRunService::class)->latestPrediction();
        if ($run === null || $run->as_of === null) {
            return ['groups' => [], 'skipped' => ['customer' => 'no_customer_prediction_run']];
        }
        $asOf = CarbonImmutable::parse($run->as_of->toDateString(), $settings->timezone())->startOfDay();
        $runHorizons = array_map('intval', (array) $run->horizons);
        $groups = [];
        $skipped = [];

        foreach ($settings->horizons() as $h) {
            if ($settings->contactSource() === 'radar') {
                $skipped["contact:{$h}"] = 'produced_by_radar';

                continue;
            }
            if (! in_array($h, $runHorizons, true)) {
                $skipped["contact:{$h}"] = 'horizon_not_in_run';

                continue;
            }
            if ($settings->expiresAt('contact', $asOf, $h)->lessThanOrEqualTo($today)) {
                $skipped["contact:{$h}"] = 'source_expired';

                continue;
            }
            $groups[] = ['kind' => 'contact', 'label' => "contact:h{$h}", 'as_of' => $asOf, 'max' => $settings->maxContactPerHorizon(), 'attributes' => $this->contacts($tenant, $settings, $run, $asOf, $h)];
        }

        // La recuperación y el riesgo a nivel de cliente no dependen del horizonte: su `horizon_days` es una constante de la corrida (el horizonte por defecto de la empresa
        // si la corrida lo trae, o el mayor), NO uno de los horizontes configurados para contacto; así reordenar o cambiar esa lista nunca cambia la huella ni duplica filas.
        $default = (int) ($tenant->effectiveSettings()['default_horizon_days'] ?? 30);
        $primary = in_array($default, $runHorizons, true) ? $default : (int) max($runHorizons ?: [$default]);
        if ($settings->expiresAt('recovery', $asOf, $primary)->lessThanOrEqualTo($today)) {
            $skipped['activity'] = 'source_expired';
        } else {
            [$recovery, $risk] = $this->activity($tenant, $run, $asOf, $primary, $settings->maxContactPerHorizon() * 5);
            $groups[] = ['kind' => 'recovery', 'label' => 'recovery', 'as_of' => $asOf, 'max' => $settings->maxContactPerHorizon(), 'attributes' => $recovery];
            $groups[] = ['kind' => 'customer_risk', 'label' => 'customer_risk', 'as_of' => $asOf, 'max' => $settings->maxContactPerHorizon(), 'attributes' => $risk];
        }

        return ['groups' => $groups, 'skipped' => $skipped];
    }

    /** @return list<array<string, mixed>> */
    private function contacts(Tenant $tenant, DecisionSettings $settings, object $run, CarbonImmutable $asOf, int $h): array
    {
        $rows = DB::table('customer_predictions as p')
            ->join('customers as c', fn ($j) => $j->on('c.id', '=', 'p.customer_id')->on('c.tenant_id', '=', 'p.tenant_id'))
            ->where('p.tenant_id', $tenant->getKey())->where('p.core_run_id', $run->id)->where('p.horizon_days', $h)
            ->whereNotNull('p.purchase_probability')->where('p.purchase_probability', '>=', $settings->contactMinProbability())
            ->where('c.is_generic', false)->whereNull('c.deleted_at')
            ->orderByDesc('p.expected_amount')->orderBy('p.id')->limit($settings->maxContactPerHorizon() * 5)
            ->get(['p.id', 'p.customer_id', 'p.product_id', 'p.purchase_probability', 'p.probability_raw', 'p.calibration_status', 'p.quantity_if_purchase', 'p.quantity_lo',
                'p.quantity_hi', 'p.expected_quantity', 'p.committed_quantity', 'p.assumed_unit_price', 'p.expected_amount', 'p.expected_orders', 'p.quantity_per_order',
                'p.expected_units', 'p.window_p10_days', 'p.window_p50_days', 'p.window_p90_days', 'p.window_status', 'p.segment', 'p.quality_flags', 'p.model_family',
                'p.median_interval_days', 'p.days_since_last_purchase', 'p.n_purchases']);

        $modelVersions = (array) (($run->model_versions ?? [])[(string) $h] ?? []);
        $out = [];
        foreach ($rows as $r) {
            $hasOrders = $r->expected_orders !== null && $r->quantity_per_order !== null;
            $units = $hasOrders ? ($r->expected_units !== null ? (float) $r->expected_units : (float) $r->expected_orders * (float) $r->quantity_per_order)
                : ($r->expected_quantity !== null ? (float) $r->expected_quantity : null);
            $ordersBasis = $hasOrders ? 'expected_orders_x_quantity_per_order' : 'probability_x_conditional_quantity';
            $s05 = CustomerS05Presenter::fields($r);
            $out[] = [
                'kind' => 'contact', 'as_of' => $asOf->toDateString(), 'horizon_days' => $h, 'entity_type' => 'customer_product',
                'customer_id' => (int) $r->customer_id, 'product_id' => (int) $r->product_id,
                'source_core_run_id' => (int) $run->id, 'data_snapshot_id' => $run->data_snapshot_id,
                'model_versions' => ['customer' => $modelVersions, 'model_family' => $r->model_family],
                'probability' => (float) $r->purchase_probability,
                'interval' => [
                    'quantity_if_purchase' => ['lo' => $r->quantity_lo === null ? null : (float) $r->quantity_lo, 'hi' => $r->quantity_hi === null ? null : (float) $r->quantity_hi],
                    'window' => $s05['window'],
                ],
                'assumptions' => [
                    'El valor esperado de pedido no implica ventas incrementales causadas por el contacto: oportunidad potencial, atribución no demostrada.',
                    $hasOrders ? 'Unidades esperadas = E(nº de pedidos) × cantidad esperada por pedido, bajo independencia entre ambos (supuesto documentado).'
                        : 'Sin modelo de conteo en esta corrida: la probabilidad de ≥ 1 compra es una cota inferior de E(nº de pedidos); unidades = P × cantidad condicionada a ≥ 1 compra.',
                    'El precio es el último precio neto de la relación (supuesto del Customer Core), sin impuestos.',
                ],
                'expected_units' => $units, 'unit_price' => $r->assumed_unit_price === null ? null : (float) $r->assumed_unit_price,
                'explanation' => [
                    $this->factor('purchase_probability', 'Probabilidad de ≥ 1 compra en el horizonte', (float) $r->purchase_probability, 'customer_predictions', $run->id, ['calibration_status' => $r->calibration_status]),
                    $this->factor('orders_basis', 'Base del número de pedidos', $ordersBasis, 'customer_predictions', $run->id, array_filter([
                        'expected_orders' => $r->expected_orders === null ? null : (float) $r->expected_orders,
                        'quantity_per_order' => $r->quantity_per_order === null ? null : (float) $r->quantity_per_order,
                        'quantity_if_purchase' => $r->quantity_if_purchase === null ? null : (float) $r->quantity_if_purchase,
                        'committed_quantity' => (float) $r->committed_quantity,
                    ], fn ($v) => $v !== null)),
                    $this->factor('purchase_pattern', 'Patrón de compra del cliente en este producto', null, 'customer_predictions', $run->id, array_filter([
                        'median_interval_days' => $r->median_interval_days === null ? null : (float) $r->median_interval_days,
                        'days_since_last_purchase' => $r->days_since_last_purchase, 'n_purchases' => $r->n_purchases, 'segment' => $r->segment,
                        'window_status' => $s05['window']['status'],
                    ], fn ($v) => $v !== null)),
                ],
            ];
        }

        return $out;
    }

    /** @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>} recuperación y riesgo */
    private function activity(Tenant $tenant, object $run, CarbonImmutable $asOf, int $h, int $limit): array
    {
        $rows = DB::table('customer_activity as a')
            ->join('customers as c', fn ($j) => $j->on('c.id', '=', 'a.customer_id')->on('c.tenant_id', '=', 'a.tenant_id'))
            ->where('a.tenant_id', $tenant->getKey())->where('a.core_run_id', $run->id)
            ->where('c.is_generic', false)->whereNull('c.deleted_at')
            ->where(fn ($q) => $q->where('a.inactivity_status', 'inactive_by_pattern')->orWhere('a.inactivity_status', 'at_risk')
                ->orWhere(fn ($q2) => $q2->where('a.behavior_change', 'decrease')->where('a.inactivity_status', 'active')))
            // Acotada: las de mayor caída observada primero (el valor que se usa para ordenar después); el tope por grupo y el score deciden el resto.
            ->orderByRaw('GREATEST(COALESCE(a.spend_prior, 0) - COALESCE(a.spend_recent, 0), 0) DESC')->orderBy('a.customer_id')->limit($limit)
            ->get(['a.customer_id', 'a.last_purchase_date', 'a.days_since_last_purchase', 'a.n_purchase_days', 'a.purchase_frequency_per_30d', 'a.median_interval_days',
                'a.interval_p75_days', 'a.interval_p90_days', 'a.n_intervals', 'a.inactivity_risk', 'a.inactivity_status', 'a.behavior_change', 'a.frequency_ratio',
                'a.behavior_p_value', 'a.spend_recent', 'a.spend_prior', 'a.spend_ratio', 'a.currency']);
        $window = $run->effective_config['behavior_window_days'] ?? null;

        $recovery = [];
        $risk = [];
        foreach ($rows as $r) {
            $inactive = $r->inactivity_status === 'inactive_by_pattern';
            $decline = $r->spend_prior !== null && $r->spend_recent !== null && (float) $r->spend_prior > 0 ? max((float) $r->spend_prior - (float) $r->spend_recent, 0.0) : null;
            $row = [
                'kind' => $inactive ? 'recovery' : 'customer_risk', 'as_of' => $asOf->toDateString(), 'horizon_days' => $h, 'entity_type' => 'customer',
                'customer_id' => (int) $r->customer_id, 'source_core_run_id' => (int) $run->id, 'data_snapshot_id' => $run->data_snapshot_id,
                'model_versions' => ['customer' => ['activity' => 'customer_activity']],
                'assumptions' => [
                    'Ausencia respecto al patrón propio del cliente (percentiles 75/90 de sus intervalos): no implica abandono definitivo.',
                    'Valor en riesgo = caída observada del gasto neto entre las dos ventanas de comportamiento del Customer Core'.($window ? " ({$window} días cada una)" : '').': es un importe observado, no un pronóstico ni una contribución.',
                    'El valor esperado no implica ventas incrementales causadas por la acción: oportunidad potencial, atribución no demostrada.',
                ],
                'expected_gross_value' => $decline,
                'explanation' => [
                    $this->factor('inactivity_status', 'Estado de actividad respecto al patrón propio', $r->inactivity_status, 'customer_activity', $run->id, array_filter([
                        'days_since_last_purchase' => $r->days_since_last_purchase, 'median_interval_days' => $r->median_interval_days === null ? null : (float) $r->median_interval_days,
                        'interval_p75_days' => $r->interval_p75_days === null ? null : (float) $r->interval_p75_days,
                        'interval_p90_days' => $r->interval_p90_days === null ? null : (float) $r->interval_p90_days,
                        'n_intervals' => $r->n_intervals, 'inactivity_risk_percentile' => $r->inactivity_risk === null ? null : (float) $r->inactivity_risk,
                    ], fn ($v) => $v !== null)),
                    $this->factor('behavior_change', 'Cambio de comportamiento (días de compra, ventana reciente vs previa)', $r->behavior_change, 'customer_activity', $run->id, array_filter([
                        'frequency_ratio' => $r->frequency_ratio === null ? null : (float) $r->frequency_ratio, 'p_value' => $r->behavior_p_value === null ? null : (float) $r->behavior_p_value,
                    ], fn ($v) => $v !== null)),
                    $this->factor('spend_windows', 'Gasto neto observado en las dos ventanas', null, 'customer_activity', $run->id, array_filter([
                        'spend_recent' => $r->spend_recent === null ? null : (float) $r->spend_recent, 'spend_prior' => $r->spend_prior === null ? null : (float) $r->spend_prior,
                        'observed_decline' => $decline, 'currency' => $r->currency,
                    ], fn ($v) => $v !== null)),
                ],
            ];
            $inactive ? $recovery[] = $row : $risk[] = $row;
        }

        return [$recovery, $risk];
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function factor(string $code, string $label, mixed $value, string $table, int|string $runId, array $detail = []): array
    {
        return array_filter(['code' => $code, 'label' => $label, 'value' => $value, 'source' => $table.':core_run='.$runId, 'detail' => $detail === [] ? null : $detail], fn ($v) => $v !== null);
    }
}
