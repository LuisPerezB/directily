import { createPinia, setActivePinia, type Pinia } from 'pinia'
import { useTenantStore } from '@/stores/tenant'
import type { PlanLimits, Tenant, TenantRole } from '@/api/types'

/** Pinia con una empresa activa de prueba (rol y plan configurables). Solo para pruebas. */
export function setupTenant(role: TenantRole = 'owner', limits: PlanLimits = { horizons: [7, 14, 30, 60, 90] }): Pinia {
  const pinia = createPinia()
  setActivePinia(pinia)
  const tenant = useTenantStore()
  tenant.currentUuid = 'tenant-test'
  tenant.tenant = {
    uuid: 'tenant-test', name: 'Empresa de prueba', currency: 'USD', timezone: 'America/Guayaquil', role,
    plan: { code: 'test', name: 'Plan de prueba', limits }, settings: { default_horizon_days: 30 },
  } as unknown as Tenant
  return pinia
}
