import { formatNumber } from './format'

/** Decimal con coma para cifras auxiliares (segundos, razones). */
export function formatDecimal(value: number | null | undefined, decimals = 1): string {
  return formatNumber(value, decimals)
}
