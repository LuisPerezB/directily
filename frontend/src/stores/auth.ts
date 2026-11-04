import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { authApi } from '@/api'
import { isApiError } from '@/api/errors'
import type { LoginPayload, RegisterPayload, TenantMembership, User } from '@/api/types'

/** Resultado de iniciar sesión: entró, o falta el segundo factor (hay un desafío pendiente en el store). */
export type LoginOutcome = 'ok' | 'mfa_required'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const memberships = ref<TenantMembership[]>([])
  const initialized = ref(false)
  let initPromise: Promise<void> | null = null

  const isAuthenticated = computed(() => user.value !== null)
  const isSuperadmin = computed(() => user.value?.is_superadmin === true)

  async function refresh(): Promise<void> {
    try {
      const me = await authApi.me()
      user.value = me.user
      memberships.value = me.tenants ?? []
    } catch (error) {
      if (isApiError(error) && (error.status === 401 || error.status === 419)) {
        user.value = null
        memberships.value = []
      } else {
        throw error
      }
    } finally {
      initialized.value = true
    }
  }

  /** Carga la sesión una sola vez (usado por las guardas de rutas). */
  function init(): Promise<void> {
    if (initialized.value) return Promise.resolve()
    initPromise ??= refresh()
      .catch(() => {
        // Error de red: se trata como no autenticado, la UI mostrará el login.
        user.value = null
      })
      .finally(() => {
        initPromise = null
      })
    return initPromise
  }

  /**
   * Desafío del segundo factor pendiente (V2.5 · W6-A). Vive solo en memoria: se pierde al recargar (hay que volver a escribir la contraseña, que es lo que se espera de un
   * desafío de un solo uso) y NUNCA se guarda en localStorage.
   */
  const pendingChallenge = ref<string | null>(null)
  const mfaPending = computed(() => pendingChallenge.value !== null)

  async function login(payload: LoginPayload): Promise<LoginOutcome> {
    const result = await authApi.login(payload)
    // Con MFA activo la contraseña correcta NO inicia sesión: responde 202 con un desafío y la sesión se emite al verificar el código.
    if (result?.mfa_required === true && typeof result.challenge === 'string') {
      pendingChallenge.value = result.challenge
      return 'mfa_required'
    }
    pendingChallenge.value = null
    await refresh()
    return 'ok'
  }

  /**
   * Segundo paso del inicio de sesión: un código TOTP o, en su lugar, un código de recuperación. Un desafío vencido o ya usado (`mfa_challenge_invalid`) o un bloqueo
   * (`mfa_locked`) cierran el paso: hay que volver a escribir la contraseña. Un código incorrecto conserva el desafío para reintentar.
   */
  async function completeMfa(input: { code?: string; recovery_code?: string }): Promise<void> {
    const challenge = pendingChallenge.value
    if (challenge === null) throw new Error('No hay un inicio de sesión pendiente de segundo factor.')
    try {
      await authApi.mfaChallenge({ challenge, ...(input.recovery_code ? { recovery_code: input.recovery_code } : { code: input.code }) })
    } catch (error) {
      if (isApiError(error) && (error.code === 'mfa_challenge_invalid' || error.code === 'mfa_locked')) pendingChallenge.value = null
      throw error
    }
    pendingChallenge.value = null
    await refresh()
  }

  function cancelMfa(): void {
    pendingChallenge.value = null
  }

  async function register(payload: RegisterPayload): Promise<void> {
    await authApi.register(payload)
    await refresh()
  }

  async function logout(): Promise<void> {
    try {
      await authApi.logout()
    } finally {
      clear()
    }
  }

  function clear(): void {
    pendingChallenge.value = null
    user.value = null
    memberships.value = []
    initialized.value = true
  }

  function setMemberships(list: TenantMembership[]): void {
    memberships.value = list
  }

  return { user, memberships, initialized, isAuthenticated, isSuperadmin, mfaPending, init, refresh, login, completeMfa, cancelMfa, register, logout, clear, setMemberships }
})
