/**
 * Tipos de la API pública de Predictily (/api/v1).
 * Fuente: docs/06-contratos-integracion.md §3. Mantener sincronizado con el contrato.
 */

import type { DemandManifest, HorizonSupport, P50Decision, QualityStatus, ResultEnvelope } from './types.v2'

// ─── Genéricos ──────────────────────────────────────────────────────────────

export type ISODate = string // YYYY-MM-DD
export type ISODateTime = string // ISO-8601 con zona

export interface Resource<T> {
  data: T
}

export interface PaginationMeta {
  current_page: number
  per_page: number
  total: number
  last_page: number
  from?: number | null
  to?: number | null
}

export interface Paginated<T> {
  data: T[]
  meta: PaginationMeta
  links?: Record<string, string | null>
}

export interface ApiErrorBody {
  message: string
  code?: string
  errors?: Record<string, string[]>
  // Límite de plan (402)
  limit?: string
  current?: number
  max?: number
  required_ability?: string
  details?: Record<string, unknown>
}

export type Granularity = 'day' | 'week' | 'month'
export type Horizon = 7 | 14 | 30 | 60 | 90
/** Horizontes del Customer Core (compra): 7, 30 y 90 días. */
export type CustomerHorizon = 7 | 30 | 90

// ─── Autenticación y cuenta ─────────────────────────────────────────────────

export type TenantRole = 'owner' | 'admin' | 'analyst' | 'viewer'

export interface User {
  id: number
  name: string
  email: string
  locale: string
  is_superadmin: boolean
}

export interface TenantMembership {
  uuid: string
  name: string
  sector: string
  /** pending_deletion: solo se puede cancelar la solicitud o exportar (la API responde 423 al resto). */
  status?: 'active' | 'suspended' | 'pending_deletion'
  role: TenantRole
  onboarding_completed_at: ISODateTime | null
}

export interface MeResponse {
  user: User
  tenants: TenantMembership[]
}

export interface RegisterPayload {
  name: string
  email: string
  password: string
  password_confirmation: string
  accept_terms: boolean
}

export interface LoginPayload {
  email: string
  password: string
  remember?: boolean
}

// ─── Empresa ────────────────────────────────────────────────────────────────

export type Sector =
  | 'ferreteria'
  | 'distribuidora'
  | 'repuestos_automotrices'
  | 'repuestos_agricolas'
  | 'retail_multisucursal'
  | 'importadora'
  | 'consumo_recurrente'
  | 'otro'

export type ForecastSchedule = 'daily' | 'weekly' | 'manual'

export interface TenantSettings {
  default_horizon_days: Horizon
  service_level: number
  review_period_days: number
  default_lead_time_days: number
  forecast_schedule: ForecastSchedule
  stockout_risk_threshold: number
  [key: string]: unknown
}

export interface PlanLimits {
  max_branches?: number | null
  max_active_products?: number | null
  max_import_rows_per_month?: number | null
  max_forecast_runs_per_day?: number | null
  max_users?: number | null
  horizons?: number[]
  features?: {
    scenarios?: boolean
    api_access?: boolean
    external_indicators?: boolean
    custom_models?: boolean
  }
}

export interface PlanSummary {
  code: string
  name: string
  limits: PlanLimits
}

export interface OnboardingStatus {
  has_branches: boolean
  has_products: boolean
  has_sales: boolean
  has_inventory: boolean
  has_forecast: boolean
  completed_at: ISODateTime | null
}

export interface Tenant {
  uuid: string
  name: string
  legal_name: string | null
  tax_id: string | null
  sector: Sector
  country: string
  currency: string
  timezone: string
  locale: string
  status: 'active' | 'suspended' | 'pending_deletion'
  settings: TenantSettings
  allow_shared_training: boolean
  shared_training_consented_at: ISODateTime | null
  data_retention_months: number
  onboarding_completed_at: ISODateTime | null
  created_at: ISODateTime
  // Presentes en GET /tenant
  plan?: PlanSummary | null
  role?: TenantRole
  onboarding?: OnboardingStatus
}

