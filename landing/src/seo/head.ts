import { createHash } from 'node:crypto'
import type { LandingCopy, LandingLang } from '../copy/types'

/**
 * Lo que un buscador necesita de la página, calculado en el build (vite.config.ts) y no en el navegador:
 * metadatos por idioma, enlaces entre idiomas, datos estructurados, sitemap, robots y la política de seguridad.
 * Nada de esto entra en el script de la página.
 */

export const LANGS: readonly LandingLang[] = ['es', 'en']
const OG_LOCALE: Record<LandingLang, string> = { es: 'es_EC', en: 'en_US' }

export interface PageInfo {
  lang: LandingLang
  copy: LandingCopy
  /** Dirección pública sin barra final, o '' si no se configuró `VITE_SITE_URL`. */
  siteUrl: string
  /** Código de Google Search Console, o ''. */
  googleVerification?: string
}

/** Ruta de la página de un idioma dentro del sitio: `/` (español) o `/en/`. */
export function pagePath(lang: LandingLang): string {
  return lang === 'en' ? '/en/' : '/'
}

export function escapeHtml(text: string): string {
  return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')
}

/** JSON listo para un bloque `<script type="application/ld+json">`: nada en él puede cerrar la etiqueta. */
export function jsonForScript(value: unknown): string {
  return JSON.stringify(value).replace(/</g, '\\u003c').replace(/>/g, '\\u003e').replace(/&/g, '\\u0026').replace(/\u2028/g, '\\u2028').replace(/\u2029/g, '\\u2029')
}

/**
 * Datos estructurados (schema.org). Solo lo que es cierto y está escrito en la página: la organización, el sitio,
 * la aplicación y las preguntas frecuentes. Sin valoraciones, precios ni reseñas (no existen).
 */
export function structuredData({ lang, copy, siteUrl }: PageInfo): Record<string, unknown> {
  const url = siteUrl ? `${siteUrl}${pagePath(lang)}` : undefined
  const org = siteUrl ? { '@id': `${siteUrl}/#organization` } : undefined
  const graph: Record<string, unknown>[] = [
    { '@type': 'Organization', ...org, name: 'Predictily', ...(siteUrl && { url: `${siteUrl}/`, logo: `${siteUrl}/media/icono.svg` }) },
    { '@type': 'WebSite', ...(siteUrl && { '@id': `${siteUrl}/#website`, url: `${siteUrl}/`, publisher: org }), name: 'Predictily', inLanguage: [...LANGS] },
    {
      '@type': 'SoftwareApplication',
      name: 'Predictily',
      applicationCategory: 'BusinessApplication',
      operatingSystem: 'Web',
      description: copy.meta.description,
      inLanguage: lang,
      ...(url && { url, publisher: org }),
    },
    {
      '@type': 'FAQPage',
      ...(url && { '@id': `${url}#faq` }),
      inLanguage: lang,
      mainEntity: copy.faq.items.map((item) => ({ '@type': 'Question', name: item.q, acceptedAnswer: { '@type': 'Answer', text: item.a } })),
    },
  ]
  return { '@context': 'https://schema.org', '@graph': graph }
}

/** Etiquetas de `<head>` de la página de un idioma (título, descripción, redes, idiomas alternos, datos estructurados). */
export function seoHead(page: PageInfo): string {
  const { lang, copy, siteUrl, googleVerification } = page
  const title = escapeHtml(copy.meta.title)
  const description = escapeHtml(copy.meta.description)
  const tags = [
    `<title>${title}</title>`,
    `<meta name="description" content="${description}" />`,
    '<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1" />',
    googleVerification ? `<meta name="google-site-verification" content="${escapeHtml(googleVerification)}" />` : '',
    '<meta property="og:type" content="website" />',
    '<meta property="og:site_name" content="Predictily" />',
    `<meta property="og:title" content="${title}" />`,
    `<meta property="og:description" content="${description}" />`,
    `<meta property="og:locale" content="${OG_LOCALE[lang]}" />`,
    ...LANGS.filter((other) => other !== lang).map((other) => `<meta property="og:locale:alternate" content="${OG_LOCALE[other]}" />`),
  ]
  if (siteUrl) {
    const url = `${siteUrl}${pagePath(lang)}`
    tags.push(
      `<link rel="canonical" href="${url}" />`,
      ...LANGS.map((other) => `<link rel="alternate" hreflang="${other}" href="${siteUrl}${pagePath(other)}" />`),
      `<link rel="alternate" hreflang="x-default" href="${siteUrl}/" />`,
      `<meta property="og:url" content="${url}" />`,
      `<meta property="og:image" content="${siteUrl}/media/og-${lang}.png" />`,
      '<meta property="og:image:width" content="1200" />',
      '<meta property="og:image:height" content="630" />',
      '<meta name="twitter:card" content="summary_large_image" />',
      `<meta name="twitter:title" content="${title}" />`,
      `<meta name="twitter:description" content="${description}" />`,
      `<meta name="twitter:image" content="${siteUrl}/media/og-${lang}.png" />`,
    )
  }
  tags.push(`<script type="application/ld+json">${jsonForScript(structuredData(page))}</script>`)
  return tags.filter(Boolean).join('\n    ')
}

