import { renderToString } from 'react-dom/server'
import { App } from './App'
import { en } from './copy/en'
import { es } from './copy/es'
import type { LandingCopy, LandingLang } from './copy/types'
import { setPageRoot } from './lib/page'

/**
 * Lo usa el build (vite.config.ts), no el navegador: escribe el contenido de la página como HTML para que un
 * buscador lo lea sin ejecutar scripts. `root` es la ruta desde la página hasta la raíz del sitio.
 */
export function render(lang: LandingLang, root: './' | '../'): string {
  setPageRoot(root)
  return renderToString(<App initialLang={lang} />)
}

export const copies: Record<LandingLang, LandingCopy> = { es, en }
