import { describe, expect, it, vi } from 'vitest'
import { mount as rawMount, type ComponentMountingOptions } from '@vue/test-utils'
import { createPinia } from 'pinia'

vi.mock('vue-echarts', () => ({ default: { name: 'VChart', props: ['option'], template: '<div data-test="vchart" />' } }))

import CalibrationChart from '../charts/CalibrationChart.vue'
import type { CalibrationBin } from '@/api/types'

// El gráfico consulta el tema (store de interfaz): se monta con Pinia.
const mount = (c: unknown, o: ComponentMountingOptions<never> = {}) => rawMount(c as never, { ...o, global: { plugins: [createPinia()] } })
const bin = (p: number, o: number, n: number): CalibrationBin => ({ mean_predicted: p, observed_rate: o, n, observed_lo95: o * 0.8, observed_hi95: o * 1.2 })

describe('CalibrationChart', () => {
  it('muestra el n de cada grupo y marca los de muestra pequeña', () => {
    const w = mount(CalibrationChart, { props: { bins: [bin(0.05, 0.06, 1200), bin(0.3, 0.28, 400), bin(0.6, 0.5, 12)], title: 'Curva' } })
    const rows = w.findAll('tbody tr')
    expect(rows).toHaveLength(3)
    expect(w.findAll('[data-test="calibration-row-small"]')).toHaveLength(1)
    expect(rows[0]!.text()).toMatch(/1\.?200/)
    expect(rows[2]!.text()).toContain('muestra pequeña')
    expect(w.get('[data-test="calibration-small"]').text()).toContain('1 grupos tienen menos de 30 observaciones')
    expect(w.get('[data-test="calibration-summary"]').text()).toMatch(/3 grupos, 1\.?612 observaciones/)
    expect(w.find('[data-test="vchart"]').exists()).toBe(true)
  })

  it('avisa cuando el modelo predice lo mismo para todos (curva plana)', () => {
    const flat = [0.0154, 0.0182, 0.014].map((o) => bin(0.0132, o, 11740))
    const w = mount(CalibrationChart, { props: { bins: flat, title: 'Curva' } })
    expect(w.get('[data-test="calibration-flat"]').text()).toContain('no distingue compradores')
  })

  it('sin curva dice que no existe en lugar de dibujar un gráfico vacío', () => {
    const w = mount(CalibrationChart, { props: { bins: [], title: 'Curva' } })
    expect(w.get('[data-test="calibration-empty"]').exists()).toBe(true)
    expect(w.find('[data-test="vchart"]').exists()).toBe(false)
    expect(mount(CalibrationChart, { props: { bins: null, title: 'Curva' } }).find('[data-test="calibration-empty"]').exists()).toBe(true)
  })
})
