import type { PlanLimits } from '@/api/types'

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
