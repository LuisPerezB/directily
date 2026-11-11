import { existsSync } from 'node:fs'
import { resolve } from 'node:path'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { en } from '../copy/en'
import { es } from '../copy/es'
import { SECTOR_IDENTITY, SectorCards } from './SectorCards'

describe('Tarjetas de «Para quién»', () => {
  afterEach(cleanup)

  const cards = (): HTMLElement[] => screen.getAllByRole('article')
  const pinned = (): string[] => cards().filter((card) => card.className.includes('is-pinned')).map((card) => card.querySelector('h3')!.textContent!)

  it('cada sector tiene su icono, su pregunta y su fotografía, en los dos idiomas', () => {
    expect(SECTOR_IDENTITY).toHaveLength(es.sectors.items.length)
    expect(en.sectors.items).toHaveLength(es.sectors.items.length)
    expect(new Set(SECTOR_IDENTITY.map((identity) => identity.icon)).size).toBe(SECTOR_IDENTITY.length)
    for (const identity of SECTOR_IDENTITY) {
      expect(existsSync(resolve(process.cwd(), 'public/media/sectors', `${identity.image}.webp`)), identity.image).toBe(true)
    }

    render(<SectorCards items={es.sectors.items} />)

    cards().forEach((card, i) => {
      const sector = es.sectors.items[i]!
      expect(card.querySelector('h3')!.textContent).toBe(sector.title)
      expect(card.textContent).toContain(sector.question)
      expect(card.textContent).toContain(sector.text)
      expect(card.querySelector('svg')).toBeTruthy()
      expect(card.querySelector('img')!.getAttribute('src')).toContain(`sectors/${SECTOR_IDENTITY[i]!.image}.webp`)
    })
  })

  it('la fotografía es decorativa y no se descarga hasta acercarse a la pantalla', () => {
    render(<SectorCards items={es.sectors.items} />)

    for (const card of cards()) {
      const photo = card.querySelector('img')!
      expect(photo.getAttribute('alt')).toBe('')
      expect(photo.getAttribute('loading')).toBe('lazy')
      // Se llega con el teclado: el foco muestra la fotografía igual que el puntero.
      expect(card.tabIndex).toBe(0)
    }
  })

  it('tocar una tarjeta la deja fija; tocar otra la reemplaza y tocarla de nuevo la suelta', () => {
    render(<SectorCards items={es.sectors.items} />)
    expect(pinned()).toEqual([])

    fireEvent.click(cards()[1]!)
    expect(pinned()).toEqual(['Ferreterías'])

    fireEvent.click(cards()[4]!)
    expect(pinned()).toEqual(['Importadoras'])

    fireEvent.click(cards()[4]!)
    expect(pinned()).toEqual([])
  })
})
