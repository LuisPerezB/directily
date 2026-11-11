import { useEffect, useState } from 'react'
import { Icon } from './Icon'

/**
 * «Hablemos» siempre a mano: aparece al dejar atrás el inicio y se retira cuando la sección
 * de contacto ya está en pantalla (ahí el formulario es el llamado).
 */
export function FloatingCta({ label }: { label: string }) {
  const [visible, setVisible] = useState(false)

  useEffect(() => {
    const update = () => {
      const contact = document.getElementById('contacto')
      const contactOnScreen = contact ? contact.getBoundingClientRect().top < window.innerHeight * 0.85 : false
      setVisible(window.scrollY > 520 && !contactOnScreen)
    }
    update()
    window.addEventListener('scroll', update, { passive: true })
    window.addEventListener('resize', update)
    return () => {
      window.removeEventListener('scroll', update)
      window.removeEventListener('resize', update)
    }
  }, [])

  return (
    <a href="#contacto" className={`lp-float ${visible ? 'is-in' : ''}`} tabIndex={visible ? undefined : -1} aria-hidden={!visible} data-testid="floating-cta">
      <Icon name="message" size={18} />
      {label}
    </a>
  )
}
