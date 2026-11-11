import type { LandingLang } from '../copy/types'

/**
 * Datos de la PÁGINA publicada. El build genera una página por idioma (`/` en español y `/en/` en inglés) y lo
 * declara en `<html data-page-lang data-root>`: así el idioma lo decide la URL (lo que Google indexa) y no el
 * navegador de quien llega. En desarrollo y en las pruebas no hay nada declarado y la página es una sola.
 */
let root = './'

/** Ruta relativa desde esta página hasta la raíz del sitio (`./` o `../`): de ella cuelgan `media/` y el otro idioma. */
export function pageRoot(): string {
  return root
}

export function setPageRoot(value: string | undefined): void {
  root = value === '../' ? '../' : './'
}

/** Idioma fijado por la página publicada, o `undefined` si no declara ninguno. */
export function pageLang(): LandingLang | undefined {
  const value = globalThis.document?.documentElement.dataset.pageLang
  return value === 'es' || value === 'en' ? value : undefined
}

const MEDIA_URL = (import.meta.env.VITE_MEDIA_BASE_URL ?? '').trim().replace(/[/]+$/, '')

/** Dirección de un archivo de `media/` (video, portadas, logotipos): `VITE_MEDIA_BASE_URL` o la carpeta junto al sitio. */
export function mediaUrl(file: string): string {
  return `${MEDIA_URL || `${root}media`}/${file}`
}

/** Dirección, relativa a esta página, de la misma página en otro idioma. */
export function pageUrlFor(lang: LandingLang): string {
  return lang === 'en' ? `${root}en/` : root
}
