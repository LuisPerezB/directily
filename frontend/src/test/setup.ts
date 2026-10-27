import { config } from '@vue/test-utils'
import { i18n } from '@/i18n'

// Todas las pruebas montan componentes con i18n en español.
config.global.plugins = [i18n]
config.global.stubs = { RouterLink: { template: '<a><slot /></a>' }, Teleport: true }

// jsdom no implementa matchMedia ni ResizeObserver (los usan ECharts y el tema).
if (!window.matchMedia) {
  window.matchMedia = ((query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addEventListener: () => undefined,
    removeEventListener: () => undefined,
    addListener: () => undefined,
    removeListener: () => undefined,
    dispatchEvent: () => false,
  })) as unknown as typeof window.matchMedia
}
