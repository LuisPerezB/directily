import { useEffect, useState } from 'react'
import { prefersReducedMotion, useInView } from '../lib/motion'
import { Icon } from './Icon'

interface Step {
  tag: string
  title: string
  text: string
}

/** Tiempo que el recorrido se queda en cada paso. */
export const STEP_MS = 2600

/**
 * Los pasos de «Cómo trabajamos» como un recorrido: al entrar en pantalla, el paso activo avanza de uno en uno, la
 * línea que los une se va llenando y los ya recorridos quedan marcados. Pasar el puntero o enfocar un paso lo
 * selecciona y detiene el avance. Sin scripts, o con «menos movimiento», se ven los cinco pasos fijos, como una lista.
 */
export function StepFlow({ steps }: { steps: readonly Step[] }) {
  const [ref, inView] = useInView<HTMLOListElement>(0.3)
  const [live, setLive] = useState(false)
  const [active, setActive] = useState(0)
  const [paused, setPaused] = useState(false)
  const count = steps.length

  useEffect(() => setLive(!prefersReducedMotion()), [])

  useEffect(() => {
    if (!live || !inView || paused) return
    // Al llegar al último paso se queda un poco más antes de volver a empezar.
    const timer = setTimeout(() => setActive((current) => (current + 1) % count), active === count - 1 ? STEP_MS * 2 : STEP_MS)
    return () => clearTimeout(timer)
  }, [live, inView, paused, active, count])

  const select = (index: number): void => {
    setActive(index)
    setPaused(true)
  }

  return (
    <ol ref={ref} className={`lp-steps ${live && inView ? 'is-live' : ''}`.trim()} onMouseLeave={() => setPaused(false)} onBlur={() => setPaused(false)}>
      {steps.map((step, i) => {
        const state = !live || !inView ? '' : i < active ? 'is-done' : i === active ? 'is-active' : ''
        return (
          <li key={step.title} className={`lp-step ${state}`.trim()} aria-current={state === 'is-active' ? 'step' : undefined} onMouseEnter={() => select(i)} onFocus={() => select(i)} tabIndex={live ? 0 : undefined}>
            <span className="lp-step__n">{state === 'is-done' ? <Icon name="check" size={16} /> : i + 1}</span>
            <p className="lp-tag">{step.tag}</p>
            <h3 className="lp-h4">{step.title}</h3>
            <p className="lp-text">{step.text}</p>
          </li>
        )
      })}
    </ol>
  )
}
