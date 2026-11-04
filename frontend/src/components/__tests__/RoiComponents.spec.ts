import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount, type DOMWrapper } from '@vue/test-utils'
import { setupTenant } from '@/test/helpers'
import { associatedBlock, cell, experiment as baseExperiment, kindRow, observedBlock, outcome, roiReport } from '@/test/enterprise'
import type { OutcomeExperiment, OutcomeRoiReport, TenantRole } from '@/api/types'

const api = vi.hoisted(() => ({ list: vi.fn(), roi: vi.fn() }))
vi.mock('@/api', async (orig) => ({ ...(await orig<typeof import('@/api')>()), outcomeApi: api }))

import RoiHeadline from '../outcomes/RoiHeadline.vue'
import ValueKindColumns from '../outcomes/ValueKindColumns.vue'
import ExperimentCards from '../outcomes/ExperimentCards.vue'
import RoiFunnel from '../outcomes/RoiFunnel.vue'
import RoiCosts from '../outcomes/RoiCosts.vue'
import RoiBreakdown from '../outcomes/RoiBreakdown.vue'
import OutcomesTable from '../outcomes/OutcomesTable.vue'

enableAutoUnmount(afterEach)
const as = (role: TenantRole = 'analyst') => [setupTenant(role)]
beforeEach(() => { Object.values(api).forEach((m) => m.mockReset()) })

const experiment = (over: Partial<OutcomeExperiment> = {}): OutcomeExperiment => baseExperiment({
  experiment_key: 'contact-default', min_group_size: 30, min_buyers_per_arm: 30,
  sample: { treatment: 120, control: 60, treatment_converted: 48, control_converted: 15 },
  conversion: { rate_treatment: 0.4, rate_control: 0.25, difference: 0.15, ci_low: 0.02, ci_high: 0.28, significant: true },
  incremental: { basis: 'difference_per_unit × treated_units_in_population', treated_units: 110, contribution: 880.5, ci_low: 40.1, ci_high: 1720.9 },
  contact_cost: { total: 20, actions: 10, actions_cost_unknown: 0 }, ...over,
})
const estimatedRoi = (over: Partial<OutcomeRoiReport['roi']> = {}): OutcomeRoiReport => roiReport({
  roi: { status: 'incremental_estimated', label: 'Incremento de contribución estimado por intención de tratamiento (no causalidad demostrada)', proven: false, causal_claims: 0, currency: 'USD', value: null, reasons: [],
    message: 'El intervalo de confianza del incremento neto excluye 0. Es una estimación por intención de tratamiento, no causalidad demostrada.', confidence_level: 0.95,
    incremental_contribution: { basis: 'x', treated_units: 110, contribution: 880.5, ci_low: 40.1, ci_high: 1720.9 }, net_incremental_contribution: { estimate: 860.5, ci_low: 20.1, ci_high: 1700.9, significant: true },
    roi_ratio: { estimate: 43.03, ci_low: 1.0, ci_high: 85.0 }, ...over },
})

