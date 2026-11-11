import { useEffect, useState } from 'react'
import { ContactForm } from './components/ContactForm'
import { DemoVideo } from './components/DemoVideo'
import { CountUp } from './components/CountUp'
import { Faq } from './components/Faq'
import { FloatingCta } from './components/FloatingCta'
import { Heading } from './components/Heading'
import { HostingCarousel } from './components/HostingCarousel'
import { Icon, type IconName } from './components/Icon'
import { Logo } from './components/Logo'
import { ProductPreview } from './components/ProductPreview'
import { Reveal } from './components/Reveal'
import { StepFlow } from './components/StepFlow'
import type { LandingLang } from './copy/types'
import { contactConfig } from './lib/contact'
import { buildContactUrl } from './lib/contactLink'
import { useLandingLocale } from './lib/locale'
import { mediaUrl } from './lib/page'
import { useTheme } from './lib/theme'
import { safeHttpUrl } from './lib/url'

const LANGS: LandingLang[] = ['es', 'en']
const MODULE_ICONS: IconName[] = ['chart', 'trend-up', 'warehouse', 'users', 'search', 'layers', 'target', 'sliders', 'bell']
const SECURITY_ICONS: IconName[] = ['building', 'key', 'database', 'shield', 'file', 'download']
const THEME_ICON = { system: 'monitor', light: 'sun', dark: 'moon' } as const
const pad = (n: number): string => String(n + 1).padStart(2, '0')

const appUrl = safeHttpUrl(import.meta.env.VITE_APP_URL).replace(/\/+$/, '')
const whatsappUrl = contactConfig.whatsapp ? buildContactUrl('whatsapp', { email: '', whatsapp: contactConfig.whatsapp }) : ''

/** La cabecera gana fondo y borde en cuanto la página deja de estar arriba del todo. */
function useScrolled(): boolean {
  const [scrolled, setScrolled] = useState(false)
  useEffect(() => {
    const update = () => setScrolled(window.scrollY > 8)
    update()
    window.addEventListener('scroll', update, { passive: true })
    return () => window.removeEventListener('scroll', update)
  }, [])
  return scrolled
}

