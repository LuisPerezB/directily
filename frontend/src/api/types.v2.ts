/**
 * Tipos de la etapa 2 (envoltura común, demanda extendida, Customer Core, core de Datos y decisiones).
 * Fuente: docs/06-contratos-integracion.md §3.8.1, §3.9, §3.14–3.16 y docs/architecture/v2/P1…P4.
 * Se reexportan desde `types.ts`. Todo campo que el contrato declara opcional o anulable se tipa así:
 * un valor desconocido es `null`, nunca cero.
 */
import type { ISODate, ISODateTime, Paginated, PaginationMeta, TrainingJob } from './types'

// ─── Envoltura común de resultados (ADR-V2-03) ─────────────────────────────

export type QualityStatus = 'ok' | 'degraded' | 'insufficient_data' | 'not_configured' | 'insufficient_evidence'
export type UncertaintySource = 'empirical_holdout' | 'conformal' | 'model' | 'unknown' | 'none'
export type ResultType = 'forecast' | 'conditional_simulation' | 'causal_estimate' | 'optimized_plan' | 'evaluation'

export interface ResultEnvelope {
  tenant_id?: number
  run_id: number | null
  core: string
  run_version?: number | null
  /** Estado técnico del trabajo; NO es la aptitud estadística (`quality_status`). */
  status?: string | null
  as_of: ISODate | null
  horizon_start: ISODate | null
  horizon_end: ISODate | null
  target_definition: Record<string, unknown> | null
  entity_keys: string[] | null
  unit: string | null
  currency: string | null
  result_type: ResultType | string | null
  model_version: unknown
  data_snapshot_id: number | null
  config_version: string | null
  assumptions: (string | { code?: string; message?: string })[]
  limitations: (string | { code?: string; message?: string })[]
  limitation_codes?: string[]
  quality_status: QualityStatus | null
  uncertainty_source: UncertaintySource | null
  horizon_support?: HorizonSupport | Record<string, HorizonSupport>
  p50?: boolean | null
}

// ─── Respaldo por horizonte (demanda) ───────────────────────────────────────

export type HorizonSupportStatus = 'ok' | 'degraded' | 'insufficient_data' | 'not_computed' | 'unknown'

export interface HorizonSupportReason {
  code: string
  message: string
}

export interface HorizonSupport {
  horizon: number
  status: HorizonSupportStatus
  reasons: (HorizonSupportReason | string)[]
  history_days?: number | null
  required_history_days?: number | null
  backtest_horizon_days?: number | null
  independent_holdout?: boolean | null
  evaluated_obs?: number | null
}

// ─── Manifiesto del protocolo de demanda (demand-protocol-v1) ───────────────

export interface DemandManifestWindow {
  window: number
  role: 'selection' | 'holdout'
  origin: ISODate
  train_through: ISODate
  target_from: ISODate
  target_to: ISODate
  target_days: number
  series: number
}

export interface DemandManifest {
  manifest_version: string
  core: 'demand'
  as_of: ISODate
  history: { from: ISODate; to: ISODate; days: number }
  seed?: number
  min_improvement?: number
  config_version?: string | null
  protocol: {
    backtest: boolean
    reason?: string
    horizon_days?: number
    step_days?: number
    selection_windows?: number
    independent_holdout?: boolean
    procedure?: string
  }
  training: {
    backtest_fits?: DemandManifestWindow[]
    final_fit?: { train_from: ISODate; train_through: ISODate; uses?: string }
  }
  selection: {
    origins: ISODate[]
    target_from: ISODate | null
    target_to: ISODate | null
    unique_target_dates: number
    shared_target_dates_between_windows: number
    criterion?: string
  } | null
  calibration: {
    source?: string
    residual_target_from: ISODate | null
    residual_target_to: ISODate | null
    observable_at: ISODate | null
    n_residual_rows: number
    residuals_observable_when_calibrating: boolean
    production?: string
  } | null
  holdout: {
    present: boolean
    independent: boolean
    origin?: ISODate | null
    target_from?: ISODate | null
    target_to?: ISODate | null
    horizon_days?: number
    n_rows?: number
    series?: number
    reason?: string
    used_for_model_selection?: boolean
    used_for_hyperparameters?: boolean
    used_for_thresholds?: boolean
    used_for_interval_calibration?: boolean
  }
  label_maturity: {
    rule: string
    label_horizon_days: number
    selection_evidence_end: ISODate | null
    latest_selection_window_target_to: ISODate | null
    holdout_evidence_end: ISODate | null
    source_complete_through?: ISODate | null
  } | null
  overlap: {
    overlapping_origins: boolean
    shared_target_dates_between_selection_windows: number
    shared_target_dates_with_holdout: number
    note: string
  } | null
  decisions?: {
    evaluation_windows_that_also_took_decisions?: number[]
    [key: string]: unknown
  }
  checks?: Record<string, boolean | null>
  horizon_support?: Record<string, HorizonSupport>
  p50?: P50Decision | null
  served?: Record<string, unknown> | null
}

export interface P50Decision {
  published: boolean
  reason?: string | null
  [key: string]: unknown
}

