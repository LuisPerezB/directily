/**
 * Tipos de las vistas Enterprise (V2.5 · W5-E): Revenue Radar y Agenda (W5-A), Decision Engine y workflow (W5-B), Outcomes y ROI (W5-C) y ciclo de vida
 * de modelos (W4). Fuente: docs/openapi/v2.yaml y docs/decision/*.md. Todo campo que el contrato declara anulable se tipa así: un valor desconocido
 * es `null`, nunca cero, y la interfaz lo muestra como «No estimado» (nunca como 0).
 */
import type { ISODate, ISODateTime, PaginationMeta } from './types'
import type { CustomerWindowStatus, ResultEnvelope } from './types.v2'

// ─── Vocabulario común ──────────────────────────────────────────────────────

/** Estados heredados de la Agenda Comercial V2.2 (radar-agenda-map-v1). */
export type AgendaState = 'pendiente' | 'contactado' | 'interesado' | 'pedido_recibido' | 'pospuesto' | 'descartado'
export type WorkflowStatus = 'suggested' | 'assigned' | 'reviewed' | 'approved' | 'rejected' | 'in_progress' | 'executed' | 'measured' | 'closed' | 'expired'
export type RadarKind = 'contact' | 'recovery' | 'customer_risk'
export type DecisionKind = RadarKind | 'replenishment' | 'inventory_mismatch' | 'budget_constraint' | 'scenario'
export type AttributionLabel = 'potential_unproven' | 'incremental_estimated' | 'causally_attributable'
export type CostStatus = 'reliable' | 'unreliable' | 'unknown'
export type DecisionAction = 'assign' | 'reassign' | 'review' | 'approve' | 'reject' | 'dismiss' | 'start' | 'start_without_approval' | 'execute' | 'close'
export type ExperimentGroup = 'treatment' | 'control'

// ─── Revenue Radar y Agenda (W5-A) ──────────────────────────────────────────

export interface RadarWindow {
  status: CustomerWindowStatus | null
  p10_days: number | null
  p50_days: number | null
  p90_days: number | null
}

export interface RadarCustomerRef {
  id: number
  code: string | null
  type: string | null
  zone: string | null
  segment: { id: number; code: string; name: string } | null
}

export interface RadarProductRef {
  id: number
  sku: string | null
  name: string | null
  unit: string | null
  category: { id: number; name: string } | null
}

/** Disponibilidad al corte: `unknown` = sin fotografía vigente (se desconoce, nunca es cero); el tránsito NO cuenta como disponible. */
export interface RadarInventory {
  status: 'known' | 'unknown' | 'not_applicable'
  reason?: string
  on_hand?: number
  reserved?: number
  available?: number
  in_transit?: number
  snapshot_to?: string
  [key: string]: unknown
}

export interface RadarValue {
  /** Supuesto: unidades esperadas × último precio neto (pronóstico) o, a nivel de cliente, caída observada del gasto. Nunca una venta confirmada. */
  expected_gross_value: number | null
  /** null si el costo no es confiable. */
  expected_contribution: number | null
  cost_status: CostStatus
  cost_reasons: string[]
  action_cost: number | null
  incremental_estimate: number | null
  attribution: { label: AttributionLabel; text: string }
  basis_code?: 'forecast_units_x_last_net_price' | 'observed_spend_decline' | string
  basis?: string
}

export interface RadarActivity {
  inactivity_status: string
  inactivity_risk: number | null
  behavior_change: string
  days_since_last_purchase: number | null
  median_interval_days: number | null
}

export interface RadarOpportunity {
  id: number
  kind: RadarKind
  entity_type: 'customer_product' | 'customer'
  as_of: ISODate
  horizon_days: number
  source_run_id: number | null
  customer: RadarCustomerRef
  product: RadarProductRef | null
  state: AgendaState
  status: WorkflowStatus
  priority: number
  score: number | null
  score_formula_version: string
  policy: string | null
  probability: number | null
  expected_orders: number | null
  quantity_per_order: number | null
  expected_units: number | null
  window: RadarWindow
  model_segment: string | null
  activity: RadarActivity | null
  value: RadarValue
  inventory: RadarInventory
  owner_user_id: number | null
  assigned_user: { id: number; name: string | null } | null
  /** Solo lo envía la API a quien aprueba; la interfaz NO lo muestra en las vistas del Radar ni de la agenda. */
  experiment_group?: ExperimentGroup | null
  expires_at: ISODateTime | null
  next_step: string | null
  follow_up: { last_feedback_at: ISODateTime | null; snoozed_until: ISODate | null }
}