describe('ROI: nunca se presenta como probado', () => {
  it('sin grupo de control: estado «potencial, no demostrado», sin cifra de ROI, con el motivo y el método', () => {
    const w = mount(RoiHeadline, { props: { report: roiReport() }, global: { plugins: as() } })
    expect(w.get('[data-test="roi-status"]').text()).toBe('Potencial, no demostrado')
    expect(w.get('[data-test="roi-unproven"]').text()).toBe('No demostrado')
    expect(w.find('[data-test="roi-net"]').exists()).toBe(false)
    expect(w.get('[data-test="roi-no-figure"]').text()).toContain('No hay cifra de ROI')
    expect(w.get('[data-test="roi-reasons"]').text()).toContain('No hay grupo de control registrado')
    expect(w.get('[data-test="roi-message"]').text()).toContain('se muestra como probado')
    expect(w.get('[data-test="roi-method"]').text()).toContain('outcomes-roi-v1')
    expect(w.find('[data-test="figure"]').exists()).toBe(false)
  })

  it('con estimación muestra el neto con intervalo y la razón, y aclara que no es causalidad demostrada', () => {
    const w = mount(RoiHeadline, { props: { report: estimatedRoi() }, global: { plugins: as() } })
    expect(w.get('[data-test="roi-net"]').text()).toContain('860')
    expect(w.get('[data-test="roi-net"]').text()).toMatch(/95\s?%/)
    expect(w.get('[data-test="roi-net"]').text()).toContain('20,1')
    expect(w.get('[data-test="roi-ratio"]').text()).toBe('43,03')
    expect(w.get('[data-test="roi-significance"]').text()).toContain('excluye 0')
    expect(w.get('[data-test="roi-not-causal"]').text()).toContain('no causalidad demostrada')
    expect(w.get('[data-test="roi-unproven"]').text()).toBe('No demostrado')
  })

  it('aunque la API dijera proven = true, la vista sigue diciendo «No demostrado»', () => {
    const w = mount(RoiHeadline, { props: { report: estimatedRoi({ proven: true, causal_claims: 3 }) }, global: { plugins: as() } })
    expect(w.get('[data-test="roi-unproven"]').text()).toBe('No demostrado')
    expect(w.text().toLowerCase()).not.toContain('probado:')
    expect(w.text()).not.toMatch(/ROI probado|beneficio probado/i)
  })

  it('un intervalo que incluye 0 se dice así', () => {
    const w = mount(RoiHeadline, { props: { report: estimatedRoi({ net_incremental_contribution: { estimate: 10, ci_low: -50, ci_high: 70, significant: false } }) }, global: { plugins: as() } })
    expect(w.get('[data-test="roi-significance"]').text()).toContain('incluye 0')
  })

  it('varios experimentos o solo conversión: sin cifra monetaria', () => {
    const multiple = mount(RoiHeadline, { props: { report: roiReport({ roi: { ...roiReport().roi, status: 'multiple_experiments', reasons: ['experiments_not_additive'], message: 'Filtre.', experiments: ['exp-a|30', 'exp-a|60'] } }) }, global: { plugins: as() } })
    expect(multiple.find('[data-test="roi-net"]').exists()).toBe(false)
    expect(multiple.get('[data-test="roi-reasons"]').text()).toContain('no son aditivos')
    expect(multiple.get('[data-test="roi-no-figure"]').text()).toContain('No hay una cifra única de ROI')
    expect(multiple.get('[data-test="roi-multiple"]').text()).toContain('exp-a · 30 días')
    expect(multiple.get('[data-test="roi-multiple"]').text()).toContain('exp-a · 60 días')
    const conversion = mount(RoiHeadline, { props: { report: roiReport({ roi: { ...roiReport().roi, status: 'incremental_conversion_only', reasons: ['contribution_not_estimable'], message: 'Solo conversión.' } }) }, global: { plugins: as() } })
    expect(conversion.find('[data-test="roi-net"]').exists()).toBe(false)
  })

  it('solo conversión (contribución monetaria no estimable): muestra el incremento de CONVERSIÓN con su intervalo, el motivo y ninguna cifra en dinero', () => {
    const roi = { ...roiReport().roi, status: 'incremental_conversion_only' as const, reasons: ['zero_variance'], incremental_contribution: null, net_incremental_contribution: null, roi_ratio: null,
      conversion: { rate_treatment: 0.25, rate_control: 0.15, difference: 0.1, ci_low: 0.01, ci_high: 0.19, significant: true },
      message: 'Hay estimación de la tasa de conversión, pero no de la contribución.' }
    const w = mount(RoiHeadline, { props: { report: roiReport({ roi }) }, global: { plugins: as('admin') } })
    expect(w.get('[data-test="roi-status"]').text()).toBe('Solo conversión estimada')
    expect(w.get('[data-test="roi-conversion"]').text()).toContain('diferencia')
    expect(w.get('[data-test="roi-conversion"]').text()).toContain('No es una cifra en dinero')
    expect(w.get('[data-test="roi-reasons"]').text()).toContain('No hay variación en la contribución')
    expect(w.get('[data-test="roi-no-figure"]').text()).toContain('la contribución monetaria no es estimable')
    expect(w.find('[data-test="roi-net"]').exists()).toBe(false)
    expect(w.find('[data-test="figure"]').exists()).toBe(false)
    expect(w.get('[data-test="roi-unproven"]').text()).toBe('No demostrado')
  })

  it('cola pesada: el texto de «intervalo indicativo» acompaña al intervalo del neto', () => {
    const w = mount(RoiHeadline, { props: { report: estimatedRoi({ reasons: ['heavy_tailed_contribution'] }) }, global: { plugins: as('admin') } })
    expect(w.get('[data-test="roi-net"] [data-test="roi-heavy-tail"]').text()).toContain('solo indicativo')
    expect(w.get('[data-test="roi-reasons"]').text()).toContain('Compradores muy concentrados')
    const sin = mount(RoiHeadline, { props: { report: estimatedRoi() }, global: { plugins: as('admin') } })
    expect(sin.find('[data-test="roi-heavy-tail"]').exists()).toBe(false)
  })

  it('quien no aprueba recibe un ROI reducido: estado y mensaje, sin motivos, conversión ni cifras, y nada del experimento', () => {
    const reduced = { status: 'incremental_estimated' as const, label: 'x', proven: false, causal_claims: 0, currency: 'USD', value: null, message: 'El detalle del análisis por grupos solo está disponible para quien puede aprobar (decisions.approve).' }
    const w = mount(RoiHeadline, { props: { report: roiReport({ roi: reduced }) }, global: { plugins: as('viewer') } })
    expect(w.get('[data-test="roi-status"]').text()).toBe('Incremento estimado (no demostrado)')
    expect(w.get('[data-test="roi-unproven"]').text()).toBe('No demostrado')
    expect(w.find('[data-test="roi-reasons"]').exists()).toBe(false)
    expect(w.find('[data-test="roi-conversion"]').exists()).toBe(false)
    expect(w.find('[data-test="roi-net"]').exists()).toBe(false)
    expect(w.get('[data-test="roi-no-figure"]').text()).toContain('No se muestra una cifra neta')
    expect(w.text().toLowerCase()).not.toContain('control')
  })

  it('con costo de contacto desconocido no hay neto', () => {
    const w = mount(RoiHeadline, { props: { report: estimatedRoi({ net_incremental_contribution: null, roi_ratio: null, reasons: ['contact_cost_unknown'] }) }, global: { plugins: as() } })
    expect(w.find('[data-test="roi-net"]').exists()).toBe(false)
    expect(w.get('[data-test="roi-reasons"]').text()).toContain('contactos sin costo configurado')
  })

  it('muestra el aviso de datos de demostración o simulados junto al ROI', () => {
    const w = mount(RoiHeadline, { props: { report: roiReport({ roi: { ...roiReport().roi, data_origin_warning: 'Datos de demostración o simulados: los importes de este informe NO son resultados reales.' } }) }, global: { plugins: as() } })
    expect(w.get('[data-test="roi-origin-warning"]').text()).toContain('NO son resultados reales')
  })
})

