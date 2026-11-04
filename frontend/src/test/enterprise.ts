import type {
  DecisionAllowedTransition, DecisionOutcome, DecisionRecommendation, DecisionSummary, OutcomeExperiment, OutcomeRoiReport, RadarHorizonSummary, RadarOpportunity, RadarOpportunityDetail, RadarSummary,
  RoiAssociated, RoiBreakdownRow, RoiObserved, RoiPerRecommendation, RoiPotentialCell,
} from '@/api/types'

/** Constructores de datos para las pruebas de las vistas Enterprise. Forma = docs/openapi/v2.yaml (W5-A, W5-B, W5-C). */

export function opportunity(over: Partial<RadarOpportunity> = {}): RadarOpportunity {
  return {
    id: 11, kind: 'contact', entity_type: 'customer_product', as_of: '2026-10-31', horizon_days: 30, source_run_id: 5,
    customer: { id: 3, code: 'CLI-003', type: 'empresa', zone: 'Quito', segment: { id: 1, code: 'frequent', name: 'Frecuente' } },
    product: { id: 8, sku: 'SKU-8', name: 'Cubeta de huevos', unit: 'cubeta', category: { id: 2, name: 'Huevos' } },
    state: 'pendiente', status: 'suggested', priority: 1, score: 42.5, score_formula_version: 'a'.repeat(64), policy: 'radar-v1:abc', probability: 0.62,
    expected_orders: 1.4, quantity_per_order: 5, expected_units: 7, window: { status: 'estimated', p10_days: 5, p50_days: 9, p90_days: 20 },
    model_segment: 'frequency:high', activity: null,
    value: {
      expected_gross_value: 350, expected_contribution: 120, cost_status: 'reliable', cost_reasons: [], action_cost: null, incremental_estimate: null,
      attribution: { label: 'potential_unproven', text: 'Oportunidad potencial, atribución no demostrada' }, basis_code: 'forecast_units_x_last_net_price', basis: 'supuesto',
    },
    inventory: { status: 'known', on_hand: 40, reserved: 4, available: 36, in_transit: 10 },
    owner_user_id: null, assigned_user: null, expires_at: '2026-12-01T04:59:59-05:00', next_step: 'Llamar al comprador y confirmar su reposición',
    follow_up: { last_feedback_at: null, snoozed_until: null },
    ...over,
  }
}

export function opportunityDetail(over: Partial<RadarOpportunityDetail> = {}): RadarOpportunityDetail {
  return {
    ...opportunity(),
    explanation: [{ code: 'value_calculation', label: 'Cálculo del valor esperado', source: 'decision_engine:score-v1' }], assumptions: [], constraints: [], uncertainty: {}, model_versions: {}, interval: {},
    source: { core_run_id: 5, forecast_run_id: 4, data_snapshot_id: 9 }, allowed_feedback: ['contactado', 'pospuesto', 'descartado'], history: [],
    ...over,
  }
}

export function horizonSummary(over: Partial<RadarHorizonSummary> = {}): RadarHorizonSummary {
  return {
    opportunities: 4, customers: 3, by_kind: { contact: 3, recovery: 1 }, by_state: { pendiente: 3, contactado: 1 },
    inventory: { known: 3, unknown: 1, below_expected: 0, not_applicable: 0 },
    value: {
      currency: 'USD', scope: 'customer_product', opportunities: 3, attribution: { label: 'potential_unproven', text: 'Oportunidad potencial, atribución no demostrada' },
      gross_value: 1000, gross_value_opportunities: 3, contribution: 400, contribution_opportunities: 2, contribution_unavailable: 1, basis_code: 'forecast_units_x_last_net_price', basis: 'supuesto',
    },
    customer_level: { opportunities: 1, customers: 1, currency: 'USD', observed_spend_decline: 250, observed_spend_decline_opportunities: 1, basis_code: 'observed_spend_decline', basis: 'observado' },
    ...over,
  }
}

export function radarSummary(over: Partial<RadarSummary> = {}): RadarSummary {
  return {
    as_of: '2026-10-31', totals: { opportunities: 6, customers: 4, unit: 'oportunidades' },
    by_horizon: { '7': horizonSummary({ opportunities: 2 }), '30': horizonSummary() }, label: 'Oportunidad potencial, atribución no demostrada', notes: [], run_id: 5, ...over,
  }
}

