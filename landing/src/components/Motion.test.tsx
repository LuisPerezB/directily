import { act, cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { es } from '../copy/es'
import { HOSTING_SLIDE_MS, HostingCarousel } from './HostingCarousel'
import { STEP_MS, StepFlow } from './StepFlow'

const selected = (): string => screen.getAllByRole('tab').find((tab) => tab.getAttribute('aria-selected') === 'true')!.textContent!
/** Avanza el reloj `times` intervalos de `ms`, de uno en uno: cada avance programa el siguiente al volver a pintar. */
const tick = (ms: number, times = 1): void => {
  for (let i = 0; i < times; i++) {
    act(() => {
      vi.advanceTimersByTime(ms)
    })
  }
}

describe('Carrusel de modalidades de instalación', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })
  afterEach(() => {
    cleanup()
    vi.useRealTimers()
  })

  const mount = () => render(<HostingCarousel items={es.hosting.items} labels={es.hosting.controls} />)

  it('las tres modalidades están siempre en la página, aunque solo una esté en pantalla', () => {
    mount()

    for (const option of es.hosting.items) {
      expect(screen.getByText(option.title)).toBeTruthy()
      for (const point of option.points) expect(screen.getByText(point)).toBeTruthy()
    }
    expect(screen.getAllByRole('tabpanel', { hidden: true })).toHaveLength(3)
    expect(selected()).toBe('Autohospedado')
  })

  it('avanza sola, vuelve al principio y se detiene mientras la persona la mira de cerca', () => {
    const { container } = mount()

    tick(HOSTING_SLIDE_MS)
    expect(selected()).toBe('VPS')
    tick(HOSTING_SLIDE_MS, 2)
    expect(selected()).toBe('Autohospedado')

    fireEvent.mouseEnter(container.querySelector('.lp-carousel')!)
    tick(HOSTING_SLIDE_MS, 3)
    expect(selected()).toBe('Autohospedado')

    fireEvent.mouseLeave(container.querySelector('.lp-carousel')!)
    tick(HOSTING_SLIDE_MS)
    expect(selected()).toBe('VPS')
  })

  it('se maneja con las pestañas, los botones y las flechas del teclado', () => {
    mount()

    fireEvent.click(screen.getByRole('tab', { name: 'Servidor compartido' }))
    expect(selected()).toBe('Servidor compartido')

    fireEvent.click(screen.getByRole('button', { name: es.hosting.controls.next }))
    expect(selected()).toBe('Autohospedado')
    fireEvent.click(screen.getByRole('button', { name: es.hosting.controls.prev }))
    expect(selected()).toBe('Servidor compartido')

    fireEvent.keyDown(screen.getByRole('tablist'), { key: 'ArrowLeft' })
    expect(selected()).toBe('VPS')
    fireEvent.keyDown(screen.getByRole('tablist'), { key: 'Home' })
    expect(selected()).toBe('Autohospedado')
  })

  it('con «menos movimiento» no avanza sola', () => {
    vi.stubGlobal('matchMedia', (query: string) => ({ matches: query.includes('reduce'), addEventListener() {}, removeEventListener() {} }))
    mount()

    tick(HOSTING_SLIDE_MS, 4)

    expect(selected()).toBe('Autohospedado')
    vi.unstubAllGlobals()
  })
})

describe('Pasos como recorrido', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })
  afterEach(() => {
    cleanup()
    vi.useRealTimers()
  })

  const current = (): string => document.querySelector('[aria-current="step"] h3')?.textContent ?? ''

  it('recorre los pasos de uno en uno, marca los ya hechos y vuelve a empezar', () => {
    render(<StepFlow steps={es.plan.steps} />)
    const titles = es.plan.steps.map((step) => step.title)

    expect(screen.getAllByRole('listitem')).toHaveLength(5)
    expect(current()).toBe(titles[0])
    tick(STEP_MS)
    expect(current()).toBe(titles[1])
    expect(document.querySelectorAll('.lp-step.is-done')).toHaveLength(1)
    tick(STEP_MS, 3)
    expect(current()).toBe(titles[4])
    expect(document.querySelectorAll('.lp-step.is-done')).toHaveLength(4)
    tick(STEP_MS, 2)
    expect(current()).toBe(titles[0])
  })

  it('pasar el puntero por un paso lo selecciona y detiene el avance hasta salir', () => {
    render(<StepFlow steps={es.plan.steps} />)
    const steps = screen.getAllByRole('listitem')

    fireEvent.mouseEnter(steps[3]!)
    tick(STEP_MS, 5)
    expect(current()).toBe(es.plan.steps[3]!.title)

    fireEvent.mouseLeave(screen.getByRole('list'))
    tick(STEP_MS)
    expect(current()).toBe(es.plan.steps[4]!.title)
  })

  it('con «menos movimiento» es una lista fija, sin paso activo', () => {
    vi.stubGlobal('matchMedia', (query: string) => ({ matches: query.includes('reduce'), addEventListener() {}, removeEventListener() {} }))
    render(<StepFlow steps={es.plan.steps} />)

    tick(STEP_MS, 3)

    expect(document.querySelector('.lp-steps')!.className).not.toContain('is-live')
    expect(document.querySelector('[aria-current="step"]')).toBeNull()
    expect(screen.getByText(es.plan.steps[4]!.text)).toBeTruthy()
    vi.unstubAllGlobals()
  })
})
