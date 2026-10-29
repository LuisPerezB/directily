import { http } from './http'
import type * as T from './types'

/**
 * Funciones de acceso a la API pública (/api/v1). Una función por endpoint del contrato
 * (docs/06 §3). Devuelven el contenido de `data` salvo en listas paginadas.
 */

type Params = Record<string, string | number | boolean | null | undefined>

/** Elimina parámetros vacíos para no enviar `?branch_id=` o `null`. */
export function cleanParams<P extends object>(params?: P): Params | undefined {
  if (!params) return undefined
  const out: Params = {}
  for (const [key, value] of Object.entries(params as Record<string, unknown>)) {
    if (value === undefined || value === null || value === '') continue
    out[key] = value as string | number | boolean
  }
  return out
}

async function getData<R>(url: string, params?: object): Promise<R> {
  const res = await http.get<T.Resource<R>>(url, { params: cleanParams(params) })
  return res.data.data
}

async function getPage<R>(url: string, params?: object): Promise<T.Paginated<R>> {
  const res = await http.get<T.Paginated<R>>(url, { params: cleanParams(params) })
  return res.data
}

async function postData<R>(url: string, body?: unknown): Promise<R> {
  const res = await http.post<T.Resource<R>>(url, body)
  return res.data?.data as R
}

async function putData<R>(url: string, body?: unknown): Promise<R> {
  const res = await http.put<T.Resource<R>>(url, body)
  return res.data?.data as R
}

async function patchData<R>(url: string, body?: unknown): Promise<R> {
  const res = await http.patch<T.Resource<R>>(url, body)
  return res.data?.data as R
}

async function del(url: string, body?: unknown): Promise<void> {
  await http.delete(url, body ? { data: body } : undefined)
}

// ─── Autenticación ─────────────────────────────────────────────────────────
export const authApi = {
  me: () => getData<T.MeResponse>('/auth/me'),
  login: (payload: T.LoginPayload) => postData<{ user: T.User }>('/auth/login', payload),
  register: (payload: T.RegisterPayload) => postData<{ user: T.User }>('/auth/register', payload),
  logout: () => http.post('/auth/logout').then(() => undefined),
}

// ─── Empresas ──────────────────────────────────────────────────────────────
export const tenantApi = {
  list: () => getData<T.TenantMembership[]>('/tenants'),
  create: (payload: T.CreateTenantPayload) => postData<T.Tenant>('/tenants', payload),
  current: () => getData<T.Tenant>('/tenant'),
  update: (payload: T.UpdateTenantPayload) => patchData<T.Tenant>('/tenant', payload),
  completeOnboarding: () => postData<T.Tenant>('/tenant/complete-onboarding'),
  setSharedTrainingConsent: (allow: boolean) => putData<T.Tenant>('/tenant/shared-training-consent', { allow }),
  usage: () => getData<T.TenantUsage>('/tenant/usage'),
  loadDemoData: () => postData<{ import_job_ids: number[] }>('/tenant/demo-data'),
  requestExport: () => postData<T.TenantExport>('/tenant/export'),
  exportStatus: (id: string | number) => getData<T.TenantExport>(`/tenant/export/${id}`),
  requestDeletion: (confirmName: string) => del('/tenant', { confirm_name: confirmName }),
  cancelDeletion: () => postData<T.Tenant>('/tenant/cancel-deletion'),
  users: () => getData<T.TenantUser[]>('/tenant/users'),
  addUser: (payload: { email: string; role: T.TenantRole; name?: string }) => postData<T.TenantUser>('/tenant/users', payload),
  updateUser: (id: number, role: T.TenantRole) => patchData<T.TenantUser>(`/tenant/users/${id}`, { role }),
  removeUser: (id: number) => del(`/tenant/users/${id}`),
}

