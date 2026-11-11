import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { es } from '../copy/es'

// Landing publicada sin ningún canal de contacto (ni API, ni correo, ni WhatsApp).
vi.mock('../lib/contact', async (original) => ({
  ...(await original<typeof import('../lib/contact')>()),
  contactConfig: { apiUrl: '', email: '', whatsapp: '' },
}))

import { ContactForm } from './ContactForm'

describe('Formulario sin destino configurado', () => {
  afterEach(() => {
    cleanup()
    vi.restoreAllMocks()
  })

  it('lo dice y no simula un envío: ni abre un correo sin destinatario ni llama a la red', () => {
    const open = vi.spyOn(window, 'open').mockImplementation(() => null)
    const fetchSpy = vi.spyOn(globalThis, 'fetch')
    const before = window.location.href
    render(<ContactForm copy={es.contact.form} lang="es" />)

    fireEvent.change(document.getElementById('lp-name')!, { target: { value: 'Ana Pérez' } })
    fireEvent.change(document.getElementById('lp-company')!, { target: { value: 'Distribuidora Ejemplo' } })
    fireEvent.change(document.getElementById('lp-email')!, { target: { value: 'ana@ejemplo.test' } })
    const submit = screen.getByRole('button', { name: es.contact.form.submitEmail })
    fireEvent.submit(submit.closest('form')!)

    expect((submit as HTMLButtonElement).disabled).toBe(true)
    expect(screen.getByTestId('contact-unavailable').textContent).toContain(es.contact.form.unavailable)
    expect(screen.queryByTestId('contact-opened')).toBeNull()
    expect(open).not.toHaveBeenCalled()
    expect(fetchSpy).not.toHaveBeenCalled()
    expect(window.location.href).toBe(before)
  })
})
