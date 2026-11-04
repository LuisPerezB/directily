import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { ApiError } from '@/api/errors'

vi.mock('@/api', () => ({
  authApi: { me: vi.fn(), login: vi.fn(), mfaChallenge: vi.fn(), register: vi.fn(), logout: vi.fn() },
  tenantApi: { current: vi.fn(), create: vi.fn(), update: vi.fn() },
}))

import { authApi } from '@/api'
import { useAuthStore } from '../auth'

const me = {
  user: { id: 1, name: 'Ana', email: 'ana@demo.ec', locale: 'es', is_superadmin: false },
  tenants: [{ uuid: 'a-1', name: 'Ferretería A', sector: 'ferreteria', role: 'owner', onboarding_completed_at: null }],
}
const challenge = { mfa_required: true, challenge: 'desafio-opaco-de-un-solo-uso', expires_in: 300, methods: ['totp', 'recovery_code'] }

describe('inicio de sesión con segundo factor (W6-A)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    for (const fn of Object.values(authApi)) vi.mocked(fn).mockReset()
    localStorage.clear()
    sessionStorage.clear()
  })

  it('una contraseña correcta con MFA activo (202 mfa_required) NO inicia sesión: guarda el desafío y no pide /auth/me', async () => {
    vi.mocked(authApi.login).mockResolvedValue(challenge as never)
    const auth = useAuthStore()

    await expect(auth.login({ email: 'ana@demo.ec', password: 'secreto123' })).resolves.toBe('mfa_required')

    expect(auth.mfaPending).toBe(true)
    expect(auth.isAuthenticated).toBe(false)
    expect(authApi.me).not.toHaveBeenCalled()
  })

  it('el desafío vive solo en memoria: no se escribe en localStorage ni en sessionStorage', async () => {
    vi.mocked(authApi.login).mockResolvedValue(challenge as never)
    const auth = useAuthStore()
    await auth.login({ email: 'ana@demo.ec', password: 'secreto123' })
    const dump = JSON.stringify({ ...localStorage }) + JSON.stringify({ ...sessionStorage })
    expect(dump).not.toContain('desafio-opaco-de-un-solo-uso')
  })

  it('completeMfa manda el desafío y el código, refresca la sesión y limpia el desafío', async () => {
    vi.mocked(authApi.login).mockResolvedValue(challenge as never)
    vi.mocked(authApi.mfaChallenge).mockResolvedValue({ user: me.user, mfa_method: 'totp' } as never)
    vi.mocked(authApi.me).mockResolvedValue(me as never)
    const auth = useAuthStore()
    await auth.login({ email: 'ana@demo.ec', password: 'secreto123' })

    await auth.completeMfa({ code: '123456' })

    expect(authApi.mfaChallenge).toHaveBeenCalledWith({ challenge: 'desafio-opaco-de-un-solo-uso', code: '123456' })
    expect(auth.mfaPending).toBe(false)
    expect(auth.user?.email).toBe('ana@demo.ec')
    expect(auth.memberships).toHaveLength(1)
  })

  it('con un código de recuperación manda recovery_code y no code', async () => {
    vi.mocked(authApi.login).mockResolvedValue(challenge as never)
    vi.mocked(authApi.mfaChallenge).mockResolvedValue({ user: me.user, mfa_method: 'recovery' } as never)
    vi.mocked(authApi.me).mockResolvedValue(me as never)
    const auth = useAuthStore()
    await auth.login({ email: 'ana@demo.ec', password: 'secreto123' })

    await auth.completeMfa({ recovery_code: 'ABCD-EFGH-JKMN-PQRS', code: '999999' })

    expect(authApi.mfaChallenge).toHaveBeenCalledWith({ challenge: 'desafio-opaco-de-un-solo-uso', recovery_code: 'ABCD-EFGH-JKMN-PQRS' })
  })

  it('un código incorrecto conserva el desafío para reintentar; uno vencido, usado o bloqueado lo descarta', async () => {
    vi.mocked(authApi.login).mockResolvedValue(challenge as never)
    const auth = useAuthStore()
    await auth.login({ email: 'ana@demo.ec', password: 'secreto123' })

    vi.mocked(authApi.mfaChallenge).mockRejectedValueOnce(new ApiError(422, { message: 'El código de verificación no es válido.', code: 'mfa_invalid_code' }, ''))
    await expect(auth.completeMfa({ code: '000000' })).rejects.toMatchObject({ code: 'mfa_invalid_code' })
    expect(auth.mfaPending).toBe(true)

    vi.mocked(authApi.mfaChallenge).mockRejectedValueOnce(new ApiError(422, { message: 'vencido', code: 'mfa_challenge_invalid' }, ''))
    await expect(auth.completeMfa({ code: '000000' })).rejects.toMatchObject({ code: 'mfa_challenge_invalid' })
    expect(auth.mfaPending).toBe(false)

    await auth.login({ email: 'ana@demo.ec', password: 'secreto123' })
    vi.mocked(authApi.mfaChallenge).mockRejectedValueOnce(new ApiError(423, { message: 'bloqueado', code: 'mfa_locked' }, ''))
    await expect(auth.completeMfa({ code: '000000' })).rejects.toMatchObject({ status: 423 })
    expect(auth.mfaPending).toBe(false)
  })

  it('sin desafío pendiente no se llama a la API; cancelar y cerrar sesión lo borran; un inicio normal responde ok', async () => {
    const auth = useAuthStore()
    await expect(auth.completeMfa({ code: '123456' })).rejects.toThrow()
    expect(authApi.mfaChallenge).not.toHaveBeenCalled()

    vi.mocked(authApi.login).mockResolvedValueOnce(challenge as never)
    await auth.login({ email: 'ana@demo.ec', password: 'secreto123' })
    auth.cancelMfa()
    expect(auth.mfaPending).toBe(false)

    vi.mocked(authApi.login).mockResolvedValueOnce(challenge as never)
    await auth.login({ email: 'ana@demo.ec', password: 'secreto123' })
    auth.clear()
    expect(auth.mfaPending).toBe(false)

    vi.mocked(authApi.login).mockResolvedValueOnce({ user: me.user } as never)
    vi.mocked(authApi.me).mockResolvedValue(me as never)
    await expect(auth.login({ email: 'ana@demo.ec', password: 'secreto123' })).resolves.toBe('ok')
    expect(auth.isAuthenticated).toBe(true)
  })
})