// ─── Reversión de modelos ───────────────────────────────────────────────────

export interface ArtifactVerification {
  verified: boolean | null
  status: 'verified' | 'partially_verified' | 'not_verifiable' | 'no_artifacts'
  registered: number
  checked: number
  reason: 'artifacts_dir_not_configured' | 'artifacts_dir_not_accessible' | 'artifact_files_not_found' | null
  message: string
}

export interface DemandRollbackResult {
  from_run_id: number
  from_version: number
  to_run_id: number
  to_version: number
  to_cutoff_date: ISODate | null
  to_data_snapshot_id: number | null
  to_config_version: string | null
  restored_model_ids: number[]
  retired_model_ids: number[]
  /** true = SHA-256 verificado; null = no verificable desde el backend (artefacto no visible). */
  artifacts_verified: boolean | null
  /** Detalle explícito de la verificación (motivo cuando no se pudo verificar). */
  artifacts_verification?: ArtifactVerification
  restored_models: { id: number; model_family: string; role: string | null }[]
  note: string
}

export interface CustomerRollbackResult {
  restored: { id: number; version: string; model_family: string; role: string | null; status: string }
  slot: string
  horizon_days: number
  objective: string
  retired: { id: number; version: string; model_family: string }[]
  artifact_sha256_verified: boolean | null
  artifact_verification?: ArtifactVerification
  ranking_source: string | null
  note: string
}

// ─── Customer Core (comportamiento de compra) ───────────────────────────────

export type CustomerOperation = 'train' | 'predict'
export type CalibrationStatus = 'calibrated' | 'degraded' | 'uncalibrated'
export type RankingSource = 'model' | 'popularity' | 'recency'

export interface CustomerRun {
  id: number
  operation: CustomerOperation
  version: number
  run_type: 'production' | 'historical'
  status: 'queued' | 'running' | 'completed' | 'failed'
  quality_status: QualityStatus | null
  result_type: string | null
  triggered_by: string
  as_of: ISODate | null
  horizons: number[] | null
  is_current: boolean
  config_version: string | null
  data_snapshot_id: number | null
  model_versions: Record<string, Record<string, unknown>> | null
  summary: Record<string, unknown> | null
  assumptions: (string | { message?: string })[]
  limitations: (string | { message?: string })[]
  error_message: string | null
  started_at: ISODateTime | null
  completed_at: ISODateTime | null
  created_at: ISODateTime
  envelope: ResultEnvelope
  training_job: TrainingJob | null
}

export interface CustomerPrediction {
  customer: { id: number; code: string | null }
  product: { id: number; sku?: string; name?: string; unit?: string }
  horizon_days: number
  as_of: ISODate
  purchase_probability: number | null
  calibration_status: CalibrationStatus | null
  probability_coherence_adjusted: boolean
  quantity_if_purchase: number | null
  quantity_interval: { lo: number | null; hi: number | null; level: number; method: string } | null
  expected_quantity: number | null
  committed_quantity: number | null
  incremental_quantity: number | null
  total_view_quantity: number | null
  /** Estimación con supuesto de precio: nunca una venta confirmada. */
  expected_amount: { value: number | null; currency: string | null; assumed_unit_price: number | null; basis: string }
  median_interval_days: number | null
  days_since_last_purchase: number | null
  n_purchases: number | null
  model: { family: string | null; role: 'primary' | 'fallback' | null }
  quality_flags: string[]
}

export interface CustomerPredictionsPage extends Paginated<CustomerPrediction> {
  envelope: ResultEnvelope
  ranking_source?: RankingSource | null
  definitions?: Record<string, string>
}

export type InactivityStatus = 'active' | 'at_risk' | 'inactive_by_pattern' | 'insufficient_history'
export type BehaviorChangeStatus = 'increase' | 'decrease' | 'stable' | 'insufficient_history'

export interface CustomerProfileRanking {
  rank: number
  ranking_source: RankingSource | null
  ranking_score: number | null
  product: { id: number; sku: string | null; name: string | null; unit: string | null }
  purchase_probability: number | null
  expected_quantity: number | null
  expected_amount: { value: number | null; currency: string | null; basis: string }
}

export interface CustomerProfile {
  customer: { id: number; code: string | null; type: string | null; is_generic: boolean; segment: string | null }
  status: 'predicted' | 'no_individual_prediction' | 'no_candidate_relations' | 'no_prediction_run'
  fallback: string | null
  as_of?: ISODate | null
  activity: {
    last_purchase_date: ISODate | null
    days_since_last_purchase: number | null
    n_purchase_days: number
    purchase_frequency_per_30d: number | null
    median_interval_days: number | null
    mean_interval_days: number | null
    inactivity: {
      status: InactivityStatus
      risk: number | null
      interval_p75_days: number | null
      interval_p90_days: number | null
      n_intervals: number
      definition: string
    }
    behavior_change: {
      status: BehaviorChangeStatus
      frequency_ratio: number | null
      p_value: number | null
      spend_ratio: number | null
    }
  } | null
  rankings: Record<string, CustomerProfileRanking[]>
  ranking_sources?: Record<string, RankingSource | null>
  ranking_source_note?: string
}