export function transition(over: Partial<DecisionAllowedTransition> = {}): DecisionAllowedTransition {
  return { action: 'assign', to_status: 'assigned', ability: 'decisions.manage', requires: { assignee: true, reason: false, expected_version: true }, allowed: true, blocked_by: null, ...over }
}

export const TRANSITIONS: Record<string, DecisionAllowedTransition> = {
  assign: transition(),
  dismiss: transition({ action: 'dismiss', to_status: 'rejected', requires: { assignee: false, reason: true, expected_version: true } }),
  review: transition({ action: 'review', to_status: 'reviewed', requires: { assignee: false, reason: false, expected_version: true } }),
  approve: transition({ action: 'approve', to_status: 'approved', ability: 'decisions.approve', requires: { assignee: false, reason: false, expected_version: true } }),
  reject: transition({ action: 'reject', to_status: 'rejected', ability: 'decisions.approve', requires: { assignee: false, reason: true, expected_version: true } }),
}

export function recommendation(over: Partial<DecisionRecommendation> = {}): DecisionRecommendation {
  return {
    id: 21, kind: 'contact', as_of: '2026-10-31', horizon_days: 30, entity_type: 'customer_product',
    customer: { id: 3, external_code: 'CLI-003' }, product: { id: 8, sku: 'SKU-8', name: 'Cubeta de huevos', unit: 'cubeta' }, branch: null,
    source: { core_run_id: 5, forecast_run_id: 4, data_snapshot_id: 9 }, model_versions: {}, probability: 0.62, interval: { lo: 0.4, hi: 0.8 }, assumptions: [], constraints: [], availability: { status: 'known', available: 36, in_transit: 10 },
    currency: 'USD', action_cost: null, expected_gross_value: 350, expected_contribution: 120, incremental_estimate: null, incremental_evidence: null,
    attribution_label: 'potential_unproven', attribution_note: 'Oportunidad potencial, atribución no demostrada: el valor esperado no implica ventas incrementales causadas por la acción.',
    score: 42.5, score_formula_version: 'b'.repeat(64), uncertainty: {}, explanation: [{ code: 'value_calculation', label: 'Cálculo del valor esperado', source: 'decision_engine:score-v1' }], policy: 'radar-v1', priority: 1,
    owner_user_id: null, assigned_user_id: null, status: 'suggested', agenda_status: null, proposed_by: { kind: 'system', user_id: null }, expires_at: '2026-12-01T04:59:59-05:00',
    next_step: 'Llamar al comprador', version: 77, requires_approval: false, allowed_transitions: [TRANSITIONS.assign!, TRANSITIONS.dismiss!], events: [], created_at: '2026-11-01T08:00:00-05:00', updated_at: '2026-11-01T08:00:00-05:00',
    ...over,
  }
}

export function decisionSummary(over: Partial<DecisionSummary> = {}): DecisionSummary {
  return {
    by_status: { suggested: 5, assigned: 2, reviewed: 1 }, by_kind: { contact: 6, replenishment: 2 }, by_attribution_label: { potential_unproven: 8 },
    awaiting_assignment: 5, awaiting_approval: 1, expiring_within_24h: 2, attribution_note: 'Las recomendaciones son oportunidades potenciales con atribución no demostrada; no son ingresos recuperados.', ...over,
  }
}

export function outcome(over: Partial<DecisionOutcome> = {}): DecisionOutcome {
  return {
    id: 1, recommendation: { id: 21, kind: 'contact', status: 'measured', as_of: '2026-09-01', horizon_days: 30, customer: { id: 3, external_code: 'CLI-003' }, product: { id: 8, sku: 'SKU-8', name: 'Cubeta de huevos' } },
    action_kind: 'contacted', action_label: 'Contactada', action_at: '2026-09-03T10:00:00-05:00', window: { from: '2026-09-02', to: '2026-10-01', knowledge_cutoff: '2026-10-02' },
    observed_event: 'purchase', observed_ref: 'sale:99', observed_units: 6, observed_net_amount: 210, contribution: 70, contribution_status: 'known', contribution_reasons: [], contact_cost: 2,
    classification: 'temporally_associated', classification_label: 'Asociado temporalmente', classification_note: 'La compra ocurrió después de la acción registrada. Sin grupo de control no hay contrafactual: NO se puede decir que la acción la causó.',
    after_action: { units: 4, net_amount: 140, contribution: 45, contribution_status: 'known', contribution_reasons: [], purchase_lines: 1 },
    method: 'none', evidence: { source: { fingerprint: 'c'.repeat(64), late_lines_not_counted: 2, late_returned_units_not_counted: 0 }, observation: { returns_after_window: { lines: 0, units: 0, net_amount: 0 } } }, measured_at: '2026-10-03T06:00:00-05:00',
    ...over,
  }
}