export interface CreateTenantPayload {
  name: string
  legal_name?: string | null
  tax_id?: string | null
  sector: Sector
  timezone?: string
  currency?: string
}

export interface UpdateTenantPayload {
  name?: string
  legal_name?: string | null
  tax_id?: string | null
  sector?: Sector
  timezone?: string
  settings?: Partial<TenantSettings>
}

export interface TenantUsage {
  period: string
  metrics: {
    import_rows: number
    forecast_runs: number
    active_products: number
    branches: number
    users: number
  }
  limits: PlanLimits
}

export interface TenantUser {
  id: number
  name: string
  email: string
  role: TenantRole
  joined_at: ISODateTime | null
  temporary_password?: string | null
}

export interface TenantExport {
  export_id: string | number
  status?: 'queued' | 'processing' | 'completed' | 'failed' | 'expired'
  download_url?: string | null
  error_message?: string | null
  expires_at?: ISODateTime | null
}

// ─── Catálogos ──────────────────────────────────────────────────────────────

export interface Branch {
  id: number
  code: string
  name: string
  province: string | null
  canton: string | null
  commercial_zone: string | null
  address: string | null
  is_active: boolean
}

export type BranchPayload = Omit<Branch, 'id' | 'is_active'> & { is_active?: boolean }

export interface ProductCategory {
  id: number
  name: string
  code: string | null
  parent_id: number | null
  products_count?: number
}

export interface ProductStats {
  units_90d: number
  revenue_90d: number
  last_sale_date: ISODate | null
}

export interface Product {
  id: number
  sku: string
  name: string
  unit: string
  brand: string | null
  category: { id: number; name: string } | null
  current_price: number | null
  current_cost: number | null
  is_active: boolean
  stats?: ProductStats
}

export interface LeadTime {
  id: number
  product_id: number | null
  supplier_id: number | null
  branch_id: number | null
  lead_time_days: number
  lead_time_std_days: number | null
  source: string
}

export interface Promotion {
  id: number
  name: string
  promo_type: 'percentage' | 'fixed_amount' | 'bundle' | 'other'
  discount_pct: number | null
  starts_on: ISODate
  ends_on: ISODate
  branch_id: number | null
  product_id: number | null
  category_id: number | null
  /** Cuándo el negocio conocía la promoción (ISO 8601). El motor solo la usa a futuro desde esa fecha. */
  known_at: string
}

/** Datos para crear una promoción; `known_at` es opcional (por defecto, el momento del alta). */
export type PromotionInput = Omit<Promotion, 'id' | 'known_at'> & { known_at?: string | null }

// ─── Ventas ────────────────────────────────────────────────────────────────

export interface SaleListItem {
  id: number
  document_type: 'invoice' | 'sales_note' | 'credit_note' | 'other'
  document_number: string
  status: 'completed' | 'voided' | 'pending'
  issued_at: ISODateTime
  sale_date: ISODate
  branch: { id: number; code: string; name: string }
  total: number
  items_count: number
}

export interface SalesSummaryPoint {
  period: ISODate
  units: number
  revenue: number
  documents: number
}

export interface SalesSummary {
  series: SalesSummaryPoint[]
  totals: { units: number; revenue: number; documents: number; returns_units: number }
}

// ─── Importaciones ─────────────────────────────────────────────────────────

export type ImportEntityType = 'sales' | 'products' | 'inventory' | 'lead_times' | 'branches' | 'suppliers' | 'promotions'

export type ImportStatus =
  | 'uploaded'
  | 'awaiting_mapping'
  | 'validating'
  | 'validated'
  | 'importing'
  | 'completed'
  | 'failed'
  | 'rejected'

export interface ImportOptions {
  date_format?: 'auto' | 'Y-m-d' | 'd/m/Y' | 'm/d/Y' | 'd-m-Y'
  decimal_separator?: 'auto' | '.' | ','
  thousands_separator?: string | null
  csv_delimiter?: 'auto' | ',' | ';' | '\t'
  default_branch_code?: string | null
  create_missing_products?: boolean
  create_missing_branches?: boolean
  create_missing_categories?: boolean
  skip_invalid_rows?: boolean
}