export interface RadarEvent {
  id: number
  event: 'transition' | 'feedback' | 'assignment' | 'comment'
  at: ISODateTime | null
  from_agenda_status: string | null
  agenda_status: string | null
  from_status: string | null
  to_status: string | null
  actor_kind: 'user' | 'system'
  actor_user_id: number | null
  note: string | null
  interest: boolean | null
  snoozed_until: ISODate | null
  implied_workflow_status: string | null
  workflow_sync?: 'applied' | 'unchanged' | 'not_applicable' | 'skipped' | null
  workflow_sync_reason?: string | null
  workflow_status_after?: string | null
  workflow_event_id?: number | null
}

export interface RadarOpportunityDetail extends RadarOpportunity {
  explanation: Record<string, unknown>[]
  assumptions: unknown[]
  constraints: Record<string, unknown>[]
  uncertainty: Record<string, unknown>
  model_versions: Record<string, unknown>
  interval: Record<string, unknown>
  source: { core_run_id: number | null; forecast_run_id: number | null; data_snapshot_id: number | null }
  allowed_feedback: AgendaState[]
  history: RadarEvent[]
}

export interface AgendaStatusMapRow {
  legacy: AgendaState
  workflow: WorkflowStatus | null
  note: string
  allowed_next: AgendaState[]
}

export interface StatusMap {
  version: string
  table: AgendaStatusMapRow[]
}

export interface RadarHorizonSummary {
  opportunities: number
  customers: number
  by_kind: Record<string, number>
  by_state: Record<string, number>
  inventory: { known: number; unknown: number; below_expected: number; not_applicable: number }
  /** Valor PRONOSTICADO de las relaciones cliente × producto (no incluye lo de nivel de cliente). */
  value: {
    currency: string
    scope: 'customer_product'
    opportunities: number
    attribution: { label: string; text: string }
    gross_value: number | null
    gross_value_opportunities: number
    /** Solo suma las valoradas con costo confiable. */
    contribution: number | null
    contribution_opportunities: number
    contribution_unavailable: number
    basis_code: 'forecast_units_x_last_net_price'
    basis: string
  }
  /** Importe OBSERVADO (caída del gasto neto): no es pronóstico ni contribución y NO se suma a `value`. */
  customer_level: {
    opportunities: number
    customers: number
    currency: string
    observed_spend_decline: number | null
    observed_spend_decline_opportunities: number
    basis_code: 'observed_spend_decline'
    basis: string
  }
}

export interface RadarSummary {
  as_of: ISODate | null
  totals: { opportunities: number; customers: number; unit: string }
  by_horizon: Record<string, RadarHorizonSummary>
  /** Solo con decisions.approve: la interfaz NO lo muestra (revelaría el experimento). */
  control_withheld?: number
  label: string
  notes: string[]
  run_id: number | null
}

export interface RadarSummaryResponse {
  data: RadarSummary
  envelope: ResultEnvelope | null
  definitions?: Record<string, string>
  message: string | null
}

export interface RadarOpportunitiesPage {
  data: RadarOpportunity[]
  meta: PaginationMeta
  envelope: ResultEnvelope | null
  label?: string
  definitions?: Record<string, string>
}

export interface RadarOpportunityResponse {
  data: RadarOpportunityDetail
  label?: string
  definitions?: Record<string, string>
  status_map?: StatusMap
}

export interface RadarAgendaGroup {
  customer: RadarCustomerRef
  opportunities_count: number
  best_score: number | null
  top_priority: number
  states: Record<string, number>
  next_action: string | null
  opportunities: RadarOpportunity[]
}

export interface RadarAgendaPage {
  data: RadarAgendaGroup[]
  meta: PaginationMeta
  date: ISODate
  envelope: ResultEnvelope | null
  label?: string
  definitions?: Record<string, string>
  status_map?: StatusMap
}