export interface CalibrationBin {
  n: number
  mean_predicted: number
  observed_rate: number
  observed_lo95?: number | null
  observed_hi95?: number | null
  p_min?: number
  p_max?: number
}

export interface ProbabilityMetrics {
  n: number
  n_positive: number
  base_rate: number | null
  brier: number | null
  log_loss: number | null
  pr_auc: number | null
  roc_auc?: number | null
  ece: number | null
  mean_predicted?: number | null
  brier_climatology?: number | null
  brier_skill_vs_climatology?: number | null
  calibration_curve: CalibrationBin[]
}

export interface RankingKMetrics {
  n_units: number
  n_units_with_positive?: number
  [key: string]: number | undefined
}

export interface RankingMetrics {
  served: RankingKMetrics
  popularity: RankingKMetrics
  recency: RankingKMetrics
  reference?: RankingKMetrics
  challenger_not_promoted?: RankingKMetrics
  selection?: { source: RankingSource; reason: string; primary_k: number; values: Record<string, number | null>; relative_margin?: number }
  definition?: string
}

export interface CustomerEvaluationRow {
  model_family: string
  evaluation_type: string
  is_baseline: boolean
  is_selected: boolean
  horizon_days: number | null
  n_obs: number
  mae: number | null
  bias: number | null
  wape: number | null
  rmse: number | null
  coverage_80: number | null
  metrics: Record<string, unknown> | null
  extra: Record<string, unknown> | null
}

export type CustomerTier = 'full' | 'lean' | 'reference_only' | 'abstained'

export interface CustomerBlock {
  cutoff_from: ISODate
  cutoff_to: ISODate
  label_window_from: ISODate
  label_window_to: ISODate
  n_cutoffs: number
}

export interface CustomerManifestHorizon {
  tier: CustomerTier
  status: string
  reason: string | null
  horizon_days: number
  blocks?: Partial<Record<'train' | 'calibration' | 'selection' | 'holdout', CustomerBlock>> & { labels_mature_through?: ISODate; gap_days_between_blocks?: number; cutoffs_discarded_in_gaps?: number }
  block_rows?: Record<string, number>
  block_positives?: Record<string, number>
  overlapping_origins?: boolean
  overlap_note?: string | null
  labels_mature_through?: ISODate
  holdout_used_for?: string[]
  holdout_not_used_for?: string[]
  promotion?: Record<string, { passed: boolean; challenger: string | null; rule_version: string; checks: Record<string, boolean | number> }>
}

export interface CustomerManifest {
  protocol_version: string
  labels_mature_through?: ISODate
  final_holdout_policy?: string
  overlapping_origins?: string
  separation?: string
  promotion_rule_version?: string
  horizons: Record<string, CustomerManifestHorizon>
  coherence?: Record<string, unknown>
}

export interface CustomerEvaluation {
  run_id?: number
  as_of?: ISODate
  manifest: CustomerManifest | null
  /** Clave de nivel (`h30:probability`, `h30:ranking`, `inactivity`...) → filas. */
  metrics: Record<string, CustomerEvaluationRow[]>
  models?: Record<string, Record<string, unknown>> | null
  summary?: Record<string, unknown> | null
  notes?: string[]
}

export type ReconciliationStatus = 'ok' | 'inconsistent' | 'aggregate_unavailable' | 'aggregate_incomplete'

export interface CustomerReconciliationRow {
  product: { id: number; sku: string | null; name: string | null; unit: string | null }
  horizon_days: number
  status: ReconciliationStatus
  severity: 'minor' | 'major' | null
  aggregate_quantity: number | null
  aggregate_days: number | null
  buyers_total_quantity: number | null
  buyers_committed_quantity: number | null
  buyers_incremental_quantity: number | null
  buyers_expected_quantity: number | null
  n_buyers: number
  committed_outside_model: number | null
  residual_quantity: number | null
  residual_share: number | null
  excess_quantity: number | null
  excess_ratio: number | null
}

export interface CustomerReconciliationPage {
  data: CustomerReconciliationRow[]
  meta: PaginationMeta
  summary: { horizon_days?: number; status_counts?: Partial<Record<ReconciliationStatus, number>> }
  policy?: string
  envelope: ResultEnvelope
}

// ─── Core de Datos (P3) ─────────────────────────────────────────────────────

export type SqlEntity =
  | 'sales' | 'products' | 'inventory' | 'commitments' | 'branches' | 'suppliers' | 'lead_times'
  | 'promotions' | 'calendar_events' | 'unit_conversions' | 'warehouses'

export interface ConnectionTestResult {
  ok: boolean
  message: string
  details: {
    server_version?: string
    warnings?: string[]
    verification?: string
    read_only_enforcement?: string
    driver_available?: boolean
    [key: string]: unknown
  }
}

export interface SourceMapping {
  id: number
  data_source_id: number
  entity: SqlEntity
  version: number
  query: string
  field_map: Record<string, string | { const: string | number }>
  options: Record<string, unknown>
  is_active: boolean
  approved_at: ISODateTime | null
  approved_by: { id: number; name: string } | null
  created_at: ISODateTime
}

