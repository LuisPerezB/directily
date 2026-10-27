import type { TenantRole } from '@/api/types'

/**
 * Habilidades por rol. Réplica de App\Enums\TenantRole::abilities() en el backend.
 * El frontend solo las usa para mostrar/ocultar acciones: la autorización real
 * siempre la decide la API (403).
 */
export type Ability =
  | 'data.view'
  | 'forecast.view'
  | 'alerts.view'
  | 'imports.manage'
  | 'forecast.run'
  | 'scenarios.run'
  | 'alerts.manage'
  | 'data.export'
  | 'catalog.manage'
  | 'users.manage'
  | 'branches.manage'
  | 'datasources.manage'
  | 'settings.manage'
  | 'audit.view'
  | 'tenant.delete'
  | 'tenant.consent'
  | 'owners.manage'
  | 'tenant.export_all'

const VIEWER: Ability[] = ['data.view', 'forecast.view', 'alerts.view']
const ANALYST: Ability[] = [...VIEWER, 'imports.manage', 'forecast.run', 'scenarios.run', 'alerts.manage', 'data.export', 'catalog.manage']
const ADMIN: Ability[] = [...ANALYST, 'users.manage', 'branches.manage', 'datasources.manage', 'settings.manage', 'audit.view']
const OWNER: Ability[] = [...ADMIN, 'tenant.delete', 'tenant.consent', 'owners.manage', 'tenant.export_all']

export const ROLE_ABILITIES: Record<TenantRole, readonly Ability[]> = {
  viewer: VIEWER,
  analyst: ANALYST,
  admin: ADMIN,
  owner: OWNER,
}

export const ROLES: TenantRole[] = ['owner', 'admin', 'analyst', 'viewer']

export function roleCan(role: TenantRole | null | undefined, ability: Ability): boolean {
  if (!role) return false
  return ROLE_ABILITIES[role]?.includes(ability) ?? false
}
