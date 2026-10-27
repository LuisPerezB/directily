import axios, { type AxiosAdapter, type InternalAxiosRequestConfig } from 'axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createHttp, setErrorHandlers, setTenantIdProvider } from '../http'
import { ApiError } from '../errors'

type Responder = (config: InternalAxiosRequestConfig) => { status: number; data?: unknown }

function withAdapter(responder: Responder) {
  const http = createHttp('/api/v1')
  const calls: InternalAxiosRequestConfig[] = []
  const adapter: AxiosAdapter = async (config) => {
    calls.push(config)
    const { status, data } = responder(config)
    const response = { data, status, statusText: String(status), headers: {}, config, request: {} }
    if (status >= 400) {
      const error = new axios.AxiosError(`Request failed with status code ${status}`, 'ERR_BAD_RESPONSE', config, {}, response)
      throw error
    }
    return response
  }
  http.defaults.adapter = adapter
  return { http, calls }
}

function clearCookies(): void {
  document.cookie.split(';').forEach((c) => {
    const name = c.split('=')[0]?.trim()
    if (name) document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`
  })
}

describe('cliente HTTP', () => {
  let csrfSpy: ReturnType<typeof vi.spyOn>

  beforeEach(() => {
    clearCookies()
    setTenantIdProvider(() => null)
    setErrorHandlers({ onUnauthorized: null, onPlanLimit: null, onForbidden: null })
    csrfSpy = vi.spyOn(axios, 'get').mockImplementation(async () => {
      document.cookie = 'XSRF-TOKEN=token-123; path=/'
      return { data: null, status: 204 }
    })
  })

  afterEach(() => {
    csrfSpy.mockRestore()
  })

  it('agrega X-Tenant-ID con la empresa activa', async () => {
    setTenantIdProvider(() => 'tenant-uuid-1')
    const { http, calls } = withAdapter(() => ({ status: 200, data: { data: [] } }))
    await http.get('/products')
    expect(calls[0]?.headers.get('X-Tenant-ID')).toBe('tenant-uuid-1')
  })

  it('no agrega X-Tenant-ID si no hay empresa activa', async () => {
    const { http, calls } = withAdapter(() => ({ status: 200, data: {} }))
    await http.get('/auth/me')
    expect(calls[0]?.headers.has('X-Tenant-ID')).toBe(false)
  })

  it('obtiene la cookie CSRF antes de la primera petición que modifica datos', async () => {
    const { http } = withAdapter(() => ({ status: 200, data: {} }))
    await http.post('/auth/login', { email: 'a@b.ec', password: 'x' })
    expect(csrfSpy).toHaveBeenCalledWith('/sanctum/csrf-cookie', expect.objectContaining({ withCredentials: true }))
    csrfSpy.mockClear()
    await http.post('/auth/logout')
    expect(csrfSpy).not.toHaveBeenCalled()
  })

  it('no pide CSRF para lecturas', async () => {
    const { http } = withAdapter(() => ({ status: 200, data: {} }))
    await http.get('/tenant')
    expect(csrfSpy).not.toHaveBeenCalled()
  })

  it('renueva CSRF y reintenta una vez ante 419', async () => {
    let attempts = 0
    const { http } = withAdapter(() => (++attempts === 1 ? { status: 419, data: { message: 'CSRF token mismatch.' } } : { status: 200, data: { ok: true } }))
    const res = await http.post('/imports/1/confirm')
    expect(res.data).toEqual({ ok: true })
    expect(attempts).toBe(2)
  })

  it('normaliza errores de validación 422', async () => {
    const { http } = withAdapter(() => ({ status: 422, data: { message: 'Datos inválidos', errors: { email: ['El correo es obligatorio.'] } } }))
    const error = await http.post('/auth/register', {}).catch((e: unknown) => e)
    expect(error).toBeInstanceOf(ApiError)
    const apiError = error as ApiError
    expect(apiError.isValidation).toBe(true)
    expect(apiError.field('email')).toBe('El correo es obligatorio.')
    expect(apiError.message).toBe('Datos inválidos')
  })

  it('invoca el manejador de 401 salvo en /auth/me', async () => {
    const onUnauthorized = vi.fn()
    setErrorHandlers({ onUnauthorized })
    const { http } = withAdapter(() => ({ status: 401, data: { message: 'No autenticado.' } }))
    await http.get('/auth/me').catch(() => undefined)
    expect(onUnauthorized).not.toHaveBeenCalled()
    await http.get('/dashboard/summary').catch(() => undefined)
    expect(onUnauthorized).toHaveBeenCalledTimes(1)
  })

  it('reconoce el límite de plan (402) y avisa al manejador', async () => {
    const onPlanLimit = vi.fn()
    setErrorHandlers({ onPlanLimit })
    const { http } = withAdapter(() => ({ status: 402, data: { message: 'Límite alcanzado', code: 'plan_limit_exceeded', max: 1 } }))
    const error = (await http.post('/forecast-runs').catch((e: unknown) => e)) as ApiError
    expect(error.isPlanLimit).toBe(true)
    expect(onPlanLimit).toHaveBeenCalledOnce()
  })

  it('decodifica errores JSON recibidos como Blob (descargas)', async () => {
    const blob = new Blob([JSON.stringify({ message: 'Sin permiso', code: 'forbidden' })], { type: 'application/json' })
    const { http } = withAdapter(() => ({ status: 403, data: blob }))
    const error = (await http.get('/exports/forecasts.csv', { responseType: 'blob' }).catch((e: unknown) => e)) as ApiError
    expect(error.status).toBe(403)
    expect(error.code).toBe('forbidden')
    expect(error.message).toBe('Sin permiso')
  })
})