export type ColumnMapping = Record<string, string | null>

export interface ImportStats {
  rows_total?: number
  rows_valid?: number
  rows_error?: number
  rows_warning?: number
  duplicates_in_file?: number
  documents_total?: number
  documents_new?: number
  documents_updated?: number
  documents_unchanged?: number
  voided_documents?: number
  return_lines?: number
  date_from?: ISODate | null
  date_to?: ISODate | null
  missing_days?: number
  products_new?: number
  branches_new?: number
  [key: string]: number | string | null | undefined
}

export type IssueSeverity = 'error' | 'warning' | 'info'

export interface QualityIssue {
  code: string
  severity: IssueSeverity
  message: string
  count?: number
  sample?: unknown
}

export interface ImportJob {
  id: number
  entity_type: ImportEntityType
  original_filename: string
  file_format: 'csv' | 'xlsx'
  file_size: number
  status: ImportStatus
  progress: number
  detected_columns: string[] | null
  suggested_mapping: ColumnMapping | null
  column_mapping: ColumnMapping | null
  options: ImportOptions | null
  preview: { headers: string[]; rows: (string | number | null)[][] } | null
  normalized_preview: Record<string, unknown>[] | null
  stats: ImportStats | null
  quality_report: { score: number | null; issues: QualityIssue[] } | null
  error_message: string | null
  created_at: ISODateTime
  validated_at: ISODateTime | null
  finished_at: ISODateTime | null
  user: { id: number; name: string } | null
}

export interface ImportErrorRow {
  row_number: number
  column: string | null
  error_code: string
  severity: 'error' | 'warning'
  message: string
  raw_value: string | null
}

export interface ImportField {
  key: string
  label: string
  required: boolean
  type: string
  description?: string | null
  synonyms?: string[]
}

// ─── Fuentes de datos ──────────────────────────────────────────────────────

export type DataSourceType = 'file_upload' | 'rest_api' | 'connector'

export interface DataSource {
  id: number
  type: DataSourceType
  connector_key: string | null
  connector?: { name: string; status: ConnectorStatus } | null
  name: string
  status: 'active' | 'inactive' | 'error'
  last_synced_at: ISODateTime | null
  last_error: string | null
  config: Record<string, unknown>
  /** Las credenciales nunca se devuelven: solo si existen. */
  has_credentials?: boolean
}

export interface DataSourcePayload {
  type: DataSourceType
  name: string
  connector_key?: string | null
  config?: Record<string, unknown>
  credentials?: Record<string, string>
}

export type ConnectorStatus = 'available' | 'requires_credentials' | 'implemented_untested' | 'planned'
/** Qué se comprobó de verdad: nunca se infiere del estado. */
export type ConnectorVerification = 'tested' | 'tested_with_test_database' | 'implemented_untested' | 'not_verified' | 'planned'

export interface Connector {
  key: string
  name: string
  description: string
  status: ConnectorStatus
  verification?: ConnectorVerification
  entity_types: string[]
  credential_keys?: string[]
  driver_available?: boolean
}

// ─── Pronósticos y trabajos ────────────────────────────────────────────────

export type JobStatus = 'queued' | 'running' | 'succeeded' | 'failed' | 'cancelled'

export interface TrainingJob {
  id: number
  job_type: string
  status: JobStatus
  stage: string | null
  progress: number
  attempts: number
  error_message: string | null
  queued_at: ISODateTime | null
  started_at: ISODateTime | null
  finished_at: ISODateTime | null
  duration_ms: number | null
  result: Record<string, unknown> | null
  forecast_run_id: number | null
}

export type RunStatus = 'queued' | 'running' | 'completed' | 'failed' | 'cancelled'

