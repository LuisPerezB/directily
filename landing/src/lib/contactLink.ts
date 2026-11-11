export type ContactChannel = 'email' | 'whatsapp'

export interface ContactTarget {
  /** Correo de destino; vacío abre el borrador sin destinatario. */
  email: string
  /** Número de WhatsApp con código de país; se ignora todo lo que no sea dígito. */
  whatsapp: string
  subject?: string
  body?: string
}

/**
 * Enlace que entrega el mensaje del formulario de contacto a la aplicación de la persona:
 * `mailto:` para correo o `wa.me` para WhatsApp. No envía nada por sí mismo.
 */
export function buildContactUrl(channel: ContactChannel, target: ContactTarget): string {
  const body = target.body ? encodeURIComponent(target.body) : ''
  if (channel === 'whatsapp') {
    const base = `https://wa.me/${target.whatsapp.replace(/\D/g, '')}`
    return body ? `${base}?text=${body}` : base
  }
  const params = [target.subject ? `subject=${encodeURIComponent(target.subject)}` : '', body ? `body=${body}` : ''].filter(Boolean)
  const base = `mailto:${target.email.trim()}`
  return params.length ? `${base}?${params.join('&')}` : base
}