export interface RadarFilterQuery {
  horizon?: number
  kind?: RadarKind
  customer_id?: number
  product_id?: number
  seller_id?: number
  segment_id?: number
  zone?: string
  category_id?: number
  state?: AgendaState
  status?: WorkflowStatus
  as_of?: string
  order_by?: 'score' | 'priority' | 'expected_gross_value' | 'expected_contribution' | 'probability' | 'as_of' | 'horizon_days'
  direction?: 'asc' | 'desc'
  page?: number
  per_page?: number
}

export interface RadarFeedbackBody {
  state: AgendaState
  note?: string | null
  idempotency_key: string
  snoozed_until?: string | null
}

export interface RadarFeedbackResult {
  data: { opportunity: RadarOpportunityDetail; event: RadarEvent; replayed: boolean }
  status_map?: { version: string }
}

export interface RadarMaterialization {
  status: 'completed' | 'partial' | 'no_run'
  run_id: number | null
  as_of: ISODate | null
  horizons: number[]
  policy: string
  candidates: number
  created: number
  existing: number
  failed: number
}

// ─── Decision Engine y workflow (W5-B) ──────────────────────────────────────

/** Lo que el Decision Engine declara de la incertidumbre de una recomendación (solo los campos que la interfaz lee; el resto es libre). */
export interface RecommendationUncertainty {
  cost?: { reliable?: boolean; reasons?: string[] }
  expected_contribution_null_because?: string[]
  [key: string]: unknown
}

/** Restricción de una recomendación: siempre un objeto con `code` (o, las del plan de compras, con `family`). */
export interface RecommendationConstraint {
  code?: string
  family?: string
  note?: string
  [key: string]: unknown
}

export interface DecisionAllowedTransition {
  action: DecisionAction
  to_status: WorkflowStatus
  ability: string | null
  requires: { assignee: boolean; reason: boolean; expected_version: boolean }
  allowed: boolean
  /** forbidden | decision_segregation_of_duties | decision_approval_required | control_group_not_actionable */
  blocked_by: string | null
}

export interface DecisionEvent {
  id: number
  event: 'transition' | 'feedback' | 'assignment' | 'comment'
  from_status: string | null
  to_status: string | null
  agenda_status: string | null
  actor_user_id: number | null
  actor_kind: 'user' | 'system'
  reason: string | null
  payload: Record<string, unknown>
  created_at: ISODateTime
}

export interface DecisionRecommendation {
  id: number
  kind: DecisionKind
  as_of: ISODate
  horizon_days: number
  entity_type: 'customer_product' | 'customer' | 'product_branch' | 'product'
  customer: { id: number; external_code?: string } | null
  product: { id: number; sku?: string; name?: string; unit?: string } | null
  branch: { id: number; code?: string; name?: string } | null
  source: { core_run_id: number | null; forecast_run_id: number | null; data_snapshot_id: number | null }
  model_versions: Record<string, unknown>
  probability: number | null
  interval: Record<string, unknown>
  assumptions: unknown[]
  constraints: RecommendationConstraint[]
  availability: Record<string, unknown>
  currency: string | null
  action_cost: number | null
  expected_gross_value: number | null
  /** Solo con costo confiable; null en otro caso (uncertainty.cost y uncertainty.expected_contribution_null_because dicen por qué). */
  expected_contribution: number | null
  incremental_estimate: number | null
  incremental_evidence: Record<string, unknown> | null
  attribution_label: AttributionLabel
  attribution_note: string | null
  score: number | null
  score_formula_version: string
  uncertainty: RecommendationUncertainty
  explanation: Record<string, unknown>[]
  policy: string | null
  priority: number
  owner_user_id: number | null
  assigned_user_id: number | null
  status: WorkflowStatus
  agenda_status: AgendaState | null
  /** Solo la API lo envía a quien aprueba; la interfaz lo muestra únicamente en el detalle del Centro de decisiones. */
  experiment_group?: ExperimentGroup | null
  proposed_by: { kind: 'system' | 'user'; user_id: number | null }
  expires_at: ISODateTime | null
  next_step: string | null
  /** Se manda como `expected_version` al hacer una transición (bloqueo optimista). */
  version: number | null
  requires_approval: boolean | null
  allowed_transitions: DecisionAllowedTransition[]
  events?: DecisionEvent[]
  created_at: ISODateTime | null
  updated_at: ISODateTime | null
}

