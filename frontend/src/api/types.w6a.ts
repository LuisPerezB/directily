/**
 * Tipos del segundo factor en el inicio de sesión (V2.5 · W6-A, ADR-V25-15). Fuente: docs/openapi/core.yaml (`POST /auth/login`, 202 `mfa_required`) y
 * docs/openapi/v2.yaml (`POST /auth/mfa/challenge`). El alta del segundo factor (QR, códigos de recuperación) es DISEÑADO: no hay interfaz de activación todavía.
 */
import type { User } from './types'

/** Respuesta de `POST /auth/login`: la sesión (200) o, con MFA activo, un desafío (202 `mfa_required`) que se completa con `POST /auth/mfa/challenge`. */
export interface LoginResult {
  user?: User
  mfa_required?: boolean
  /** Desafío opaco de un solo uso y corta vida; nunca se muestra ni se guarda fuera de la memoria del store. */
  challenge?: string
  /** Segundos de vigencia del desafío. */
  expires_in?: number
  methods?: Array<'totp' | 'recovery_code'>
}

/** Segundo paso: el desafío y un código TOTP de 6 dígitos o, en su lugar, un código de recuperación. */
export interface MfaChallengePayload {
  challenge: string
  code?: string
  recovery_code?: string
}
