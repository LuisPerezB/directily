/// <reference types="vitest/config" />
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { createServer, defineConfig, loadEnv, type Plugin } from 'vite'
import react from '@vitejs/plugin-react'
import { viteSingleFile } from 'vite-plugin-singlefile'
import type { LandingCopy, LandingLang } from './src/copy/types'
import { LANGS, pageHtml, robotsTxt, sitemapXml, withContentSecurityPolicy } from './src/seo/head.ts'
import { llmsFiles, llmsTxt } from './src/seo/llms.ts'

// La landing se publica como páginas autónomas: el script y los estilos quedan dentro de cada index.html
// (vite-plugin-singlefile), así se puede subir a cualquier alojamiento estático.
// Toda la configuración sale de .env (ver .env.example).

const LOCAL_HOSTS = new Set(['localhost', '127.0.0.1', '[::1]'])

/** Origen (`https://host`) de una URL de configuración, o '' si está vacía o es relativa. Falla si no es segura. */
function originOf(name: string, value: string | undefined): string {
  const raw = (value ?? '').trim()
  if (raw === '' || raw.startsWith('.') || raw.startsWith('/')) return ''
  let url: URL
  try {
    url = new URL(raw)
  } catch {
    throw new Error(`${name} no es una URL válida.`)
  }
  const local = url.protocol === 'http:' && (LOCAL_HOSTS.has(url.hostname) || url.hostname.endsWith('.localhost'))
  if (url.protocol !== 'https:' && !local) throw new Error(`${name} debe usar https:// (http solo hacia el propio equipo).`)
  if (url.username || url.password) throw new Error(`${name} no admite usuario ni contraseña.`)
  return url.origin
}

interface PublishOptions {
  siteUrl: string
  googleVerification: string
  /** Correo de contacto público (el de la página); se declara en los datos estructurados y en llms.txt. */
  contactEmail: string
  origins: { media: string; api: string }
}

/**
 * Último paso del build: convierte la página compilada en lo que se publica.
 *
 *  1. PRERENDER. Ejecuta la aplicación en Node y escribe su contenido dentro de `#root`: un buscador (o quien no
 *     ejecuta scripts) lee toda la página en el HTML. En el navegador React la reemplaza por la versión interactiva.
 *  2. UNA DIRECCIÓN POR IDIOMA. `dist/index.html` (español) y `dist/en/index.html` (inglés), cada una con su título,
 *     descripción, `canonical`, `hreflang` y datos estructurados.
 *  3. `robots.txt` y, con `VITE_SITE_URL`, `sitemap.xml`.
 *  4. Content-Security-Policy de cada página, con sus scripts autorizados por huella. `frame-ancestors` y HSTS no
 *     se pueden declarar en una etiqueta: van como cabeceras del alojamiento (public/_headers, README).
 */
