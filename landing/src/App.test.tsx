import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { en } from './copy/en'
import { es } from './copy/es'
import { buildContactUrl } from './lib/contactLink'
import { detectLandingLang } from './lib/locale'

// El destino del contacto se fija al compilar: aquí se simula una landing con API configurada.
vi.mock('./lib/contact', async (original) => ({
  ...(await original<typeof import('./lib/contact')>()),
  contactConfig: { apiUrl: 'https://app.ejemplo.test/api/v1/contact-requests', email: '', whatsapp: '' },
}))

import { App } from './App'

/** Recorre el copy y devuelve las rutas de sus hojas, para comparar la forma de ambos idiomas. */
function shape(node: unknown, path = ''): string[] {
  if (Array.isArray(node)) return [`${path}[${node.length}]`, ...node.flatMap((child, i) => shape(child, `${path}.${i}`))]
  if (node && typeof node === 'object') return Object.entries(node).flatMap(([key, child]) => shape(child, `${path}.${key}`))
  return [path]
}

const field = (id: string) => document.getElementById(id) as HTMLInputElement
function fillRequired(): void {
  fireEvent.change(field('lp-name'), { target: { value: ' Ana Pérez ' } })
  fireEvent.change(field('lp-company'), { target: { value: 'Distribuidora Ejemplo' } })
  fireEvent.change(field('lp-email'), { target: { value: 'ana@ejemplo.test' } })
}

