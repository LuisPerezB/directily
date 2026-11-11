import { useCallback, useEffect, useState } from 'react'
import { en } from '../copy/en'
import { es } from '../copy/es'
import type { LandingCopy, LandingLang } from '../copy/types'
import { storageGet, storageSet } from './storage'

const STORAGE_KEY = 'predictily.landing.lang'
const COPY: Record<LandingLang, LandingCopy> = { es, en }

function isLang(value: unknown): value is LandingLang {
  return value === 'es' || value === 'en'
}

/**
 * Idioma inicial: `?lang=` (enlace compartido) → preferencia guardada → idioma fijado en
 * `VITE_DEFAULT_LANG` → idioma del navegador. Fuera del inglés se cae al español.
 */
export function detectLandingLang(search: string, stored: string | null, browser: string | undefined, fallback?: string): LandingLang {
  const fromQuery = new URLSearchParams(search).get('lang')
  if (isLang(fromQuery)) return fromQuery
  if (isLang(stored)) return stored
  if (isLang(fallback)) return fallback
  return browser?.toLowerCase().startsWith('en') ? 'en' : 'es'
}

/** Idioma de la página; mantiene sincronizados `<html lang>`, el título y la descripción. */
export function useLandingLocale(): { lang: LandingLang; copy: LandingCopy; setLang: (value: LandingLang) => void } {
  const [lang, setLangState] = useState<LandingLang>(() =>
    detectLandingLang(globalThis.location?.search ?? '', storageGet(STORAGE_KEY), globalThis.navigator?.language, import.meta.env.VITE_DEFAULT_LANG),
  )
  const copy = COPY[lang]

  const setLang = useCallback((value: LandingLang) => {
    setLangState(value)
    storageSet(STORAGE_KEY, value)
  }, [])

  useEffect(() => {
    document.documentElement.lang = lang
    document.title = copy.meta.title
    document.querySelector('meta[name="description"]')?.setAttribute('content', copy.meta.description)
  }, [lang, copy])

  return { lang, copy, setLang }
}
