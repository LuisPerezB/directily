import { useState } from 'react'
import { mediaUrl } from '../lib/page'
import { Icon, type IconName } from './Icon'
import { Reveal } from './Reveal'

interface Sector {
  title: string
  question: string
  text: string
}

/** Identidad de cada sector, en el mismo orden que `copy.sectors.items`: su icono y su fotografía (`media/sectors/`). */
export const SECTOR_IDENTITY: readonly { icon: IconName; image: string }[] = [
  { icon: 'truck', image: 'distribuidoras' },
  { icon: 'wrench', image: 'ferreterias' },
  { icon: 'cog', image: 'repuestos' },
  { icon: 'store', image: 'sucursales' },
  { icon: 'ship', image: 'importadoras' },
  { icon: 'repeat', image: 'recurrente' },
]

/**
 * Tarjetas de «Para quién». Cada sector tiene su icono, la pregunta que esa empresa se hace a diario y una fotografía
 * de su día a día, que aparece de fondo al pasar el puntero, al llegar con el teclado o al tocar la tarjeta (al tocarla
 * queda fija hasta tocarla de nuevo o tocar otra). La imagen es decorativa: el texto se lee igual sin ella, y solo se
 * descarga cuando la tarjeta se acerca a la pantalla.
 */
export function SectorCards({ items }: { items: readonly Sector[] }) {
  const [pinned, setPinned] = useState<number | null>(null)

  return (
    <ul className="lp-grid lp-grid--3">
      {items.map((sector, i) => {
        const identity = SECTOR_IDENTITY[i]
        return (
          <Reveal as="li" key={sector.title} delay={(i % 3) * 80}>
            <article className={`lp-card lp-sector ${pinned === i ? 'is-pinned' : ''}`.trim()} tabIndex={0} onClick={() => setPinned((current) => (current === i ? null : i))}>
              {identity && <img className="lp-sector__photo" src={mediaUrl(`sectors/${identity.image}.webp`)} alt="" width={1344} height={752} loading="lazy" decoding="async" />}
              {identity && (
                <span className="lp-icon">
                  <Icon name={identity.icon} />
                </span>
              )}
              <h3 className="lp-h4">{sector.title}</h3>
              <p className="lp-sector__question">{sector.question}</p>
              <p className="lp-text">{sector.text}</p>
            </article>
          </Reveal>
        )
      })}
    </ul>
  )
}
