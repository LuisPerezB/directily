/**
 * Reglas comunes de honestidad de las vistas Enterprise (V2.5 · W5-E; ADR-V25-22). Funciones puras, sin i18n:
 *  - un valor `null` de la API es «no estimado», nunca cero ni un guion mudo;
 *  - el origen de los datos (real, demostración, simulado) y la antigüedad del corte siempre se pueden mostrar;
 *  - la interfaz nunca suma tipos de valor distintos (potencial, observado, asociado, incremental) ni horizontes entre sí.
 */
import { parseISODate } from './format'

/** ¿Hay una cifra? `null`, `undefined` y NaN son «sin cifra»; el 0 SÍ es una cifra (un hecho medido). */
export function isKnown(value: unknown): value is number {
  return typeof value === 'number' && Number.isFinite(value)
}

/** Días entre el corte (as-of) y hoy; null si no hay corte o la fecha no es válida. 0 = datos de hoy. */
export function stalenessDays(asOf: string | null | undefined, today: string): number | null {
  if (!asOf) return null
  const from = parseISODate(asOf).getTime()
  const to = parseISODate(today).getTime()
  if (Number.isNaN(from) || Number.isNaN(to)) return null
  return Math.max(0, Math.round((to - from) / 86_400_000))
}

export type DataOriginKey = 'real' | 'demo' | 'simulated' | 'undeclared'

/** Origen declarado de los datos. Sin declarar NO se supone real (V25-UIX-012). */
export function dataOriginKey(value: unknown): DataOriginKey {
  return value === 'real' || value === 'demo' || value === 'simulated' ? value : 'undeclared'
}

/** Los datos de demostración o simulados no producen resultados reales: la interfaz los avisa en cada cifra económica. */
export function originIsNotReal(key: DataOriginKey): boolean {
  return key === 'demo' || key === 'simulated'
}

/** Origen de datos declarado en los ajustes de la empresa (`tenants.settings.data_origin`, que viaja en GET /tenant). */
export function tenantDataOrigin(settings: Record<string, unknown> | null | undefined): DataOriginKey {
  return dataOriginKey(settings?.data_origin)
}

/** Texto seguro para un valor opcional de la API: vacío → null. */
export function textOrNull(value: unknown): string | null {
  return typeof value === 'string' && value.trim() !== '' ? value : null
}

/** Cuenta total de un mapa de conteos (solo enteros del MISMO tipo: estados de una misma lista, no importes de tipos distintos). */
export function countOf(map: Record<string, number> | null | undefined, key: string): number {
  const v = map?.[key]
  return isKnown(v) ? v : 0
}

/** Máximo del periodo que acepta el servidor para el informe de ROI (días entre el corte inicial y el final). */
export const MAX_REPORT_RANGE_DAYS = 730

/** Hoy como AAAA-MM-DD en la zona horaria del navegador (el servidor usa la de la empresa: una diferencia de un día la corrige él con su 422). */
export function todayISODate(now: Date = new Date()): string {
  const pad = (n: number): string => String(n).padStart(2, '0')
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
}

/** Días entre dos fechas AAAA-MM-DD (final − inicial); null si alguna falta o no es válida. */
export function daysBetween(from: string | null | undefined, to: string | null | undefined): number | null {
  if (!from || !to) return null
  const a = parseISODate(from).getTime()
  const b = parseISODate(to).getTime()
  return Number.isNaN(a) || Number.isNaN(b) ? null : Math.round((b - a) / 86_400_000)
}

/**
 * Cuántas de las oportunidades de una suma entran en ella. Una suma parcial (algunas oportunidades sin valor o sin costo confiable) se dice parcial: devuelve
 * cuántas quedaron fuera, o 0 si entran todas o no se sabe el total.
 */
export function leftOut(total: number | null | undefined, included: number | null | undefined): number {
  return isKnown(total) && isKnown(included) && included < total ? total - included : 0
}