export interface DecisionQuery {
  status?: string
  kind?: string
  priority?: number
  horizon_days?: number
  customer_id?: number
  product_id?: number
  branch_id?: number
  assigned_user_id?: number
  mine?: boolean
  requires_approval?: boolean
  attribution_label?: AttributionLabel
  as_of?: string
  expiring_before?: string
  sort?: '-score' | 'score' | 'priority' | '-priority' | '-created_at' | 'created_at' | 'expires_at' | '-expires_at' | '-as_of'
  page?: number
  per_page?: number
}

export interface DecisionSummary {
  by_status: Record<string, number>
  by_kind: Record<string, number>
  by_attribution_label: Record<string, number>
  awaiting_assignment: number
  awaiting_approval: number
  expiring_within_24h: number
  attribution_note: string
  /** Solo con decisions.approve; la interfaz no lo muestra fuera del detalle. */
  control_group_units?: number
}

export interface DecisionTransitionBody {
  to_status: WorkflowStatus
  idempotency_key: string
  expected_version: number
  reason?: string | null
  assignee_user_id?: number | null
}

export interface DecisionTransitionResult {
  data: DecisionRecommendation
  meta: { idempotent_replay: boolean; event_id: number }
}

export interface DecisionSettingsData {
  settings: Record<string, unknown>
  defaults: Record<string, unknown>
  score_formula: { id: string; revision: number; version: string; parameters: Record<string, unknown> }
  currency: string
  note: string
}

// ─── Outcomes y ROI (W5-C) ──────────────────────────────────────────────────

export type OutcomeClassification = 'forecast' | 'observed' | 'temporally_associated' | 'incremental_estimated' | 'causally_attributable'
export type OutcomeActionKind = 'contacted' | 'executed' | 'not_contacted' | 'not_executed' | 'rejected' | 'expired' | 'control'
/** `no_sell_through` = al cierre de la ventana la disponibilidad no bajó respecto de la de la recomendación (observación de existencias; no diagnostica exceso). */
export type ObservedEvent = 'purchase' | 'no_purchase' | 'product_sales' | 'stockout' | 'no_sell_through'
export type RoiStatus = 'potential_unproven' | 'incremental_estimated' | 'incremental_conversion_only' | 'multiple_experiments'

/**
 * Evidencia de una medición: solo lo que la interfaz lee. Quien no aprueba no recibe `experiment`, `itt` ni `unit`; la interfaz no los muestra en ningún caso.
 * `lines` y `unit_costs` sirven al servidor para deduplicar entre recomendaciones: la interfaz no los usa.
 */
export interface OutcomeEvidence {
  source?: {
    fingerprint?: string
    late_lines_not_counted?: number
    late_units_not_counted?: number
    /** Unidades DEVUELTAS que llegaron después del corte y no se descontaron. */
    late_returned_units_not_counted?: number
    lines_revised_or_removed_after_cutoff?: number
    knowledge_cutoff?: string
    [key: string]: unknown
  }
  window?: { knowledge_cutoff?: string; [key: string]: unknown }
  observation?: {
    /** Devoluciones emitidas DESPUÉS de cerrar la ventana pero conocidas al corte: ya se restaron del neto. */
    returns_after_window?: { lines?: number; units?: number; net_amount?: number }
    purchases_before_action?: number
    purchases_after_action?: number
    [key: string]: unknown
  }
  [key: string]: unknown
}

/** Lo posterior a la acción registrada, neto de las devoluciones posteriores: lo ÚNICO asociable a la acción. */
export interface OutcomeAfterAction {
  units: number | null
  net_amount: number | null
  /** null si el costo no es confiable (con `contribution_status = unknown` y sus motivos). */
  contribution: number | null
  contribution_status: 'known' | 'unknown' | 'not_applicable_no_purchase' | null
  contribution_reasons: string[]
  purchase_lines: number | null
}

