import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import { router } from './router'
import { i18n } from './i18n'
import { setErrorHandlers, setTenantIdProvider } from './api/http'
import { useAuthStore } from './stores/auth'
import { useTenantStore } from './stores/tenant'
import { useUiStore } from './stores/ui'
import './assets/main.css'

const app = createApp(App)
const pinia = createPinia()
app.use(pinia)
app.use(i18n)

const ui = useUiStore()
ui.applyTheme()
const tenant = useTenantStore()
setTenantIdProvider(() => tenant.currentUuid)

const t = i18n.global.t
setErrorHandlers({
  onUnauthorized: () => {
    const auth = useAuthStore()
    auth.clear()
    const current = router.currentRoute.value
    if (current.name !== 'login') {
      ui.notify(t('errors.sessionExpired'), 'warning')
      void router.push({ name: 'login', query: { redirect: current.fullPath } })
    }
  },
  onPlanLimit: (error) => {
    ui.notify(error.message || t('errors.planLimit'), 'warning', 8000)
  },
  onForbidden: (error, config) => {
    if ((config?.method ?? 'get').toLowerCase() !== 'get') ui.notify(error.message || t('errors.forbidden'), 'error')
  },
})

app.use(router)
app.mount('#app')