/** Celda de oportunidad potencial de la API: UN tipo en UN horizonte (la API suma 0 cuando ninguna recomendación de la celda tenía costo confiable). */
export function cell(kind: string, horizon: number, recommendations: number, withoutReliableCost: number, contribution: number, gross: number): RoiPotentialCell {
  return { kind, horizon_days: horizon, recommendations, expected_contribution: contribution, recommendations_without_reliable_cost: withoutReliableCost, expected_gross_value: gross }
}

export function perRecommendation(over: Partial<RoiPerRecommendation> = {}): RoiPerRecommendation {
  return { rows: 0, units: 0, net_amount: 0, contribution_known: 0, rows_with_known_contribution: 0, rows_with_unknown_contribution: 0, note: 'Suma por recomendación, solo para el detalle: NO es aditiva.', ...over }
}

export function observedBlock(over: Partial<RoiObserved> = {}): RoiObserved {
  return {
    label: 'Observado (compras de clientes en la ventana, sin doble conteo)', customers: 0, purchase_lines: 0, units: 0, net_amount: 0, contribution_known: 0, pairs_with_unknown_contribution: 0,
    recommendations_with_purchase: 0, rows_without_line_detail: 0, per_recommendation: perRecommendation(), note: '', ...over,
  }
}

export function associatedBlock(over: Partial<RoiAssociated> = {}): RoiAssociated {
  return {
    label: 'Asociado temporalmente (SOLO lo posterior a la acción registrada; sin contrafactual)', recommendations: 0, customers: 0, units: 0, net_amount: 0, contribution_known: 0,
    pairs_with_unknown_contribution: 0, rows_without_line_detail: 0, per_recommendation: perRecommendation(), note: 'NO es beneficio atribuible.', ...over,
  }
}

/** Fila del desglose (cada grupo se deduplica por línea de venta dentro de sí mismo). Con `cells` lleva SU oportunidad potencial, como la API. */
export function kindRow(key: string, identified: number, over: Partial<RoiBreakdownRow> = {}): RoiBreakdownRow {
  return {
    key, label: key, contact_cost: { total: 0, actions: 0, actions_cost_unknown: 0, note: '' },
    funnel: { recommendations: identified, superseded_not_counted: 0, identified, actionable: identified, measured: 0, measured_actionable: 0, forecast_pending: {}, attended: 0, action_rate: null, responded_after_action: 0, response_rate: null, notes: {} },
    potential: { label: 'Oportunidad potencial, atribución no demostrada', cells: [], note: '' },
    observed: observedBlock(),
    associated: associatedBlock(),
    ...over,
  }
}

/** Experimento con estimación de conversión y de contribución (bootstrap percentil), como lo entrega la API a quien aprueba. */
export function experiment(over: Partial<OutcomeExperiment> = {}): OutcomeExperiment {
  return {
    experiment_key: 'exp-contacto', horizon_days: 30, method: 'itt', status: 'estimated', confidence_level: 0.95, min_group_size: 30, min_buyers_per_arm: 10,
    sample: { treatment: 120, control: 40, treatment_converted: 30, control_converted: 6 },
    sample_ratio: { status: 'ok', expected_control_fraction: 0.25, observed_control_share: 0.25, chi_square: 0, p_value: 1, threshold: 0.001 },
    dropped_units: { repeat_customer: { treatment: 0, control: 0 }, unverified_assignment: { treatment: 0, control: 0 }, conflicting_groups: { treatment: 0, control: 0 } },
    contamination: { control_units_with_registered_action: 0 },
    conversion: { rate_treatment: 0.25, rate_control: 0.15, difference: 0.1, ci_low: 0.01, ci_high: 0.19, significant: true },
    contribution: {
      status: 'estimated', reason: null, buyers: { treatment: 30, control: 6 }, population: { treatment: 120, control: 40 }, difference_per_unit: 4.5, ci_low: 1.2, ci_high: 7.8, significant: true,
      interval: { method: 'bootstrap_percentile', resamples: 2000, seed: 123 }, tail_diagnostic: { buyer_cv: 0.8, largest_buyer_share_of_arm: 0.1, heavy_tailed: false, note: 'Sin concentración fuerte de compradores.' },
    },
    incremental: { basis: 'difference_per_unit × treated_units_in_population', treated_units: 120, contribution: 540, ci_low: 144, ci_high: 936 },
    note: 'Estimación por intención de tratamiento con intervalo; no es causalidad demostrada.',
    ...over,
  }
}