export interface DecisionOutcome {
  id: number
  recommendation: {
    id: number
    kind?: DecisionKind
    status?: string
    as_of?: ISODate
    horizon_days?: number
    entity_type?: string
    priority?: number
    assigned_user_id?: number | null
    customer?: { id: number; external_code?: string } | null
    product?: { id: number; sku?: string; name?: string } | null
    expected_contribution?: number | null
    attribution_label_at_generation?: AttributionLabel
  }
  action_kind: OutcomeActionKind
  action_label: string | null
  action_at: ISODateTime | null
  window: { from: ISODate | null; to: ISODate | null; knowledge_cutoff: ISODate | null }
  observed_event: ObservedEvent
  observed_ref: string | null
  observed_units: number | null
  observed_net_amount: number | null
  /** null si el costo no es confiable (nunca 0 por desconocido); 0 si no hubo compra. */
  contribution: number | null
  contribution_status: 'known' | 'unknown' | 'not_applicable_no_purchase' | null
  contribution_reasons: string[]
  /** Solo lo posterior a la acción registrada; null si no hubo acción registrada dentro de la ventana. `observed_*` y `contribution` son de la ventana completa. */
  after_action?: OutcomeAfterAction | null
  contact_cost: number | null
  /** Las filas son `observed` o `temporally_associated`: `incremental_estimated` es un resultado AGREGADO del informe (experimentos y `roi.status`), nunca de una fila. */
  classification: OutcomeClassification
  classification_label: string | null
  classification_note: string | null
  /** Siempre `none` en las filas: el ITT es un método del agregado. */
  method: 'itt' | 'quasi_experiment' | 'none'
  evidence: OutcomeEvidence
  measured_at: ISODateTime | null
  /** Solo con decisions.approve; la interfaz no lo muestra en la lista de resultados. */
  experiment_group?: ExperimentGroup | null
}

export interface OutcomeQuery {
  kind?: string
  classification?: string
  action_kind?: string
  observed_event?: string
  horizon_days?: number
  customer_id?: number
  product_id?: number
  contribution_known?: boolean
  from?: string
  to?: string
  sort?: '-measured_at' | 'measured_at' | '-window_to' | 'window_to' | '-observed_net_amount' | '-contribution' | '-id' | 'id'
  page?: number
  per_page?: number
}

export interface RoiQuery {
  kind?: string
  horizon_days?: number
  from?: string
  to?: string
  group_by?: 'kind' | 'horizon' | 'segment' | 'owner'
}

export interface IttInterval {
  ci_low: number | null
  ci_high: number | null
}

export interface OutcomeArmCounts {
  treatment: number
  control: number
}

export interface OutcomeTailDiagnostic {
  buyer_cv: number
  largest_buyer_share_of_arm: number
  /** Pocos compradores concentran el importe: el intervalo monetario cubre menos que su nivel nominal y debe leerse como indicativo. */
  heavy_tailed: boolean
  note: string
}

/** Análisis por intención de tratamiento de un experimento y horizonte. Solo lo recibe quien aprueba. */
export interface OutcomeExperiment {
  /** null: unidades con grupo que no se pudieron atribuir a un experimento (`status = not_analysed`). */
  experiment_key: string | null
  horizon_days: number
  method?: 'itt'
  status: 'estimated' | 'insufficient_sample' | 'no_control_group' | 'no_treatment_group' | 'heterogeneous_control_fraction' | 'not_analysed'
  confidence_level?: number
  min_group_size?: number
  /** Compradores mínimos por grupo para publicar el intervalo monetario (provisional, a aprobar en el piloto). */
  min_buyers_per_arm?: number
  sample?: { treatment: number; control: number; treatment_converted: number; control_converted: number }
  /** Cociente de muestras (SRM): `mismatch` = la proporción de control observada no es compatible con la fracción registrada al asignar; el estimado debe leerse con ese aviso. */
  sample_ratio?: { status: 'ok' | 'mismatch' | 'not_evaluable'; expected_control_fraction?: number | null; observed_control_share?: number | null; chi_square?: number; p_value?: number; threshold?: number; note?: string }
  dropped_units?: Record<string, OutcomeArmCounts>
  contamination?: { control_units_with_registered_action: number }
  conversion?: IttInterval & { rate_treatment: number | null; rate_control: number | null; difference: number | null; significant: boolean | null }
  /**
   * La contribución monetaria puede ser `not_estimable` (`buyers_below_min_per_arm`, `zero_variance`, `bootstrap_budget_exceeded`) aunque la conversión SÍ tenga estimación.
   * El intervalo monetario es un bootstrap percentil con semilla registrada.
   */
  contribution?: {
    status: 'estimated' | 'insufficient_sample' | 'not_estimated' | 'not_estimable'
    /** `buyers_below_min_per_arm`, `zero_variance`, `bootstrap_budget_exceeded`, `contribution_population_below_min_group_size` o el estado de la conversión si no se estimó. */
    reason: string | null
    buyers?: OutcomeArmCounts
    population?: OutcomeArmCounts
    excluded_unreliable_cost?: OutcomeArmCounts
    difference_per_unit?: number
    ci_low?: number
    ci_high?: number
    significant?: boolean
    interval?: { method: string; resamples: number; seed: number; [key: string]: unknown }
    tail_diagnostic?: OutcomeTailDiagnostic
    diagnostic_welch?: { std_error: number; ci_low: number; ci_high: number; note: string }
  }
  /** null si la contribución no se pudo estimar (aunque la conversión sí). */
  incremental?: { basis: string; treated_units: number; contribution: number; ci_low: number; ci_high: number } | null
  note?: string
  contact_cost?: { total: number; actions: number; actions_cost_unknown: number }
}