describe('Cuatro tipos de valor, sin total', () => {
  const text = (w: ReturnType<typeof mount>) => w.text().replace(/\s|\u00a0/g, '')

  it('observado y asociado van en columnas separadas y la oportunidad potencial, por tipo; ninguna suma aparece en ninguna parte', () => {
    const w = mount(ValueKindColumns, { props: { report: roiReport() }, global: { plugins: as() } })
    expect(w.get('[data-test="col-observed"]').text()).toContain('300')
    expect(w.get('[data-test="col-associated"]').text()).toContain('200')
    const t = text(w)
    expect(t).not.toMatch(/1\.?500/) // observado + asociado + 1000
    expect(t).not.toMatch(/\b500,00/) // observado + asociado
    expect(w.text()).not.toMatch(/\bTotal\b/)
    expect(w.text()).toContain('no hay total')
    expect(w.get('[data-test="col-potential"]').text()).toContain('Oportunidad potencial, atribución no demostrada')
    expect(w.get('[data-test="col-associated"]').text()).toContain('No es beneficio atribuible')
    expect(w.get('[data-test="observed-not-additive"]').text()).toContain('No se suma con las otras columnas')
    expect(w.get('[data-test="associated-not-additive"]').text()).toContain('Lo asociado ya está dentro de lo observado')
    expect(w.get('[data-test="potential-not-additive"]').text()).toContain('ni entre tipos ni entre horizontes')
  })

  it('observado: clientes y líneas de venta distintas; asociado: SOLO lo posterior a la acción; el detalle por recomendación va aparte y rotulado no aditivo', () => {
    const w = mount(ValueKindColumns, { props: { report: roiReport() }, global: { plugins: as() } })
    expect(w.get('[data-test="observed-counts"]').text()).toContain('4 clientes · 5 líneas de venta · 12 unidades')
    expect(w.get('[data-test="col-observed"]').text()).toContain('ventana completa')
    expect(w.get('[data-test="associated-counts"]').text()).toContain('2 recomendaciones atendidas con compra posterior · 2 clientes · 5 unidades')
    expect(w.get('[data-test="col-associated"]').text()).toContain('SOLO lo posterior a la acción registrada')
    expect(w.get('[data-test="col-associated"]').text()).toContain('Ventas netas posteriores a la acción')
    const detail = w.get('[data-test="observed-per-rec"]')
    expect(detail.text()).toContain('no aditivo')
    expect(detail.text()).toContain('no es un total')
    expect(detail.text()).toContain('5 recomendaciones · 14 unidades')
    // El total deduplicado (12 unidades, 800) no es la suma por recomendación (14, 900): conviven rotulados, nunca uno en lugar del otro.
    expect(w.get('[data-test="observed-counts"]').text()).not.toContain('14')
  })

  it('el detalle por recomendación no se muestra si la API no lo trae', () => {
    const base = roiReport()
    const report = roiReport({ summary: { ...base.summary, observed: { ...base.summary.observed, per_recommendation: undefined as never } } })
    const w = mount(ValueKindColumns, { props: { report }, global: { plugins: as() } })
    expect(w.find('[data-test="observed-per-rec"]').exists()).toBe(false)
  })

  it('avisa los resultados sin detalle de líneas (pueden contar dos veces una venta)', () => {
    const base = roiReport()
    const report = roiReport({ summary: { ...base.summary, observed: { ...base.summary.observed, rows_without_line_detail: 3 }, associated: { ...base.summary.associated, rows_without_line_detail: 1 } } })
    const w = mount(ValueKindColumns, { props: { report }, global: { plugins: as() } })
    expect(w.get('[data-test="observed-no-detail"]').text()).toContain('3 resultados sin detalle de líneas')
    expect(w.get('[data-test="associated-no-detail"]').text()).toContain('1 resultados sin detalle de líneas')
    const limpio = mount(ValueKindColumns, { props: { report: roiReport() }, global: { plugins: as() } })
    expect(limpio.find('[data-test="observed-no-detail"]').exists()).toBe(false)
  })

  it('H1 (heterogéneo): la oportunidad potencial se muestra POR TIPO y ni la suma de contribuciones (267,52) ni la de brutos aparecen', () => {
    const w = mount(ValueKindColumns, { props: { report: roiReport() }, global: { plugins: as() } })
    const kinds = w.findAll('[data-test="potential-kind"]')
    expect(kinds.map((k) => k.attributes('data-kind'))).toEqual(['contact', 'recovery', 'replenishment', 'budget_constraint'])
    const byKind = Object.fromEntries(kinds.map((k) => [k.attributes('data-kind'), k]))
    expect(byKind.contact!.get('[data-test="potential-contribution"]').text()).toContain('27,52')
    expect(byKind.replenishment!.get('[data-test="potential-contribution"]').text()).toContain('160')
    expect(byKind.budget_constraint!.get('[data-test="potential-contribution"]').text()).toContain('80')
    // Reposición en dos horizontes: dos celdas con sus propias cifras (160 a 30 días y 50 a 60 días), nunca 210.
    const cells = byKind.replenishment!.findAll('[data-test="potential-cell"]')
    expect(cells.map((c: DOMWrapper<Element>) => c.attributes('data-horizon'))).toEqual(['30', '60'])
    expect(cells[0]!.get('[data-test="potential-contribution"]').text()).toContain('160')
    expect(cells[1]!.get('[data-test="potential-contribution"]').text()).toContain('50')
    expect(cells[0]!.text()).toContain('Horizonte de 30 días · 3 recomendaciones')
    // Ni formateada ni cruda: la reposición no trae un total entre sus horizontes (contribución 160 + 50 = 210; bruto 700 + 200 = 900).
    for (const sum of ['210', '900']) expect(byKind.replenishment!.text().replace(/\s|\u00a0/g, ''), `reposición ${sum}`).not.toMatch(new RegExp(`(?<![\\d.,])${sum}(?![\\d])`))
    const t = text(w)
    for (const sum of ['267,52', '1.760,50', '1.120,50', '1760,5', '267.52', '210,00']) expect(t, sum).not.toContain(sum.replace(/\s/g, ''))
    expect(w.get('[data-test="potential-per-kind"]').text()).toContain('sin total entre tipos')
  })

  it('un tipo sin costo confiable es «No estimado» con su aviso, y el de nivel de cliente se rotula aparte (no es un pronóstico)', () => {
    const w = mount(ValueKindColumns, { props: { report: roiReport() }, global: { plugins: as() } })
    const recovery = w.get('[data-test="potential-kind"][data-kind="recovery"]')
    expect(recovery.get('[data-test="potential-contribution"]').text()).toContain('No estimado')
    expect(recovery.get('[data-test="potential-unknown"]').text()).toContain('2 recomendaciones sin costo confiable')
    expect(recovery.get('[data-test="potential-gross"]').attributes('data-basis')).toBe('customer_level')
    expect(recovery.get('[data-test="potential-gross"]').text()).toContain('Monto de nivel de cliente')
    expect(recovery.get('[data-test="potential-customer-level"]').text()).toContain('no es un pronóstico')
    const contact = w.get('[data-test="potential-kind"][data-kind="contact"]')
    expect(contact.get('[data-test="potential-gross"]').text()).toContain('Valor bruto esperado')
    expect(contact.find('[data-test="potential-customer-level"]').exists()).toBe(false)
  })

  it('si la API no entrega las celdas de la oportunidad potencial, lo dice y no muestra ningún total', () => {
    const base = roiReport()
    const report = roiReport({ summary: { ...base.summary, potential: { label: 'x', note: 'y' } } })
    const w = mount(ValueKindColumns, { props: { report }, global: { plugins: as() } })
    expect(w.get('[data-test="potential-unavailable"]').text()).toContain('no la entregó por celdas')
    expect(w.find('[data-test="potential-kind"]').exists()).toBe(false)
    expect(text(w)).not.toContain('267,52')
  })

  it('los pares cliente × producto con contribución desconocida se cuentan aparte, no como cero', () => {
    const w = mount(ValueKindColumns, { props: { report: roiReport() }, global: { plugins: as() } })
    expect(w.get('[data-test="observed-unknown"]').text()).toContain('2 pares cliente × producto sin costo confiable')
    expect(w.find('[data-test="associated-unknown"]').exists()).toBe(false)
  })

  it('sin ningún par con costo confiable observado y asociado son «No estimado», no 0', () => {
    const base = roiReport()
    const report = roiReport({ summary: { ...base.summary,
      observed: observedBlock({ customers: 3, purchase_lines: 5, units: 12, net_amount: 800, contribution_known: 0, pairs_with_unknown_contribution: 5 }),
      associated: associatedBlock({ recommendations: 2, customers: 2, units: 5, net_amount: 400, contribution_known: 0, pairs_with_unknown_contribution: 2 }) } })
    const w = mount(ValueKindColumns, { props: { report }, global: { plugins: as() } })
    for (const col of ['col-observed', 'col-associated']) expect(w.get(`[data-test="${col}"] p.text-xl`).text(), col).toContain('No estimado')
  })

  it('solo conversión, varios experimentos o detalle restringido: la columna del incremento lo dice y no inventa una cifra', () => {
    const conv = mount(ValueKindColumns, { props: { report: roiReport({ roi: { ...roiReport().roi, status: 'incremental_conversion_only', reasons: ['buyers_below_min_per_arm'] } }) }, global: { plugins: as('admin') } })
    expect(conv.get('[data-test="incremental-conversion-only"]').text()).toBe('Solo se estimó la conversión')
    expect(conv.find('[data-test="incremental-interval"]').exists()).toBe(false)
    const multi = mount(ValueKindColumns, { props: { report: roiReport({ roi: { ...roiReport().roi, status: 'multiple_experiments' } }) }, global: { plugins: as('admin') } })
    expect(multi.get('[data-test="incremental-multiple"]').text()).toContain('Varios experimentos')
    const restricted = mount(ValueKindColumns, { props: { report: roiReport({ roi: { status: 'incremental_estimated', label: 'x', proven: false, causal_claims: 0, currency: 'USD', value: null, message: 'm' } }) }, global: { plugins: as('viewer') } })
    expect(restricted.get('[data-test="incremental-restricted"]').text()).toContain('solo lo ve quien puede aprobar')
    expect(restricted.find('[data-test="incremental-interval"]').exists()).toBe(false)
  })

  it('cola pesada: el intervalo del incremento lleva el aviso de que es solo indicativo', () => {
    const w = mount(ValueKindColumns, { props: { report: estimatedRoi({ reasons: ['heavy_tailed_contribution'] }) }, global: { plugins: as('admin') } })
    expect(w.get('[data-test="col-incremental"] [data-test="incremental-heavy-tail"]').text()).toContain('solo indicativo')
    const sin = mount(ValueKindColumns, { props: { report: estimatedRoi() }, global: { plugins: as('admin') } })
    expect(sin.find('[data-test="incremental-heavy-tail"]').exists()).toBe(false)
  })

  it('sin experimento el incremento es «Sin estimación»; con estimación aparece con su intervalo', () => {
    const none = mount(ValueKindColumns, { props: { report: roiReport() }, global: { plugins: as() } })
    expect(none.get('[data-test="incremental-none"]').text()).toBe('Sin estimación')
    expect(none.find('[data-test="incremental-interval"]').exists()).toBe(false)
    const some = mount(ValueKindColumns, { props: { report: estimatedRoi() }, global: { plugins: as() } })
    expect(some.get('[data-test="col-incremental"]').text()).toContain('880')
    expect(some.get('[data-test="incremental-interval"]').text()).toContain('40,1')
  })
})

