import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import HorizonSupportNotice from '../forecast/HorizonSupportNotice.vue'
import type { HorizonSupport } from '@/api/types'

const support = (status: HorizonSupport['status'], reasons: HorizonSupport['reasons'] = []): HorizonSupport => ({ horizon: 60, status, reasons })
const mountNotice = (s: HorizonSupport | null) => mount(HorizonSupportNotice, { props: { support: s, horizon: 60 }, slots: { default: '<a class="extra">Generar</a>' } })

describe('HorizonSupportNotice', () => {
  it('insufficient_data: avisa que la cifra no es completa y lista los motivos del motor', () => {
    const w = mountNotice(support('insufficient_data', [{ code: 'history_lt_2h', message: 'La historia (80 días) es menor que 2×60 días.' }]))
    const notice = w.get('[data-test="horizon-support-notice"]')
    expect(notice.attributes('data-status')).toBe('insufficient_data')
    expect(notice.text()).toContain('Horizonte de 60 días sin respaldo suficiente')
    expect(notice.text()).toContain('provisionales')
    expect(notice.text()).toContain('La historia (80 días) es menor que 2×60 días.')
  })

  it('degraded: avisa que las métricas son optimistas', () => {
    expect(mountNotice(support('degraded')).text()).toContain('métricas de precisión de este horizonte provienen de las mismas ventanas')
  })

  it('not_computed: pide generar un pronóstico con ese horizonte', () => {
    const w = mountNotice(support('not_computed'))
    expect(w.text()).toContain('Genere un pronóstico con este horizonte')
    expect(w.find('.extra').exists()).toBe(true)
  })

  it('unknown: ejecución antigua, no se sabe si hay respaldo', () => {
    expect(mountNotice(support('unknown')).text()).toContain('anterior a la verificación de horizontes')
  })

  it('ok o sin información: no muestra ningún aviso', () => {
    expect(mountNotice(support('ok')).find('[data-test="horizon-support-notice"]').exists()).toBe(false)
    expect(mountNotice(null).find('[data-test="horizon-support-notice"]').exists()).toBe(false)
  })
})
