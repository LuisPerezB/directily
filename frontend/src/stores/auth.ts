import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { authApi } from '@/api'
import { isApiError } from '@/api/errors'
import type { LoginPayload, RegisterPayload, TenantMembership, User } from '@/api/types'

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

  async function login(payload: LoginPayload): Promise<void> {
    await authApi.login(payload)
    await refresh()
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
    user.value = null
    memberships.value = []
    initialized.value = true
  }

  function setMemberships(list: TenantMembership[]): void {
    memberships.value = list
  }

  return { user, memberships, initialized, isAuthenticated, isSuperadmin, init, refresh, login, register, logout, clear, setMemberships }
})