function publishPages(options: PublishOptions): Plugin {
  let root = ''
  let outDir = ''
  let mode = 'production'
  return {
    name: 'predictily:publish-pages',
    apply: 'build',
    configResolved(config) {
      root = config.root
      outDir = resolve(config.root, config.build.outDir)
      mode = config.mode
    },
    async closeBundle() {
      let base: string
      try {
        base = readFileSync(resolve(outDir, 'index.html'), 'utf8')
      } catch {
        return
      }

      // Un servidor de Vite sin red, solo para cargar el código de la página (TSX) en Node.
      const server = await createServer({ root, mode, configFile: false, plugins: [react()], appType: 'custom', logLevel: 'error', server: { middlewareMode: true, hmr: false, watch: null }, optimizeDeps: { noDiscovery: true } })
      let prerender: { render: (lang: LandingLang, root: './' | '../') => string; copies: Record<LandingLang, LandingCopy> }
      try {
        prerender = (await server.ssrLoadModule('/src/prerender.tsx')) as typeof prerender
        for (const lang of LANGS) {
          const body = prerender.render(lang, lang === 'en' ? '../' : './')
          const info = { lang, copy: prerender.copies[lang], siteUrl: options.siteUrl, googleVerification: options.googleVerification, contactEmail: options.contactEmail }
          const page = pageHtml(base, info, body)
          const secured = withContentSecurityPolicy(page, options.origins)
          const dir = lang === 'en' ? resolve(outDir, 'en') : outDir
          mkdirSync(dir, { recursive: true })
          writeFileSync(resolve(dir, 'index.html'), secured.html)
          this.info(`${lang === 'en' ? 'en/index.html' : 'index.html'}: ${Math.round(body.length / 1024)} kB de contenido en HTML, ${secured.scripts} script(s) autorizados por huella.`)
        }
        // Resumen para agentes de IA (llms.txt), generado del mismo copy que la página.
        for (const file of llmsFiles()) {
          writeFileSync(resolve(outDir, file.name), llmsTxt({ lang: file.lang, copy: prerender.copies[file.lang], siteUrl: options.siteUrl, contactEmail: options.contactEmail }, file.full))
        }
        this.info(`${llmsFiles().map((file) => file.name).join(', ')}: resumen para agentes de IA.`)
      } finally {
        await server.close()
      }

      writeFileSync(resolve(outDir, 'robots.txt'), robotsTxt(options.siteUrl))
      if (options.siteUrl) writeFileSync(resolve(outDir, 'sitemap.xml'), sitemapXml(options.siteUrl, new Date().toISOString().slice(0, 10)))
    },
  }
}

export default defineConfig(({ mode, command }) => {
  const env = loadEnv(mode, process.cwd(), 'VITE_')
  const port = Number(env.VITE_DEV_PORT) || 5174

  // Una URL insegura detiene el build: mejor no publicar que publicar un enlace o un envío en claro.
  const siteUrl = originOf('VITE_SITE_URL', env.VITE_SITE_URL) ? (env.VITE_SITE_URL ?? '').trim().replace(/\/+$/, '') : ''
  const api = originOf('VITE_CONTACT_API_URL', env.VITE_CONTACT_API_URL)
  const media = originOf('VITE_MEDIA_BASE_URL', env.VITE_MEDIA_BASE_URL)
  originOf('VITE_APP_URL', env.VITE_APP_URL)
  const googleVerification = (env.VITE_GOOGLE_SITE_VERIFICATION ?? '').trim()
  if (googleVerification !== '' && !/^[A-Za-z0-9_-]{20,100}$/.test(googleVerification)) {
    throw new Error('VITE_GOOGLE_SITE_VERIFICATION debe ser solo el código (el valor de «content» de la etiqueta de Search Console).')
  }

  if (command === 'build' && mode !== 'test') {
    const missing = [
      !api && !env.VITE_CONTACT_EMAIL?.trim() && !env.VITE_CONTACT_WHATSAPP?.trim() && 'ningún canal de contacto (VITE_CONTACT_EMAIL / VITE_CONTACT_WHATSAPP): el formulario quedará deshabilitado',
      !siteUrl && 'VITE_SITE_URL: sin canonical, hreflang, sitemap ni imagen para compartir (Google no sabrá cuál es la dirección oficial)',
    ].filter(Boolean)
    for (const item of missing) console.warn(`\n[predictily-landing] AVISO antes de publicar — falta ${item}.`)
  }

  return {
    plugins: [react(), viteSingleFile(), publishPages({ siteUrl, googleVerification, contactEmail: /^[^\s@<>,;:?&"']+@[^\s@<>,;:?&"']+\.[^\s@<>,;:?&"']+$/.test((env.VITE_CONTACT_EMAIL ?? '').trim()) ? (env.VITE_CONTACT_EMAIL ?? '').trim() : '', origins: { media, api } })],
    server: { port, strictPort: true },
    preview: { port, strictPort: true },
    build: { target: 'es2022' },
    test: { environment: 'jsdom', include: ['src/**/*.test.{ts,tsx}'], css: false },
  }
})
