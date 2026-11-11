import { useCallback, useEffect, useState } from 'react'
import { en } from '../copy/en'
import { es } from '../copy/es'
import type { LandingCopy, LandingLang } from '../copy/types'
import { pageLang, pageUrlFor } from './page'
import { storageGet, storageSet } from './storage'

const STORAGE_KEY = 'predictily.landing.lang'
const COPY: Record<LandingLang, LandingCopy> = { es, en }

function isLang(value: unknown): value is LandingLang {
  return value === 'es' || value === 'en'
}

/**
 * Idioma inicial. En una página publicada manda la URL (`page`: `/` español, `/en/` inglés): es lo que Google
 * indexa y no debe cambiar según el navegador de quien llega. Sin página declarada (desarrollo): `?lang=` (enlace
 * compartido) → preferencia guardada → idioma fijado en `VITE_DEFAULT_LANG` → idioma del navegador. Fuera del
 * inglés se cae al español.
 */
export function detectLandingLang(search: string, stored: string | null, browser: string | undefined, fallback?: string, page?: LandingLang): LandingLang {
  if (isLang(page)) return page
  const fromQuery = new URLSearchParams(search).get('lang')
  if (isLang(fromQuery)) return fromQuery
  if (isLang(stored)) return stored
  if (isLang(fallback)) return fallback
  return browser?.toLowerCase().startsWith('en') ? 'en' : 'es'
}

/** Idioma de la página; mantiene sincronizados `<html lang>`, el título y la descripción. */
export function useLandingLocale(initial?: LandingLang): { lang: LandingLang; copy: LandingCopy; setLang: (value: LandingLang) => void } {
  const [lang, setLangState] = useState<LandingLang>(
    () => initial ?? detectLandingLang(globalThis.location?.search ?? '', storageGet(STORAGE_KEY), globalThis.navigator?.language, import.meta.env.VITE_DEFAULT_LANG, pageLang()),
  )
  const copy = COPY[lang]

  const setLang = useCallback((value: LandingLang) => {
    storageSet(STORAGE_KEY, value)
    // Página publicada: cada idioma tiene su dirección; cambiar de idioma es ir a ella.
    if (pageLang() !== undefined) {
      if (value !== pageLang()) globalThis.location.assign(pageUrlFor(value))
      return
    }
    setLangState(value)
  }, [])

  useEffect(() => {
    document.documentElement.lang = lang
    document.title = copy.meta.title
    document.querySelector('meta[name="description"]')?.setAttribute('content', copy.meta.description)
  }, [lang, copy])

  return { lang, copy, setLang }
}