/**
 * Página de un idioma a partir de la página compilada: idioma y raíz en `<html>`, sus metadatos y el contenido ya
 * escrito dentro de `#root` (lo que lee un buscador sin ejecutar el script).
 */
export function pageHtml(base: string, page: PageInfo, body: string): string {
  const root = page.lang === 'en' ? '../' : './'
  const stripped = base
    .replace(/<html[^>]*>/, `<html lang="${page.lang}" data-page-lang="${page.lang}" data-root="${root}">`)
    .replace(/[ \t]*<title>[\s\S]*?<\/title>\r?\n/, '')
    .replace(/[ \t]*<meta name="description"[^>]*>\r?\n/, '')
    .replace(/[ \t]*<meta property="og:[^>]*>\r?\n/g, '')
    .replace(/[ \t]*<meta name="twitter:[^>]*>\r?\n/g, '')
  const marker = '<meta name="viewport"'
  if (!stripped.includes(marker) || !stripped.includes('<div id="root"></div>')) throw new Error('La página compilada no tiene la forma esperada (viewport o #root).')
  // Con función: el contenido puede traer «$» y no debe leerse como patrón de reemplazo.
  return stripped.replace('<div id="root"></div>', () => `<div id="root">${body}</div>`).replace(marker, () => `${seoHead(page)}\n    ${marker}`)
}

export function robotsTxt(siteUrl: string): string {
  return ['User-agent: *', 'Allow: /', ...(siteUrl ? ['', `Sitemap: ${siteUrl}/sitemap.xml`] : []), ''].join('\n')
}

/** Sitemap con las dos páginas y sus equivalentes por idioma. `lastmod` en AAAA-MM-DD. */
export function sitemapXml(siteUrl: string, lastmod: string): string {
  const alternates = [...LANGS.map((lang) => `    <xhtml:link rel="alternate" hreflang="${lang}" href="${siteUrl}${pagePath(lang)}" />`), `    <xhtml:link rel="alternate" hreflang="x-default" href="${siteUrl}/" />`].join('\n')
  const urls = LANGS.map((lang) => `  <url>\n    <loc>${siteUrl}${pagePath(lang)}</loc>\n    <lastmod>${lastmod}</lastmod>\n${alternates}\n  </url>`).join('\n')
  return `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">\n${urls}\n</urlset>\n`
}

/**
 * Content-Security-Policy de una página compilada. El script va EN LÍNEA (un solo archivo), así que cada bloque
 * ejecutable se autoriza por su SHA-256: un script inyectado no coincide con ninguna huella y el navegador no lo
 * ejecuta. Los bloques de datos (`application/ld+json`) no se ejecutan y no necesitan huella.
 */
export function withContentSecurityPolicy(source: string, origins: { media: string; api: string }): { html: string; scripts: number } {
  // El navegador normaliza los saltos de línea (CRLF → LF) ANTES de calcular la huella de un script: con el archivo
  // en CRLF (un checkout de Windows) la huella no coincidiría y el script quedaría bloqueado.
  const html = source.replace(/\r\n?/g, '\n')
  const hashes = [...html.matchAll(/<script\b([^>]*)>([\s\S]*?)<\/script>/g)]
    .filter(([, attrs = '', body = '']) => !/\bsrc\s*=/.test(attrs) && !/type="application\/ld\+json"/.test(attrs) && body.trim() !== '')
    .map(([, , body = '']) => `'sha256-${createHash('sha256').update(body, 'utf8').digest('base64')}'`)
  const unique = [...new Set(hashes)]
  const list = (...values: string[]) => values.filter(Boolean).join(' ')
  const policy = [
    "default-src 'none'",
    `script-src ${list(...unique) || "'none'"}`,
    // Estilos: el bloque compilado y los atributos `style` que React escribe (anchos de barras, retardos).
    "style-src 'unsafe-inline'",
    `img-src ${list("'self'", 'data:', origins.media)}`,
    `media-src ${list("'self'", origins.media)}`,
    'font-src data:',
    `connect-src ${origins.api || "'none'"}`,
    "base-uri 'none'",
    "form-action 'none'",
    "manifest-src 'none'",
  ].join('; ')
  const anchor = '<meta charset="UTF-8" />'
  if (!html.includes(anchor)) throw new Error('No se encontró dónde insertar la CSP.')
  return { html: html.replace(anchor, `${anchor}\n    <meta http-equiv="Content-Security-Policy" content="${policy}" />`), scripts: unique.length }
}