// ─── Catálogos ─────────────────────────────────────────────────────────────
export const catalogApi = {
  branches: () => getData<T.Branch[]>('/branches'),
  createBranch: (payload: T.BranchPayload) => postData<T.Branch>('/branches', payload),
  updateBranch: (id: number, payload: Partial<T.BranchPayload>) => patchData<T.Branch>(`/branches/${id}`, payload),
  deleteBranch: (id: number) => del(`/branches/${id}`),
  categories: () => getData<T.ProductCategory[]>('/product-categories'),
  products: (params?: { search?: string; category_id?: number; is_active?: boolean; page?: number; per_page?: number; sort?: string }) =>
    getPage<T.Product>('/products', params),
  product: (id: number) => getData<T.Product>(`/products/${id}`),
  leadTimes: (params?: { page?: number; product_id?: number }) => getPage<T.LeadTime>('/lead-times', params),
  createLeadTime: (payload: Omit<T.LeadTime, 'id' | 'source'>) => postData<T.LeadTime>('/lead-times', payload),
  promotions: (params?: { page?: number }) => getPage<T.Promotion>('/promotions', params),
  createPromotion: (payload: T.PromotionInput) => postData<T.Promotion>('/promotions', payload),
  deletePromotion: (id: number) => del(`/promotions/${id}`),
}

// ─── Ventas ────────────────────────────────────────────────────────────────
export const salesApi = {
  list: (params?: { date_from?: string; date_to?: string; branch_id?: number; status?: string; document_type?: string; page?: number }) =>
    getPage<T.SaleListItem>('/sales', params),
  summary: (params?: { date_from?: string; date_to?: string; branch_id?: number; category_id?: number; product_id?: number; granularity?: T.Granularity }) =>
    getData<T.SalesSummary>('/sales/summary', params),
}

// ─── Importaciones ─────────────────────────────────────────────────────────
export const importsApi = {
  list: (params?: { page?: number; per_page?: number }) => getPage<T.ImportJob>('/imports', params),
  upload: (file: File, entityType: T.ImportEntityType, onProgress?: (pct: number) => void) => {
    const form = new FormData()
    form.append('file', file)
    form.append('entity_type', entityType)
    return http
      .post<T.Resource<T.ImportJob>>('/imports', form, {
        headers: { 'Content-Type': 'multipart/form-data' },
        timeout: 300_000,
        onUploadProgress: (e) => {
          if (onProgress && e.total) onProgress(Math.round((e.loaded / e.total) * 100))
        },
      })
      .then((r) => r.data.data)
  },
  get: (id: number) => getData<T.ImportJob>(`/imports/${id}`),
  saveMapping: (id: number, mapping: T.ColumnMapping, options: T.ImportOptions) =>
    putData<T.ImportJob>(`/imports/${id}/mapping`, { mapping, options }),
  errors: (id: number, params?: { severity?: string; page?: number; per_page?: number }) =>
    getPage<T.ImportErrorRow>(`/imports/${id}/errors`, params),
  confirm: (id: number) => postData<T.ImportJob>(`/imports/${id}/confirm`),
  reject: (id: number) => postData<T.ImportJob>(`/imports/${id}/reject`),
  fields: (entityType: T.ImportEntityType) => getData<T.ImportField[]>(`/import-fields/${entityType}`),
}

// ─── Fuentes de datos ──────────────────────────────────────────────────────
export const dataSourcesApi = {
  list: () => getData<T.DataSource[]>('/data-sources'),
  create: (payload: T.DataSourcePayload) => postData<T.DataSource>('/data-sources', payload),
  update: (id: number, payload: Partial<T.DataSourcePayload> & { status?: string }) => patchData<T.DataSource>(`/data-sources/${id}`, payload),
  remove: (id: number) => del(`/data-sources/${id}`),
  createToken: (id: number) => postData<{ token: string }>(`/data-sources/${id}/tokens`),
  connectors: () => getData<T.Connector[]>('/connectors'),
}

// ─── Pronósticos ───────────────────────────────────────────────────────────
export const forecastApi = {
  createRun: (payload: T.CreateForecastRunPayload) => postData<T.ForecastRun>('/forecast-runs', payload),
  runs: (params?: { page?: number; per_page?: number }) => getPage<T.ForecastRun>('/forecast-runs', params),
  run: (id: number) => getData<T.ForecastRun>(`/forecast-runs/${id}`),
  job: (id: number) => getData<T.TrainingJob>(`/jobs/${id}`),
  series: (params: T.ForecastSeriesQuery) => getData<T.ForecastSeries>('/forecasts/series', params),
  products: (params: T.ForecastTableQuery) => getPage<T.ProductForecastRow>('/forecasts/products', params),
  categories: (params: T.ForecastTableQuery) => getPage<T.CategoryForecastRow>('/forecasts/categories', params),
  dashboard: (params: { horizon?: T.Horizon; branch_id?: number }) => getData<T.DashboardSummary>('/dashboard/summary', params),
}

