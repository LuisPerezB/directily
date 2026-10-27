import { describe, expect, it } from 'vitest'
import es from '../es.json'

/**
 * Verifica que cada clave i18n estática usada en el código exista en es.json y que
 * las familias dinámicas (estados, modelos, etc.) tengan todas sus variantes.
 */
const sources = import.meta.glob('../../**/*.{vue,ts}', { query: '?raw', import: 'default', eager: true }) as Record<string, string>

function has(path: string): boolean {
  let node: unknown = es
  for (const part of path.split('.')) {
    if (node && typeof node === 'object' && part in (node as Record<string, unknown>)) node = (node as Record<string, unknown>)[part]
    else return false
  }
  return node !== undefined
}

describe('claves de traducción', () => {
  it('todas las claves estáticas existen', () => {
    const missing = new Set<string>()
    for (const [file, code] of Object.entries(sources)) {
      if (file.includes('__tests__')) continue
      for (const m of code.matchAll(/\bt[em]?\(\s*'([a-zA-Z0-9_.]+)'/g)) {
        if (!has(m[1]!)) missing.add(`${m[1]} (${file})`)
      }
    }
    expect([...missing]).toEqual([])
  })

  it('las familias dinámicas están completas', () => {
    const families: Record<string, string[]> = {
      'status.import': ['uploaded', 'awaiting_mapping', 'validating', 'validated', 'importing', 'completed', 'failed', 'rejected'],
      'status.job': ['queued', 'running', 'succeeded', 'failed', 'cancelled'],
      'status.run': ['queued', 'running', 'completed', 'failed'],
      'status.risk': ['low', 'medium', 'high', 'critical'],
      'status.recommendation': ['open', 'acknowledged', 'dismissed', 'ordered'],
      'status.severity': ['info', 'warning', 'critical'],
      roles: ['owner', 'admin', 'analyst', 'viewer'],
      sectors: ['ferreteria', 'distribuidora', 'repuestos_automotrices', 'repuestos_agricolas', 'retail_multisucursal', 'importadora', 'consumo_recurrente', 'otro'],
      models: ['naive', 'seasonal_naive', 'window_average', 'auto_ets', 'croston_sba', 'adida', 'tsb', 'lightgbm_global', 'timesfm'],
      levels: ['total', 'branch', 'category', 'branch_category', 'product', 'series'],
      'imports.entities': ['sales', 'products', 'inventory', 'lead_times', 'branches', 'suppliers', 'promotions'],
      'imports.entityHints': ['sales', 'products', 'inventory', 'lead_times', 'branches', 'suppliers', 'promotions'],
      'inventory.types': ['reorder', 'stockout_risk', 'overstock', 'no_action'],
      'settings.tabs': ['empresa', 'parametros', 'sucursales', 'usuarios', 'privacidad', 'plan', 'auditoria'],
      granularity: ['day', 'week', 'month'],
      'scenarios.rows': ['units', 'revenue', 'cost', 'gross_margin', 'gross_margin_pct', 'purchase_need_units', 'purchase_need_cost'],
    }
    const missing = Object.entries(families).flatMap(([prefix, keys]) => keys.map((k) => `${prefix}.${k}`).filter((k) => !has(k)))
    expect(missing).toEqual([])
  })

  it('no usa caracteres reservados de vue-i18n sin escapar', () => {
    const bad: string[] = []
    const walk = (node: unknown, path: string) => {
      if (typeof node === 'string') {
        if (/[@|$]/.test(node)) bad.push(path)
      } else if (node && typeof node === 'object') {
        for (const [k, v] of Object.entries(node)) walk(v, path ? `${path}.${k}` : k)
      }
    }
    walk(es, '')
    expect(bad).toEqual([])
  })
})