describe('Landing bilingüe (React)', () => {
  beforeEach(() => {
    localStorage.clear()
    // jsdom se presenta como en-US: se fija español para partir del idioma del producto.
    localStorage.setItem('predictily.landing.lang', 'es')
    vi.stubGlobal('fetch', vi.fn())
  })
  afterEach(() => {
    cleanup()
    vi.unstubAllGlobals()
  })

  it('español e inglés tienen la misma estructura y ningún texto vacío', () => {
    expect(shape(en)).toEqual(shape(es))
    const empty = (node: unknown): boolean => (typeof node === 'string' ? node.trim() === '' : Object.values(node as object).some(empty))
    expect(empty(es)).toBe(false)
    expect(empty(en)).toBe(false)
  })

  it('el idioma inicial sale del enlace, luego de la preferencia guardada y luego del navegador', () => {
    expect(detectLandingLang('?lang=en', 'es', 'es-EC')).toBe('en')
    expect(detectLandingLang('', 'en', 'es-EC')).toBe('en')
    expect(detectLandingLang('', null, 'en-US')).toBe('en')
    expect(detectLandingLang('', null, 'pt-BR')).toBe('es')
    expect(detectLandingLang('?lang=fr', 'xx', undefined)).toBe('es')
    // VITE_DEFAULT_LANG manda sobre el navegador, pero no sobre lo que la persona eligió.
    expect(detectLandingLang('', null, 'en-US', 'es')).toBe('es')
    expect(detectLandingLang('', 'en', 'es-EC', 'es')).toBe('en')
    expect(detectLandingLang('', null, 'en-US', '')).toBe('en')
  })

  it('cambia el contenido, el título y <html lang> al elegir inglés, y lo recuerda', () => {
    render(<App />)
    expect(screen.getByRole('heading', { level: 1 }).textContent).toContain(es.hero.title[0])

    fireEvent.click(screen.getByRole('button', { name: 'EN' }))

    expect(screen.getByRole('heading', { level: 1 }).textContent).toContain(en.hero.title[0])
    expect(document.documentElement.lang).toBe('en')
    expect(document.title).toBe(en.meta.title)
    expect(localStorage.getItem('predictily.landing.lang')).toBe('en')
  })

  it('todos los llamados a la acción llevan a contacto: no se ofrecen planes ni cuentas gratis', () => {
    const { container } = render(<App />)
    const toContact = [...container.querySelectorAll('a.btn')].filter((a) => a.getAttribute('href') === '#contacto')
    expect(toContact.length).toBeGreaterThanOrEqual(3)
    expect(container.textContent).not.toMatch(/crear cuenta|gratis|USD \d/i)
  })

  it('«Hablemos» es el llamado principal: cabecera, inicio y botón flotante llevan a contacto', () => {
    render(<App />)
    const talk = screen.getAllByRole('link', { name: es.nav.cta })
    expect(talk.length).toBeGreaterThanOrEqual(3)
    expect(talk.every((link) => link.getAttribute('href') === '#contacto')).toBe(true)
    expect(screen.getByRole('heading', { level: 2, name: es.contact.title })).toBeTruthy()
    expect(screen.getByTestId('floating-cta').getAttribute('href')).toBe('#contacto')
  })

  it('la vista previa deja probar el horizonte, recorrer el gráfico y aprobar una decisión', () => {
    render(<App />)
    const demand = () => screen.getByTestId('kpi-demand').textContent
    const at30 = demand()

    fireEvent.click(screen.getByRole('button', { name: es.preview.forecast.days.replace('{n}', '90') }))
    expect(demand()).not.toBe(at30)

    // Con el teclado: la primera flecha a la derecha lleva al primer día pronosticado, que muestra su rango.
    fireEvent.keyDown(screen.getByRole('img', { name: es.preview.forecast.chartLabel }), { key: 'ArrowRight' })
    expect(screen.getByTestId('chart-tip').textContent).toContain(es.preview.forecast.dayFuture.replace('{n}', '1'))

    fireEvent.click(screen.getByRole('tab', { name: es.preview.tabs.decisions }))
    const approve = screen.getAllByRole('button', { name: es.preview.decisions.approve })[0]!
    fireEvent.click(approve)
    expect(approve.getAttribute('aria-pressed')).toBe('true')
    expect(approve.textContent).toContain(es.preview.decisions.approved)

    fireEvent.click(screen.getByRole('tab', { name: es.preview.tabs.inventory }))
    expect(screen.getByRole('table')).toBeTruthy()
    // La vista previa y el video llevan el mismo aviso: los datos son ficticios.
    expect(screen.getAllByText(es.preview.note)).toHaveLength(2)
  })

  it('el video del producto usa el archivo del idioma elegido y no suena', () => {
    render(<App />)
    const video = screen.getByTestId('demo-video') as HTMLVideoElement
    expect(video.getAttribute('src')).toBe('./media/predictily-demo-es.mp4')
    expect(video.muted).toBe(true)

    fireEvent.click(screen.getByRole('button', { name: 'EN' }))

    expect(screen.getByTestId('demo-video').getAttribute('src')).toBe('./media/predictily-demo-en.mp4')
    expect(screen.getByTestId('demo-video').getAttribute('poster')).toBe('./media/predictily-demo-poster-en.jpg')
  })

  it('las preguntas frecuentes se abren de una en una', () => {
    render(<App />)
    const first = screen.getByRole('button', { name: es.faq.items[0]!.q })
    const second = screen.getByRole('button', { name: es.faq.items[1]!.q })
    expect(first.getAttribute('aria-expanded')).toBe('true')

    fireEvent.click(second)

    expect(second.getAttribute('aria-expanded')).toBe('true')
    expect(first.getAttribute('aria-expanded')).toBe('false')
  })

  it('no envía nada si faltan los datos obligatorios y dice cómo corregirlos', () => {
    const { container } = render(<App />)

    fireEvent.submit(screen.getByTestId('contact-form'))

    expect([...container.querySelectorAll('.field-error')].map((e) => e.textContent)).toEqual([es.contact.form.errors.name, es.contact.form.errors.company, es.contact.form.errors.email])
    expect(fetch).not.toHaveBeenCalled()
    expect(document.activeElement?.id).toBe('lp-name')
  })

  it('envía la solicitud a la API sin cookies y confirma a qué correo se responderá', async () => {
    vi.mocked(fetch).mockResolvedValue(new Response(JSON.stringify({ data: { reference: 'r-1' } }), { status: 201 }))
    render(<App />)
    fillRequired()

    fireEvent.submit(screen.getByTestId('contact-form'))

    await waitFor(() => expect(screen.getByTestId('contact-sent').textContent).toContain(es.contact.form.successText.replace('{email}', 'ana@ejemplo.test')))
    const [url, init] = vi.mocked(fetch).mock.calls[0]!
    expect(url).toBe('https://app.ejemplo.test/api/v1/contact-requests')
    expect(init).toMatchObject({ method: 'POST', credentials: 'omit' })
    expect(JSON.parse(String(init?.body))).toEqual({ name: 'Ana Pérez', company: 'Distribuidora Ejemplo', email: 'ana@ejemplo.test', locale: 'es' })
  })

  it('si el envío falla conserva lo escrito y distingue el límite de solicitudes', async () => {
    vi.mocked(fetch).mockRejectedValueOnce(new TypeError('sin red'))
    render(<App />)
    fillRequired()

    fireEvent.submit(screen.getByTestId('contact-form'))
    await waitFor(() => expect(screen.getByTestId('contact-error').textContent).toContain(es.contact.form.failed))
    expect(field('lp-company').value).toBe('Distribuidora Ejemplo')

    vi.mocked(fetch).mockResolvedValueOnce(new Response('{}', { status: 429 }))
    fireEvent.submit(screen.getByTestId('contact-form'))
    await waitFor(() => expect(screen.getByTestId('contact-error').textContent).toContain(es.contact.form.tooMany))
  })

  it('marca los campos que rechaza el servidor', async () => {
    vi.mocked(fetch).mockResolvedValue(new Response(JSON.stringify({ errors: { email: ['inválido'] } }), { status: 422 }))
    const { container } = render(<App />)
    fillRequired()

    fireEvent.submit(screen.getByTestId('contact-form'))

    await waitFor(() => expect(screen.getByTestId('contact-error').textContent).toContain(es.contact.form.invalid))
    expect([...container.querySelectorAll('.field-error')].map((e) => e.textContent)).toEqual([es.contact.form.errors.email])
  })
})

describe('enlace de contacto', () => {
  it('arma un mailto con asunto y cuerpo codificados', () => {
    expect(buildContactUrl('email', { email: ' ventas@empresa.test ', whatsapp: '', subject: 'Hola · ACME', body: 'Línea 1\nLínea 2' })).toBe(
      'mailto:ventas@empresa.test?subject=Hola%20%C2%B7%20ACME&body=L%C3%ADnea%201%0AL%C3%ADnea%202',
    )
  })

  it('arma un enlace de WhatsApp dejando solo los dígitos del número', () => {
    expect(buildContactUrl('whatsapp', { email: '', whatsapp: '+593 9 1234-5678', body: 'Hola' })).toBe('https://wa.me/593912345678?text=Hola')
  })
})
