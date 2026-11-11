import type { LandingLang } from '../copy/types'
import { safeEmail, safeHttpUrl } from './url'

/**
 * Destinos del contacto; se leen al compilar (ver .env.example). Un valor mal formado se descarta: la API solo por
 * `https:` (los datos de quien escribe no viajan en claro) y el correo solo si tiene forma de correo.
 */
export const contactConfig = {
  apiUrl: safeHttpUrl(import.meta.env.VITE_CONTACT_API_URL),
  email: safeEmail(import.meta.env.VITE_CONTACT_EMAIL),
  whatsapp: (import.meta.env.VITE_CONTACT_WHATSAPP ?? '').replace(/\D/g, ''),
}

export interface ContactPayload {
  name: string
  company: string
  email: string
  phone?: string
  sector?: string
  current_system?: string
  message?: string
  locale: LandingLang
  /** Campo trampa contra envíos automáticos: una persona lo deja vacío. */
  website?: string
}

export type ContactFailure = 'failed' | 'tooMany' | 'invalid'

export class ContactError extends Error {
  constructor(
    readonly kind: ContactFailure,
    /** Campos que el servidor rechazó (solo con `invalid`). */
    readonly fields: string[] = [],
  ) {
    super(kind)
    this.name = 'ContactError'
  }
}

/**
 * Envía la solicitud al backend de Predictily (POST /api/v1/contact-requests). La landing vive en otro
 * dominio: no manda cookies, así que no necesita CSRF; el backend solo debe permitir su origen en CORS.
 */
export async function sendContactRequest(apiUrl: string, payload: ContactPayload): Promise<void> {
  let response: Response
  try {
    response = await fetch(apiUrl, {
      method: 'POST',
      credentials: 'omit',
      referrerPolicy: 'no-referrer',
      redirect: 'error',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(payload),
    })
  } catch {
    throw new ContactError('failed')
  }
  if (response.ok) return
  if (response.status === 429) throw new ContactError('tooMany')
  if (response.status === 422) {
    const body = (await response.json().catch(() => null)) as { errors?: Record<string, unknown> } | null
    throw new ContactError('invalid', Object.keys(body?.errors ?? {}))
  }
  throw new ContactError('failed')
}
