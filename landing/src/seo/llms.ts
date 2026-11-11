import type { LandingCopy, LandingLang } from '../copy/types'
import { pagePath } from './head.ts'

/**
 * Resumen del producto para agentes de IA (convención llms.txt: Markdown en texto plano en la raíz del sitio).
 * Un agente que compara opciones para una empresa lee esto antes que el HTML: qué es, para quién, qué hace, dónde
 * se instala, cómo se conecta y cómo se contrata. Se genera del MISMO copy de la página, así nunca dice otra cosa.
 */

interface LlmsInfo {
  lang: LandingLang
  copy: LandingCopy
  /** Dirección pública sin barra final, o ''. */
  siteUrl: string
  /** Correo de contacto público, o ''. */
  contactEmail: string
}

const LABELS: Record<LandingLang, Record<string, string>> = {
  es: {
    category: 'Categoría',
    categoryValue: 'Software B2B de inteligencia comercial predictiva: pronóstico de demanda e ingresos, inventario y abastecimiento, recompra de clientes',
    audience: 'Para quién',
    region: 'Mercado',
    regionValue: 'Ecuador (español, USD, calendario comercial ecuatoriano); arquitectura preparada para Latinoamérica',
    pricing: 'Precio',
    contact: 'Contacto',
    website: 'Sitio',
    facts: 'Datos clave',
    what: 'Qué hace',
    modules: 'Módulos',
    how: 'Cómo se calcula cada cifra',
    hosting: 'Dónde se instala',
    connect: 'Cómo se conecta con los sistemas de la empresa',
    tech: 'Tecnología de terceros que usa',
    isNot: 'Qué es y qué no es',
    is: 'Es',
    not: 'No es',
    security: 'Seguridad y control de datos',
    process: 'Cómo se contrata',
    faq: 'Preguntas frecuentes',
    pages: 'Páginas',
    full: 'Versión completa de este resumen',
    other: 'English version',
    home: 'Página principal (español)',
    homeOther: 'Página principal (inglés)',
  },
  en: {
    category: 'Category',
    categoryValue: 'B2B predictive commercial intelligence software: demand and revenue forecasting, inventory and replenishment, customer repurchase',
    audience: 'Who it is for',
    region: 'Market',
    regionValue: 'Ecuador (Spanish, USD, Ecuadorian commercial calendar); architecture ready for Latin America',
    pricing: 'Pricing',
    contact: 'Contact',
    website: 'Website',
    facts: 'Key facts',
    what: 'What it does',
    modules: 'Modules',
    how: 'How every figure is calculated',
    hosting: 'Deployment options',
    connect: 'How it connects to company systems',
    tech: 'Third-party technology it uses',
    isNot: 'What it is and what it is not',
    is: 'It is',
    not: 'It is not',
    security: 'Security and data control',
    process: 'How to buy',
    faq: 'Frequently asked questions',
    pages: 'Pages',
    full: 'Full version of this summary',
    other: 'Versión en español',
    home: 'Home page (English)',
    homeOther: 'Home page (Spanish)',
  },
}

const join = (title: readonly [string, string]): string => `${title[0]} ${title[1]}`.trim()

function fileName(lang: LandingLang, full: boolean): string {
  return `llms${full ? '-full' : ''}${lang === 'es' ? '-es' : ''}.txt`
}

/** Nombres de los archivos que se publican en la raíz del sitio. */
export function llmsFiles(): { lang: LandingLang; full: boolean; name: string }[] {
  return (['en', 'es'] as const).flatMap((lang) => [false, true].map((full) => ({ lang, full, name: fileName(lang, full) })))
}

/** `llms.txt` (resumen) o `llms-full.txt` (todo el contenido) de un idioma. */
export function llmsTxt({ lang, copy, siteUrl, contactEmail }: LlmsInfo, full: boolean): string {
  const t = LABELS[lang]
  const other: LandingLang = lang === 'es' ? 'en' : 'es'
  const url = (path: string): string => `${siteUrl}${path}`
  const pricing = copy.faq.items.find((item) => /cuesta|cost/i.test(item.q))?.a
  const lines: string[] = [`# Predictily`, '', `> ${copy.meta.description}`, '']

  lines.push(`## ${t.facts}`, '')
  lines.push(`- ${t.category}: ${t.categoryValue}`)
  lines.push(`- ${t.audience}: ${copy.sectors.items.map((item) => item.title).join('; ')}`)
  lines.push(`- ${t.region}: ${t.regionValue}`)
  lines.push(`- ${t.hosting}: ${copy.hosting.items.map((item) => item.tag).join('; ')}`)
  lines.push(`- ${t.connect}: ${copy.sources.items.join(', ')}`)
  if (pricing) lines.push(`- ${t.pricing}: ${pricing}`)
  if (contactEmail) lines.push(`- ${t.contact}: ${contactEmail}`)
  if (siteUrl) lines.push(`- ${t.website}: ${url(pagePath(lang))}`)
  lines.push('')

  lines.push(`## ${t.what}`, '')
  for (const layer of copy.platform.layers) lines.push(`- **${layer.name}** — ${layer.title} ${layer.text}`)
  lines.push('')

  lines.push(`## ${t.hosting}`, '', copy.hosting.lead, '')
  for (const option of copy.hosting.items) lines.push(`- **${option.tag}** — ${option.title}. ${option.text}`)
  lines.push('', copy.hosting.note, '')

  lines.push(`## ${t.isNot}`, '')
  for (const row of copy.compare.rows) lines.push(`- ${t.is}: ${row.is} ${t.not}: ${row.isNot}`)
  lines.push('')

  if (full) {
    lines.push(`## ${t.modules}`, '')
    for (const module of copy.platform.modules) lines.push(`- **${module.title}** — ${module.text}`)
    lines.push('')

    lines.push(`## ${t.how}`, '')
    for (const item of copy.trust.method) lines.push(`- ${item}`)
    lines.push('')

    lines.push(`## ${t.audience}`, '', copy.sectors.lead, '')
    for (const sector of copy.sectors.items) lines.push(`- **${sector.title}** — ${sector.text}`)
    lines.push('')

    lines.push(`## ${t.security}`, '')
    for (const item of copy.security.items) lines.push(`- **${item.title}** — ${item.text}`)
    lines.push('', copy.security.note, '')

    lines.push(`## ${t.tech}`, '')
    for (const item of copy.tech.items) lines.push(`- ${item.name}: ${item.role}`)
    lines.push('', copy.tech.note, '')

    lines.push(`## ${t.process}`, '', join(copy.plan.title), '')
    copy.plan.steps.forEach((step, i) => lines.push(`${i + 1}. **${step.title}** — ${step.text}`))
    lines.push('')

    lines.push(`## ${t.faq}`, '')
    for (const item of copy.faq.items) lines.push(`### ${item.q}`, '', item.a, '')
  }

  if (siteUrl) {
    lines.push(`## ${t.pages}`, '')
    lines.push(`- [${t.home}](${url(pagePath(lang))})`)
    lines.push(`- [${t.homeOther}](${url(pagePath(other))})`)
    if (!full) lines.push(`- [${t.full}](${url(`/${fileName(lang, true)}`)})`)
    lines.push(`- [${t.other}](${url(`/${fileName(other, full)}`)})`)
    lines.push('')
  }
  return lines.join('\n')
}
