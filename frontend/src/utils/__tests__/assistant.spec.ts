import { describe, expect, it } from 'vitest'
import { ApiError } from '@/api/errors'
import { assistantError, figureHasData, flattenFigure, formatFigureValue, replyView, trimHistory } from '../assistant'
import type { AssistantFigure, AssistantReply } from '@/api/types'

const figure = (over: Partial<AssistantFigure> = {}): AssistantFigure => ({
  tool: 'dashboard_summary', arguments: { horizon: 30 }, status: 'ok',
  data: { kpis: { forecast_units: 1234.5, stockout_risk_products: 3 }, run: { id: 9, version: 2 } }, envelope: null, ...over,
})
const reply = (over: Partial<AssistantReply> = {}): AssistantReply => ({
  status: 'answered', answer: 'Se espera vender unas mil doscientas unidades.', figures: [figure()],
  grounding: { checked: true, ungrounded_numbers: [] }, provider: { name: 'ollama_cloud', model: 'm' }, usage: {}, notice: 'No calcula.', ...over,
})

describe('asistente: las cifras salen de figures, nunca del texto', () => {
  it('aplana los datos de la herramienta en filas ruta → valor', () => {
    const { rows, truncated } = flattenFigure(figure().data)
    expect(rows).toEqual([
      { path: 'kpis.forecast_units', value: 1234.5 }, { path: 'kpis.stockout_risk_products', value: 3 },
      { path: 'run.id', value: 9 }, { path: 'run.version', value: 2 },
    ])
    expect(truncated).toBe(false)
  })

  it('las listas se recortan y se avisa; los nulos se conservan como nulos', () => {
    const { rows, truncated } = flattenFigure({ top: [1, 2, 3, 4, 5, 6, 7], missing: null }, { maxListItems: 3 })
    expect(rows.map((r) => r.path)).toEqual(['top[1]', 'top[2]', 'top[3]', 'missing'])
    expect(truncated).toBe(true)
    expect(rows.at(-1)!.value).toBeNull()
  })

  it('el texto no aporta filas: un número escrito en la respuesta no aparece como cifra', () => {
    const r = reply({ answer: 'Venderá 99999 unidades.', figures: [figure({ data: { kpis: { forecast_units: 1234.5 } } })] })
    const rows = replyView(r).figures.flatMap((f) => flattenFigure(f.data).rows)
    expect(rows.map((x) => x.value)).toEqual([1234.5])
    expect(JSON.stringify(rows)).not.toContain('99999')
  })

  it('answered: muestra el texto como explicación y las cifras de las herramientas', () => {
    const v = replyView(reply())
    expect(v.showExplanation).toBe(true)
    expect(v.fixedMessage).toBeNull()
    expect(v.figures.length).toBe(1)
  })

  it('ungrounded_rejected: no se muestra el texto del modelo, se informan las cifras descartadas y se conservan las figuras', () => {
    const v = replyView(reply({ status: 'ungrounded_rejected', answer: 'Mensaje fijo del sistema.', grounding: { checked: true, ungrounded_numbers: ['42', '7,5'] } }))
    expect(v.showExplanation).toBe(false)
    expect(v.fixedMessage).toBe('Mensaje fijo del sistema.')
    expect(v.discardedNumbers).toEqual(['42', '7,5'])
    expect(v.figures.length).toBe(1)
  })

  it('sin herramienta aplicable se muestra solo el mensaje fijo', () => {
    const v = replyView(reply({ status: 'no_applicable_tool', answer: 'No puedo responder esa pregunta…', figures: [] }))
    expect(v.showExplanation).toBe(false)
    expect(v.fixedMessage).toContain('No puedo responder')
  })

  it('las herramientas negadas (permiso, plan) no aportan cifras', () => {
    const v = replyView(reply({ status: 'tool_denied', figures: [figure({ status: 'forbidden', data: null }), figure({ tool: 'supply_plan', status: 'plan_limit', data: null })] }))
    expect(v.figures).toEqual([])
    expect(v.deniedFigures.map((f) => f.status)).toEqual(['forbidden', 'plan_limit'])
    expect(figureHasData(figure({ status: 'no_data' }))).toBe(false)
    expect(figureHasData(figure())).toBe(true)
  })

  it('formatea valores sin inventar: nulo es guion', () => {
    const n = (v: number) => v.toFixed(1)
    expect(formatFigureValue(null, n, 'sí', 'no')).toBe('—')
    expect(formatFigureValue(true, n, 'sí', 'no')).toBe('sí')
    expect(formatFigureValue(2.25, n, 'sí', 'no')).toBe('2.3')
    expect(formatFigureValue('texto', n, 'sí', 'no')).toBe('texto')
  })

  it('el historial reenviado se recorta a 6 mensajes', () => {
    expect(trimHistory([1, 2, 3, 4, 5, 6, 7, 8])).toEqual([3, 4, 5, 6, 7, 8])
    expect(trimHistory([1, 2], 6)).toEqual([1, 2])
  })

  it('clasifica los errores del asistente', () => {
    expect(assistantError(new ApiError(503, { message: 'x', code: 'assistant_disabled' }, 'x')).kind).toBe('disabled')
    expect(assistantError(new ApiError(402, { message: 'x', code: 'plan_limit_exceeded' }, 'x')).kind).toBe('plan')
    expect(assistantError(new ApiError(429, { message: 'x', code: 'assistant_rate_limited' }, 'x')).kind).toBe('rate_limited')
    expect(assistantError(new ApiError(429, { message: 'x', code: 'assistant_quota_exceeded' }, 'x')).kind).toBe('quota')
    expect(assistantError(new ApiError(502, { message: 'x', code: 'assistant_provider_error' }, 'x')).kind).toBe('provider')
    expect(assistantError(new Error('x')).kind).toBe('other')
  })
})
