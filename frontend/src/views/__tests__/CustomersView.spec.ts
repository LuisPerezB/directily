import { describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach } from 'vitest'
import { setupTenant } from '@/test/helpers'

vi.mock('@/api', async (orig) => ({ ...(await orig<typeof import('@/api')>()), customerApi: {} }))
vi.mock('vue-router', async (orig) => ({ ...(await orig<typeof import('vue-router')>()), useRouter: () => ({ replace: vi.fn() }) }))

import CustomersView from '../CustomersView.vue'

enableAutoUnmount(afterEach)
const stubs = { CustomerPredictions: true, CustomerEvaluation: true, CustomerReconciliation: true, CustomerModels: true, CustomerRuns: true }
const radios = (w: ReturnType<typeof mount>) => w.findAll('[role="radiogroup"][aria-label="Horizonte"] [role="radio"]').map((r) => r.text())

describe('Compradores: plan y horizontes', () => {
  it('Growth (7/15/30, con Customer) ofrece 7, 15 y 30 días', async () => {
    const w = mount(CustomersView, { props: { tab: 'predicciones' }, global: { plugins: [setupTenant('analyst', { horizons: [7, 15, 30], features: { customer: true } })], stubs } })
    await flushPromises()
    expect(radios(w)).toEqual(['7 días', '15 días', '30 días'])
    expect(w.find('[data-test="customer-not-in-plan"]').exists()).toBe(false)
  })

  it('el catálogo completo habilitado ofrece los horizontes de Customer (sin 60)', async () => {
    const w = mount(CustomersView, { props: { tab: 'predicciones' }, global: { plugins: [setupTenant('analyst', { horizons: [7, 14, 15, 30, 60, 90] })], stubs } })
    await flushPromises()
    expect(radios(w)).toEqual(['7 días', '14 días', '15 días', '30 días', '90 días'])
  })

  it('un plan sin Customer (Starter) lo dice y no muestra selector ni pestañas', async () => {
    const w = mount(CustomersView, { props: { tab: 'predicciones' }, global: { plugins: [setupTenant('analyst', { horizons: [7, 30], features: { customer: false } })], stubs } })
    await flushPromises()
    expect(w.get('[data-test="customer-not-in-plan"]').text()).toContain('no incluye el comportamiento de compra')
    expect(radios(w)).toEqual([])
    expect(w.find('[role="tablist"]').exists()).toBe(false)
    expect(w.find('#cust-panel').exists()).toBe(false)
  })
})