export function roiReport(over: Partial<OutcomeRoiReport> = {}): OutcomeRoiReport {
  return {
    method: { id: 'outcomes-roi-v1', version: 'v1', summary: 'Cada recomendación se mide en su ventana fija.', classification: { observed: 'Observado', temporally_associated: 'Asociado temporalmente' }, estimators: { conversion: 'Newcombe' }, causally_attributable: 'No se produce', settings: {} },
    filters: { from: '2026-05-06', to: '2026-11-02', period_days: 180, period_defaulted: true, period_default_days: 180, period_max_days: 730, group_by: 'kind' },
    currency: 'USD', today: '2026-11-02', data_origin: { value: 'real', declared: true, note: 'Datos declarados como reales.' },
    summary: {
      funnel: { recommendations: 12, superseded_not_counted: 1, identified: 11, control_held_out: 0, actionable: 11, measured: 6, measured_actionable: 6, forecast_pending: { window_open: 3, settling: 1, measurable_not_yet_measured: 1, total: 5 }, attended: 4, action_rate: 0.6667, responded_after_action: 2, response_rate: 0.5, notes: {} },
      by_classification: { forecast: 5, observed: 3, temporally_associated: 3, incremental_estimated: 0, causally_attributable: 0 }, by_action_kind: { contacted: 4, not_contacted: 2 },
      // Como la API final: celdas por tipo Y horizonte, sin ningún total (el mismo tipo en dos horizontes cuenta a los mismos clientes).
      potential: {
        label: 'Oportunidad potencial, atribución no demostrada', note: 'no sumar entre tipos ni horizontes',
        cells: [cell('contact', 30, 4, 0, 27.52, 100.5), cell('recovery', 30, 2, 2, 0, 640), cell('replenishment', 30, 3, 0, 160, 700), cell('replenishment', 60, 2, 0, 50, 200), cell('budget_constraint', 30, 2, 0, 80, 320)],
      },
      observed: observedBlock({
        customers: 4, purchase_lines: 5, units: 12, net_amount: 800, contribution_known: 300, pairs_with_unknown_contribution: 2, recommendations_with_purchase: 5,
        per_recommendation: perRecommendation({ rows: 5, units: 14, net_amount: 900, contribution_known: 330, rows_with_known_contribution: 3, rows_with_unknown_contribution: 2 }),
      }),
      associated: associatedBlock({
        recommendations: 2, customers: 2, units: 5, net_amount: 400, contribution_known: 200,
        per_recommendation: perRecommendation({ rows: 2, units: 5, net_amount: 400, contribution_known: 200, rows_with_known_contribution: 2 }),
      }),
      control_baseline: {}, supply: {},
    },
    experiments: [],
    costs: {
      contact: { total: 8, actions: 4, actions_cost_unknown: 0, note: '' },
      platform_usage: { source: 'tenant_usage_records', metrics: [{ metric: 'forecast_runs', unit: 'corridas', quantity: 12, unit_price: null, cost: null }], total_cost: null, unpriced_metrics: ['forecast_runs'], reason: 'no_price_model', note: 'Uso completo del periodo.' },
    },
    roi: { status: 'potential_unproven', label: 'Oportunidad potencial, atribución no demostrada', proven: false, causal_claims: 0, currency: 'USD', value: null, reasons: ['no_control_group'], message: 'Sin un grupo de control analizado por intención de tratamiento no hay base para afirmar un beneficio: el ROI no se calcula ni se muestra como probado.' },
    breakdown: { group_by: 'kind', rows: [
      kindRow('contact', 4, { potential: { label: '', cells: [cell('contact', 30, 4, 0, 27.52, 100.5)], note: '' } }), kindRow('recovery', 2), kindRow('replenishment', 5), kindRow('budget_constraint', 2),
    ] },
    limitations: ['Sin grupo de control analizado no hay beneficio atribuible.'],
    ...over,
  }
}
