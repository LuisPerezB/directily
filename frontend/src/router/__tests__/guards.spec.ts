import { describe, expect, it } from 'vitest'
import { resolveGuard, safeRedirect, type GuardState } from '../guards'
import { roleCan } from '@/utils/abilities'

const anon: GuardState = { isAuthenticated: false, hasMemberships: false, hasTenant: false, isSuperadmin: false, can: () => false }
const viewer: GuardState = { isAuthenticated: true, hasMemberships: true, hasTenant: true, isSuperadmin: false, can: (a) => roleCan('viewer', a) }

describe('guardas de rutas', () => {
  it('redirige al login con la ruta de retorno', () => {
    expect(resolveGuard({ fullPath: '/app/inicio', meta: { requiresTenant: true } }, anon)).toEqual({ name: 'login', query: { redirect: '/app/inicio' } })
  })

  it('deja pasar rutas públicas', () => {
    expect(resolveGuard({ fullPath: '/', meta: {} }, anon)).toBe(true)
  })

  it('envía a crear empresa si el usuario no tiene ninguna', () => {
    const state = { ...anon, isAuthenticated: true }
    expect(resolveGuard({ fullPath: '/app/inicio', meta: { requiresTenant: true } }, state)).toEqual({ name: 'tenant-create' })
  })

  it('envía a elegir empresa si tiene varias pero ninguna activa', () => {
    const state = { ...anon, isAuthenticated: true, hasMemberships: true }
    expect(resolveGuard({ fullPath: '/app/inicio', meta: { requiresTenant: true } }, state)).toEqual({ name: 'tenant-select' })
  })

  it('evita que un usuario autenticado vuelva al login', () => {
    expect(resolveGuard({ fullPath: '/login', meta: { guestOnly: true } }, viewer)).toEqual({ name: 'dashboard' })
  })

  it('bloquea secciones según el rol', () => {
    expect(resolveGuard({ fullPath: '/app/importaciones/nueva', meta: { requiresTenant: true, ability: 'imports.manage' } }, viewer)).toEqual({ name: 'forbidden' })
    expect(resolveGuard({ fullPath: '/app/pronosticos', meta: { requiresTenant: true, ability: 'forecast.view' } }, viewer)).toBe(true)
  })

  it('restringe la administración al superadmin', () => {
    expect(resolveGuard({ fullPath: '/admin', meta: { superadmin: true } }, viewer)).toEqual({ name: 'dashboard' })
    expect(resolveGuard({ fullPath: '/admin', meta: { superadmin: true } }, { ...viewer, isSuperadmin: true })).toBe(true)
  })

  it('solo acepta redirecciones internas', () => {
    expect(safeRedirect('/app/inventario')).toBe('/app/inventario')
    expect(safeRedirect('//evil.example')).toBeNull()
    expect(safeRedirect('https://evil.example')).toBeNull()
    expect(safeRedirect(undefined)).toBeNull()
  })
})

describe('habilidades por rol (réplica de TenantRole)', () => {
  it('es acumulativa', () => {
    expect(roleCan('viewer', 'data.view')).toBe(true)
    expect(roleCan('viewer', 'data.export')).toBe(false)
    expect(roleCan('analyst', 'forecast.run')).toBe(true)
    expect(roleCan('analyst', 'users.manage')).toBe(false)
    expect(roleCan('admin', 'users.manage')).toBe(true)
    expect(roleCan('admin', 'tenant.delete')).toBe(false)
    expect(roleCan('owner', 'tenant.delete')).toBe(true)
    expect(roleCan(null, 'data.view')).toBe(false)
  })
})