export interface ForecastRun {
  id: number
  version: number
  run_type: 'production' | 'historical'
  status: RunStatus
  triggered_by: string
  horizon_days: number
  cutoff_date: ISODate | null
  data_from: ISODate | null
  data_to: ISODate | null
  feature_set_version: string | null
  is_current: boolean
  selection: Record<string, unknown> | null
  summary: Record<string, unknown> | null
  error_message: string | null
  started_at: ISODateTime | null
  completed_at: ISODateTime | null
  created_at: ISODateTime
  training_job?: TrainingJob | null
  config_version?: string | null
  data_snapshot_id?: number | null
  effective_config?: Record<string, unknown> | null
  /** Solo en el detalle (GET /forecast-runs/{id}). */
  evaluation_manifest?: DemandManifest | null
  envelope?: ResultEnvelope | null
}

export interface CreateForecastRunPayload {
  horizon_days?: Horizon
  cutoff_date?: ISODate | null
}

export type NodeLevel = 'total' | 'branch' | 'category' | 'branch_category' | 'product' | 'series'

export interface HistoryPoint {
  date: ISODate
  units: number
  revenue: number
  stockout: boolean
  /** En niveles agregados: proporción de productos sin existencias ese período. */
  stockout_share?: number | null
}

export interface ForecastPoint {
  date: ISODate
  yhat: number
  lo_80: number | null
  hi_80: number | null
  lo_95: number | null
  hi_95: number | null
  revenue: number | null
  /** Mediana (P50): solo si el motor la evaluó y mejoró a la media; null en caso contrario. */
  p50?: number | null
  /** Respaldo del horizonte al que pertenece el punto (etapa 2). */
  quality_status?: QualityStatus | null
  /** Caso especial de la serie: producto nuevo, descontinuado, sucursal cerrada/abierta, evento extraordinario... */
  case_label?: string | null
  period_days?: number
}

export interface ForecastSeriesMeta {
  run_id: number | null
  run_version: number | null
  run_type?: 'production' | 'historical' | null
  has_forecast?: boolean
  interval_levels?: number[]
  notes?: string[]
  generated_at: ISODateTime | null
  cutoff_date: ISODate | null
  model_family: string | null
  models: Record<string, number>
  price_assumption: string | null
  intervals_available: boolean
  horizon_support?: HorizonSupport | null
}

export interface ForecastSeries {
  node: { key: string; level: NodeLevel; label: string }
  granularity: Granularity
  horizon?: number
  history: HistoryPoint[]
  forecast: ForecastPoint[]
  meta: ForecastSeriesMeta | null
  envelope?: ResultEnvelope | null
}

export interface ForecastSeriesQuery {
  run_id?: number
  branch_id?: number
  category_id?: number
  product_id?: number
  granularity?: Granularity
  horizon?: Horizon
  history_days?: number
}

export type Trend = 'up' | 'down' | 'flat'

export interface ProductForecastRow {
  product: { id: number; sku: string; name: string; category: { id: number; name: string } | null }
  history_units: number
  forecast_units: number
  change_pct: number | null
  /** Base histórica < 10 unidades: el cambio porcentual no indica una tendencia. */
  low_base?: boolean
  trend: Trend
  forecast_revenue: number | null
  lo_80: number | null
  hi_80: number | null
  model_family: string | null
  demand_class: string | null
  wape: number | null
  /** Caso especial de la serie (producto nuevo, descontinuado...); null = regular. */
  case_label?: string | null
}

/** Listados de pronóstico: la paginación estándar más el respaldo del horizonte pedido. */
export interface ForecastListPage<R> extends Paginated<R> {
  run?: { id: number; version: number; generated_at: ISODateTime | null; cutoff_date: ISODate | null } | null
  horizon?: number
  horizon_support?: HorizonSupport | null
  message?: string
}

export interface CategoryForecastRow {
  category: { id: number; name: string } | null
  history_units: number
  forecast_units: number
  change_pct: number | null
  trend: Trend
  forecast_revenue: number | null
  lo_80: number | null
  hi_80: number | null
  model_family?: string | null
  wape?: number | null
}

