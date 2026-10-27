import { useTenantStore } from '@/stores/tenant'
import type { Ability } from '@/utils/abilities'

export function useAbility() {
  const tenant = useTenantStore()
  return { can: (ability: Ability) => tenant.can(ability) }
}
