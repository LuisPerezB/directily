import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useTenantStore } from '@/stores/tenant'
import { isApiError } from '@/api/errors'
import { resolveGuard } from './guards'

const AppLayout = () => import('@/layouts/AppLayout.vue')

export const routes: RouteRecordRaw[] = [
  { path: '/', name: 'landing', component: () => import('@/views/LandingView.vue'), meta: { layout: 'public', title: 'Predictily' } },
  { path: '/login', name: 'login', component: () => import('@/views/auth/LoginView.vue'), meta: { guestOnly: true, layout: 'auth', title: 'Iniciar sesión' } },
  { path: '/register', name: 'register', component: () => import('@/views/auth/RegisterView.vue'), meta: { guestOnly: true, layout: 'auth', title: 'Crear cuenta' } },
  { path: '/empresas/nueva', name: 'tenant-create', component: () => import('@/views/tenant/CreateTenantView.vue'), meta: { requiresAuth: true, layout: 'auth', title: 'Crear empresa' } },
  { path: '/empresas', name: 'tenant-select', component: () => import('@/views/tenant/SelectTenantView.vue'), meta: { requiresAuth: true, layout: 'auth', title: 'Elegir empresa' } },
  {
    path: '/app',
    component: AppLayout,
    meta: { requiresTenant: true, layout: 'app' },
    children: [
      { path: '', redirect: { name: 'dashboard' } },
      { path: 'inicio', name: 'dashboard', component: () => import('@/views/DashboardView.vue'), meta: { requiresTenant: true, title: 'Dashboard' } },
      { path: 'primeros-pasos', name: 'onboarding', component: () => import('@/views/OnboardingView.vue'), meta: { requiresTenant: true, title: 'Primeros pasos' } },
      { path: 'pronosticos', name: 'forecasts', component: () => import('@/views/ForecastsView.vue'), meta: { requiresTenant: true, ability: 'forecast.view', title: 'Pronósticos' } },
      { path: 'productos', name: 'products', component: () => import('@/views/ProductsView.vue'), meta: { requiresTenant: true, ability: 'data.view', title: 'Productos y categorías' } },
      { path: 'compradores/:tab?', name: 'customers', component: () => import('@/views/CustomersView.vue'), props: true, meta: { requiresTenant: true, ability: 'forecast.view', title: 'Compradores' } },
      { path: 'comprador/:id(\\d+)', name: 'customer-detail', component: () => import('@/views/CustomerDetailView.vue'), props: true, meta: { requiresTenant: true, ability: 'forecast.view', title: 'Ficha del comprador' } },
      { path: 'plan-de-compras/:tab?', name: 'supply', component: () => import('@/views/SupplyView.vue'), props: true, meta: { requiresTenant: true, ability: 'forecast.view', title: 'Plan de compras' } },
      { path: 'inventario', name: 'inventory', component: () => import('@/views/InventoryView.vue'), meta: { requiresTenant: true, ability: 'forecast.view', title: 'Inventario y abastecimiento' } },
      { path: 'precision', name: 'accuracy', component: () => import('@/views/AccuracyView.vue'), meta: { requiresTenant: true, ability: 'forecast.view', title: 'Historial de precisión' } },
      { path: 'escenarios', name: 'scenarios', component: () => import('@/views/ScenariosView.vue'), meta: { requiresTenant: true, ability: 'forecast.view', title: 'Simulador de escenarios' } },
      { path: 'fuentes/:tab?', name: 'data-sources', component: () => import('@/views/DataSourcesView.vue'), props: true, meta: { requiresTenant: true, ability: 'data.view', title: 'Fuentes de datos' } },
      { path: 'fuentes/sql/:id(\\d+)', name: 'sql-source', component: () => import('@/views/SqlSourceView.vue'), props: true, meta: { requiresTenant: true, ability: 'datasources.manage', title: 'Fuente SQL' } },
      { path: 'importaciones/nueva', name: 'import-new', component: () => import('@/views/ImportWizardView.vue'), meta: { requiresTenant: true, ability: 'imports.manage', title: 'Importar datos' } },
      { path: 'importaciones/:id(\\d+)', name: 'import-detail', component: () => import('@/views/ImportWizardView.vue'), props: true, meta: { requiresTenant: true, ability: 'data.view', title: 'Importación' } },
      { path: 'alertas', name: 'alerts', component: () => import('@/views/AlertsView.vue'), meta: { requiresTenant: true, ability: 'alerts.view', title: 'Alertas' } },
      { path: 'configuracion/:tab?', name: 'settings', component: () => import('@/views/SettingsView.vue'), props: true, meta: { requiresTenant: true, title: 'Configuración' } },
      { path: 'sin-permiso', name: 'forbidden', component: () => import('@/views/ForbiddenView.vue'), meta: { requiresTenant: true, title: 'Sin permiso' } },
    ],
  },
  { path: '/admin', name: 'admin', component: () => import('@/views/AdminView.vue'), meta: { superadmin: true, layout: 'auth', title: 'Administración de la plataforma' } },
  { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('@/views/NotFoundView.vue'), meta: { layout: 'public', title: 'Página no encontrada' } },
]

export const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
  scrollBehavior(_to, _from, saved) {
    return saved ?? { top: 0 }
  },
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  const tenant = useTenantStore()
  await auth.init()

  if (auth.isAuthenticated) {
    tenant.ensureSelection()
    if (to.meta.requiresTenant && tenant.hasTenant && !tenant.tenant) {
      try {
        await tenant.load()
      } catch (error) {
        // La empresa guardada ya no es accesible: elegir otra.
        if (isApiError(error) && (error.status === 404 || error.status === 423 || error.status === 400)) {
          tenant.select(null)
        }
      }
    }
  }

  return resolveGuard(to, {
    isAuthenticated: auth.isAuthenticated,
    hasMemberships: auth.memberships.length > 0,
    hasTenant: tenant.hasTenant,
    isSuperadmin: auth.isSuperadmin,
    can: tenant.can,
  })
})

router.afterEach((to) => {
  const title = to.meta.title
  document.title = title && title !== 'Predictily' ? `${title} · Predictily` : 'Predictily — Anticipa tus ventas. Optimiza tus decisiones.'
})