export interface ForecastTableQuery {
  run_id?: number
  horizon?: Horizon
  branch_id?: number
  category_id?: number
  search?: string
  sort?: string
  page?: number
  per_page?: number
}

// ─── Dashboard ─────────────────────────────────────────────────────────────

export interface DashboardKpis {
  forecast_units: number | null
  forecast_units_lo_80: number | null
  forecast_units_hi_80: number | null
  forecast_revenue: number | null
  previous_period_units: number | null
  previous_period_revenue: number | null
  change_pct: number | null
  stockout_risk_products: number | null
  purchase_investment: number | null
  backtest_wape: number | null
  baseline_wape: number | null
  realized_wape: number | null
}

export interface DashboardProductChange {
  product_id: number
  sku: string
  name: string
  change_pct: number | null
  forecast_units: number
}

export interface StockoutRisk {
  product_id: number
  branch_id: number
  product_name: string
  branch_name: string
  days_of_cover: number | null
  stockout_probability: number | null
  risk_level: RiskLevel
  /** null cuando el pronóstico no cubre plazo + revisión (assumptions.coverage_insufficient). */
  suggested_qty: number | null
}

export interface DataStatus {
  has_sales: boolean
  has_inventory: boolean
  has_forecast: boolean
  last_sale_date: ISODate | null
  readiness_score: number | null
  warnings: string[]
}

export interface DashboardSummary {
  horizon: number
  horizon_support?: HorizonSupport | null
  run: { id: number; version: number; generated_at: ISODateTime | null; cutoff_date: ISODate | null } | null
  kpis: DashboardKpis
  top_increasing: DashboardProductChange[]
  top_decreasing: DashboardProductChange[]
  stockout_risks: StockoutRisk[]
  alerts: Alert[]
  data_status: DataStatus
}

// ─── Precisión ─────────────────────────────────────────────────────────────

export interface MoneyMetrics {
  currency: string
  wape: number | null
  mae: number | null
  bias: number | null
  actual_total: number | null
  forecast_total: number | null
  n_obs: number
}

export interface MetricSample {
  n_obs: number
  n_series?: number
  n_windows?: number
  n_scored_intervals?: number
  exclusions?: Record<string, number>
}

export interface MetricSet {
  wape: number | null
  mae: number | null
  rmse?: number | null
  bias: number | null
  coverage_80?: number | null
  coverage_95?: number | null
  /** Etapa 2: errores escalados (menor que 1 = mejor que el naive estacional de entrenamiento). */
  mase?: number | null
  rmsse?: number | null
  /** Pinball de P10/P50/P90; P50 null si no se publicó. Solo con período final independiente. */
  pinball?: { p10: number | null; p50: number | null; p90: number | null } | null
  interval_width?: { '80': number | null; '95': number | null } | null
  /** Dinero aparte de las unidades. */
  money?: MoneyMetrics | null
  sample?: MetricSample | null
  n_obs?: number
}

export interface AccuracyByHorizon {
  horizon_days: number
  /** Tramos de evaluación diaria: h1-7, h8-14, h15-30, h31-60, h61-90 (ejecuciones nuevas). */
  level_key?: string
  wape: number | null
  baseline_wape: number | null
  n_obs?: number
}

/** Error del TOTAL de H días (el que importa al comprar), por horizonte. */
export interface AccuracyHorizonTotal {
  horizon_days: number
  wape: number | null
  baseline_wape: number | null
  bias: number | null
  mae: number | null
  money: MoneyMetrics | null
  n_obs: number
}

export interface AccuracyByGroup {
  key?: string
  level_key?: string
  label?: string
  wape: number | null
  baseline_wape?: number | null
  mae?: number | null
  bias?: number | null
  n_obs?: number
  model_family?: string | null
  branch_id?: number | null
  branch_name?: string | null
  [key: string]: unknown
}

export interface AccuracyModelRow {
  model_family: string
  wape: number | null
  is_baseline: boolean
  is_selected: boolean
}

