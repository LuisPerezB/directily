import { existsSync, readFileSync, readdirSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import es from '../es.json'

/**
 * Todo código de bandera de Customer y de gate de promoción que el motor puede enviar a la API tiene su etiqueta en español: ninguno llega a la pantalla como
 * un código crudo. La lista conocida es fija (siempre se comprueba); si el código del motor está disponible junto al frontend, además se EXTRAEN de allí sus
 * códigos, de modo que un código nuevo sin etiqueta rompe esta prueba.
 */
const get = (path: string): unknown => path.split('.').reduce<unknown>((n, k) => (n && typeof n === 'object' ? (n as Record<string, unknown>)[k] : undefined), es)

/** Banderas de `customer_predictions.quality_flags` (engine/predictily_engine/customer/predict.py y s05.py; docs/ml/CUSTOMER_REPURCHASE.md §14). */
const CUSTOMER_FLAGS = [
  'thin_history', 'stock_unavailable_now', 'low_availability_90d', 'price_unknown', 'coherence_adjusted', 'quantity_coherence_adjusted',
  'calibration_degraded', 'calibration_uncalibrated', 'orders_nested_adjusted', 'cold_start', 'window_beyond_validation', 's05_unavailable', 's05_failed',
]
/** Gates de `model_promotion_decisions.gates` (engine/predictily_engine/selection/promotion.py). */
const GATES = [
  'no_leakage', 'valid_data', 'verifiable_artifacts', 'temporal_stability', 'segment_regression', 'metrics_documented', 'improvement', 'human_approval',
  'beats_baseline', 'legacy_rule', 'min_improvement', 'windows_won', 'used_pooled',
]
const GATE_STATUSES = ['pass', 'fail', 'not_evaluable', 'not_applicable', 'pending']
const DECISIONS = ['promote', 'reject', 'keep_champion', 'publish_baseline', 'shadow']

describe('etiquetas de los códigos del motor', () => {
  it('toda bandera conocida de Customer tiene etiqueta', () => {
    expect(CUSTOMER_FLAGS.filter((f) => typeof get(`customerFlags.${f}`) !== 'string')).toEqual([])
  })

  it('toda bandera que explica una cifra tiene además su explicación', () => {
    for (const f of ['orders_nested_adjusted', 'quantity_coherence_adjusted', 'cold_start', 'window_beyond_validation']) expect(typeof get(`customerFlagHints.${f}`), f).toBe('string')
  })

  it('todo gate conocido de la política heredada y de la V2.5, sus estados y las decisiones tienen etiqueta', () => {
    expect(GATES.filter((g) => typeof get(`promotion.gates.${g}`) !== 'string')).toEqual([])
    expect(GATE_STATUSES.filter((x) => typeof get(`promotion.gateStatus.${x}`) !== 'string')).toEqual([])
    expect(DECISIONS.filter((x) => typeof get(`promotion.decision.${x}`) !== 'string')).toEqual([])
  })

  const engine = resolve(__dirname, '../../../../engine/predictily_engine')
  const hasEngine = existsSync(join(engine, 'customer')) && existsSync(join(engine, 'selection', 'promotion.py'))

  it.skipIf(!hasEngine)('los códigos que el motor escribe hoy están todos en la lista conocida (y por tanto etiquetados)', () => {
    const customer = readdirSync(join(engine, 'customer')).filter((f) => f.endsWith('.py')).map((f) => readFileSync(join(engine, 'customer', f), 'utf8')).join('\n')
    const flags = new Set<string>()
    for (const m of customer.matchAll(/\b(?:flags\[i\]|fl\[i\]|f)\.append\(\s*"([a-z0-9_]+)"\s*\)/g)) flags.add(m[1]!)
    for (const m of customer.matchAll(/f"(calibration)_\{[^}]+\}"/g)) { flags.add(`${m[1]}_degraded`); flags.add(`${m[1]}_uncalibrated`) }
    expect(flags.size, 'el extractor no encontró banderas: revisar la expresión').toBeGreaterThan(8)
    expect([...flags].filter((f) => !CUSTOMER_FLAGS.includes(f))).toEqual([])
    expect([...flags].filter((f) => typeof get(`customerFlags.${f}`) !== 'string')).toEqual([])

    const promotion = readFileSync(join(engine, 'selection', 'promotion.py'), 'utf8')
    const gates = new Set<string>()
    for (const m of promotion.matchAll(/"([a-z_]+)":\s*_gate\(/g)) gates.add(m[1]!)
    const order = /GATE_ORDER = \(([^)]*)\)/.exec(promotion)?.[1] ?? ''
    for (const m of order.matchAll(/"([a-z_]+)"/g)) gates.add(m[1]!)
    expect(gates.size, 'el extractor no encontró gates: revisar la expresión').toBeGreaterThan(8)
    expect([...gates].filter((g) => !GATES.includes(g))).toEqual([])
    expect([...gates].filter((g) => typeof get(`promotion.gates.${g}`) !== 'string')).toEqual([])
  })
})