export interface OutcomeRoi {
  status: RoiStatus
  label: string
  /** Siempre false en esta versión; la interfaz NUNCA presenta el ROI como probado. */
  proven: boolean
  causal_claims: number
  currency: string
  /** Solo viene en los estados sin estimación (siempre null); la interfaz nunca lo muestra como cifra. */
  value?: number | null
  /** Ausente para quien no aprueba (solo recibe status, label, value, proven, causal_claims, currency y message). */
  reasons?: string[]
  message: string
  /** Aviso de origen (demostración, simulado o no verificado cuando no se declaró). */
  data_origin_warning?: string
  experiment_key?: string
  horizon_days?: number
  confidence_level?: number
  conversion?: (IttInterval & { rate_treatment: number | null; rate_control: number | null; difference: number | null; significant: boolean | null }) | null
  sample?: Record<string, number>
  sample_ratio?: { status: string; [key: string]: unknown }
  incremental_contribution?: { basis: string; treated_units: number; contribution: number; ci_low: number; ci_high: number } | null
  contact_cost?: { total: number; actions: number; actions_cost_unknown: number }
  net_incremental_contribution?: { estimate: number; ci_low: number; ci_high: number; significant: boolean } | null
  roi_ratio?: { estimate: number; ci_low: number; ci_high: number } | null
  net_after_platform_usage?: { estimate: number; ci_low: number; ci_high: number; platform_usage_cost: number } | null
  /** Solo con `multiple_experiments`: claves `experimento|horizonte` con estimación (no son aditivas). */
  experiments?: string[]
  associated_note?: string
}

/** Celda de oportunidad potencial: UN tipo de recomendación en UN horizonte. Las celdas nunca se suman (ni entre tipos ni entre horizontes). */
export interface RoiPotentialCell {
  kind: string
  horizon_days: number
  recommendations: number
  /** Suma de las recomendaciones de la celda CON costo confiable (0 si ninguna lo tenía). */
  expected_contribution: number
  recommendations_without_reliable_cost: number
  expected_gross_value: number
}

/** Oportunidad potencial POR TIPO Y HORIZONTE. No existe un total entre celdas. */
export interface RoiPotential {
  label?: string
  cells?: RoiPotentialCell[]
  note?: string
}

/** Suma por recomendación, solo para el detalle: NO es aditiva (varias recomendaciones pueden observar la misma venta). */
export interface RoiPerRecommendation {
  rows: number
  units: number
  net_amount: number
  contribution_known: number
  rows_with_known_contribution: number
  rows_with_unknown_contribution: number
  note?: string
}

/** Lo observado en la ventana completa (con o sin acción), deduplicado por línea de venta distinta: no cuenta dos veces una misma venta. */
export interface RoiObserved {
  label: string
  customers: number
  purchase_lines: number
  units: number
  net_amount: number
  contribution_known: number
  /** Pares cliente × producto sin costo confiable: su contribución no entra en `contribution_known`. */
  pairs_with_unknown_contribution: number
  recommendations_with_purchase: number
  /** Resultados sin detalle de líneas (más de 100 o anteriores a este formato): se sumaron por recomendación y pueden contar dos veces. */
  rows_without_line_detail: number
  per_recommendation: RoiPerRecommendation
  note: string
}

