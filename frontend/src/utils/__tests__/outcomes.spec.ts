import { describe, expect, it } from 'vitest'
import { existsSync, readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import type { DecisionOutcome, OutcomeRoi, OutcomeRoiReport } from '@/api/types'
import es from '@/locales/es.json'
import { associatedBlock, cell, observedBlock, outcome, perRecommendation, roiReport } from '@/test/enterprise'
import {
  ACTION_KINDS, CLASSIFICATION_ORDER, OBSERVED_EVENTS, ROW_CLASSIFICATIONS, actionLabelKey, classificationCounts, classificationTone, experimentEstimated, funnelCounts, knownContribution,
  latestKnowledgeCutoff, potentialByKind, reportPeriod, roiView, valueKinds,
} from '../outcomes'

function report(over: { roi?: Partial<OutcomeRoi>; summary?: Partial<OutcomeRoiReport['summary']> } = {}): Pick<OutcomeRoiReport, 'summary' | 'roi' | 'filters'> {
  const base = roiReport()
  return {
    roi: { ...base.roi, ...over.roi },
    summary: { ...base.summary, ...over.summary },
    filters: base.filters,
  }
}

describe('valueKinds: cuatro tipos de valor, la oportunidad potencial por tipo y horizonte y ningún total', () => {
  it('devuelve potencial (por tipo y horizonte), observado, asociado e incremental por separado, sin campo de total ni suma', () => {
    const kinds = valueKinds(report())
    expect(Object.keys(kinds).sort()).toEqual(['associated', 'heavyTailed', 'incremental', 'incrementalStatus', 'observed', 'potential'])
    expect(kinds.potential!.map((p) => p.kind)).toEqual(['contact', 'recovery', 'replenishment', 'budget_constraint'])
    expect(kinds.observed.contributionKnown).toBe(300)
    expect(kinds.associated.contributionKnown).toBe(200)
    expect(kinds.incremental).toBeNull()
    expect(JSON.stringify(kinds)).not.toContain('"total"')
  })

  it('HETEROGÉNEO: la oportunidad potencial no se suma entre tipos ni entre horizontes (contacto + reposición + presupuesto = 267,52 y reposición 30 + 60 = 210 no existen)', () => {
    const kinds = valueKinds(report())
    const byKind = Object.fromEntries(kinds.potential!.map((p) => [p.kind, p]))
    expect(byKind.contact!.cells.map((c) => c.contribution)).toEqual([27.52])
    expect(byKind.replenishment!.cells.map((c) => [c.horizonDays, c.contribution])).toEqual([[30, 160], [60, 50]])
    expect(byKind.budget_constraint!.cells.map((c) => c.contribution)).toEqual([80])
    const text = JSON.stringify(kinds)
    // La suma de contribuciones (27,52 + 160 + 80), la de reposición en dos horizontes (160 + 50), las de brutos y el total de otro nivel no se devuelven.
    for (const sum of ['267.52', '1760.5', '1120.5', '210', '1.760,5']) expect(text, sum).not.toContain(sum)
    // Y ningún elemento acumula otro: cada cifra es exactamente la de su celda.
    expect(kinds.potential!.flatMap((p) => p.cells.map((c) => c.grossValue))).toEqual([100.5, 640, 700, 200, 320])
  })

  it('una celda sin ninguna recomendación con costo confiable queda «no estimada» (null), no 0; con alguna, la cifra es parcial y lo dice', () => {
    const kinds = valueKinds(report({ summary: { potential: { cells: [cell('recovery', 30, 2, 2, 0, 640), cell('contact', 30, 4, 1, 20, 100), cell('contact', 60, 3, 0, 0, 90)] } } }))
    const recovery = kinds.potential!.find((p) => p.kind === 'recovery')!.cells[0]!
    expect(recovery.contribution).toBeNull()
    expect(recovery.withoutReliableCost).toBe(2)
    const contact = kinds.potential!.find((p) => p.kind === 'contact')!.cells
    expect(contact[0]).toMatchObject({ horizonDays: 30, contribution: 20, withoutReliableCost: 1 })
    // Un 0 con todas las recomendaciones con costo confiable SÍ es una contribución esperada de 0.
    expect(contact[1]).toMatchObject({ horizonDays: 60, contribution: 0, withoutReliableCost: 0 })
  })

  it('recuperación y riesgo de cliente se rotulan aparte: su monto puede ser una caída observada, no un pronóstico', () => {
    const kinds = valueKinds(report({ summary: { potential: { cells: [cell('contact', 30, 4, 0, 27.52, 100.5), cell('recovery', 30, 2, 0, 10, 640), cell('customer_risk', 30, 1, 1, 0, 90)] } } }))
    expect(kinds.potential!.map((p) => [p.kind, p.grossBasis])).toEqual([['contact', 'forecast'], ['recovery', 'customer_level'], ['customer_risk', 'customer_level']])
  })

  it('las celdas de un tipo salen ordenadas por horizonte y los tipos en el orden fijo, vengan como vengan', () => {
    const kinds = valueKinds(report({ summary: { potential: { cells: [cell('replenishment', 60, 1, 0, 5, 9), cell('scenario', 30, 1, 0, 1, 1), cell('contact', 30, 1, 0, 2, 3), cell('replenishment', 14, 1, 0, 6, 8)] } } }))
    expect(kinds.potential!.map((p) => p.kind)).toEqual(['contact', 'replenishment', 'scenario'])
    expect(kinds.potential![1]!.cells.map((c) => c.horizonDays)).toEqual([14, 60])
  })

  it('sin celdas en la respuesta no hay oportunidad potencial que mostrar (null): no se reconstruye de otro lado ni se suma', () => {
    const base = report()
    expect(potentialByKind({ summary: { ...base.summary, potential: undefined } })).toBeNull()
    expect(potentialByKind({ summary: { ...base.summary, potential: { label: 'x' } } })).toBeNull()
    expect(potentialByKind({ summary: { ...base.summary, potential: { cells: [] } } })).toEqual([])
  })

  it('es tolerante a una API sin experimentos ni control: no supone que existan', () => {
    const base = report()
    const summary = { ...base.summary, by_action_kind: undefined, control_baseline: undefined, supply: undefined }
    expect(() => valueKinds({ ...base, summary })).not.toThrow()
    expect(valueKinds({ ...base, summary }).potential).not.toBeNull()
  })

  it('sin ningún par con costo confiable, observado y asociado son «no estimados» (null), no 0', () => {
    const kinds = valueKinds(report({ summary: {
      observed: observedBlock({ customers: 3, purchase_lines: 3, units: 4, net_amount: 90, contribution_known: 0, pairs_with_unknown_contribution: 3 }),
      associated: associatedBlock({ recommendations: 1, customers: 1, units: 2, net_amount: 50, contribution_known: 0, pairs_with_unknown_contribution: 1 }),
    } }))
    expect(kinds.observed.contributionKnown).toBeNull()
    expect(kinds.associated.contributionKnown).toBeNull()
    expect(kinds.observed.pairsUnknown).toBe(3)
  })

  it('con pares con y sin costo la cifra es parcial: se conserva y se cuentan los pares que quedaron fuera', () => {
    const kinds = valueKinds(report({ summary: { observed: observedBlock({ units: 6, net_amount: 200, contribution_known: 70, pairs_with_unknown_contribution: 2 }) } }))
    expect(kinds.observed.contributionKnown).toBe(70)
    expect(kinds.observed.pairsUnknown).toBe(2)
  })

  it('sin compras (0 unidades) la contribución es 0 de verdad y un 0 con todos los pares con costo confiable se conserva', () => {
    expect(valueKinds(report({ summary: { observed: observedBlock({ units: 0, contribution_known: 0, pairs_with_unknown_contribution: 0 }) } })).observed.contributionKnown).toBe(0)
    expect(valueKinds(report({ summary: { observed: observedBlock({ units: 1, net_amount: 10, contribution_known: 0, pairs_with_unknown_contribution: 0 }) } })).observed.contributionKnown).toBe(0)
  })

  it('knownContribution: null solo si hay compras, hay pares sin costo y la suma conocida es 0', () => {
    expect(knownContribution(5, 0, 2)).toBeNull()
    expect(knownContribution(5, 0, 0)).toBe(0)
    expect(knownContribution(0, 0, 3)).toBe(0)
    expect(knownContribution(5, 40, 2)).toBe(40)
    expect(knownContribution(5, Number.NaN, 0)).toBeNull()
  })

  it('observado = ventana completa deduplicada por línea de venta (clientes, líneas distintas) y asociado = solo lo posterior a la acción; el detalle por recomendación va aparte y no aditivo', () => {
    const kinds = valueKinds(report())
    expect(kinds.observed).toMatchObject({ customers: 4, purchaseLines: 5, units: 12, netAmount: 800, recommendationsWithPurchase: 5 })
    expect(kinds.associated).toMatchObject({ recommendations: 2, customers: 2, units: 5, netAmount: 400 })
    // La suma por recomendación (14 unidades, 900) NO es el total deduplicado (12, 800): se conserva aparte y nunca lo reemplaza.
    expect(kinds.observed.perRecommendation).toMatchObject({ rows: 5, units: 14, netAmount: 900, contributionKnown: 330, rowsUnknown: 2 })
    expect(kinds.observed.units).not.toBe(kinds.observed.perRecommendation!.units)
  })

  it('el detalle por recomendación sin ninguna con costo confiable también es «no estimado», y sin detalle no inventa uno', () => {
    const kinds = valueKinds(report({ summary: { observed: observedBlock({ per_recommendation: perRecommendation({ rows: 2, units: 3, net_amount: 40, contribution_known: 0, rows_with_unknown_contribution: 2 }) }) } }))
    expect(kinds.observed.perRecommendation!.contributionKnown).toBeNull()
    const base = report()
    const sinDetalle = valueKinds({ ...base, summary: { ...base.summary, observed: { ...base.summary.observed, per_recommendation: undefined as never } } })
    expect(sinDetalle.observed.perRecommendation).toBeNull()
  })

  it('avisa los resultados sin detalle de líneas: se sumaron por recomendación y pueden contar dos veces', () => {
    expect(valueKinds(report({ summary: { observed: observedBlock({ rows_without_line_detail: 4 }) } })).observed.rowsWithoutLineDetail).toBe(4)
  })

  it('el incremento solo existe con estimación por intención de tratamiento y contribución estimable', () => {
    const inc = { basis: 'difference_per_unit × treated_units_in_population', treated_units: 40, contribution: 250, ci_low: 10, ci_high: 480 }
    expect(valueKinds(report({ roi: { status: 'incremental_estimated', incremental_contribution: inc } })).incremental).toEqual({ contribution: 250, ciLow: 10, ciHigh: 480, treatedUnits: 40 })
    expect(valueKinds(report({ roi: { status: 'potential_unproven', incremental_contribution: inc } })).incremental).toBeNull()
    const conversionOnly = valueKinds(report({ roi: { status: 'incremental_conversion_only', incremental_contribution: null } }))
    expect(conversionOnly.incremental).toBeNull()
    expect(conversionOnly.incrementalStatus).toBe('incremental_conversion_only')
  })

  it('cola pesada: el intervalo monetario se marca indicativo cuando la API lo avisa en los motivos del ROI', () => {
    expect(valueKinds(report()).heavyTailed).toBe(false)
    expect(valueKinds(report({ roi: { status: 'incremental_estimated', reasons: ['heavy_tailed_contribution'] } })).heavyTailed).toBe(true)
  })
})

describe('periodo aplicado (el que el servidor declara en la respuesta)', () => {
  it('lee el periodo, los días y si se completó por omisión', () => {
    expect(reportPeriod(report())).toEqual({ from: '2026-05-06', to: '2026-11-02', days: 180, defaulted: true, defaultDays: 180, maxDays: 730 })
  })
  it('sin esos campos no inventa un periodo', () => {
    expect(reportPeriod({ filters: {} })).toEqual({ from: null, to: null, days: null, defaulted: false, defaultDays: null, maxDays: null })
  })
  it('un periodo indicado entero no se rotula «por omisión»', () => {
    expect(reportPeriod({ filters: { from: '2026-01-01', to: '2026-02-01', period_days: 31, period_defaulted: false, period_default_days: 180, period_max_days: 730 } }).defaulted).toBe(false)
  })
})

describe('conteos coherentes con lo que el rol puede listar (M1)', () => {
  const funnel = { ...roiReport().summary.funnel, identified: 135, actionable: 69, measured: 129, measured_actionable: 64, control_held_out: 66 }

  it('quien aprueba ve todas; el resto, las accionables que la API ya separa (nada se resta ni se deriva)', () => {
    expect(funnelCounts(funnel, true)).toEqual({ identified: 135, measured: 129 })
    expect(funnelCounts(funnel, false)).toEqual({ identified: 69, measured: 64 })
  })

  it('sin los campos de retención (la API ya no los envía) no se supone que existan', () => {
    const { control_held_out: _omit, ...clean } = funnel
    void _omit
    expect(funnelCounts(clean as typeof funnel, false)).toEqual({ identified: 69, measured: 64 })
  })

  it('los conteos por clasificación de quien no aprueba se omiten si la API aún trajera la retención y se muestran cuando ya no la trae (caso actual)', () => {
    const summary = { funnel, by_classification: { forecast: 5, observed: 3 } }
    expect(classificationCounts(summary, true)).toEqual({ forecast: 5, observed: 3 })
    expect(classificationCounts(summary, false)).toBeNull()
    const { control_held_out: _omit, ...clean } = funnel
    void _omit
    expect(classificationCounts({ funnel: clean as typeof funnel, by_classification: { forecast: 5, observed: 3 } }, false)).toEqual({ forecast: 5, observed: 3 })
    expect(classificationCounts({ funnel: { ...funnel, control_held_out: 0 }, by_classification: { forecast: 5 } }, false)).toEqual({ forecast: 5 })
  })
})

describe('corte real de los datos (M4)', () => {
  const row = (cutoff: string | null, over: Partial<DecisionOutcome> = {}) => outcome({ window: { from: '2026-09-02', to: '2026-10-01', knowledge_cutoff: cutoff }, ...over })
  it('es el mayor corte de conocimiento de los resultados medidos; sin resultados, null', () => {
    expect(latestKnowledgeCutoff([row('2026-10-02'), row('2026-10-20'), row('2026-10-05')])).toBe('2026-10-20')
    expect(latestKnowledgeCutoff([])).toBeNull()
    expect(latestKnowledgeCutoff([row(null)])).toBeNull()
  })
  it('usa el de la evidencia si la ventana no lo trae', () => {
    expect(latestKnowledgeCutoff([row(null, { evidence: { source: { knowledge_cutoff: '2026-10-09' } } })])).toBe('2026-10-09')
  })
})

describe('roiView: el ROI nunca se presenta como probado', () => {
  const net = { estimate: 120, ci_low: 15, ci_high: 230, significant: true }
  it('sin experimento no hay cifra: solo el estado, el mensaje y los motivos', () => {
    const v = roiView(report().roi)
    expect(v).toMatchObject({ status: 'potential_unproven', proven: false, hasNetFigure: false, net: null, ratio: null, reasons: ['no_control_group'] })
  })

  it('aunque la API dijera proven = true, la interfaz lo ignora', () => {
    expect(roiView({ ...report().roi, proven: true }).proven).toBe(false)
    expect(roiView({ ...report().roi, status: 'incremental_estimated', proven: true, net_incremental_contribution: net, roi_ratio: { estimate: 1.5, ci_low: 0.2, ci_high: 2.8 } }).proven).toBe(false)
  })

  it('con estimación por intención de tratamiento se muestra el neto con su intervalo', () => {
    const v = roiView({ ...report().roi, status: 'incremental_estimated', net_incremental_contribution: net, roi_ratio: { estimate: 1.5, ci_low: 0.2, ci_high: 2.8 } })
    expect(v.hasNetFigure).toBe(true)
    expect(v.net).toEqual({ estimate: 120, ciLow: 15, ciHigh: 230, significant: true })
    expect(v.ratio).toEqual({ estimate: 1.5, ciLow: 0.2, ciHigh: 2.8 })
  })

  it('con costo de contacto desconocido no hay neto; con varios experimentos o solo conversión, tampoco cifras monetarias', () => {
    expect(roiView({ ...report().roi, status: 'incremental_estimated', net_incremental_contribution: null, reasons: ['contact_cost_unknown'] }).hasNetFigure).toBe(false)
    expect(roiView({ ...report().roi, status: 'multiple_experiments', net_incremental_contribution: net }).hasNetFigure).toBe(false)
    expect(roiView({ ...report().roi, status: 'incremental_conversion_only', net_incremental_contribution: net }).hasNetFigure).toBe(false)
  })

  it('conserva el aviso de datos de demostración o simulados y el de origen no verificado', () => {
    expect(roiView({ ...report().roi, data_origin_warning: 'Datos de demostración' }).originWarning).toBe('Datos de demostración')
    expect(roiView({ ...report().roi, data_origin_warning: 'Origen de los datos no verificado' }).originWarning).toBe('Origen de los datos no verificado')
    expect(roiView(report().roi).originWarning).toBeNull()
  })

  it('solo conversión: hay incremento de conversión con su intervalo pero NINGUNA cifra monetaria, y el motivo se conserva', () => {
    const v = roiView({
      ...report().roi, status: 'incremental_conversion_only', reasons: ['zero_variance'], incremental_contribution: null, net_incremental_contribution: null, roi_ratio: null,
      conversion: { rate_treatment: 0.25, rate_control: 0.15, difference: 0.1, ci_low: 0.01, ci_high: 0.19, significant: true },
    })
    expect(v.hasNetFigure).toBe(false)
    expect(v.net).toBeNull()
    expect(v.ratio).toBeNull()
    expect(v.conversion).toEqual({ rateTreatment: 0.25, rateControl: 0.15, difference: 0.1, ciLow: 0.01, ciHigh: 0.19, significant: true })
    expect(v.reasons).toEqual(['zero_variance'])
  })

  it('cola pesada: el motivo heavy_tailed_contribution marca el intervalo monetario como indicativo', () => {
    expect(roiView({ ...report().roi, status: 'incremental_estimated', net_incremental_contribution: net, reasons: ['sample_ratio_mismatch', 'heavy_tailed_contribution'] }).heavyTailed).toBe(true)
    expect(roiView(report().roi).heavyTailed).toBe(false)
  })

  it('varios experimentos: lista los que tienen estimación, sin cifra única', () => {
    const v = roiView({ ...report().roi, status: 'multiple_experiments', experiments: ['exp-a|30', 'exp-a|60'], reasons: ['experiments_not_additive'] })
    expect(v.multiple).toEqual([{ key: 'exp-a', horizonDays: 30 }, { key: 'exp-a', horizonDays: 60 }])
    expect(v.hasNetFigure).toBe(false)
    expect(roiView(report().roi).multiple).toEqual([])
  })

  it('quien no aprueba recibe un ROI reducido (sin motivos, conversión ni experimentos): no inventa nada de lo que falta', () => {
    const reduced: OutcomeRoi = { status: 'incremental_estimated', label: 'x', proven: false, causal_claims: 0, currency: 'USD', value: null, message: 'El detalle solo está disponible para quien puede aprobar.' }
    const v = roiView(reduced)
    expect(v).toMatchObject({ status: 'incremental_estimated', proven: false, hasNetFigure: false, net: null, ratio: null, conversion: null, reasons: [], heavyTailed: false, multiple: [] })
  })
})

describe('clasificaciones y acciones', () => {
  it('ninguna clasificación se pinta como éxito; el incremento y lo causal no son clasificaciones de fila y quedan neutros', () => {
    expect(classificationTone('forecast')).toBe('neutral')
    expect(classificationTone('observed')).toBe('info')
    expect(classificationTone('temporally_associated')).toBe('warning')
    expect(classificationTone('incremental_estimated')).toBe('neutral')
    expect(classificationTone('causally_attributable')).toBe('neutral')
  })

  it('las filas son solo observado o asociado temporalmente: el resumen no ofrece conteos de incremental ni de causal', () => {
    expect([...ROW_CLASSIFICATIONS]).toEqual(['observed', 'temporally_associated'])
    expect([...CLASSIFICATION_ORDER]).toEqual(['forecast', 'observed', 'temporally_associated'])
  })

  it('el experimento solo tiene diferencia e intervalo con estado «estimated»', () => {
    expect(experimentEstimated({ status: 'estimated' })).toBe(true)
    expect(experimentEstimated({ status: 'insufficient_sample' })).toBe(false)
    expect(experimentEstimated({ status: 'no_control_group' })).toBe(false)
    expect(experimentEstimated({ status: 'not_analysed' })).toBe(false)
  })

  it('la acción de control se rotula «retenida para medición»', () => {
    expect(actionLabelKey('control')).toBe('roi.actions.held')
    expect(actionLabelKey('contacted')).toBe('roi.actions.contacted')
  })
})

describe('el vocabulario del backend está traducido (acciones, eventos observados y clasificaciones)', () => {
  const dict = es as unknown as { roi: { events: Record<string, string>; actions: Record<string, string>; classification: Record<string, string> } }

  it('los eventos observados de la interfaz son los del backend e incluyen no_sell_through (antes overstock)', () => {
    expect([...OBSERVED_EVENTS]).toEqual(['purchase', 'no_purchase', 'product_sales', 'stockout', 'no_sell_through'])
    for (const e of OBSERVED_EVENTS) expect(dict.roi.events[e], `roi.events.${e}`).toBeTruthy()
    expect(dict.roi.events.overstock).toBeUndefined()
  })

  it('cada acción tiene su etiqueta (la de control se muestra como «retenida para medición»)', () => {
    for (const a of ACTION_KINDS) {
      const key = actionLabelKey(a).replace('roi.actions.', '')
      expect(dict.roi.actions[key], actionLabelKey(a)).toBeTruthy()
    }
  })

  // Lee OutcomeVocabulary.php (solo lectura): un evento, acción o clasificación nuevos del backend sin etiqueta ni constante aquí hace fallar esta prueba.
  const vocabulary = resolve(dirname(fileURLToPath(import.meta.url)), '../../../../backend/app/Services/Outcomes/OutcomeVocabulary.php')
  const constantList = (php: string, name: string): string[] => {
    const m = php.match(new RegExp(`const ${name} = \\[([^\\]]*)\\]`))
    const names = [...(m?.[1] ?? '').matchAll(/self::([A-Z_]+)/g)].map((x) => x[1]!)
    return names.map((n) => php.match(new RegExp(`const ${n} = '([a-z_]+)'`))?.[1] ?? n)
  }
  it.skipIf(!existsSync(vocabulary))('los eventos, acciones y clasificaciones de OutcomeVocabulary.php están todos cubiertos', () => {
    const php = readFileSync(vocabulary, 'utf8')
    const events = constantList(php, 'EVENTS')
    const actions = constantList(php, 'ACTION_KINDS')
    const classifications = constantList(php, 'CLASSIFICATIONS')
    const written = constantList(php, 'WRITTEN')
    expect(events).toContain('no_sell_through')
    expect(events.sort()).toEqual([...OBSERVED_EVENTS].sort())
    expect(actions.sort()).toEqual([...ACTION_KINDS].sort())
    for (const c of classifications) expect(dict.roi.classification[c], `roi.classification.${c}`).toBeTruthy()
    // Las filas escritas por el servidor son las que la interfaz ofrece como filtro; el resto (incremental, causal) no es de fila.
    expect(written.sort()).toEqual([...ROW_CLASSIFICATIONS].sort())
  })
})
