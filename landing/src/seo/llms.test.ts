// @vitest-environment node
import { describe, expect, it } from 'vitest'
import { en } from '../copy/en'
import { es } from '../copy/es'
import { AI_AGENTS, robotsTxt, structuredData } from './head'
import { llmsFiles, llmsTxt } from './llms'

const SITE = 'https://www.ejemplo.test'

describe('Visibilidad para agentes de IA', () => {
  it('robots.txt da la bienvenida por nombre a los agentes de IA y no bloquea nada', () => {
    const robots = robotsTxt(SITE)

    for (const agent of ['GPTBot', 'ClaudeBot', 'PerplexityBot', 'Google-Extended', 'OAI-SearchBot']) {
      expect(AI_AGENTS).toContain(agent)
      expect(robots).toContain(`User-agent: ${agent}\n`)
    }
    expect(robots).not.toMatch(/^Disallow/m)
    expect(robots).toContain('/llms.txt')
  })

  it('publica el resumen en inglés y en español, corto y completo', () => {
    expect(llmsFiles().map((file) => file.name)).toEqual(['llms.txt', 'llms-full.txt', 'llms-es.txt', 'llms-full-es.txt'])
  })

  it('el resumen dice lo que un agente necesita para comparar: qué es, para quién, dónde corre y cómo contactar', () => {
    const text = llmsTxt({ lang: 'es', copy: es, siteUrl: SITE, contactEmail: 'info@ejemplo.test' }, false)

    expect(text.startsWith('# Predictily\n\n> ')).toBe(true)
    expect(text).toContain(es.meta.description)
    for (const option of es.hosting.items) expect(text).toContain(`**${option.tag}**`)
    expect(text).toContain('Autohospedado; VPS; Servidor compartido')
    expect(text).toContain('- Contacto: info@ejemplo.test')
    expect(text).toContain(`- Sitio: ${SITE}/`)
    expect(text).toContain(`(${SITE}/llms-full-es.txt)`)
    expect(text).toContain(`(${SITE}/llms.txt)`)
    expect(text.length).toBeLessThan(6000)
  })

  it('la versión completa lleva módulos, seguridad, tecnología con su aclaración y todas las preguntas', () => {
    const text = llmsTxt({ lang: 'en', copy: en, siteUrl: SITE, contactEmail: '' }, true)

    for (const module of en.platform.modules) expect(text).toContain(`**${module.title}**`)
    for (const item of en.faq.items) expect(text).toContain(`### ${item.q}`)
    for (const item of en.tech.items) expect(text).toContain(`- ${item.name}: ${item.role}`)
    expect(text).toContain(en.tech.note)
    expect(text).not.toContain('Contact:')
  })

  it('sin dirección pública no inventa enlaces', () => {
    expect(llmsTxt({ lang: 'es', copy: es, siteUrl: '', contactEmail: '' }, false)).not.toMatch(/https?:\/\//)
  })

  it('los datos estructurados declaran funciones, público, modalidades y contacto', () => {
    const graph = structuredData({ lang: 'es', copy: es, siteUrl: SITE, contactEmail: 'info@ejemplo.test' })['@graph'] as Record<string, unknown>[]
    const app = graph.find((node) => node['@type'] === 'SoftwareApplication')!
    const org = graph.find((node) => node['@type'] === 'Organization')!

    expect(app.featureList).toEqual(es.platform.modules.map((module) => module.title))
    expect(app.softwareRequirements).toBe('Autohospedado · VPS · Servidor compartido')
    expect((app.audience as { audienceType: string }).audienceType).toContain('Distribuidoras')
    expect(org.email).toBe('info@ejemplo.test')
    expect(structuredData({ lang: 'es', copy: es, siteUrl: SITE })['@graph']).not.toEqual(expect.arrayContaining([expect.objectContaining({ email: expect.anything() })]))
  })
})

describe('Tecnología de terceros', () => {
  it('nombra a los cuatro proveedores y aclara en los dos idiomas que no son socios', () => {
    for (const copy of [es, en]) {
      expect(copy.tech.items.map((item) => item.name)).toEqual(['AWS', 'Cloudflare', 'Microsoft', 'Anthropic'])
      expect(copy.tech.note).toMatch(/no es socio|not a partner/)
      expect(copy.tech.note).toMatch(/marcas|trademarks/)
    }
  })
})