export interface SourceMappingPayload {
  entity: SqlEntity
  query: string
  field_map: Record<string, string | { const: string | number }>
  options?: Record<string, unknown>
}

export interface MappingPreview {
  headers: string[]
  rows: (string | number | null)[][]
  rows_returned: number
  note: string
}

export type SyncStatus = 'queued' | 'running' | 'completed' | 'needs_review' | 'failed'

export interface SourceSync {
  id: number
  data_source_id: number
  entity: SqlEntity
  mapping_version: number | null
  mode: 'full' | 'incremental'
  status: SyncStatus
  triggered_by?: string
  since: ISODateTime | null
  watermark: ISODateTime | null
  rows_extracted: number | null
  pages: number | null
  import_job_id: number | null
  stats: Record<string, unknown>
  error_message: string | null
  started_at: ISODateTime | null
  finished_at: ISODateTime | null
  created_at: ISODateTime
}

export type CoverageEntity = 'sales' | 'inventory' | 'purchases' | 'costs' | 'promotions' | 'customers' | 'commitments' | 'calendar' | 'externals'

export interface CoverageRow {
  id: number
  entity: CoverageEntity
  data_source_id: number | null
  branch_id: number | null
  complete_from: ISODate | null
  complete_through: ISODate | null
  basis: 'declared' | 'inferred'
  last_ingested_at: ISODateTime | null
  notes: string | null
}

export interface EffectiveCoverage {
  entity: CoverageEntity
  complete_from: ISODate | null
  complete_through: ISODate | null
  rule: 'declared_by_user' | 'min_over_sources' | 'none'
  basis: 'declared' | 'inferred' | null
  sources: number[]
}

export interface CoverageResponse {
  data: CoverageRow[]
  effective: Record<string, EffectiveCoverage>
}

export interface CoverageDeclaration {
  entity: CoverageEntity
  complete_from?: ISODate | null
  complete_through: ISODate
  data_source_id?: number | null
  branch_id?: number | null
  notes?: string | null
}

export type QualitySectionStatus = 'ok' | 'warning' | 'no_data'
export type TemporalCoverageStatus = 'undeclared' | 'declared' | 'inferred' | 'stale' | 'data_beyond_coverage' | 'no_data'

export interface ExtendedQualityReport {
  generated_at: ISODateTime
  as_of_local_date: ISODate
  overall_status: 'ok' | 'warning'
  notes: string[]
  sections: {
    temporal_coverage: {
      status: QualitySectionStatus
      entities: Record<string, {
        observed_from: ISODate | null
        observed_to: ISODate | null
        days_with_records: number
        coverage: EffectiveCoverage
        status: TemporalCoverageStatus
        message: string | null
      }>
    }
    sales_days: {
      status: QualitySectionStatus
      message: string
      range?: { from: ISODate; to: ISODate; days: number }
      days_without_sales?: number
      classification?: 'observed_zero_days' | 'absent_days_coverage_unknown'
      observed_zero_days?: number
      absent_days?: number
      days_after_coverage?: number | null
      longest_gaps?: { from: ISODate; to: ISODate; days: number }[]
    }
    customer_identity: {
      status: QualitySectionStatus
      message: string
      documents_total: number
      documents_without_customer: number
      documents_with_generic_customer: number
      share_without_stable_identifier: number | null
      customers_total: number
      generic_customers: number
      customers_without_tax_id: number
    }
    product_code_changes: {
      status: QualitySectionStatus
      message: string
      groups_detected: number
      groups: { name: string; skus: string[]; pattern: string }[]
    }
    price_history: {
      status: QualitySectionStatus
      message: string
      products_with_sales: number
      products_with_price_variation: number
      products_with_variation_over_20pct: number
      price_history_rows: number
      price_history_rows_without_known_at: number
      top_variation: { product_id: number; sku: string | null; distinct_prices: number; min_price: number; max_price: number; spread_pct: number }[]
    }
    units: {
      status: QualitySectionStatus
      message: string
      incompatible_without_conversion: number
      commitment_lines_converted: number
      declared_conversions: number
      incompatible_units: { source: string; sku: string | null; unit: string; product_unit: string; rows: number; has_declared_conversion: boolean }[]
      categories_with_mixed_units: { category: string; units: string[] }[]
    }
  }
}

export type CalendarScope = 'country' | 'region' | 'tenant' | 'branch' | 'category' | 'product' | 'sector'
export type CalendarCategory =
  | 'holiday' | 'local_holiday' | 'commercial_season' | 'campaign' | 'promotion' | 'sector_event' | 'agricultural_season'
  | 'international_peak' | 'exceptional'
export type CalendarStatus = 'planned' | 'confirmed' | 'cancelled'

export interface CalendarEvent {
  id: number
  is_public: boolean
  scope: CalendarScope
  scope_ref: string | null
  code: string
  name: string
  category: CalendarCategory
  starts_on: ISODate
  ends_on: ISODate
  status: CalendarStatus
  known_at: ISODateTime | null
  known_at_unknown: boolean
  source: string | null
  revision: number
}

