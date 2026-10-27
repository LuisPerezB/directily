import { describe, expect, it } from 'vitest'
import { formatChange, formatCurrency, formatDate, formatInteger, formatNumber, formatPercent, parseISODate, formatBytes } from '../format'

// Según la versión de ICU puede haber (o no) un espacio antes de %: se compara sin espacios.
const norm = (s: string) => s.replace(/\s/g, ' ')
const tight = (s: string) => s.replace(/\s/g, '')

describe('formateo es-EC', () => {
  it('formatea números y enteros', () => {
    expect(formatNumber(1234.56, 1)).toMatch(/1.?234,6/)
    expect(formatInteger(1234.4)).toMatch(/1.?234/)
    expect(formatNumber(null)).toBe('—')
    expect(formatNumber(Number.NaN)).toBe('—')
  })

  it('formatea dólares', () => {
    expect(norm(formatCurrency(1500.5))).toMatch(/1.?500,50/)
    expect(formatCurrency(1500.5)).toMatch(/\$/)
    expect(formatCurrency(undefined)).toBe('—')
  })

  it('convierte proporciones a porcentaje', () => {
    expect(tight(formatPercent(0.234))).toBe('23,4%')
    expect(tight(formatPercent(0.2, 0))).toBe('20%')
  })

  it('muestra variaciones con signo', () => {
    expect(tight(formatChange(0.05))).toBe('+5%')
    expect(tight(formatChange(-0.1))).toBe('−10%')
    expect(tight(formatChange(0))).toBe('0%')
  })

  it('interpreta fechas calendario sin desplazar el día', () => {
    const d = parseISODate('2026-01-01')
    expect(d.getUTCFullYear()).toBe(2026)
    expect(d.getUTCDate()).toBe(1)
    expect(formatDate('2026-12-31', 'long')).toMatch(/31 de diciembre de 2026/)
    expect(formatDate(null)).toBe('—')
  })

  it('formatea tamaños de archivo', () => {
    expect(formatBytes(512)).toBe('512 B')
    expect(formatBytes(2 * 1024 * 1024)).toMatch(/^2 MB$/)
  })
})
