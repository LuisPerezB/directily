import { useEffect, useRef, useState, type KeyboardEvent, type TouchEvent } from 'react'
import { prefersReducedMotion } from '../lib/motion'
import { Icon, type IconName } from './Icon'

interface Option {
  tag: string
  title: string
  text: string
  points: readonly string[]
}

const ICONS: IconName[] = ['building', 'server', 'cloud']
/** Tiempo que cada modalidad queda en pantalla cuando el carrusel avanza solo. */
export const HOSTING_SLIDE_MS = 6000

/**
 * Carrusel de las modalidades de instalación. Avanza solo, se detiene mientras la persona lo mira de cerca (puntero
 * encima o foco dentro) y se maneja con las pestañas, las flechas del teclado, los botones o deslizando el dedo.
 * Las tres modalidades están SIEMPRE en el HTML (buscadores y agentes las leen); sin scripts se muestran apiladas.
 * Con «menos movimiento» no avanza solo.
 */
export function HostingCarousel({ items, labels }: { items: readonly Option[]; labels: { list: string; prev: string; next: string } }) {
  const [active, setActive] = useState(0)
  const [paused, setPaused] = useState(false)
  const [auto, setAuto] = useState(false)
  const tabs = useRef<(HTMLButtonElement | null)[]>([])
  const touchX = useRef<number | null>(null)
  const count = items.length

  // El avance automático empieza en el navegador (nunca al generar el HTML) y solo si la persona no pidió menos movimiento.
  useEffect(() => setAuto(!prefersReducedMotion()), [])

  useEffect(() => {
    if (!auto || paused) return
    const timer = setTimeout(() => setActive((current) => (current + 1) % count), HOSTING_SLIDE_MS)
    return () => clearTimeout(timer)
  }, [auto, paused, active, count])

  const go = (index: number, focus = false): void => {
    const next = (index + count) % count
    setActive(next)
    if (focus) tabs.current[next]?.focus()
  }

  const onKeyDown = (event: KeyboardEvent<HTMLDivElement>): void => {
    if (event.key === 'ArrowRight') go(active + 1, true)
    else if (event.key === 'ArrowLeft') go(active - 1, true)
    else if (event.key === 'Home') go(0, true)
    else if (event.key === 'End') go(count - 1, true)
    else return
    event.preventDefault()
  }

  const onTouchEnd = (event: TouchEvent<HTMLDivElement>): void => {
    const start = touchX.current
    touchX.current = null
    const end = event.changedTouches[0]?.clientX
    if (start === null || end === undefined || Math.abs(end - start) < 48) return
    go(end < start ? active + 1 : active - 1)
  }

  return (
    <div
      className={`lp-carousel ${auto && !paused ? 'is-playing' : ''}`.trim()}
      onMouseEnter={() => setPaused(true)}
      onMouseLeave={() => setPaused(false)}
      onFocus={() => setPaused(true)}
      onBlur={() => setPaused(false)}
    >
      <div className="lp-carousel__tabs" role="tablist" aria-label={labels.list} onKeyDown={onKeyDown}>
        {items.map((item, i) => (
          <button
            key={item.tag}
            ref={(node) => {
              tabs.current[i] = node
            }}
            type="button"
            role="tab"
            id={`hosting-tab-${i}`}
            aria-selected={active === i}
            aria-controls={`hosting-panel-${i}`}
            tabIndex={active === i ? 0 : -1}
            className="lp-carousel__tab"
            onClick={() => go(i)}
          >
            <Icon name={ICONS[i] ?? 'cloud'} size={18} />
            <span>{item.tag}</span>
            {/* Barra de tiempo de la modalidad en pantalla; se reinicia al cambiar de pestaña. */}
            {active === i && <i key={`${i}-${auto}`} className="lp-carousel__timer" aria-hidden="true" />}
          </button>
        ))}
      </div>

      <div className="lp-carousel__stage" onTouchStart={(event) => (touchX.current = event.touches[0]?.clientX ?? null)} onTouchEnd={onTouchEnd}>
        {items.map((item, i) => (
          <article
            key={item.tag}
            id={`hosting-panel-${i}`}
            role="tabpanel"
            aria-labelledby={`hosting-tab-${i}`}
            className={`lp-carousel__slide ${active === i ? 'is-active' : ''} ${i < active ? 'is-before' : ''}`.trim()}
            inert={active !== i}
          >
            <div className="lp-carousel__body">
              <p className="lp-option__tag">{item.tag}</p>
              <h3 className="lp-carousel__title">{item.title}</h3>
              <p className="lp-carousel__text">{item.text}</p>
              <ul className="lp-carousel__points">
                {item.points.map((point) => (
                  <li key={point}>
                    <Icon name="check" size={16} />
                    {point}
                  </li>
                ))}
              </ul>
            </div>
            <div className="lp-carousel__art" aria-hidden="true">
              <span className="lp-carousel__ring" />
              <span className="lp-carousel__ring lp-carousel__ring--2" />
              <span className="lp-carousel__icon">
                <Icon name={ICONS[i] ?? 'cloud'} size={56} />
              </span>
            </div>
          </article>
        ))}
      </div>

      <div className="lp-carousel__nav">
        <button type="button" className="lp-iconbtn" aria-label={labels.prev} onClick={() => go(active - 1)}>
          <Icon name="chevron-left" size={18} />
        </button>
        <div className="lp-carousel__dots" aria-hidden="true">
          {items.map((item, i) => (
            <span key={item.tag} className={active === i ? 'is-active' : ''} />
          ))}
        </div>
        <button type="button" className="lp-iconbtn" aria-label={labels.next} onClick={() => go(active + 1)}>
          <Icon name="chevron-right" size={18} />
        </button>
      </div>
    </div>
  )
}
