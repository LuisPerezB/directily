/**
 * Tipos de la API pública de Predictily (/api/v1).
 * Fuente: docs/06-contratos-integracion.md §3. Mantener sincronizado con el contrato.
 */

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
export type Horizon = 7 | 30 | 90

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
}

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
  name: string
  status: 'active' | 'inactive' | 'error'
  last_synced_at: ISODateTime | null
  last_error: string | null
  config: Record<string, unknown>
}

export interface DataSourcePayload {
  type: DataSourceType
  name: string
  connector_key?: string | null
  config?: Record<string, unknown>
  credentials?: Record<string, string>
}

export interface Connector {
  key: string
  name: string
  description: string
  status: 'available' | 'requires_credentials' | 'planned'
  entity_types: string[]
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
}

export interface ForecastSeries {
  node: { key: string; level: NodeLevel; label: string }
  granularity: Granularity
  history: HistoryPoint[]
  forecast: ForecastPoint[]
  meta: ForecastSeriesMeta | null
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
  trend: Trend
  forecast_revenue: number | null
  lo_80: number | null
  hi_80: number | null
  model_family: string | null
  demand_class: string | null
  wape: number | null
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
  suggested_qty: number
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
  run: { id: number; version: number; generated_at: ISODateTime | null; cutoff_date: ISODate | null } | null
  kpis: DashboardKpis
  top_increasing: DashboardProductChange[]
  top_decreasing: DashboardProductChange[]
  stockout_risks: StockoutRisk[]
  alerts: Alert[]
  data_status: DataStatus
}

// ─── Precisión ─────────────────────────────────────────────────────────────

export interface MetricSet {
  wape: number | null
  mae: number | null
  rmse?: number | null
  bias: number | null
  coverage_80?: number | null
  coverage_95?: number | null
}

export interface AccuracyByHorizon {
  horizon_days: number
  wape: number | null
  baseline_wape: number | null
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
    by_horizon: AccuracyByHorizon[]
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
  evaluation_type: 'backtest' | 'realized'
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

export interface ModelRegistryEntry {
  id: number
  version: string
  model_family: string
  strategy_level: string
  status: 'candidate' | 'production' | 'fallback' | 'archived' | 'rejected'
  demand_class: string | null
  trained_at: ISODateTime | null
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
  demand_lead_time: number
  demand_lead_time_std: number | null
  safety_stock: number
  reorder_point: number
  suggested_qty: number
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
