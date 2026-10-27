import type { ApiErrorBody } from './types'

/** Error normalizado de la API. Todas las llamadas fallidas se convierten a esta clase. */
export class ApiError extends Error {
  readonly status: number
  readonly code: string | null
  readonly fieldErrors: Record<string, string[]>
  readonly body: ApiErrorBody | null

  constructor(status: number, body: ApiErrorBody | null, fallbackMessage: string) {
    super(body?.message || fallbackMessage)
    this.name = 'ApiError'
    this.status = status
    this.code = body?.code ?? null
    this.fieldErrors = body?.errors ?? {}
    this.body = body
  }

  get isValidation(): boolean {
    return this.status === 422
  }

  get isPlanLimit(): boolean {
    return this.status === 402 || this.code === 'plan_limit_exceeded'
  }

  get isNetwork(): boolean {
    return this.status === 0
  }

  /** Primer mensaje de error de un campo (para formularios). */
  field(name: string): string | undefined {
    return this.fieldErrors[name]?.[0]
  }
}

export function isApiError(value: unknown): value is ApiError {
  return value instanceof ApiError
}

/** Mensaje legible para cualquier error (usa claves i18n para casos genéricos). */
export function errorMessage(error: unknown, t: (key: string) => string): string {
  if (isApiError(error)) {
    if (error.isNetwork) return t('errors.network')
    if (error.status >= 500) return t('errors.server')
    return error.message || t('errors.generic')
  }
  if (error instanceof Error && error.message) return error.message
  return t('errors.generic')
}
