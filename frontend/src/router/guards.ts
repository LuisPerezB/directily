import type { RouteLocationRaw } from 'vue-router'
import type { Ability } from '@/utils/abilities'

/** Metadatos de ruta usados por las guardas. */
declare module 'vue-router' {
  interface RouteMeta {
    requiresAuth?: boolean
    guestOnly?: boolean
    requiresTenant?: boolean
    superadmin?: boolean
    ability?: Ability
    title?: string
    layout?: 'public' | 'auth' | 'app'
  }
}

export interface GuardState {
  isAuthenticated: boolean
  hasMemberships: boolean
  hasTenant: boolean
  isSuperadmin: boolean
  can: (ability: Ability) => boolean
}

export interface GuardTarget {
  fullPath: string
  name?: string | symbol | null
  meta: {
    requiresAuth?: boolean
    guestOnly?: boolean
    requiresTenant?: boolean
    superadmin?: boolean
    ability?: Ability
  }
}

/** Decide si se permite la navegación o a dónde redirigir (función pura, probada). */
export function resolveGuard(to: GuardTarget, state: GuardState): true | RouteLocationRaw {
  const { meta } = to
  if (meta.guestOnly && state.isAuthenticated) {
    return homeFor(state)
  }
  if ((meta.requiresAuth || meta.requiresTenant || meta.superadmin) && !state.isAuthenticated) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }
  if (meta.superadmin && !state.isSuperadmin) {
    return homeFor(state)
  }
  if (meta.requiresTenant && !state.hasTenant) {
    return state.hasMemberships ? { name: 'tenant-select' } : { name: 'tenant-create' }
  }
  if (meta.ability && !state.can(meta.ability)) {
    return { name: 'forbidden' }
  }
  return true
}

export function homeFor(state: Pick<GuardState, 'hasTenant' | 'hasMemberships'>): RouteLocationRaw {
  if (state.hasTenant) return { name: 'dashboard' }
  return state.hasMemberships ? { name: 'tenant-select' } : { name: 'tenant-create' }
}

/** Evita redirecciones abiertas: solo rutas internas. */
export function safeRedirect(value: unknown): string | null {
  if (typeof value !== 'string') return null
  if (!value.startsWith('/') || value.startsWith('//')) return null
  return value
}
