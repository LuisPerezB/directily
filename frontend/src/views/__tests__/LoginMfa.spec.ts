import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ApiError } from '@/api/errors'

const replace = vi.fn()
vi.mock('vue-router', async (orig) => ({ ...(await orig<typeof import('vue-router')>()), useRouter: () => ({ replace }), useRoute: () => ({ query: {} }) }))
vi.mock('@/router/guards', () => ({ homeFor: () => '/app/panel', safeRedirect: () => null }))
vi.mock('@/api', () => ({
  authApi: { me: vi.fn(), login: vi.fn(), mfaChallenge: vi.fn(), register: vi.fn(), logout: vi.fn() },
  tenantApi: { current: vi.fn(), create: vi.fn(), update: vi.fn() },
}))

import { authApi } from '@/api'
import LoginView from '../auth/LoginView.vue'

enableAutoUnmount(afterEach)

const me = { user: { id: 1, name: 'Ana', email: 'ana@demo.ec', locale: 'es', is_superadmin: false }, tenants: [{ uuid: 'a-1', name: 'Ferretería A', sector: 'ferreteria', role: 'owner', onboarding_completed_at: null }] }
const challenge = { mfa_required: true, challenge: 'desafio-1', expires_in: 300, methods: ['totp', 'recovery_code'] }

async function passwordStep(w: ReturnType<typeof mount>) {
  await w.get('#email').setValue('ana@demo.ec')
  await w.get('#password').setValue('secreto123')
  await w.get('form').trigger('submit')
  await flushPromises()
}

describe('Inicio de sesión con segundo factor (SPA)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    replace.mockReset()
    for (const fn of Object.values(authApi)) vi.mocked(fn).mockReset()
    localStorage.clear()
  })

  it('un 202 mfa_required muestra el paso del código en vez de dar la sesión por iniciada', async () => {
    vi.mocked(authApi.login).mockResolvedValue(challenge as never)
    const w = mount(LoginView)
    await passwordStep(w)

    expect(w.find('[data-test="mfa-form"]').exists()).toBe(true)
    expect(w.text()).toContain('Verificación en dos pasos')
    expect(w.find('#password').exists()).toBe(false)
    expect(replace).not.toHaveBeenCalled()
    expect(authApi.me).not.toHaveBeenCalled()
  })

  it('con el código correcto completa el desafío y entra a la aplicación', async () => {
    vi.mocked(authApi.login).mockResolvedValue(challenge as never)
    vi.mocked(authApi.mfaChallenge).mockResolvedValue({ user: me.user, mfa_method: 'totp' } as never)
    vi.mocked(authApi.me).mockResolvedValue(me as never)
    const w = mount(LoginView)
    await passwordStep(w)

    expect(w.get('#mfa-code').attributes('autocomplete')).toBe('one-time-code')
    expect(w.get('#mfa-code').attributes('inputmode')).toBe('numeric')
    await w.get('#mfa-code').setValue('123 456')
    await w.get('[data-test="mfa-form"]').trigger('submit')
    await flushPromises()

    expect(authApi.mfaChallenge).toHaveBeenCalledWith({ challenge: 'desafio-1', code: '123456' })
    expect(replace).toHaveBeenCalledWith('/app/panel')
  })

  it('permite usar un código de recuperación en lugar del código de la aplicación', async () => {
    vi.mocked(authApi.login).mockResolvedValue(challenge as never)
    vi.mocked(authApi.mfaChallenge).mockResolvedValue({ user: me.user, mfa_method: 'recovery' } as never)
    vi.mocked(authApi.me).mockResolvedValue(me as never)
    const w = mount(LoginView)
    await passwordStep(w)

    await w.get('[data-test="mfa-toggle"]').trigger('click')
    expect(w.find('#mfa-code').exists()).toBe(false)
    await w.get('#mfa-recovery').setValue(' ABCD-EFGH-JKMN-PQRS ')
    await w.get('[data-test="mfa-form"]').trigger('submit')
    await flushPromises()

    expect(authApi.mfaChallenge).toHaveBeenCalledWith({ challenge: 'desafio-1', recovery_code: 'ABCD-EFGH-JKMN-PQRS' })
    expect(replace).toHaveBeenCalled()
  })

  it('un código incorrecto muestra el error y deja reintentar; uno vencido o bloqueado vuelve a pedir la contraseña', async () => {
    vi.mocked(authApi.login).mockResolvedValue(challenge as never)
    const w = mount(LoginView)
    await passwordStep(w)

    vi.mocked(authApi.mfaChallenge).mockRejectedValueOnce(new ApiError(422, { message: 'x', code: 'mfa_invalid_code' }, ''))
    await w.get('#mfa-code').setValue('000000')
    await w.get('[data-test="mfa-form"]').trigger('submit')
    await flushPromises()
    expect(w.get('[role="alert"]').text()).toContain('El código no es válido')
    expect(w.find('[data-test="mfa-form"]').exists()).toBe(true)

    vi.mocked(authApi.mfaChallenge).mockRejectedValueOnce(new ApiError(423, { message: 'x', code: 'mfa_locked' }, ''))
    await w.get('[data-test="mfa-form"]').trigger('submit')
    await flushPromises()
    expect(w.find('[data-test="mfa-form"]').exists()).toBe(false)
    expect(w.get('[role="alert"]').text()).toContain('bloqueada')
    expect(w.find('#password').exists()).toBe(true)
    expect(replace).not.toHaveBeenCalled()
  })

  it('Volver descarta el desafío y regresa al formulario de contraseña', async () => {
    vi.mocked(authApi.login).mockResolvedValue(challenge as never)
    const w = mount(LoginView)
    await passwordStep(w)

    await w.get('[data-test="mfa-back"]').trigger('click')
    expect(w.find('[data-test="mfa-form"]').exists()).toBe(false)
    expect(w.find('#email').exists()).toBe(true)
  })

  it('sin segundo factor el inicio de sesión sigue siendo de un solo paso', async () => {
    vi.mocked(authApi.login).mockResolvedValue({ user: me.user } as never)
    vi.mocked(authApi.me).mockResolvedValue(me as never)
    const w = mount(LoginView)
    await passwordStep(w)

    expect(w.find('[data-test="mfa-form"]').exists()).toBe(false)
    expect(authApi.mfaChallenge).not.toHaveBeenCalled()
    expect(replace).toHaveBeenCalledWith('/app/panel')
  })
})
