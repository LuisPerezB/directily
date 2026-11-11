import { useState } from 'react'
import { Icon } from './Icon'

/** Preguntas frecuentes: una abierta a la vez, con apertura animada. */
export function Faq({ items }: { items: readonly { q: string; a: string }[] }) {
  const [open, setOpen] = useState<number | null>(0)
  return (
    <div className="lp-faq__list">
      {items.map((item, i) => {
        const isOpen = open === i
        return (
          <div key={item.q} className={`lp-faq__item ${isOpen ? 'is-open' : ''}`}>
            <h3>
              <button type="button" id={`faq-q-${i}`} aria-expanded={isOpen} aria-controls={`faq-a-${i}`} onClick={() => setOpen(isOpen ? null : i)}>
                <span>{item.q}</span>
                <Icon name="plus" size={18} />
              </button>
            </h3>
            <div id={`faq-a-${i}`} role="region" aria-labelledby={`faq-q-${i}`} className="lp-faq__panel" inert={!isOpen}>
              <div>
                <p>{item.a}</p>
              </div>
            </div>
          </div>
        )
      })}
    </div>
  )
}
