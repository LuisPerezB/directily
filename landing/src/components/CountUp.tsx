import { useEffect, useState } from 'react'
import { prefersReducedMotion, useInView } from '../lib/motion'

const DURATION = 1100

/** Muestra un texto con cifras («≈26 %», «85 · 98 %») contando desde cero cuando entra en pantalla. */
export function CountUp({ text }: { text: string }) {
  const [ref, inView] = useInView<HTMLSpanElement>(0.4)
  const [progress, setProgress] = useState(() => (prefersReducedMotion() || typeof requestAnimationFrame === 'undefined' ? 1 : 0))

  useEffect(() => {
    if (!inView || progress === 1) return
    let frame = 0
    const start = performance.now()
    const tick = (now: number) => {
      const t = Math.min(1, (now - start) / DURATION)
      setProgress(1 - (1 - t) ** 3)
      if (t < 1) frame = requestAnimationFrame(tick)
    }
    frame = requestAnimationFrame(tick)
    return () => cancelAnimationFrame(frame)
    // Solo debe arrancar una vez, al entrar en pantalla.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [inView])

  const shown = text.replace(/\d+/g, (digits) => String(Math.round(Number(digits) * progress)))
  return (
    <span ref={ref} aria-label={text}>
      <span aria-hidden="true">{shown}</span>
    </span>
  )
}
