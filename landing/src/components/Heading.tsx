import type { SplitTitle } from '../copy/types'
import { Reveal } from './Reveal'

/** Encabezado de sección: rótulo, título (la segunda parte va atenuada) y bajada opcional. */
export function Heading({ kicker, title, lead, center = false }: { kicker: string; title: SplitTitle; lead?: string; center?: boolean }) {
  return (
    <Reveal className={`lp-head ${center ? 'lp-head--center' : ''}`.trim()}>
      <p className="lp-eyebrow">{kicker}</p>
      <h2 className="lp-h2">
        {title[0]} <span className="lp-soft">{title[1]}</span>
      </h2>
      {lead && <p className="lp-lead">{lead}</p>}
    </Reveal>
  )
}