// ─── Precisión ─────────────────────────────────────────────────────────────
export const accuracyApi = {
  summary: (params?: { run_id?: number }) => getData<T.AccuracySummary>('/accuracy/summary', params),
  comparison: (params: { run_id: number; branch_id?: number; category_id?: number; product_id?: number }) =>
    getData<T.AccuracyComparison>('/accuracy/comparison', params),
  evaluations: (params: { run_id: number; evaluation_type?: string; level?: string }) =>
    getData<T.ModelEvaluation[]>('/model-evaluations', params),
  models: () => getData<T.ModelRegistryEntry[]>('/models'),
}

// ─── Inventario y alertas ──────────────────────────────────────────────────
export const inventoryApi = {
  recommendations: (params: T.RecommendationQuery) => getPage<T.Recommendation>('/inventory/recommendations', params),
  updateRecommendation: (id: number, status: T.RecommendationStatus) =>
    patchData<T.Recommendation>(`/inventory/recommendations/${id}`, { status }),
  summary: () => getData<T.InventorySummary>('/inventory/summary'),
}

export const alertsApi = {
  list: (params?: { status?: T.AlertStatus; type?: string; severity?: T.AlertSeverity; page?: number; per_page?: number }) =>
    getPage<T.Alert>('/alerts', params),
  update: (id: number, status: 'acknowledged' | 'resolved') => patchData<T.Alert>(`/alerts/${id}`, { status }),
}

// ─── Escenarios ────────────────────────────────────────────────────────────
export const scenariosApi = {
  simulate: (payload: T.ScenarioRequest) => postData<T.ScenarioResult>('/scenarios/simulate', payload),
  list: () => getData<T.SavedScenario[]>('/scenarios'),
  save: (payload: T.ScenarioRequest & { name: string }) => postData<T.SavedScenario>('/scenarios', payload),
  remove: (id: number) => del(`/scenarios/${id}`),
}

// ─── Auditoría y plataforma ────────────────────────────────────────────────
export const auditApi = {
  list: (params?: { page?: number }) => getPage<T.AuditLogEntry>('/audit-logs', params),
}

export const adminApi = {
  tenants: (params?: { page?: number; search?: string }) => getPage<T.AdminTenant>('/admin/tenants', params),
  updateTenant: (uuid: string, payload: { plan_code?: string; status?: string }) => patchData<T.AdminTenant>(`/admin/tenants/${uuid}`, payload),
  plans: () => getData<T.AdminPlan[]>('/admin/plans'),
}

// ─── Descargas (CSV) ───────────────────────────────────────────────────────
/**
 * Descarga un archivo autenticado. Se usa axios (no un <a href>) para enviar el
 * header X-Tenant-ID, que el navegador no agrega en enlaces normales.
 */
export async function downloadFile(url: string, params: object | undefined, fallbackName: string): Promise<void> {
  const res = await http.get<Blob>(url, { params: cleanParams(params), responseType: 'blob', timeout: 300_000 })
  const disposition = String(res.headers['content-disposition'] ?? '')
  const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(disposition)
  const filename = match?.[1] ? decodeURIComponent(match[1]) : fallbackName
  const href = URL.createObjectURL(res.data)
  const link = document.createElement('a')
  link.href = href
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  setTimeout(() => URL.revokeObjectURL(href), 1000)
}

export const exportsApi = {
  forecasts: (params: T.ForecastTableQuery & { granularity?: T.Granularity }) =>
    downloadFile('/exports/forecasts.csv', params, 'pronosticos.csv'),
  recommendations: (params: T.RecommendationQuery) => downloadFile('/exports/recommendations.csv', params, 'recomendaciones.csv'),
  accuracy: (params?: { run_id?: number }) => downloadFile('/exports/accuracy.csv', params, 'precision.csv'),
  salesSummary: (params?: { date_from?: string; date_to?: string; branch_id?: number; granularity?: T.Granularity }) =>
    downloadFile('/exports/sales-summary.csv', params, 'resumen-ventas.csv'),
  importErrors: (importId: number) => downloadFile(`/imports/${importId}/errors.csv`, undefined, `errores-importacion-${importId}.csv`),
  importTemplate: (entityType: T.ImportEntityType) =>
    downloadFile(`/import-templates/${entityType}`, undefined, `plantilla-${entityType}.csv`),
}
