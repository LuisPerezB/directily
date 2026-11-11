import { describe, expect, it } from 'vitest'
import { safeEmail, safeHttpUrl } from './url'

describe('safeHttpUrl', () => {
  it('acepta https y http solo hacia el propio equipo', () => {
    expect(safeHttpUrl(' https://app.ejemplo.com/api/v1/contact-requests ')).toBe('https://app.ejemplo.com/api/v1/contact-requests')
    expect(safeHttpUrl('http://localhost:8000/api')).toBe('http://localhost:8000/api')
    expect(safeHttpUrl('http://127.0.0.1:8000')).toBe('http://127.0.0.1:8000')
  })

  it.each([
    ['javascript:alert(1)'],
    ['data:text/html,<script>alert(1)</script>'],
    ['http://app.ejemplo.com/login'],
    ['https://usuario:clave@app.ejemplo.com'],
    ['//app.ejemplo.com'],
    ['no es una url'],
    [''],
    [undefined],
  ])('descarta %s', (value) => {
    expect(safeHttpUrl(value)).toBe('')
  })
})

describe('safeEmail', () => {
  it('acepta un correo con forma de correo', () => {
    expect(safeEmail(' ventas@ejemplo.com ')).toBe('ventas@ejemplo.com')
  })

  it.each([
    ['ventas@ejemplo.com?bcc=otro@ejemplo.com'],
    ['ventas@ejemplo.com,otro@ejemplo.com'],
    ['ventas@ejemplo.com&subject=x'],
    ['<ventas@ejemplo.com>'],
    ['sin-arroba'],
    [''],
    [undefined],
  ])('descarta %s (no puede añadir destinatarios ni cabeceras a un mailto)', (value) => {
    expect(safeEmail(value)).toBe('')
  })
})
