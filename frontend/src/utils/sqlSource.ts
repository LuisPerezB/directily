import { isApiError } from '@/api/errors'
import type { DataSourcePayload, SourceMappingPayload, SqlEntity } from '@/api/types'

/** Entidades que un mapeo SQL puede alimentar (customers y costs no tienen importador propio). */
export const SQL_ENTITIES: SqlEntity[] = [
  'sales', 'products', 'inventory', 'commitments', 'branches', 'suppliers', 'lead_times', 'promotions', 'calendar_events', 'unit_conversions', 'warehouses',
]

export const SQL_CONNECTORS = ['sql_postgresql', 'sql_mysql', 'sql_sqlserver'] as const

export function isSqlConnector(key: string | null | undefined): boolean {
  return !!key && (SQL_CONNECTORS as readonly string[]).includes(key)
}

export function defaultPort(key: string | null | undefined): number {
  return key === 'sql_mysql' ? 3306 : key === 'sql_sqlserver' ? 1433 : 5432
}

export interface SqlFormState {
  name: string
  connector_key: string
  host: string
  port: number | null
  database: string
  sslmode: string
  source_timezone: string
  sync_schedule: 'manual' | 'hourly' | 'daily'
  username: string
  password: string
}

export function emptySqlForm(connectorKey = 'sql_postgresql', timezone = 'America/Guayaquil'): SqlFormState {
  return { name: '', connector_key: connectorKey, host: '', port: defaultPort(connectorKey), database: '', sslmode: 'prefer', source_timezone: timezone, sync_schedule: 'manual', username: '', password: '' }
}

/**
 * Las credenciales van en `credentials` (cifradas en el servidor, nunca se devuelven); el resto en `config`.
 * En una edición, sin usuario ni clave nuevos no se envían credenciales (se conservan las guardadas).
 */
export function buildSqlPayload(form: SqlFormState, mode: 'create'): DataSourcePayload
export function buildSqlPayload(form: SqlFormState, mode: 'update'): Partial<DataSourcePayload>
export function buildSqlPayload(form: SqlFormState, mode: 'create' | 'update'): DataSourcePayload | Partial<DataSourcePayload> {
  const config: Record<string, unknown> = {
    host: form.host.trim(),
    port: form.port ?? defaultPort(form.connector_key),
    database: form.database.trim(),
    sslmode: form.sslmode,
    source_timezone: form.source_timezone,
    sync_schedule: form.sync_schedule,
  }
  const hasCredentials = form.username !== '' || form.password !== ''
  const credentials = hasCredentials ? { username: form.username, password: form.password } : undefined
  if (mode === 'create') {
    return { type: 'connector', name: form.name.trim(), connector_key: form.connector_key, config, ...(credentials ? { credentials } : {}) }
  }
  return { name: form.name.trim(), config, ...(credentials ? { credentials } : {}) }
}

/** `config.host` → `host`; `credentials.username` → `username`. */
export function sqlFieldErrors(error: unknown): Record<string, string> {
  if (!isApiError(error)) return {}
  const out: Record<string, string> = {}
  for (const [key, messages] of Object.entries(error.fieldErrors)) {
    const short = key.replace(/^(config|credentials)\./, '')
    if (messages[0]) out[short] = messages[0]
  }
  return out
}

export type FieldMapEntry = { column: string; fixed: boolean }

/** De las filas del editor al `field_map` de la API: columna de origen o `{const: valor}`; las filas vacías se omiten. */
export function buildFieldMap(rows: Record<string, FieldMapEntry>): SourceMappingPayload['field_map'] {
  const out: SourceMappingPayload['field_map'] = {}
  for (const [field, entry] of Object.entries(rows)) {
    const value = entry.column.trim()
    if (value === '') continue
    out[field] = entry.fixed ? { const: value } : value
  }
  return out
}

export function fieldMapToRows(map: SourceMappingPayload['field_map'] | null | undefined): Record<string, FieldMapEntry> {
  const out: Record<string, FieldMapEntry> = {}
  for (const [field, v] of Object.entries(map ?? {})) {
    out[field] = typeof v === 'string' ? { column: v, fixed: false } : { column: String(v.const), fixed: true }
  }
  return out
}

/** Campos obligatorios sin columna asignada (bloquean guardar la versión). */
export function missingRequiredFields(fields: { key: string; required: boolean }[], rows: Record<string, FieldMapEntry>): string[] {
  return fields.filter((f) => f.required && (rows[f.key]?.column ?? '').trim() === '').map((f) => f.key)
}

/** `422 sql_query_rejected`: motivo legible de por qué la consulta no es de solo lectura. */
export function sqlRejection(error: unknown): { reason: string } | null {
  if (!isApiError(error) || error.status !== 422 || error.code !== 'sql_query_rejected') return null
  const reason = (error.body as { reason?: string } | null)?.reason
  return { reason: reason ?? error.fieldErrors.query?.[0] ?? error.message }
}

export function parseOptionsJson(text: string): { ok: true; value: Record<string, unknown> } | { ok: false } {
  const t = text.trim()
  if (t === '') return { ok: true, value: {} }
  try {
    const parsed: unknown = JSON.parse(t)
    return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? { ok: true, value: parsed as Record<string, unknown> } : { ok: false }
  } catch {
    return { ok: false }
  }
}