export interface AccuracySummary {
  run: { id: number; version: number; generated_at?: ISODateTime | null; cutoff_date?: ISODate | null } | null
  backtest: {
    selected: MetricSet | null
    baseline: MetricSet | null
    n_windows?: number
    /** 'holdout' = período final reservado (principal); 'selection_windows' = sin holdout independiente. */
    evaluation_scope?: 'holdout' | 'selection_windows' | null
    cutoffs?: ISODate[]
    /** Métricas secundarias (optimistas) de las ventanas que eligieron el modelo. */
    selection_windows?: { selected: MetricSet | null; baseline: MetricSet | null; n_windows: number; cutoffs: ISODate[] } | null
    by_horizon: AccuracyByHorizon[]
    by_horizon_total?: AccuracyHorizonTotal[]
    by_demand_class: AccuracyByGroup[]
    by_branch: AccuracyByGroup[]
    models: AccuracyModelRow[]
  } | null
  realized: {
    wape: number | null
    mae: number | null
    bias: number | null
    n_obs: number
    by_run: { run_id: number; version: number; wape: number | null; n_obs: number }[]
  } | null
  explanation: string[]
  /** Etapa 2 (aditivo). */
  evaluation_manifest?: DemandManifest | null
  horizon_support?: Record<string, HorizonSupport> | null
  p50?: P50Decision | null
}

export interface ComparisonPoint {
  date: ISODate
  yhat: number
  lo_80: number | null
  hi_80: number | null
  actual: number | null
}

export interface AccuracyComparison {
  points: ComparisonPoint[]
  metrics: { wape: number | null; mae: number | null; bias: number | null; n_obs: number }
}

export interface ModelEvaluation {
  id: number
  model_family: string
  evaluation_type: 'backtest' | 'selection_windows' | 'realized'
  level: string
  level_key: string | null
  horizon_days: number | null
  is_baseline: boolean
  is_selected: boolean
  wape: number | null
  mae: number | null
  rmse: number | null
  bias: number | null
  coverage_80: number | null
  coverage_95: number | null
  n_obs: number
  n_windows: number
}

/** Estados del registro: nuevos (etapa 2) y legados (aún aceptados por el filtro de la API). */
export type ModelStatus = 'candidate' | 'validated' | 'production' | 'retired' | 'failed'
export type ModelRole = 'primary' | 'fallback'

export interface ModelRegistryEntry {
  id: number
  version: string
  model_family: string
  label?: string
  strategy_level: string
  scope?: string
  status: ModelStatus
  /** Estado de la etapa 1 equivalente (fallback, archived, rejected...). */
  legacy_status?: string | null
  core?: 'demand' | 'customer' | string | null
  role?: ModelRole | null
  demand_class: string | null
  hyperparameters?: Record<string, unknown> | null
  forecast_run_id?: number | null
  artifact_sha256?: string | null
  validated_at?: ISODateTime | null
  trained_at: ISODateTime | null
  promoted_at?: ISODateTime | null
  retired_at?: ISODateTime | null
  training_data_from: ISODate | null
  training_data_to: ISODate | null
  series_count: number
}

// ─── Inventario y alertas ──────────────────────────────────────────────────

export type RiskLevel = 'low' | 'medium' | 'high' | 'critical'
export type RecommendationType = 'reorder' | 'stockout_risk' | 'overstock' | 'no_action'
export type RecommendationStatus = 'open' | 'acknowledged' | 'dismissed' | 'ordered'

