import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import EnvelopePanel from '../forecast/EnvelopePanel.vue'
import QualityBadge from '../ui/QualityBadge.vue'
import type { ResultEnvelope } from '@/api/types'

const envelope = (over: Partial<ResultEnvelope> = {}): ResultEnvelope => ({
  run_id: 41, core: 'customer', as_of: '2026-10-26', horizon_start: '2026-10-27', horizon_end: '2026-11-25', target_definition: null,
  entity_keys: ['customer_id', 'product_id'], unit: 'product_unit', currency: 'USD', result_type: 'forecast', model_version: null,
  data_snapshot_id: 77, config_version: '0123456789abcdef0123', assumptions: ['Precio de la última compra de la relación'],
  limitations: ['La predicción es condicional a que el producto esté disponible'], quality_status: 'degraded', uncertainty_source: 'empirical_holdout', ...over,
})

describe('QualityBadge', () => {
  it.each([
    ['ok', 'Resultado completo'], ['degraded', 'Con reservas'], ['insufficient_data', 'Datos insuficientes'],
    ['not_configured', 'Sin configurar'], ['insufficient_evidence', 'Evidencia insuficiente'],
  ])('%s se muestra como «%s»', (status, text) => {
    const w = mount(QualityBadge, { props: { status } })
    expect(w.text()).toBe(text)
    expect(w.attributes('data-status')).toBe(status)
  })

  it('un estado ausente o desconocido nunca se muestra como completo', () => {
    expect(mount(QualityBadge, { props: { status: null } }).text()).toBe('Sin evaluar')
    expect(mount(QualityBadge, { props: { status: 'inventado' } }).text()).toBe('Sin evaluar')
  })
})

describe('EnvelopePanel', () => {
  it('muestra siempre la calidad, las limitaciones y los supuestos de la envoltura', () => {
    const w = mount(EnvelopePanel, { props: { envelope: envelope() } })
    expect(w.get('[data-test="quality-badge"]').text()).toBe('Con reservas')
    expect(w.get('[data-test="envelope-limitations"]').text()).toContain('condicional a que el producto esté disponible')
    expect(w.get('[data-test="envelope-assumptions"]').text()).toContain('Precio de la última compra')
    expect(w.text()).toContain('Medida en un período de prueba reservado')
    expect(w.text()).toContain('#77')
    expect(w.text()).toContain('0123456789')
  })

  it('un estado de abstención lo explica con palabras', () => {
    const w = mount(EnvelopePanel, { props: { envelope: envelope({ quality_status: 'insufficient_data', limitations: [] }) } })
    expect(w.get('[data-test="envelope-abstention"]').text()).toContain('No hay datos suficientes')
    expect(w.text()).toContain('no declara limitaciones adicionales')
  })

  it('evidencia insuficiente se explica y la incertidumbre desconocida no se presenta como conocida', () => {
    const w = mount(EnvelopePanel, { props: { envelope: envelope({ quality_status: 'insufficient_evidence', uncertainty_source: 'unknown' }) } })
    expect(w.get('[data-test="envelope-abstention"]').text()).toContain('no permite publicar')
    expect(w.get('[data-test="envelope-uncertainty"]').text()).toContain('Desconocida')
  })

  it('sin envoltura no dibuja nada', () => {
    expect(mount(EnvelopePanel, { props: { envelope: null } }).find('[data-test="envelope-panel"]').exists()).toBe(false)
  })
})
