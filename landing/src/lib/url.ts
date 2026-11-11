/**
 * Las URL de configuración (`VITE_*`) terminan en `href` y en `fetch`: solo se aceptan `https:` y, para
 * desarrollo, `http:` hacia el propio equipo. Cualquier otra cosa (`javascript:`, `data:`, `http:` hacia otro
 * host, texto que no es una URL) se descarta y la página se comporta como si la variable no existiera.
 */
const LOCAL_HOSTS = new Set(['localhost', '127.0.0.1', '[::1]'])

export function safeHttpUrl(value: string | undefined): string {
  const raw = (value ?? '').trim()
  if (raw === '') return ''
  let url: URL
  try {
    url = new URL(raw)
  } catch {
    return ''
  }
  if (url.username !== '' || url.password !== '') return ''
  if (url.protocol === 'https:') return raw
  if (url.protocol === 'http:' && (LOCAL_HOSTS.has(url.hostname) || url.hostname.endsWith('.localhost'))) return raw
  return ''
}

/** Correo de destino con forma de correo y sin nada que pueda añadir cabeceras a un `mailto:`. */
export function safeEmail(value: string | undefined): string {
  const raw = (value ?? '').trim()
  return /^[^\s@<>,;:?&"']+@[^\s@<>,;:?&"']+\.[^\s@<>,;:?&"']+$/.test(raw) ? raw : ''
}
