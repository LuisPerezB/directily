import type { AxiosAdapter, InternalAxiosRequestConfig } from 'axios'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { setupTenant } from '@/test/helpers'
import { booleanFiltersAsBits, modelQualityApi } from '@/api'
import { http } from '@/api/http'

import SegmentAccuracy from '../accuracy/SegmentAccuracy.vue'

/**
 * La API (Laravel) validaba los filtros booleanos con la regla `boolean`, que rechaza «true»/«false» con 422; axios serializa un booleano como «true». Estas
 * pruebas NO simulan la API: dejan pasar la petición real por el cliente HTTP, capturan su configuración y comprueban la URL que axios construye (`getUri`).
 */
const calls: InternalAxiosRequestConfig[] = []
const original = http.defaults.adapter
const adapter: AxiosAdapter = async (config) => {
  calls.push(config)
  const data = config.url?.includes('shadow') || config.url?.includes('promotion')
    ? { data: [], meta: { current_page: 1, per_page: 50, total: 0, last_page: 1 }, run: null, summary: null, shadow_forecasts: [] }
    : { data: [], meta: { current_page: 1, per_page: 50, total: 0, last_page: 1 } }
  return { data, status: 200, statusText: 'OK', headers: {}, config, request: {} }
}
beforeEach(() => { calls.length = 0; http.defaults.adapter = adapter })
afterEach(() => { http.defaults.adapter = original })

const uriOf = (config: InternalAxiosRequestConfig): string => http.getUri(config)
const queryOf = (config: InternalAxiosRequestConfig): URLSearchParams => new URL(uriOf(config), 'http://localhost').searchParams

describe('filtros booleanos de la calidad ML: la petición real', () => {
  it('la tabla por segmento pide al modelo servido con is_selected=1 (no «true»)', async () => {
    mount(SegmentAccuracy, { props: { runId: 5 }, global: { plugins: [setupTenant('analyst')] } })
    await flushPromises()
    const call = calls.find((c) => c.url === '/models/evaluations')!
    expect(call).toBeDefined()
    const query = queryOf(call)
    expect(query.get('is_selected')).toBe('1')
    expect(query.get('level')).toBe('segment')
    expect(query.get('dimension')).toBe('demand_class')
    expect(query.get('run_id')).toBe('5')
    expect(query.get('evaluation_type')).toBe('backtest')
    expect(uriOf(call)).not.toMatch(/=(true|false)\b/)
  })

  it('is_baseline e is_effective también viajan como 1/0, tanto verdaderos como falsos', async () => {
    await modelQualityApi.evaluations({ run_id: 5, is_baseline: true, is_selected: false })
    await modelQualityApi.promotionDecisions({ run_id: 5, is_effective: false })
    await modelQualityApi.promotionDecisions({ run_id: 5, is_effective: true })
    const [evaluations, off, on] = calls
    expect(queryOf(evaluations!).get('is_baseline')).toBe('1')
    expect(queryOf(evaluations!).get('is_selected')).toBe('0')
    expect(queryOf(off!).get('is_effective')).toBe('0')
    expect(queryOf(on!).get('is_effective')).toBe('1')
    for (const c of calls) expect(uriOf(c)).not.toMatch(/=(true|false)\b/)
  })

  it('un filtro booleano ausente no se envía y los demás parámetros no se alteran', async () => {
    await modelQualityApi.evaluations({ run_id: 5, horizon: 30, level: 'segment' })
    const query = queryOf(calls[0]!)
    expect(query.has('is_selected')).toBe(false)
    expect(query.has('is_baseline')).toBe(false)
    expect(query.get('horizon')).toBe('30')
  })

  it('booleanFiltersAsBits solo toca los booleanos conocidos y no modifica el original', () => {
    const original = { is_selected: true, is_baseline: false, per_page: 25, level: 'segment', other: true }
    expect(booleanFiltersAsBits(original)).toEqual({ is_selected: 1, is_baseline: 0, per_page: 25, level: 'segment', other: true })
    expect(original.is_selected).toBe(true)
    expect(booleanFiltersAsBits(undefined)).toBeUndefined()
  })
})
