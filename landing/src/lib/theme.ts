import { useCallback, useState } from 'react'
import { storageGet, storageSet } from './storage'

export type ThemePreference = 'system' | 'light' | 'dark'

const STORAGE_KEY = 'predictily.theme'
const ORDER: ThemePreference[] = ['system', 'light', 'dark']

function stored(): ThemePreference {
  const value = storageGet(STORAGE_KEY)
  return value === 'light' || value === 'dark' ? value : 'system'
}

/** Tema de la página: el del sistema, o claro/oscuro fijados con `data-theme` en <html>. */
export function useTheme(): { theme: ThemePreference; cycleTheme: () => void } {
  const [theme, setTheme] = useState<ThemePreference>(stored)

  const cycleTheme = useCallback(() => {
    setTheme((current) => {
      const next = ORDER[(ORDER.indexOf(current) + 1) % ORDER.length] ?? 'system'
      if (next === 'system') document.documentElement.removeAttribute('data-theme')
      else document.documentElement.setAttribute('data-theme', next)
      storageSet(STORAGE_KEY, next === 'system' ? null : next)
      return next
    })
  }, [])

  return { theme, cycleTheme }
}
