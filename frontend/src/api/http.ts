import axios, { AxiosError, type AxiosInstance, type AxiosRequestConfig, type InternalAxiosRequestConfig } from 'axios'
import { ApiError } from './errors'
import type { ApiErrorBody } from './types'

/**
 * Cliente HTTP de la SPA.
 * - Sanctum modo SPA: cookies de sesión (withCredentials) + header X-XSRF-TOKEN (withXSRFToken).
 * - Antes de la primera petición que modifica datos se obtiene la cookie CSRF.
 * - Header X-Tenant-ID con la empresa activa (proveedor registrado por el store de empresa).
 * - Errores normalizados a ApiError; manejadores globales para 401/402/403.
 */

export const API_BASE_URL = import.meta.env.VITE_API_BASE_URL ?? '/api/v1'
const CSRF_URL = '/sanctum/csrf-cookie'
const MUTATING = new Set(['post', 'put', 'patch', 'delete'])

type TenantIdProvider = () => string | null
type ErrorHandler = (error: ApiError, config?: AxiosRequestConfig) => void

interface Handlers {
  tenantId: TenantIdProvider
  onUnauthorized: ErrorHandler | null
  onPlanLimit: ErrorHandler | null
  onForbidden: ErrorHandler | null
}

const handlers: Handlers = {
  tenantId: () => null,
  onUnauthorized: null,
  onPlanLimit: null,
  onForbidden: null,
}

export function setTenantIdProvider(provider: TenantIdProvider): void {
  handlers.tenantId = provider
}

export function setErrorHandlers(h: Partial<Omit<Handlers, 'tenantId'>>): void {
  Object.assign(handlers, h)
}

/** Peticiones para las que un 401 es esperado y no debe redirigir al login. */
const SILENT_401 = ['/auth/me', '/auth/login', '/auth/register']

export function readCookie(name: string): string | null {
  if (typeof document === 'undefined') return null
  const match = document.cookie.split('; ').find((c) => c.startsWith(`${name}=`))
  return match ? decodeURIComponent(match.slice(name.length + 1)) : null
}

export function createHttp(baseURL = API_BASE_URL): AxiosInstance {
  const instance = axios.create({
    baseURL,
    withCredentials: true,
    withXSRFToken: true,
    xsrfCookieName: 'XSRF-TOKEN',
    xsrfHeaderName: 'X-XSRF-TOKEN',
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    timeout: 60_000,
  })

  let csrfPromise: Promise<unknown> | null = null
  const ensureCsrf = async (force = false): Promise<void> => {
    if (!force && readCookie('XSRF-TOKEN')) return
    csrfPromise ??= axios.get(CSRF_URL, { withCredentials: true }).finally(() => {
      csrfPromise = null
    })
    await csrfPromise
  }

  instance.interceptors.request.use(async (config: InternalAxiosRequestConfig) => {
    const method = (config.method ?? 'get').toLowerCase()
    if (MUTATING.has(method)) await ensureCsrf()
    const tenantId = handlers.tenantId()
    if (tenantId && !config.headers.has('X-Tenant-ID')) {
      config.headers.set('X-Tenant-ID', tenantId)
    }
    return config
  })

  instance.interceptors.response.use(
    (response) => response,
    async (error: AxiosError<ApiErrorBody>) => {
      const config = error.config as (InternalAxiosRequestConfig & { _csrfRetried?: boolean }) | undefined
      const status = error.response?.status ?? 0

      // 419: token CSRF vencido → renovar una vez y reintentar.
      if (status === 419 && config && !config._csrfRetried) {
        config._csrfRetried = true
        await ensureCsrf(true)
        return instance.request(config)
      }

      const body = await parseBody(error.response?.data)
      const apiError = new ApiError(status, body, error.message || 'Error de red')
      const url = config?.url ?? ''

      if (status === 401 && !SILENT_401.some((p) => url.endsWith(p))) handlers.onUnauthorized?.(apiError, config)
      if (apiError.isPlanLimit) handlers.onPlanLimit?.(apiError, config)
      if (status === 403) handlers.onForbidden?.(apiError, config)

      return Promise.reject(apiError)
    },
  )

  return instance
}

/** Los errores de descargas (responseType blob) llegan como Blob: se decodifican a JSON. */
async function parseBody(data: unknown): Promise<ApiErrorBody | null> {
  if (!data) return null
  if (typeof Blob !== 'undefined' && data instanceof Blob) {
    try {
      return JSON.parse(await data.text()) as ApiErrorBody
    } catch {
      return null
    }
  }
  if (typeof data === 'object') return data as ApiErrorBody
  return null
}

export const http = createHttp()