export interface CalendarEventPayload {
  scope: CalendarScope
  scope_ref?: string | null
  code: string
  name: string
  category: CalendarCategory
  starts_on: ISODate
  ends_on?: ISODate | null
  status?: CalendarStatus
  known_at?: string | null
}

export type CommitmentType = 'quote' | 'order' | 'contract'
export type CommitmentStatus = 'open' | 'confirmed' | 'partially_fulfilled' | 'fulfilled' | 'cancelled' | 'expired'

export interface CommitmentItem {
  line_number: number | null
  product_id: number
  quantity: number
  quantity_fulfilled: number
  pending: number
  unit: string | null
  unit_price: number | null
  delivery_date: ISODate | null
  source_quantity?: number | null
  source_unit?: string | null
  conversion_factor?: number | null
}

export interface Commitment {
  id: number
  commitment_type: CommitmentType
  external_id: string
  status: CommitmentStatus
  issued_at: ISODateTime | null
  known_at: ISODateTime | null
  known_at_unknown: boolean
  valid_from: ISODate | null
  valid_to: ISODate | null
  expected_delivery_date: ISODate | null
  cancelled_at: ISODateTime | null
  cancellation_date_unknown: boolean
  currency: string | null
  customer_id: number | null
  branch_id: number | null
  items: CommitmentItem[]
  pending_total: number
  revisions?: { revision: number; status: string; known_at: ISODateTime | null; cancelled_at: ISODateTime | null; recorded_at: ISODateTime | null }[]
}

export interface Warehouse {
  id: number
  code: string
  name: string
  branch: { id: number; code: string; name: string } | null
  storage_capacity: number | null
  capacity_unit: string | null
  is_active: boolean
}

export interface WarehousePayload {
  code: string
  name: string
  branch_code: string
  storage_capacity?: number | null
  capacity_unit?: string | null
  is_active?: boolean
}

export interface UnitConversion {
  id: number
  product: { id: number; sku: string; name: string } | null
  from_unit: string
  to_unit: string
  factor: number
  valid_from: ISODate | null
  source: string | null
}

export interface UnitConversionPayload {
  product_sku?: string | null
  from_unit: string
  to_unit: string
  factor: number
  valid_from?: ISODate | null
  source?: string | null
}

export type ConfigCore = 'demand' | 'customer' | 'supply' | 'cost' | 'scenario'
export type ConfigSource = 'core' | 'tenant' | `profile:${string}`

export interface EffectiveConfigKey {
  key: string
  value: unknown
  source: ConfigSource | string
}

export interface EffectiveConfig {
  core: ConfigCore
  config_version: string | null
  profile: { code: string; version: string; description?: string; synthetic?: boolean } | null
  precedence: string[]
  keys: EffectiveConfigKey[]
  tenant_overrides: Record<string, unknown>
  note?: string
}

/** El motor devuelve los errores de configuración como lista; Laravel como mapa. */
export interface ConfigValidationIssue {
  key: string
  code?: string
  message: string
}

export interface ExtensionProvider {
  kind: string
  name: string
  version: string
  inputs: string[]
  outputs: string[]
  units: Record<string, string>
  temporal_availability: string
}

export interface PlatformExtensions {
  tenant_id?: number
  sector: string | null
  profile: { code: string; version: string; description: string; synthetic: boolean } | null
  providers: ExtensionProvider[]
  note: string | null
}

// ─── Decisiones comunes (P4) ────────────────────────────────────────────────

export type SolverStatus = 'optimal' | 'feasible_with_gap' | 'time_limit' | 'infeasible' | 'heuristic_fallback' | 'not_solved'

export interface SupplyRun {
  id: number
  core: 'supply' | 'cost' | 'scenario' | string
  operation: 'plan' | 'simulate' | 'forecast' | 'evaluate' | string
  version: number
  run_type: string
  status: 'queued' | 'running' | 'completed' | 'failed'
  result_type: string | null
  quality_status: QualityStatus | null
  uncertainty_source: UncertaintySource | null
  as_of: ISODate | null
  horizon_start: ISODate | null
  horizon_end: ISODate | null
  options: Record<string, unknown> | null
  config_version: string | null
  data_snapshot_id: number | null
  summary: Record<string, unknown> | null
  assumptions: (string | { message?: string })[]
  limitations: (string | { message?: string })[]
  is_current: boolean
  error_message: string | null
  started_at: ISODateTime | null
  completed_at: ISODateTime | null
  created_at: ISODateTime
  training_job: TrainingJob | null
}

export interface PlanRequest {
  objective?: 'min_cost' | 'max_margin'
  coverage_mode?: 'soft' | 'hard'
  forecast_run_id?: number | null
  horizon_days?: number
  period_days?: 1 | 7 | 14 | 30
  undated_receipt_policy?: 'lead_time_end' | 'observed_lead_time' | 'exclude'
  overdue_receipt_policy?: 'lead_time_end' | 'observed_lead_time' | 'exclude'
  config?: {
    holding_cost_rate_annual?: number
    shortage_penalty_multiplier?: number
    safety_stock_weight?: number
    solver_time_limit_seconds?: number
    solver_mip_gap?: number
    use_implicit_offers?: boolean
    diagnose_unmet?: boolean
  }
}

