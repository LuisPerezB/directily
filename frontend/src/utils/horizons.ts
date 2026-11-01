import type { HorizonSupport, PlanLimits } from '@/api/types'

/**
 * Horizontes que la empresa puede usar: los del dominio ∩ los de su plan (`plan.limits.horizons`).
 * Si el plan no declara horizontes (respuesta antigua) no se restringe en el cliente: la API decide (402/422).
 */
export function allowedHorizons<H extends number>(domain: readonly H[], limits: PlanLimits | null | undefined): H[] {
  const planHorizons = limits?.horizons
  if (!Array.isArray(planHorizons)) return [...domain]
  return domain.filter((h) => planHorizons.includes(h))
}

/**
 * Horizonte inicial: el preferido si está permitido; si no, el mayor permitido que no lo supere; si todos lo superan, el menor.
 * Devuelve null si el plan no incluye ningún horizonte del dominio.
 */
export function pickHorizon<H extends number>(allowed: readonly H[], preferred: number | null | undefined): H | null {
  if (allowed.length === 0) return null
  const sorted = [...allowed].sort((a, b) => a - b)
  if (preferred != null) {
    const exact = sorted.find((h) => h === preferred)
    if (exact !== undefined) return exact
    const below = sorted.filter((h) => h <= preferred)
    if (below.length) return below[below.length - 1]!
  }
  return sorted[0] ?? null
}

/**
 * Respaldo por horizonte de una ejecución (`summary.horizon_support`: mapa horizonte → respaldo). Un resumen ausente o de una ejecución
 * anterior a la etapa 2 devuelve un mapa vacío: no se inventa información de respaldo.
 */
export function horizonSupportMap(summary: Record<string, unknown> | null | undefined): Record<number, HorizonSupport> {
  const raw = summary?.horizon_support
  const out: Record<number, HorizonSupport> = {}
  if (!raw || typeof raw !== 'object') return out
  for (const [key, value] of Object.entries(raw as Record<string, HorizonSupport>)) {
    const h = Number(key)
    if (Number.isFinite(h) && value && typeof value === 'object' && typeof value.status === 'string') out[h] = value
  }
  return out
}
