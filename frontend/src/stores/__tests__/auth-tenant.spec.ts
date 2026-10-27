import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { ApiError } from '@/api/errors'

vi.mock('@/api', () => ({
  authApi: { me: vi.fn(), login: vi.fn(), register: vi.fn(), logout: vi.fn() },
  tenantApi: { current: vi.fn(), create: vi.fn(), update: vi.fn() },
}))

import { authApi, tenantApi } from '@/api'
import { useAuthStore } from '../auth'
import { useTenantStore } from '../tenant'

const me = {
  user: { id: 1, name: 'Ana', email: 'ana@demo.ec', locale: 'es', is_superadmin: false },
  tenants: [
    { uuid: 'a-1', name: 'Ferretería A', sector: 'ferreteria', role: 'analyst', onboarding_completed_at: null },
    { uuid: 'b-2', name: 'Distribuidora B', sector: 'distribuidora', role: 'viewer', onboarding_completed_at: '2026-10-01T00:00:00Z' },
  ],
}

describe('store de autenticación', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(authApi.me).mockReset()
    localStorage.clear()
  })

  it('init carga la sesión una sola vez', async () => {
    vi.mocked(authApi.me).mockResolvedValue(me as never)
    const auth = useAuthStore()
    await Promise.all([auth.init(), auth.init()])
    await auth.init()
    expect(authApi.me).toHaveBeenCalledTimes(1)
    expect(auth.isAuthenticated).toBe(true)
    expect(auth.memberships).toHaveLength(2)
  })

  it('401 en /auth/me deja la sesión como no autenticada', async () => {
    vi.mocked(authApi.me).mockRejectedValue(new ApiError(401, { message: 'No autenticado.' }, ''))
    const auth = useAuthStore()
    await auth.init()
    expect(auth.isAuthenticated).toBe(false)
    expect(auth.initialized).toBe(true)
  })

  it('login refresca el usuario', async () => {
    vi.mocked(authApi.login).mockResolvedValue({ user: me.user } as never)
    vi.mocked(authApi.me).mockResolvedValue(me as never)
    const auth = useAuthStore()
    await auth.login({ email: 'ana@demo.ec', password: 'secreto123' })
    expect(auth.user?.email).toBe('ana@demo.ec')
  })
})

describe('store de empresa', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    localStorage.clear()
  })

  it('selecciona la primera empresa si no hay una guardada válida', () => {
    const auth = useAuthStore()
    auth.setMemberships(me.tenants as never)
    const tenant = useTenantStore()
    expect(tenant.ensureSelection()).toBe('a-1')
    expect(localStorage.getItem('predictily.tenant')).toBe('a-1')
  })

  it('respeta la empresa guardada si sigue disponible', () => {
    localStorage.setItem('predictily.tenant', 'b-2')
    const auth = useAuthStore()
    auth.setMemberships(me.tenants as never)
    const tenant = useTenantStore()
    expect(tenant.ensureSelection()).toBe('b-2')
    expect(tenant.role).toBe('viewer')
  })

  it('aplica permisos por rol', () => {
    const auth = useAuthStore()
    auth.setMemberships(me.tenants as never)
    const tenant = useTenantStore()
    tenant.select('a-1')
    expect(tenant.can('imports.manage')).toBe(true)
    expect(tenant.can('users.manage')).toBe(false)
    tenant.select('b-2')
    expect(tenant.can('forecast.view')).toBe(true)
    expect(tenant.can('forecast.run')).toBe(false)
    expect(tenant.can('data.export')).toBe(false)
  })

  it('load usa el rol devuelto por GET /tenant', async () => {
    const auth = useAuthStore()
    auth.setMemberships(me.tenants as never)
    vi.mocked(tenantApi.current).mockResolvedValue({ uuid: 'a-1', name: 'Ferretería A', role: 'owner', settings: {} } as never)
    const tenant = useTenantStore()
    tenant.select('a-1')
    await tenant.load()
    expect(tenant.role).toBe('owner')
    expect(tenant.can('tenant.delete')).toBe(true)
  })

  it('cambiar de empresa limpia los datos cargados', async () => {
    const auth = useAuthStore()
    auth.setMemberships(me.tenants as never)
    vi.mocked(tenantApi.current).mockResolvedValue({ uuid: 'a-1', name: 'Ferretería A', role: 'analyst' } as never)
    const tenant = useTenantStore()
    tenant.select('a-1')
    await tenant.load()
    tenant.select('b-2')
    expect(tenant.tenant).toBeNull()
  })
})