/** SOLO lo posterior a la acción registrada, deduplicado: lo único asociable (temporalmente) a la acción. NO es beneficio atribuible. */
export interface RoiAssociated {
  label: string
  recommendations: number
  customers: number
  units: number
  net_amount: number
  contribution_known: number
  pairs_with_unknown_contribution: number
  rows_without_line_detail: number
  per_recommendation: RoiPerRecommendation
  note: string
}

export interface RoiFunnel {
  recommendations: number
  superseded_not_counted: number
  identified: number
  /** Solo lo envía la API a quien aprueba; la interfaz nunca lo muestra ni lo usa para derivar otras cifras. */
  control_held_out?: number
  actionable: number
  measured: number
  measured_actionable: number
  forecast_pending: Record<string, number>
  attended: number
  action_rate: number | null
  responded_after_action: number
  response_rate: number | null
  notes: Record<string, string>
}

export interface RoiSummary {
  funnel: RoiFunnel
  by_classification: Record<string, number>
  /** Puede traer `control`; la interfaz no lo usa. */
  by_action_kind?: Record<string, number>
  potential?: RoiPotential
  observed: RoiObserved
  associated: RoiAssociated
  control_baseline?: Record<string, unknown>
  /** Quiebres, `no_sell_through` y ventas del producto observados en los tipos por producto. */
  supply?: Record<string, unknown>
}

export interface RoiPlatformUsage {
  source: string
  metrics: { metric: string; unit: string; quantity: number; unit_price: number | null; cost: number | null }[]
  total_cost: number | null
  unpriced_metrics: string[]
  reason: string | null
  note: string
}

export interface RoiBreakdownRow extends Partial<RoiSummary> {
  key: string
  label: string
  contact_cost: { total: number; actions: number; actions_cost_unknown: number; note: string }
}

export interface OutcomeRoiReport {
  method: { id: string; version: string; summary: string; classification: Record<string, string>; estimators: Record<string, string>; causally_attributable: string; settings: Record<string, unknown> }
  /** Filtros efectivos que aplicó el servidor, con el periodo ya resuelto (por omisión, los últimos 180 días por el corte `as_of`; máximo 730). */
  filters: {
    from?: string | null
    to?: string | null
    period_days?: number
    period_defaulted?: boolean
    period_default_days?: number
    period_max_days?: number
    [key: string]: unknown
  }
  currency: string
  today: ISODate
  data_origin: { value: 'real' | 'demo' | 'simulated' | null; declared: boolean; note: string }
  summary: RoiSummary
  /** Ausente para quien no aprueba. */
  experiments?: OutcomeExperiment[]
  costs: { contact: { total: number; actions: number; actions_cost_unknown: number; note: string }; platform_usage: RoiPlatformUsage }
  roi: OutcomeRoi
  breakdown: { group_by: 'kind' | 'horizon' | 'segment' | 'owner'; rows: RoiBreakdownRow[] }
  limitations: string[]
}

// ─── Ciclo de vida de modelos (W4) ──────────────────────────────────────────

export interface ModelRegistryEventEntry {
  id: number
  from_status: string | null
  to_status: string
  actor_user_id: number | null
  actor_kind: string
  reason: string | null
  evidence: Record<string, unknown>
  created_at: ISODateTime
}

export interface ModelPromotionDecisionRef {
  id: number
  policy: string
  scope_key: string
  decision: string
  is_effective: boolean
  reason: string | null
  approved_by: number | null
  approved_at: ISODateTime | null
  decided_at: ISODateTime | null
}

export interface ModelLifecycleDetail {
  id: number
  version: string
  model_family: string
  label?: string
  status: string
  serving_basis?: string
  core: string | null
  role: string | null
  published_by: number | null
  published_at: ISODateTime | null
  approved_by: number | null
  approved_at: ISODateTime | null
  rejected_reason: string | null
  events: ModelRegistryEventEntry[]
  promotion_decision: ModelPromotionDecisionRef | null
  published: boolean
  retired_model_ids?: number[]
}