export interface Recommendation {
  id: number
  type: RecommendationType
  branch_id: number
  product_id: number
  inventory_snapshot_date: ISODate | null
  on_hand: number | null
  reserved: number
  in_transit: number
  available: number | null
  lead_time_days: number
  lead_time_source: string
  review_period_days: number
  service_level: number
  /** Cantidades null si el pronóstico no cubre plazo + revisión (assumptions.coverage_insufficient). */
  demand_lead_time: number | null
  /** null si la incertidumbre es desconocida (assumptions.uncertainty = 'unknown'). */
  demand_lead_time_std: number | null
  safety_stock: number | null
  reorder_point: number | null
  suggested_qty: number | null
  unit_cost: number | null
  estimated_investment: number | null
  days_of_cover: number | null
  stockout_probability: number | null
  stockout_date_estimate: ISODate | null
  risk_level: RiskLevel
  status: RecommendationStatus
  assumptions: Record<string, unknown>
  product?: { id: number; sku: string; name: string }
  branch?: { id: number; name: string }
}

export interface RecommendationQuery {
  run_id?: number
  branch_id?: number
  category_id?: number
  risk_level?: RiskLevel
  type?: RecommendationType
  search?: string
  sort?: string
  page?: number
  per_page?: number
}

export interface InventorySummary {
  snapshot_date: ISODate | null
  products_with_stock: number
  total_on_hand_value: number | null
  risk_counts: Record<RiskLevel, number>
  purchase_investment: number | null
  coverage_days_median: number | null
}

export type AlertSeverity = 'info' | 'warning' | 'critical'
export type AlertStatus = 'open' | 'acknowledged' | 'resolved'

export interface Alert {
  id: number
  type: string
  severity: AlertSeverity
  title: string
  message: string
  payload: Record<string, unknown>
  related_type: string | null
  related_id: number | null
  status: AlertStatus
  acknowledged_at: ISODateTime | null
  created_at: ISODateTime
}

// ─── Escenarios ────────────────────────────────────────────────────────────

export interface ScenarioAssumptions {
  price_change_pct: number
  discount_pct: number
  cost_change_pct: number
  demand_change_pct: number
  lead_time_days?: number | null
  purchase_budget?: number | null
  macro_note?: string | null
}

export interface ScenarioFilters {
  branch_id?: number | null
  category_id?: number | null
  product_id?: number | null
}

export interface ScenarioRequest {
  horizon_days: Horizon
  filters: ScenarioFilters
  assumptions: ScenarioAssumptions
}

export interface ScenarioFigures {
  units: number
  revenue: number
  cost: number | null
  gross_margin: number | null
  gross_margin_pct: number | null
  purchase_need_units: number | null
  purchase_need_cost: number | null
}

export interface ScenarioRisk {
  code: 'negative_margin' | 'budget_shortfall' | 'stockout_risk' | 'low_data_quality' | 'cost_unknown' | string
  severity: 'info' | 'warning' | 'critical'
  message: string
}

export interface ScenarioResult {
  type: 'simulation'
  disclaimer: string
  base: ScenarioFigures
  scenario: ScenarioFigures
  delta: {
    units: number
    revenue: number
    gross_margin: number | null
    gross_margin_pct: number | null
  }
  budget: { purchase_budget: number | null; coverage_pct: number | null; shortfall: number | null } | null
  risks: ScenarioRisk[]
  assumptions_used: Record<string, unknown>
  base_run: { id: number; version: number } | null
}

export interface SavedScenario {
  id: number
  name: string
  horizon_days: Horizon
  filters: ScenarioFilters
  assumptions: ScenarioAssumptions
  results: ScenarioResult | null
  created_at: ISODateTime
}

// ─── Auditoría y plataforma ────────────────────────────────────────────────

export interface AuditLogEntry {
  id: number
  action: string
  user: { id: number; name: string } | null
  auditable_type: string | null
  auditable_id: number | null
  ip_address: string | null
  metadata: Record<string, unknown>
  created_at: ISODateTime
}

export interface AdminTenant {
  uuid: string
  name: string
  sector: string
  status: string
  plan: { code: string; name: string } | null
  created_at: ISODateTime
  users_count?: number
}

export interface AdminPlan {
  id?: number
  code: string
  name: string
  limits: PlanLimits
  monthly_price_usd: number | null
  is_active: boolean
}

// ─── Etapa 2 ───────────────────────────────────────────────────────────────
export * from './types.v2'