/** `initialLang` fija el idioma al generar la página en el build (prerender); en el navegador se detecta. */
export function App({ initialLang }: { initialLang?: LandingLang } = {}) {
  const { lang, copy, setLang } = useLandingLocale(initialLang)
  const { theme, cycleTheme } = useTheme()
  const [menuOpen, setMenuOpen] = useState(false)
  const scrolled = useScrolled()

  const navLinks = [
    { href: '#producto', label: copy.nav.product },
    { href: '#plan', label: copy.nav.plan },
    { href: '#instalacion', label: copy.nav.hosting },
    { href: '#seguridad', label: copy.nav.security },
    { href: '#faq', label: copy.nav.faq },
  ]
  const closeMenu = () => setMenuOpen(false)

  return (
    <div className="lp">
      <a href="#main" className="sr-only-focusable btn btn-primary btn-sm lp-skip">{copy.nav.skip}</a>

      <header className={`lp-header ${scrolled || menuOpen ? 'is-solid' : ''}`}>
        <div className="lp-wrap lp-header__bar">
          <a href="#main" className="lp-header__logo" aria-label="Predictily"><Logo size={30} /></a>
          <nav className="lp-nav" aria-label={copy.nav.label}>
            {navLinks.map((link) => (
              <a key={link.href} href={link.href} className="lp-nav__link">{link.label}</a>
            ))}
          </nav>
          <div className="lp-header__actions">
            <div className="lp-lang" role="group" aria-label={copy.nav.langLabel}>
              {LANGS.map((code) => (
                <button key={code} type="button" className="lp-lang__btn" aria-pressed={lang === code} lang={code} onClick={() => setLang(code)}>
                  {code.toUpperCase()}
                </button>
              ))}
            </div>
            <button type="button" className="lp-iconbtn" aria-label={copy.theme[theme]} title={copy.theme[theme]} onClick={cycleTheme}>
              <Icon name={THEME_ICON[theme]} size={18} />
            </button>
            {appUrl && <a href={`${appUrl}/login`} className="btn btn-ghost btn-sm lp-hide-sm">{copy.nav.signIn}</a>}
            <a href="#contacto" className="btn btn-primary btn-sm lp-hide-xs">{copy.nav.cta}</a>
            <button type="button" className="lp-iconbtn lp-menu-btn" aria-label={copy.nav.menu} aria-expanded={menuOpen} aria-controls="lp-menu" onClick={() => setMenuOpen((open) => !open)}>
              <Icon name={menuOpen ? 'x' : 'menu'} size={20} />
            </button>
          </div>
        </div>
        <nav id="lp-menu" className="lp-menu" aria-label={copy.nav.label} hidden={!menuOpen}>
          {navLinks.map((link) => (
            <a key={link.href} href={link.href} className="lp-menu__link" onClick={closeMenu}>{link.label}</a>
          ))}
          {appUrl && <a href={`${appUrl}/login`} className="lp-menu__link">{copy.nav.signIn}</a>}
          <a href="#contacto" className="btn btn-primary btn-lg" onClick={closeMenu}>{copy.nav.cta}</a>
        </nav>
      </header>

      <main id="main">
        {/* Inicio */}
        <section className="lp-hero">
          <div className="lp-hero__glow" aria-hidden="true" />
          <div className="lp-wrap">
            <div className="lp-hero__text">
              <p className="lp-badge lp-enter" style={{ animationDelay: '0ms' }}><i aria-hidden="true" />{copy.hero.badge}</p>
              <h1 className="lp-h1 lp-enter" style={{ animationDelay: '80ms' }}>
                <span>{copy.hero.title[0]}</span>{' '}
                <span className="lp-soft">{copy.hero.title[1]}</span>
              </h1>
              <p className="lp-hero__lead lp-enter" style={{ animationDelay: '160ms' }}>{copy.hero.lead}</p>
              <div className="lp-hero__cta lp-enter" style={{ animationDelay: '240ms' }}>
                <a href="#contacto" className="btn btn-primary btn-lg">{copy.hero.cta}<Icon name="arrow-right" size={18} /></a>
                <a href="#producto" className="btn btn-secondary btn-lg">{copy.hero.ctaSecondary}</a>
              </div>
              <ul className="lp-chips lp-enter" style={{ animationDelay: '320ms' }}>
                {copy.hero.chips.map((chip) => (
                  <li key={chip}><Icon name="check" size={15} />{chip}</li>
                ))}
              </ul>
            </div>
            <div className="lp-hero__preview lp-enter" style={{ animationDelay: '420ms' }}>
              <ProductPreview copy={copy.preview} lang={lang} />
            </div>
          </div>
        </section>

        {/* Se conecta con lo que ya usa */}
        <section className="lp-sources" aria-label={copy.sources.label}>
          <Reveal className="lp-wrap lp-sources__row">
            <p>{copy.sources.label}</p>
            <ul>
              {copy.sources.items.map((item) => (
                <li key={item}>{item}</li>
              ))}
            </ul>
          </Reveal>
          {/* Tecnología de terceros: logotipo oficial de cada empresa, sin modificar y sobre fondo claro, con la aclaración de que no son socios. */}
          <Reveal className="lp-wrap lp-tech">
            <p className="lp-tech__label">{copy.tech.label}</p>
            <ul className="lp-tech__list">
              {copy.tech.items.map((item) => (
                <li key={item.name}>
                  <span className="lp-tech__logo">
                    <img src={mediaUrl(`tech/${item.name.toLowerCase()}.svg`)} alt={item.name} loading="lazy" decoding="async" />
                  </span>
                  <span>{item.role}</span>
                </li>
              ))}
            </ul>
            <p className="lp-tech__note">{copy.tech.note}</p>
          </Reveal>
        </section>

        {/* Video: la herramienta en 25 segundos */}
        <section id="video" className="lp-section lp-section--tight">
          <div className="lp-wrap">
            <Heading kicker={copy.video.kicker} title={copy.video.title} lead={copy.video.lead} center />
            <Reveal className="lp-video">
              <DemoVideo lang={lang} label={copy.video.title.join(' ')} />
              <p className="lp-video__note">{copy.preview.note}</p>
            </Reveal>
          </div>
        </section>

        {/* Punto de partida */}
        <section className="lp-section">
          <div className="lp-wrap">
            <Heading kicker={copy.problem.kicker} title={copy.problem.title} lead={copy.problem.lead} />
            <div className="lp-grid lp-grid--3">
              {copy.problem.cards.map((card, i) => (
                <Reveal as="article" key={card.tag} delay={i * 90} className="lp-card lp-card--plain">
                  <p className="lp-tag">{card.tag}</p>
                  <h3 className="lp-h3">{card.title}</h3>
                  <p className="lp-text">{card.text}</p>
                </Reveal>
              ))}
            </div>
          </div>
        </section>

        {/* Plataforma */}
        <section id="producto" className="lp-section lp-section--soft">
          <div className="lp-wrap">
            <Heading kicker={copy.platform.kicker} title={copy.platform.title} lead={copy.platform.lead} />
            <ol className="lp-flow">
              {copy.platform.layers.map((layer, i) => (
                <Reveal as="li" key={layer.tag} delay={i * 120} className="lp-flow__step">
                  <span className="lp-flow__n">{pad(i)}</span>
                  <p className="lp-flow__name">{layer.name}</p>
                  <h3 className="lp-h3">{layer.title}</h3>
                  <p className="lp-text">{layer.text}</p>
                </Reveal>
              ))}
            </ol>

            <Reveal><h3 className="lp-subhead">{copy.platform.modulesTitle}</h3></Reveal>
            <ul className="lp-grid lp-grid--3">
              {copy.platform.modules.map((mod, i) => (
                <Reveal as="li" key={mod.title} delay={(i % 3) * 80} className="lp-card lp-card--hover">
                  <span className="lp-icon"><Icon name={MODULE_ICONS[i] ?? 'chart'} /></span>
                  <h4 className="lp-h4">{mod.title}</h4>
                  <p className="lp-text">{mod.text}</p>
                </Reveal>
              ))}
            </ul>
          </div>
        </section>

        {/* Confianza */}
        <section id="confianza" className="lp-section">
          <div className="lp-wrap lp-trust">
            <Reveal>
              <p className="lp-eyebrow">{copy.trust.kicker}</p>
              <h2 className="lp-h2">{copy.trust.empathyTitle}</h2>
              {copy.trust.empathyText.map((paragraph) => (
                <p key={paragraph} className="lp-lead">{paragraph}</p>
              ))}
              <h3 className="lp-subhead">{copy.trust.methodTitle}</h3>
              <ul className="lp-checks">
                {copy.trust.method.map((point) => (
                  <li key={point}><Icon name="check" size={18} />{point}</li>
                ))}
              </ul>
            </Reveal>
            <Reveal as="article" delay={120} className="lp-proof">
              <p className="lp-tag">{copy.trust.proofTag}</p>
              <h3 className="lp-proof__title">{copy.trust.proofTitle}</h3>
              <dl className="lp-proof__stats">
                {copy.trust.stats.map((stat) => (
                  <div key={stat.label}>
                    <dt>{stat.label}</dt>
                    <dd><CountUp text={stat.value} /></dd>
                  </div>
                ))}
              </dl>
              <p className="lp-proof__note">{copy.trust.footnote}</p>
            </Reveal>
          </div>
        </section>

        {/* Cómo trabajamos */}
        <section id="plan" className="lp-section lp-section--soft">
          <div className="lp-wrap">
            <Heading kicker={copy.plan.kicker} title={copy.plan.title} lead={copy.plan.lead} />
            <Reveal>
              <StepFlow steps={copy.plan.steps} />
            </Reveal>
            <Reveal className="lp-inline-cta">
              <a href="#contacto" className="btn btn-primary btn-lg">{copy.hero.cta}<Icon name="arrow-right" size={18} /></a>
            </Reveal>
          </div>
        </section>

        {/* Es / No es */}
        <section id="diferencias" className="lp-section">
          <div className="lp-wrap">
            <Heading kicker={copy.compare.kicker} title={copy.compare.title} lead={copy.compare.lead} />
            <Reveal className="lp-compare">
              <div className="lp-compare__head" aria-hidden="true">
                <span>{copy.compare.isLabel}</span>
                <span>{copy.compare.isNotLabel}</span>
              </div>
              <ul>
                {copy.compare.rows.map((row) => (
                  <li key={row.is}>
                    <p className="lp-compare__is"><Icon name="check" size={18} /><span><span className="sr-only">{copy.compare.isLabel}: </span>{row.is}</span></p>
                    <p className="lp-compare__not"><Icon name="x" size={18} /><span><span className="sr-only">{copy.compare.isNotLabel}: </span>{row.isNot}</span></p>
                  </li>
                ))}
              </ul>
            </Reveal>
          </div>
        </section>

        {/* Sectores */}
        <section id="sectores" className="lp-section lp-section--soft">
          <div className="lp-wrap">
            <Heading kicker={copy.sectors.kicker} title={copy.sectors.title} lead={copy.sectors.lead} />
            <ul className="lp-grid lp-grid--3">
              {copy.sectors.items.map((sector, i) => (
                <Reveal as="li" key={sector.title} delay={(i % 3) * 80} className="lp-card lp-card--hover">
                  <h3 className="lp-h4">{sector.title}</h3>
                  <p className="lp-text">{sector.text}</p>
                </Reveal>
              ))}
            </ul>
            <Reveal className="lp-other">
              <div>
                <h3 className="lp-h4">{copy.sectors.other.title}</h3>
                <p className="lp-text">{copy.sectors.other.text}</p>
              </div>
              <a href="#contacto" className="btn btn-secondary">{copy.sectors.other.cta}<Icon name="arrow-right" size={16} /></a>
            </Reveal>
          </div>
        </section>

        {/* Dónde se instala: autohospedado, VPS o plataforma compartida */}
        <section id="instalacion" className="lp-section">
          <div className="lp-wrap">
            <Heading kicker={copy.hosting.kicker} title={copy.hosting.title} lead={copy.hosting.lead} />
            <Reveal>
              <HostingCarousel items={copy.hosting.items} labels={copy.hosting.controls} />
            </Reveal>
            <Reveal><p className="lp-note">{copy.hosting.note}</p></Reveal>
          </div>
        </section>

        {/* Seguridad */}
        <section id="seguridad" className="lp-section lp-section--soft">
          <div className="lp-wrap">
            <Heading kicker={copy.security.kicker} title={copy.security.title} lead={copy.security.lead} />
            <ul className="lp-grid lp-grid--3">
              {copy.security.items.map((item, i) => (
                <Reveal as="li" key={item.title} delay={(i % 3) * 80} className="lp-feature">
                  <span className="lp-icon"><Icon name={SECURITY_ICONS[i] ?? 'shield'} /></span>
                  <h3 className="lp-h4">{item.title}</h3>
                  <p className="lp-text">{item.text}</p>
                </Reveal>
              ))}
            </ul>
            <Reveal><p className="lp-note">{copy.security.note}</p></Reveal>
          </div>
        </section>

        {/* Preguntas frecuentes */}
        <section id="faq" className="lp-section">
          <div className="lp-wrap lp-faq">
            <Heading kicker={copy.faq.kicker} title={copy.faq.title} />
            <Reveal delay={100}><Faq items={copy.faq.items} /></Reveal>
          </div>
        </section>

        {/* Hablemos */}
        <section id="contacto" className="lp-section lp-talk">
          <div className="lp-wrap">
            <Reveal className="lp-talk__panel">
              <div className="lp-talk__glow" aria-hidden="true" />
              <div className="lp-talk__intro">
                <p className="lp-eyebrow">{copy.contact.kicker}</p>
                <h2 className="lp-talk__title">{copy.contact.title}</h2>
                <p className="lp-talk__lead">{copy.contact.lead}</p>
                <h3 className="lp-subhead">{copy.contact.nextTitle}</h3>
                <ol className="lp-next">
                  {copy.contact.next.map((line, i) => (
                    <li key={line}><span>{i + 1}</span>{line}</li>
                  ))}
                </ol>
                {(contactConfig.email || whatsappUrl) && (
                  <>
                    <h3 className="lp-subhead">{copy.contact.directTitle}</h3>
                    <p className="lp-direct">
                      {contactConfig.email && <a href={`mailto:${contactConfig.email}`}>{contactConfig.email}</a>}
                      {whatsappUrl && <a href={whatsappUrl} target="_blank" rel="noopener noreferrer">WhatsApp</a>}
                    </p>
                  </>
                )}
              </div>
              <ContactForm copy={copy.contact.form} lang={lang} />
            </Reveal>
          </div>
        </section>
      </main>

      <footer className="lp-footer">
        <div className="lp-wrap lp-footer__grid">
          <div>
            <Logo size={28} />
            <p className="lp-footer__note">{copy.footer.note}</p>
          </div>
          <nav className="lp-footer__nav" aria-label={copy.nav.label}>
            {navLinks.map((link) => (
              <a key={link.href} href={link.href}>{link.label}</a>
            ))}
            <a href="#contacto">{copy.nav.cta}</a>
          </nav>
        </div>
        <p className="lp-wrap lp-footer__rights">© {new Date().getFullYear()} {copy.footer.rights}</p>
      </footer>

      <FloatingCta label={copy.nav.cta} />
    </div>
  )
}