export interface BindingConstraint {
  family: string
  ref?: string | number | null
  period?: string | number | null
  used: number
  limit: number
}

export interface PlanLine {
  id?: number
  line_type: 'purchase' | 'production'
  branch_id: number | null
  branch?: { id: number; code: string; name: string } | null
  product_id: number
  product?: { id: number; sku: string; name: string } | null
  supplier_id: number | null
  supplier?: { id: number; code: string; name: string } | null
  offer_id: number | null
  recipe_id?: number | null
  quantity: number
  unit: string | null
  offer_quantity: number | null
  offer_unit: string | null
  /** Fecha sugerida de emisión del pedido. */
  order_date: ISODate | null
  expected_receipt_date: ISODate | null
  unit_cost: number | null
  line_cost: number | null
  currency: string | null
  actionable_now: boolean
  binding_constraints: BindingConstraint[] | null
  justification: string | null
  assumptions: Record<string, unknown> | null
}

export type ShortfallReason = 'lead_time' | 'budget' | 'supplier_capacity' | 'storage_capacity' | 'resource_capacity' | 'economic' | 'no_offer' | 'mixed'

export interface PlanShortfall {
  id?: number
  branch_id: number | null
  branch?: { id: number; code: string; name: string } | null
  product_id: number
  product?: { id: number; sku: string; name: string } | null
  period_start: ISODate
  period_end: ISODate
  demand: number
  unmet: number
  reason: ShortfallReason | string | null
}

export interface SupplyPlanSummary {
  id: number
  objective: 'min_cost' | 'max_margin'
  coverage_mode: 'soft' | 'hard'
  solver_status: SolverStatus
  mip_gap: number | null
  objective_value: number | null
  solve_seconds: number | null
  currency: string | null
  total_purchase_cost: number | null
  total_holding_cost: number | null
  total_shortage_penalty: number | null
  unmet_units_total: number | null
  demand_units_total: number | null
  forecast_run_id: number | null
  is_optimal: boolean
  note: string
  /** Fecha de corte: el plan usa datos hasta ese día (null si se desconoce). */
  as_of: ISODate | null
  /** Hoy en la zona horaria de la empresa, según el servidor. */
  today: ISODate
  timezone: string
  /** `today − as_of` en días: 0 = el plan se calculó con datos de hoy; > 0 = datos desactualizados; null = sin fecha de corte. */
  as_of_age_days: number | null
}

/** Resumen de una lista que el detalle del plan solo trae como vista previa (el listado completo se pagina en `/lines` y `/shortfalls`). */
export interface PlanListSummary {
  total: number
  shown: number
  matched: number
  truncated: boolean
  /** Solo en `lines_summary`: líneas cuya emisión sugerida es la fecha de corte. */
  actionable_now?: number
  /** Solo en `shortfalls_summary`. */
  unmet_units?: number
  demand_units?: number
}

export interface PlanBranch {
  id: number
  code: string
  name: string
}

/** Filtros de `GET /supply/plans/{id}/lines`. `sort`: `order_date`, `expected_receipt_date`, `product`, `branch`, `supplier`, `quantity`, `unit_cost`, `line_cost` (prefijo `-` = descendente). */
export interface PlanLinesQuery {
  page?: number
  per_page?: number
  branch_id?: number
  product_id?: number
  supplier_id?: number
  search?: string
  actionable_now?: boolean
  sort?: string
}

/** Filtros de `GET /supply/plans/{id}/shortfalls`. `sort`: `period_start`, `product`, `branch`, `demand`, `unmet`, `reason` (prefijo `-` = descendente). */
export interface PlanShortfallsQuery {
  page?: number
  per_page?: number
  branch_id?: number
  product_id?: number
  search?: string
  reason?: string
  sort?: string
}

export interface PlanInfeasibility {
  message: string
  causes: { family: string; label: string; units_recovered: number; unmet_with_constraint: number; unmet_if_relaxed: number; required?: Record<string, unknown>[] }[]
  structural?: { family: string; label: string; unmet_even_without_constraints: number } | null
  alternatives: { type: string; description: string; unmet_units: number; total_cost: number; n_purchase_lines: number }[]
}

export interface PlanOfferReport {
  offer_id: number | null
  supplier_id: number | null
  product_id: number
  status: 'comparable' | 'excluded' | string
  implicit?: boolean
  reasons: string[]
  messages: string[]
  landed_unit_cost?: number | null
  lead_days?: number | null
}