describe('Experimentos (intención de tratamiento)', () => {
  it('con estimación: tamaños, tasas e intervalo; con muestra insuficiente: solo el motivo, sin diferencia ni intervalo', () => {
    const w = mount(ExperimentCards, { props: { experiments: [experiment(), experiment({ experiment_key: 'otro', status: 'insufficient_sample', sample: { treatment: 5, control: 3, treatment_converted: 1, control_converted: 0 }, conversion: { rate_treatment: 0.2, rate_control: 0, difference: null, ci_low: null, ci_high: null, significant: null }, incremental: null })], currency: 'USD' }, global: { plugins: as('admin') } })
    const cards = w.findAll('[data-test="experiment"]')
    expect(cards).toHaveLength(2)
    expect(cards[0]!.get('[data-test="itt-conversion"]').text()).toContain('diferencia')
    expect(cards[0]!.text()).toContain('120 en tratamiento y 60 en control')
    expect(cards[0]!.get('[data-test="itt-incremental"]').text()).toContain('880')
    expect(cards[1]!.find('[data-test="itt-conversion"]').exists()).toBe(false)
    expect(cards[1]!.get('[data-test="itt-no-estimate"]').text()).toContain('no hay base para afirmar un incremento')
    expect(cards[1]!.text()).toContain('Muestra insuficiente')
  })

  it('contribución monetaria no estimable (pocos compradores por brazo) con la conversión sí estimada: dice el mínimo y no inventa cifra', () => {
    const e = experiment({ incremental: null, min_buyers_per_arm: 30, contribution: { status: 'not_estimable', reason: 'buyers_below_min_per_arm', buyers: { treatment: 12, control: 3 } } })
    const w = mount(ExperimentCards, { props: { experiments: [e], currency: 'USD' }, global: { plugins: as('admin') } })
    expect(w.find('[data-test="itt-conversion"]').exists()).toBe(true)
    expect(w.find('[data-test="itt-incremental"]').exists()).toBe(false)
    expect(w.get('[data-test="itt-no-contribution"]').text()).toContain('al menos 30 compradores por grupo')
    expect(w.get('[data-test="itt-buyers"]').text()).toContain('12 en tratamiento y 3 en control')
    expect(w.find('[data-test="figure"]').exists()).toBe(false)
  })

  it('varianza nula o presupuesto del bootstrap excedido: la conversión se estima, la contribución no, con su motivo y sin cifra en dinero', () => {
    for (const [reason, text] of [['zero_variance', 'no hay variación en la contribución'], ['bootstrap_budget_exceeded', 'excede el presupuesto de cálculo']] as const) {
      const w = mount(ExperimentCards, { props: { experiments: [experiment({ incremental: null, contribution: { status: 'not_estimable', reason } })], currency: 'USD' }, global: { plugins: as('admin') } })
      expect(w.find('[data-test="itt-conversion"]').exists(), reason).toBe(true)
      expect(w.get('[data-test="itt-no-contribution"]').text().toLowerCase(), reason).toContain(text)
      expect(w.find('[data-test="itt-incremental"]').exists(), reason).toBe(false)
    }
  })

  it('con estimación monetaria: intervalo percentil bootstrap y, si los compradores están concentrados, el aviso de «solo indicativo» junto al intervalo', () => {
    const heavy = experiment({ contribution: { status: 'estimated', reason: null, interval: { method: 'bootstrap_percentile', resamples: 2000, seed: 7 }, tail_diagnostic: { buyer_cv: 2.1, largest_buyer_share_of_arm: 0.4, heavy_tailed: true, note: 'Cola pesada.' } } })
    const w = mount(ExperimentCards, { props: { experiments: [heavy, experiment({ experiment_key: 'otro' })], currency: 'USD' }, global: { plugins: as('admin') } })
    const [a, b] = w.findAll('[data-test="experiment"]')
    expect(a!.get('[data-test="itt-interval-method"]').text()).toContain('bootstrap percentil (2.000 remuestreos')
    expect(a!.get('[data-test="itt-heavy-tail"]').text()).toContain('solo indicativo')
    expect(b!.find('[data-test="itt-heavy-tail"]').exists()).toBe(false)
  })

  it('desajuste del cociente de muestras (SRM): aviso visible; con proporción compatible, nada', () => {
    const w = mount(ExperimentCards, { props: { experiments: [experiment({ sample_ratio: { status: 'mismatch', expected_control_fraction: 0.25, observed_control_share: 0.4, p_value: 0.0003 } }), experiment({ experiment_key: 'ok' })], currency: 'USD' }, global: { plugins: as('admin') } })
    const [a, b] = w.findAll('[data-test="experiment"]')
    expect(a!.get('[data-test="itt-srm"]').text()).toContain('no coincide con la fracción registrada')
    expect(b!.find('[data-test="itt-srm"]').exists()).toBe(false)
  })

  it('unidades sin experimento atribuible (clave null) y unidades descartadas: se dicen, sin análisis ni cifras', () => {
    const e: OutcomeExperiment = { experiment_key: null, horizon_days: 30, status: 'not_analysed', dropped_units: { repeat_customer: { treatment: 3, control: 1 }, unverified_assignment: { treatment: 0, control: 0 } }, note: 'Unidades con grupo asignado que no se pudieron atribuir a un experimento.' }
    const w = mount(ExperimentCards, { props: { experiments: [e], currency: 'USD' }, global: { plugins: as('admin') } })
    const card = w.get('[data-test="experiment"]')
    expect(card.text()).toContain('Unidades sin experimento atribuible · 30 días')
    expect(card.text()).toContain('Sin experimento atribuible')
    expect(card.find('[data-test="itt-conversion"]').exists()).toBe(false)
    expect(card.find('[data-test="itt-no-estimate"]').exists()).toBe(false)
    expect(card.get('[data-test="itt-dropped"]').text()).toContain('Descartados por cliente repetido: 3 en tratamiento y 1 en control')
    expect(card.get('[data-test="itt-dropped"]').findAll('li')).toHaveLength(1) // solo los grupos con unidades
  })

  it('sin experimentos lo dice', () => {
    const w = mount(ExperimentCards, { props: { experiments: [], currency: 'USD' }, global: { plugins: as('admin') } })
    expect(w.find('[data-test="no-experiments"]').exists()).toBe(true)
  })

  it('avisa de la contaminación del control', () => {
    const w = mount(ExperimentCards, { props: { experiments: [experiment({ contamination: { control_units_with_registered_action: 4 } })], currency: 'USD' }, global: { plugins: as('admin') } })
    expect(w.text()).toContain('4 clientes de control tienen una acción registrada')
  })
})

