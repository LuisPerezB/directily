import type { CSSProperties, ReactNode } from 'react'
import { useInView } from '../lib/motion'

/** Aparece con un desplazamiento suave al entrar en pantalla. `delay` escalona elementos vecinos (ms). */
export function Reveal({ children, delay = 0, className = '', as: Tag = 'div' }: { children: ReactNode; delay?: number; className?: string; as?: 'div' | 'li' | 'article' | 'section' }) {
  const [ref, inView] = useInView<HTMLElement>()
  return (
    // El tipo del ref depende de la etiqueta elegida; todas son HTMLElement.
    <Tag ref={ref as never} className={`lp-reveal ${inView ? 'is-in' : ''} ${className}`.trim()} style={{ '--d': `${delay}ms` } as CSSProperties}>
      {children}
    </Tag>
  )
}