export interface PlanDetail {
  run: SupplyRun
  plan: SupplyPlanSummary | null
  /** Vista previa (primeras 20); el listado completo y paginado está en `supplyApi.planLines`. */
  lines: PlanLine[]
  lines_summary: PlanListSummary
  /** Vista previa (primeras 20); el listado completo y paginado está en `supplyApi.planShortfalls`. */
  shortfalls: PlanShortfall[]
  shortfalls_summary: PlanListSummary
  /** Sucursales con líneas o faltantes en el plan (filtro de las tablas). */
  branches: PlanBranch[]
  constraints: { family: string; ref?: string | number | null; period?: string | number | null; used: number; limit: number; binding: boolean }[]
  solver: { status: SolverStatus; detail?: string; notes?: string[]; components?: number; mip_gap?: number | null; seconds?: number } | null
  diagnosis: Record<string, unknown> | null
  infeasibility: PlanInfeasibility | null
  alternative_plan: { description: string; summary: Record<string, unknown>; lines: PlanLine[]; shortfalls: PlanShortfall[] } | null
  offers: PlanOfferReport[]
  comparison_coverage: {
    offers_received: number
    offers_comparable: number
    offers_excluded: number
    comparable_share: number | null
    products_without_comparable_offer?: number[]
    products_single_source?: number[]
    note?: string
  } | null
  offer_diagnostics: Record<string, unknown>[]
  excluded_nodes: { product_id?: number; reason?: string; [key: string]: unknown }[]
  production: { status: 'not_configured' | string; [key: string]: unknown }
  periods: { period: number; start: ISODate; end: ISODate }[]
}

export interface Comparability {
  comparable: boolean
  issues: ('no_unit_conversion' | 'no_fx_rate')[]
  unit_conversion: { declared: boolean; from: string | null; to: string | null; factor: number | null }
  exchange_rate: { declared: boolean; from: string; to: string; rate: number | null; valid_on: ISODate | null }
}

export interface SupplierOffer {
  id: number
  supplier_id: number
  supplier: { id: number; code: string; name: string } | null
  product_id: number
  product: { id: number; sku: string; name: string; unit: string } | null
  branch_id: number | null
  unit_cost: number
  currency: string
  unit: string
  min_order_qty: number | null
  order_multiple: number | null
  lead_time_days: number | null
  lead_time_std_days: number | null
  capacity_per_period: number | null
  capacity_period_days: number
  fixed_order_cost: number
  acquisition_cost_pct: number
  acquisition_cost_per_unit: number
  valid_from: ISODate | null
  valid_to: ISODate | null
  known_at: ISODateTime | null
  source: string
  is_active: boolean
  notes: string | null
  comparability: Comparability
}

export interface ExchangeRate {
  id: number
  from_currency: string
  to_currency: string
  rate: number
  valid_on: ISODate
  source: string
  known_at: ISODateTime | null
}

export interface LocationCapacity {
  id: number
  branch_id: number
  branch: { id: number; code: string; name: string } | null
  warehouse_id: number | null
  capacity: number
  capacity_unit: string
  valid_from: ISODate | null
  valid_to: ISODate | null
  notes: string | null
}

export interface PurchaseBudget {
  id: number
  branch_id: number | null
  branch: { id: number; code: string; name: string } | null
  period_start: ISODate
  period_end: ISODate
  amount: number
  currency: string
  notes: string | null
}

export interface ProductionResource {
  id: number
  code: string
  name: string
  branch_id: number | null
  capacity_per_period: number
  capacity_unit: string
  period_days: number
  is_active: boolean
}

export interface ProductionRecipe {
  id: number
  code: string
  name: string
  output_product_id: number
  output_product: { id: number; sku: string; name: string } | null
  branch_id: number | null
  output_quantity: number
  yield_rate: number
  useful_output_per_batch: number
  production_days: number
  resource_id: number | null
  resource_usage_per_batch: number
  cost_per_batch: number | null
  max_batches_per_period: number | null
  is_active: boolean
  lines: { input_product_id: number; quantity_per_batch: number; input_product: { id: number; sku: string; name: string } | null }[]
}

export interface CostForecastMethodMetrics {
  selection?: { mae?: number | null; n?: number; [key: string]: unknown } | null
  holdout?: { mae?: number | null; n?: number; [key: string]: unknown } | null
}

export interface CostForecast {
  id: number
  product_id: number
  product: { id: number; sku: string; name: string } | null
  supplier_id: number | null
  supplier: { id: number; code: string; name: string } | null
  cost_source: string | null
  currency: string | null
  unit: string | null
  /** Método servido; si no mejoró a la referencia, es la referencia. */
  method: string | null
  reference_method: string | null
  quality_status: QualityStatus | null
  n_obs: number | null
  last_observed_on: ISODate | null
  last_observed_cost: number | null
  points: { step: number; target_date: ISODate; yhat: number | null; reference: number | null; lo: number | null; hi: number | null }[] | null
  metrics: Record<string, CostForecastMethodMetrics> | null
  availability: Record<string, unknown> | null
  limitations: (string | { message?: string })[] | null
}

export interface CostForecastPage {
  data: CostForecast[]
  meta: PaginationMeta | { total: number }
  envelope: ResultEnvelope
}

export type InputClassification = 'observed' | 'forecasted' | 'assumed'

export interface ScenarioStrategyInput {
  key: string
  name?: string | null
  price_change_pct?: number | number[] | null
  discount_pct?: number | number[] | null
  cost_change_pct?: number | number[] | null
  demand_change_pct?: number | number[] | null
  use_cost_forecast?: boolean
  plan_run_id?: number | null
  supply_fill_rate?: number
  lead_time_delta_days?: number
}

export interface ScenarioEvaluationRequest {
  name?: string | null
  horizon_days?: number
  strategies: ScenarioStrategyInput[]
  filters?: { branch_id?: number | null; category_id?: number | null; product_id?: number | null }
}