describe('Embudo, costos y desglose', () => {
  it('las tasas nulas son «No estimada» y la respuesta se rotula como asociación', () => {
    const funnel = { ...roiReport().summary.funnel, action_rate: null, response_rate: null }
    const w = mount(RoiFunnel, { props: { funnel }, global: { plugins: as() } })
    expect(w.get('[data-test="action-rate"]').text()).toBe('No estimada')
    expect(w.get('[data-test="response-rate"]').text()).toContain('No estimada')
    expect(w.text()).toContain('Asociación temporal, no efecto de la acción')
    expect(w.get('[data-test="forecast-pending"]').text()).toContain('Previsto, aún sin medir')
  })

  it('el embudo no revela cuántas se retienen para medición', () => {
    const w = mount(RoiFunnel, { props: { funnel: { ...roiReport().summary.funnel, control_held_out: 12 } }, global: { plugins: as('admin') } })
    expect(w.text().toLowerCase()).not.toContain('control')
    expect(w.text()).not.toContain('12')
  })

  it('M1: quien no aprueba ve las identificadas y medidas que puede listar (69/64), quien aprueba todas (135/129); nada se resta', () => {
    const funnel = { ...roiReport().summary.funnel, identified: 135, actionable: 69, measured: 129, measured_actionable: 64, control_held_out: 57 }
    const viewer = mount(RoiFunnel, { props: { funnel }, global: { plugins: as('viewer') } })
    expect(viewer.get('[data-test="identified"]').text()).toBe('69')
    expect(viewer.get('[data-test="measured"]').text()).toBe('64')
    const admin = mount(RoiFunnel, { props: { funnel, canApprove: true }, global: { plugins: as('admin') } })
    expect(admin.get('[data-test="identified"]').text()).toBe('135')
    expect(admin.get('[data-test="measured"]').text()).toBe('129')
    expect(viewer.text()).not.toContain('135')
    expect(viewer.text()).not.toContain('57')
  })

  it('costo de contacto desconocido no es cero; uso sin precios no se convierte en dinero', () => {
    const costs = { contact: { total: 0, actions: 3, actions_cost_unknown: 3, note: '' }, platform_usage: roiReport().costs.platform_usage }
    const w = mount(RoiCosts, { props: { costs, currency: 'USD' }, global: { plugins: as() } })
    expect(w.get('[data-test="contact-cost"]').text()).toContain('Costo de contacto no configurado')
    expect(w.get('[data-test="contact-partial"]').text()).toContain('3 acciones sin costo configurado')
    expect(w.get('[data-test="platform-usage"]').text()).toContain('Costo de uso no estimado')
    expect(w.get('[data-test="usage-reason"]').text()).toContain('no configuró precios de uso')
    expect(w.get('[data-test="platform-usage"]').text()).toContain('Sin precio')
    expect(w.get('[data-test="platform-usage"]').find('[data-test="figure"]').exists()).toBe(false)
  })

  it('con precios configurados muestra el costo de uso', () => {
    const usage = { ...roiReport().costs.platform_usage, metrics: [{ metric: 'forecast_runs', unit: 'corridas', quantity: 12, unit_price: 0.5, cost: 6 }], total_cost: 6, unpriced_metrics: [], reason: null }
    const w = mount(RoiCosts, { props: { costs: { contact: { total: 8, actions: 4, actions_cost_unknown: 0, note: '' }, platform_usage: usage }, currency: 'USD' }, global: { plugins: as() } })
    expect(w.get('[data-test="platform-usage"]').text()).toMatch(/6,00/)
    expect(w.find('[data-test="contact-partial"]').exists()).toBe(false)
  })

  it('el desglose usa una columna por tipo de valor (observado, asociado; sin potencial) y trata como «No estimado» lo que no tiene pares con costo confiable', () => {
    const row = kindRow('contact', 4, {
      observed: observedBlock({ customers: 2, purchase_lines: 3, units: 6, net_amount: 90, contribution_known: 0, pairs_with_unknown_contribution: 2 }),
      associated: associatedBlock({ recommendations: 1, customers: 1, units: 2, net_amount: 200, contribution_known: 0, pairs_with_unknown_contribution: 0 }),
    })
    const w = mount(RoiBreakdown, { props: { breakdown: { group_by: 'kind', rows: [row] }, currency: 'USD' }, global: { plugins: as() } })
    const cells = w.get('[data-test="breakdown-row"]').findAll('td')
    expect(cells).toHaveLength(5) // identificadas, atendidas, medidas, observado, asociado: la oportunidad potencial no va en el desglose
    expect(cells[3]!.text()).toContain('No estimado') // observado: ningún par con costo confiable
    expect(cells[4]!.text()).toContain('0,00') // asociado: todos los pares con costo confiable y contribución 0 de verdad
    expect(w.find('[data-test="breakdown-potential"]').exists()).toBe(false)
    expect(w.get('[data-test="breakdown-row"] th').text()).toBe('Contacto')
  })

  it('M2: una suma parcial del desglose dice cuántos pares quedaron fuera', () => {
    const rows = [
      kindRow('contact', 4, { observed: observedBlock({ units: 6, net_amount: 200, contribution_known: 70, pairs_with_unknown_contribution: 2 }) }),
      kindRow('recovery', 2, { observed: observedBlock({ units: 2, net_amount: 40, contribution_known: 10, pairs_with_unknown_contribution: 0 }) }),
    ]
    const w = mount(RoiBreakdown, { props: { breakdown: { group_by: 'kind', rows }, currency: 'USD' }, global: { plugins: as() } })
    const [contact, recovery] = w.findAll('[data-test="breakdown-row"]')
    expect(contact!.get('[data-test="breakdown-observed"] [data-test="partial"]').text()).toContain('2 sin costo confiable')
    expect(contact!.get('[data-test="breakdown-observed"]').text()).toContain('70')
    expect(recovery!.find('[data-test="breakdown-observed"] [data-test="partial"]').exists()).toBe(false)
  })

  it('las filas del desglose no son aditivas: lo dice, y con cualquier agrupación no hay oportunidad potencial ni totales', () => {
    for (const group_by of ['kind', 'horizon', 'segment', 'owner'] as const) {
      const w = mount(RoiBreakdown, { props: { breakdown: { group_by, rows: [kindRow('30', 5, { potential: { cells: [cell('contact', 30, 4, 0, 267.52, 1000)] } })] }, currency: 'USD' }, global: { plugins: as() } })
      expect(w.get('[data-test="breakdown-not-additive"]').text(), group_by).toContain('no se suman entre sí')
      expect(w.find('[data-test="breakdown-potential"]').exists(), group_by).toBe(false)
      expect(w.text().replace(/\s|\u00a0/g, ''), group_by).not.toContain('267,52')
    }
  })

  it('L6: la tabla desplazable es una región con nombre y foco; la columna de acciones tiene nombre accesible', () => {
    const w = mount(RoiBreakdown, { props: { breakdown: roiReport().breakdown, currency: 'USD' }, global: { plugins: as() } })
    const region = w.get('[data-test="breakdown"]')
    expect(region.attributes('tabindex')).toBe('0')
    expect(region.attributes('role')).toBe('region')
    expect(region.attributes('aria-label')).toBeTruthy()
  })
})

