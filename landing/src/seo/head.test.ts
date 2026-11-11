// @vitest-environment node
import { describe, expect, it } from 'vitest'
import { en } from '../copy/en'
import { es } from '../copy/es'
import { detectLandingLang } from '../lib/locale'
import { jsonForScript, pageHtml, robotsTxt, seoHead, sitemapXml, structuredData, withContentSecurityPolicy } from './head'

const SITE = 'https://www.ejemplo.test'
const BASE = `<!doctype html>
<html lang="es">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Viejo</title>
    <meta name="description" content="Vieja" />
    <meta property="og:type" content="website" />
    <meta property="og:title" content="Viejo" />
    <script>document.documentElement.classList.add('js')</script>
  </head>
  <body>
    <div id="root"></div>
    <script type="module">console.log('app')</script>
  </body>
</html>
`

describe('SEO de la página publicada', () => {
  it('cada idioma tiene su dirección, su canonical y enlaza al otro con hreflang', () => {
    const esHead = seoHead({ lang: 'es', copy: es, siteUrl: SITE })
    const enHead = seoHead({ lang: 'en', copy: en, siteUrl: SITE })

    expect(esHead).toContain(`<link rel="canonical" href="${SITE}/" />`)
    expect(enHead).toContain(`<link rel="canonical" href="${SITE}/en/" />`)
    for (const head of [esHead, enHead]) {
      expect(head).toContain(`<link rel="alternate" hreflang="es" href="${SITE}/" />`)
      expect(head).toContain(`<link rel="alternate" hreflang="en" href="${SITE}/en/" />`)
      expect(head).toContain(`<link rel="alternate" hreflang="x-default" href="${SITE}/" />`)
      expect(head).toContain('content="index, follow')
    }
    expect(esHead).toContain(`<title>${es.meta.title}</title>`)
    expect(enHead).toContain(`<title>${en.meta.title}</title>`)
    expect(enHead).toContain(`${SITE}/media/og-en.png`)
  })

  it('sin dirección pública no inventa canonical, hreflang ni imagen', () => {
    const head = seoHead({ lang: 'es', copy: es, siteUrl: '' })

    expect(head).not.toMatch(/canonical|hreflang|og:image|og:url|twitter:/)
    expect(head).toContain(`<title>${es.meta.title}</title>`)
    expect(head).toContain('application/ld+json')
  })

  it('el código de Search Console solo aparece si se configuró', () => {
    expect(seoHead({ lang: 'es', copy: es, siteUrl: SITE })).not.toContain('google-site-verification')
    expect(seoHead({ lang: 'es', copy: es, siteUrl: SITE, googleVerification: 'abc123' })).toContain('<meta name="google-site-verification" content="abc123" />')
  })

  it('los datos estructurados solo afirman lo que la página dice: sin valoraciones ni precios', () => {
    const data = structuredData({ lang: 'en', copy: en, siteUrl: SITE })
    const graph = data['@graph'] as Record<string, unknown>[]
    const faq = graph.find((node) => node['@type'] === 'FAQPage') as { mainEntity: { name: string; acceptedAnswer: { text: string } }[] }

    expect(graph.map((node) => node['@type'])).toEqual(['Organization', 'WebSite', 'SoftwareApplication', 'FAQPage'])
    expect(faq.mainEntity.map((q) => q.name)).toEqual(en.faq.items.map((item) => item.q))
    expect(faq.mainEntity[0]!.acceptedAnswer.text).toBe(en.faq.items[0]!.a)
    const keys = graph.flatMap((node) => Object.keys(node))
    expect(keys.filter((key) => ['aggregateRating', 'review', 'offers', 'price'].includes(key))).toEqual([])
  })

  it('un texto no puede cerrar el bloque de datos estructurados', () => {
    expect(jsonForScript({ a: '</script><script>alert(1)</script>' })).not.toContain('<')
    expect(JSON.parse(jsonForScript({ a: '</script>&' }))).toEqual({ a: '</script>&' })
  })

  it('la página de cada idioma lleva su contenido escrito en el HTML y reemplaza los metadatos viejos', () => {
    const page = pageHtml(BASE, { lang: 'en', copy: en, siteUrl: SITE }, '<main><h1>Cost: $1 & $&</h1></main>')

    expect(page).toContain('<html lang="en" data-page-lang="en" data-root="../">')
    expect(page).toContain('<div id="root"><main><h1>Cost: $1 & $&</h1></main></div>')
    expect(page).not.toMatch(/Viejo|Vieja/)
    expect(page.match(/<title>/g)).toHaveLength(1)
    expect(pageHtml(BASE, { lang: 'es', copy: es, siteUrl: SITE }, '')).toContain('<html lang="es" data-page-lang="es" data-root="./">')
  })

  it('robots.txt permite todo y anuncia el sitemap; el sitemap lista las dos páginas', () => {
    expect(robotsTxt(SITE).startsWith('User-agent: *' + String.fromCharCode(10) + 'Allow: /')).toBe(true)
    expect(robotsTxt(SITE)).toContain(`Sitemap: ${SITE}/sitemap.xml`)
    expect(robotsTxt('')).not.toContain('Sitemap')
    expect(robotsTxt(SITE)).not.toContain('Disallow')
    const sitemap = sitemapXml(SITE, '2026-11-11')
    expect(sitemap.match(/<loc>/g)).toHaveLength(2)
    expect(sitemap).toContain(`<loc>${SITE}/en/</loc>`)
    expect(sitemap).toContain('<lastmod>2026-11-11</lastmod>')
  })

  it('la CSP autoriza por huella los scripts ejecutables y no los bloques de datos', () => {
    const page = pageHtml(BASE, { lang: 'es', copy: es, siteUrl: SITE }, '<main></main>')
    const { html, scripts } = withContentSecurityPolicy(page, { media: '', api: 'https://app.ejemplo.test' })
    const policy = /http-equiv="Content-Security-Policy" content="([^"]+)"/.exec(html)![1]!

    expect(scripts).toBe(2)
    expect(policy).toMatch(/script-src 'sha256-[^']+' 'sha256-[^']+';/)
    expect(policy).not.toMatch(/script-src[^;]*unsafe/)
    expect(policy).toContain("connect-src https://app.ejemplo.test")
    expect(policy).toContain("default-src 'none'")
  })

  it('la huella se calcula como la calcula el navegador: con los saltos de línea normalizados', () => {
    const page = ['<meta charset="UTF-8" />', '<script>', '      var a = 1', '    </script>'].join('\n')
    const unix = withContentSecurityPolicy(page, { media: '', api: '' })
    const windows = withContentSecurityPolicy(page.replaceAll('\n', '\r\n'), { media: '', api: '' })

    expect(windows.html).toBe(unix.html)
    expect(windows.html).not.toContain('\r')
    expect(unix.scripts).toBe(1)
  })
})

describe('Idioma de una página publicada', () => {
  it('lo decide la URL, no el navegador ni lo guardado: Google indexa lo mismo que ve la persona', () => {
    expect(detectLandingLang('', null, 'en-US', undefined, 'es')).toBe('es')
    expect(detectLandingLang('?lang=es', 'es', 'es-EC', 'es', 'en')).toBe('en')
  })

  it('sin página declarada (desarrollo) conserva la detección de siempre', () => {
    expect(detectLandingLang('?lang=en', 'es', 'es-EC')).toBe('en')
    expect(detectLandingLang('', null, 'en-US')).toBe('en')
  })
})