export interface ScenarioStrategyResult {
  key: string
  name: string
  metrics: {
    demand_units: number | null
    attainable_sales_units: number | null
    unmet_units: number | null
    fill_rate: number | null
    revenue: number
    cogs: number | null
    gross_margin: number | null
    gross_margin_pct: number | null
    revenue_without_known_cost: number
    ending_inventory_units: number | null
    ending_inventory_value: number
    plan_purchase_outlay: number | null
    units_note: string | null
  }
  delta_vs_first?: Record<string, number | null>
  inputs: { input: string; classification: InputClassification; source: string }[]
  products_without_cost?: number[]
  products_without_price?: number[]
  coherence?: Record<string, unknown>
}

export interface ScenarioEvaluationResult {
  run_id: number
  status: string
  name: string | null
  horizon_days: number | null
  request: Record<string, unknown> | null
  results: {
    common_basis: { as_of: ISODate; horizon_days: number; period_days: number; currency: string; series: number; note: string }
    strategies: ScenarioStrategyResult[]
    ranking_by_gross_margin: string[]
    assumed_inputs: string[]
    classification_summary: Record<InputClassification, string[]>
    disclaimer: string
  } | null
}

export interface CausalRequirement {
  id: string
  label: string
  status: 'met' | 'not_met' | 'declared_not_verifiable'
  evidence: Record<string, unknown>
  how_to_fix: string | null
}

export interface CausalCheck {
  contract_version: string
  verdict: 'insufficient_evidence'
  effect: null
  estimator_enabled: boolean
  eligible_for_estimator_evaluation: boolean
  reason_codes: string[]
  requirements: CausalRequirement[]
  message: string
  conditional_simulation: { available: boolean; endpoint: string; note: string }
  predictive_vs_causal: string
}

export interface CausalCheckRequest {
  product_id?: number | null
  branch_id?: number | null
  treatment?: string | null
  outcome?: string | null
  unit_of_analysis?: string | null
  estimand?: string | null
  horizon_days?: number | null
  population?: string | null
  price_change_pct?: number | null
  confounders_controlled?: string[]
  unobserved_confounders_acknowledged?: string[]
  identification_assumptions_acknowledged?: string[]
}

export interface SimulationPolicyResult {
  policy: string
  demand_units: number
  sold_units: number
  unmet_units: number
  fill_rate: number
  stockout_day_share: number
  avg_inventory_value: number
  days_of_inventory: number
  holding_cost: number
  lost_margin: number
  operating_cost_proxy: number
  avg_capital_committed: number
  purchase_outlay: number
  solver?: { seconds: number; statuses: Record<string, number> }
  [key: string]: unknown
}

export interface SimulationResults {
  window?: { start: ISODate; end: ISODate; days: number; review_days: number; series: number }
  demand_basis?: string
  policies?: Record<string, SimulationPolicyResult>
  delta_vs_current?: Record<string, Record<string, number>>
  caveats?: string[]
  parameters?: Record<string, unknown>
  budget_note?: string
}

export interface SimulationDetail {
  run: SupplyRun
  results: SimulationResults | null
}

export interface SupplyResponse<T> {
  data: T
  envelope: ResultEnvelope
}

export interface Supplier {
  id: number
  code: string
  name: string
  is_active?: boolean
  default_lead_time_days?: number | null
}

// ─── Asistente conversacional opcional (P5b) ────────────────────────────────

export type AssistantDisabledReason =
  | 'missing_api_key' | 'disabled_by_config' | 'provider_disabled' | 'unknown_provider'
  | 'disabled_for_plan' | 'invalid_base_url' | 'missing_model'

export interface AssistantStatus {
  enabled: boolean
  reason: AssistantDisabledReason | string | null
  provider?: string | null
  model?: string | null
  tools: { name: string; available_to_you: boolean }[]
  limits?: { max_message_chars: number; max_history_messages: number; rate_per_minute: number; daily_limit_per_tenant: number | null; max_tool_rounds: number }
  usage_today?: Record<string, unknown>
  notice: string
}

export type AssistantFigureStatus = 'ok' | 'no_data' | 'forbidden' | 'plan_limit' | 'invalid_arguments' | 'not_found' | 'tool_not_allowed' | 'error'

/** Resultado de una herramienta tal como lo devolvió: las CIFRAS salen de `data`, nunca del texto de la respuesta. */
export interface AssistantFigure {
  tool: string
  arguments: Record<string, unknown>
  status: AssistantFigureStatus
  data: unknown
  envelope: ResultEnvelope | null
}

export type AssistantReplyStatus = 'answered' | 'no_applicable_tool' | 'tool_denied' | 'no_answer' | 'ungrounded_rejected'

export interface AssistantReply {
  status: AssistantReplyStatus
  answer: string
  figures: AssistantFigure[]
  grounding: { checked: boolean; ungrounded_numbers: string[] }
  provider: { name: string; model: string | null }
  usage: Record<string, unknown>
  notice: string
}

export interface AssistantHistoryItem {
  role: 'user' | 'assistant'
  content: string
}
