/**
 * Formateo con Intl para Ecuador (es-EC, USD, America/Guayaquil).
 * Las métricas como WAPE llegan como proporción (0.23) y se muestran como porcentaje.
 */

export const LOCALE = 'es-EC'
export const DEFAULT_TIMEZONE = 'America/Guayaquil'
export const DASH = '—'

const nf = new Map<string, Intl.NumberFormat>()
function numberFormat(options: Intl.NumberFormatOptions): Intl.NumberFormat {
  const key = JSON.stringify(options)
  let f = nf.get(key)
  if (!f) {
    f = new Intl.NumberFormat(LOCALE, options)
    nf.set(key, f)
  }
  return f
}

function isNum(v: unknown): v is number {
  return typeof v === 'number' && Number.isFinite(v)
}

export function formatNumber(value: number | null | undefined, maxDecimals = 1): string {
  if (!isNum(value)) return DASH
  return numberFormat({ maximumFractionDigits: maxDecimals, minimumFractionDigits: 0 }).format(value)
}

export function formatInteger(value: number | null | undefined): string {
  if (!isNum(value)) return DASH
  return numberFormat({ maximumFractionDigits: 0 }).format(Math.round(value))
}

export function formatCurrency(value: number | null | undefined, currency = 'USD', decimals = 2): string {
  if (!isNum(value)) return DASH
  return numberFormat({ style: 'currency', currency, minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(value)
}

/** Monto compacto para tarjetas (p. ej. "12,3 mil US$"). */
export function formatCurrencyCompact(value: number | null | undefined, currency = 'USD'): string {
  if (!isNum(value)) return DASH
  if (Math.abs(value) < 10_000) return formatCurrency(value, currency, 0)
  return numberFormat({ style: 'currency', currency, notation: 'compact', maximumFractionDigits: 1 }).format(value)
}

/** Proporción → porcentaje (0.234 → "23,4 %"). */
export function formatPercent(ratio: number | null | undefined, decimals = 1): string {
  if (!isNum(ratio)) return DASH
  return numberFormat({ style: 'percent', minimumFractionDigits: 0, maximumFractionDigits: decimals }).format(ratio)
}

/** Variación con signo explícito (0.05 → "+5 %", -0.1 → "−10 %"). */
export function formatChange(ratio: number | null | undefined, decimals = 1): string {
  if (!isNum(ratio)) return DASH
  const formatted = numberFormat({ style: 'percent', maximumFractionDigits: decimals, signDisplay: 'exceptZero' }).format(ratio)
  return formatted.replace('-', '−')
}

/** Parse "YYYY-MM-DD" como fecha calendario (sin desplazamiento por zona horaria). */
export function parseISODate(value: string): Date {
  const [y, m, d] = value.slice(0, 10).split('-').map(Number)
  return new Date(Date.UTC(y ?? 1970, (m ?? 1) - 1, d ?? 1, 12))
}

export function formatDate(value: string | null | undefined, style: 'short' | 'medium' | 'long' = 'medium'): string {
  if (!value) return DASH
  const date = parseISODate(value)
  if (Number.isNaN(date.getTime())) return DASH
  const options: Intl.DateTimeFormatOptions =
    style === 'short'
      ? { day: '2-digit', month: 'short', timeZone: 'UTC' }
      : style === 'long'
        ? { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' }
        : { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'UTC' }
  return new Intl.DateTimeFormat(LOCALE, options).format(date)
}

export function formatMonth(value: string | null | undefined): string {
  if (!value) return DASH
  return new Intl.DateTimeFormat(LOCALE, { month: 'short', year: 'numeric', timeZone: 'UTC' }).format(parseISODate(value))
}

export function formatDateTime(value: string | null | undefined, timeZone = DEFAULT_TIMEZONE): string {
  if (!value) return DASH
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return DASH
  return new Intl.DateTimeFormat(LOCALE, { dateStyle: 'medium', timeStyle: 'short', timeZone }).format(date)
}

export function formatDuration(ms: number | null | undefined): string {
  if (!isNum(ms)) return DASH
  const s = Math.round(ms / 1000)
  if (s < 60) return `${s} s`
  const m = Math.floor(s / 60)
  return `${m} min ${s % 60} s`
}

export function formatBytes(bytes: number | null | undefined): string {
  if (!isNum(bytes)) return DASH
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${formatNumber(bytes / 1024, 1)} KB`
  return `${formatNumber(bytes / (1024 * 1024), 1)} MB`
}

/** Fecha de hoy (YYYY-MM-DD) en la zona horaria indicada. */
export function todayISO(timeZone = DEFAULT_TIMEZONE): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date())
}