describe('Resultados medidos', () => {
  const page = (rows: ReturnType<typeof outcome>[]) => ({ data: rows, meta: { current_page: 1, per_page: 15, total: rows.length, last_page: 1 } })
  const mountTable = async (rows = [outcome()], role: TenantRole = 'analyst') => {
    api.list.mockResolvedValue(page(rows))
    const w = mount(OutcomesTable, { props: { filters: {}, currency: 'USD' }, global: { plugins: as(role) } })
    await flushPromises()
    return w
  }

  it('muestra ventana con corte de conocimiento, acción, evento observado, contribución, costo de contacto y clasificación con lo que SÍ y NO afirma', async () => {
    const w = await mountTable([outcome()], 'admin')
    expect(w.text()).toContain('ventas conocidas hasta')
    expect(w.text()).toContain('Contactada')
    expect(w.text()).toContain('Compra')
    expect(w.get('[data-test="classification"]').text()).toBe('Asociado temporalmente')
    expect(w.text()).toContain('NO se puede decir que la acción la causó')
    expect(w.text()).toContain('2 líneas tardías no contadas')
    expect(w.text()).toContain('cccccccccccc')
    expect(w.get('[data-test="rec-link"]').text()).toBe('#21')
  })

  it('M1: la nota de la API sobre el experimento solo se muestra a quien aprueba; el resto lee un texto propio que no habla de control', async () => {
    const apiNote = 'Pertenece a un experimento con grupo de control analizado por intención de tratamiento: el incremento se estima a nivel del experimento.'
    const row = outcome({ classification: 'temporally_associated', classification_note: apiNote })
    const admin = await mountTable([row], 'admin')
    expect(admin.get('[data-test="classification-note"]').text()).toContain('Pertenece a un experimento')
    for (const role of ['viewer', 'analyst'] as const) {
      const w = await mountTable([row], role)
      const note = w.get('[data-test="classification-note"]').text()
      expect(note, role).toContain('Sin contrafactual')
      expect(w.text().toLowerCase(), role).not.toContain('experimento')
      expect(w.text().toLowerCase(), role).not.toContain('control')
    }
  })

  it('la contribución nula con costo no confiable es «Costo no confiable» con sus motivos, nunca 0', async () => {
    const w = await mountTable([outcome({ contribution: null, contribution_status: 'unknown', contribution_reasons: ['no_cost_in_sales_lines'] })])
    expect(w.text()).toContain('Costo no confiable')
    expect(w.text()).toContain('Las ventas no traen costo')
  })

  it('sin compra no hay contribución que mostrar y se dice así', async () => {
    const w = await mountTable([outcome({ observed_event: 'no_purchase', observed_units: 0, observed_net_amount: 0, contribution: 0, contribution_status: 'not_applicable_no_purchase', classification: 'observed' })])
    expect(w.get('[data-test="no-purchase"]').text()).toBe('Sin compra')
  })

  it('un costo de contacto desconocido es «Costo no configurado», no 0', async () => {
    const w = await mountTable([outcome({ contact_cost: null })])
    expect(w.text()).toContain('Costo no configurado')
  })

  it('las rechazadas y caducadas también se miden y se rotulan', async () => {
    const w = await mountTable([outcome({ id: 2, action_kind: 'rejected', observed_event: 'no_purchase', contribution: 0, contribution_status: 'not_applicable_no_purchase', classification: 'observed' }), outcome({ id: 3, action_kind: 'expired', classification: 'observed', contribution_status: 'known' })])
    expect(w.text()).toContain('Rechazada')
    expect(w.text()).toContain('Caducada')
  })

  it('la acción «retenida para medición» solo se ofrece como filtro a quien aprueba', async () => {
    const analyst = await mountTable([outcome()], 'analyst')
    expect(analyst.findAll('#oc-action option').map((o) => o.text())).not.toContain('Retenida para medición')
    const admin = await mountTable([outcome()], 'admin')
    expect(admin.findAll('#oc-action option').map((o) => o.text())).toContain('Retenida para medición')
  })

  it('una fila retenida no muestra el grupo ni la palabra control, solo «Retenida para medición»', async () => {
    const w = await mountTable([outcome({ action_kind: 'control', action_label: 'Grupo de control (retenida a propósito)', experiment_group: 'control', observed_event: 'no_purchase', contribution: 0, contribution_status: 'not_applicable_no_purchase', classification: 'observed', classification_note: 'Se observó lo ocurrido en la ventana. No se atribuye a ninguna acción.' })], 'admin')
    const text = w.text()
    expect(text).toContain('Retenida para medición')
    expect(text.toLowerCase()).not.toContain('control')
    expect(text.toLowerCase()).not.toContain('experiment')
  })

  it('observado es la ventana completa y lo posterior a la acción va aparte (único asociable a la acción), con su contribución y su costo no confiable', async () => {
    const w = await mountTable([outcome({
      observed_units: 6, observed_net_amount: 210, contribution: 70,
      after_action: { units: 4, net_amount: 140, contribution: null, contribution_status: 'unknown', contribution_reasons: ['no_cost_in_sales_lines'], purchase_lines: 1 },
    })])
    const block = w.get('[data-test="after-action"]')
    expect(w.text()).toContain('ventana completa')
    expect(block.text()).toContain('Tras la acción registrada')
    expect(block.text()).toContain('4 unidades')
    expect(block.text()).toContain('140')
    // La contribución posterior a la acción tiene costo no confiable: «Costo no confiable», nunca 0.
    expect(block.get('[data-test="after-action-contribution"]').text()).toContain('Costo no confiable')
    expect(block.get('[data-test="after-action-contribution"]').text()).not.toMatch(/\b0,00\b/)
  })

  it('sin acción registrada (after_action null) no hay bloque posterior; con acción pero sin compra posterior se dice así', async () => {
    const none = await mountTable([outcome({ after_action: null, classification: 'observed' })])
    expect(none.find('[data-test="after-action"]').exists()).toBe(false)
    const nopurchase = await mountTable([outcome({ after_action: { units: 0, net_amount: 0, contribution: 0, contribution_status: 'not_applicable_no_purchase', contribution_reasons: [], purchase_lines: 0 }, classification: 'observed' })])
    expect(nopurchase.get('[data-test="after-action-none"]').text()).toBe('Sin compra posterior a la acción')
    expect(nopurchase.find('[data-test="after-action-contribution"]').exists()).toBe(false)
  })

  it('las devoluciones posteriores a la ventana (ya descontadas) y las devueltas tardías no descontadas se dicen en «Qué afirma y qué no»', async () => {
    const w = await mountTable([outcome({ evidence: { source: { fingerprint: 'c'.repeat(64), late_lines_not_counted: 1, late_returned_units_not_counted: 2.5 }, observation: { returns_after_window: { lines: 1, units: 3, net_amount: 45 } } } })])
    expect(w.get('[data-test="late-returned"]').text()).toContain('2,5 unidades devueltas tardías no descontadas')
    expect(w.get('[data-test="returns-after-window"]').text()).toContain('ya descontadas: 3 unidades (1 líneas)')
    const clean = await mountTable([outcome()])
    expect(clean.find('[data-test="late-returned"]').exists()).toBe(false)
    expect(clean.find('[data-test="returns-after-window"]').exists()).toBe(false)
  })

  it('las filas nunca son incrementales: el filtro de clasificación ofrece solo observado y asociado temporalmente', async () => {
    const w = await mountTable()
    expect(w.findAll('#oc-class option').map((o) => o.text())).toEqual(['Todos', 'Observado', 'Asociado temporalmente'])
  })

  it('los filtros viajan al servidor', async () => {
    const w = await mountTable()
    await w.get('#oc-class').setValue('observed')
    await w.get('#oc-action').setValue('expired')
    await flushPromises()
    expect(api.list.mock.calls.at(-1)![0]).toMatchObject({ classification: 'observed', action_kind: 'expired', page: 1 })
  })

  it('sin resultados lo dice y explica que lo demás es previsto', async () => {
    const w = await mountTable([])
    expect(w.text()).toContain('Aún no hay resultados medidos')
    expect(w.text()).toContain('Lo demás es previsto')
  })
})
