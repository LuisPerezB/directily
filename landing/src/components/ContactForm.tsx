import { useEffect, useRef, useState, type FormEvent } from 'react'
import type { LandingCopy, LandingLang } from '../copy/types'
import { ContactError, contactConfig, sendContactRequest, type ContactFailure } from '../lib/contact'
import { buildContactUrl, type ContactChannel } from '../lib/contactLink'
import { Icon } from './Icon'

type FormCopy = LandingCopy['contact']['form']
type RequiredField = 'name' | 'company' | 'email'
type SendState = 'idle' | 'sending' | 'sent' | 'opened' | ContactFailure

const REQUIRED: RequiredField[] = ['name', 'company', 'email']
const EMPTY = { name: '', company: '', email: '', phone: '', sector: '', system: '', message: '', website: '' }
const NONE_INVALID: Record<RequiredField, boolean> = { name: false, company: false, email: false }

function validate(form: typeof EMPTY): Record<RequiredField, boolean> {
  return {
    name: form.name.trim().length < 2,
    company: form.company.trim().length < 2,
    email: !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email.trim()),
  }
}

/** El mismo contenido del formulario como texto, para correo o WhatsApp. */
function composeMessage(copy: FormCopy, form: typeof EMPTY): string {
  const rows: [string, string][] = [
    [copy.name, form.name],
    [copy.company, form.company],
    [copy.email, form.email],
    [copy.phone, form.phone],
    [copy.sector, form.sector],
    [copy.system, form.system],
    [copy.message, form.message],
  ]
  const lines = rows.filter(([, value]) => value.trim() !== '').map(([label, value]) => `${label}: ${value.trim()}`)
  return [copy.bodyIntro, '', ...lines].join('\n')
}

/**
 * Formulario de contacto. Con `VITE_CONTACT_API_URL` envía la solicitud al backend de Predictily;
 * sin ella arma el mensaje y lo entrega al correo o WhatsApp de la persona (no envía nada por sí mismo).
 */
export function ContactForm({ copy, lang }: { copy: FormCopy; lang: LandingLang }) {
  const { apiUrl, email: contactEmail, whatsapp } = contactConfig
  const usesApi = apiUrl !== ''
  const [form, setForm] = useState(EMPTY)
  const [invalid, setInvalid] = useState(NONE_INVALID)
  const [state, setState] = useState<SendState>('idle')
  const [sentTo, setSentTo] = useState('')
  const successHeading = useRef<HTMLHeadingElement>(null)

  useEffect(() => {
    if (state === 'sent') successHeading.current?.focus()
  }, [state])

  const set = (field: keyof typeof EMPTY) => (event: { target: { value: string } }) => setForm((current) => ({ ...current, [field]: event.target.value }))

  /** Marca los campos con error y lleva el foco al primero; devuelve si se puede continuar. */
  function check(marks: Record<RequiredField, boolean>): boolean {
    setInvalid(marks)
    const first = REQUIRED.find((field) => marks[field])
    if (first) document.getElementById(`lp-${first}`)?.focus()
    return first === undefined
  }

  const linkTo = (channel: ContactChannel): string =>
    buildContactUrl(channel, { email: contactEmail, whatsapp, subject: copy.subject.replace('{company}', form.company.trim()), body: composeMessage(copy, form) })

  function openLink(channel: ContactChannel): void {
    if (!check(validate(form))) {
      setState('idle')
      return
    }
    if (channel === 'whatsapp') window.open(linkTo('whatsapp'), '_blank', 'noopener')
    else window.location.href = linkTo('email')
    setState('opened')
  }

  async function sendToApi(): Promise<void> {
    if (state === 'sending') return
    if (!check(validate(form))) {
      setState('idle')
      return
    }
    setState('sending')
    const optional = (value: string): string | undefined => value.trim() || undefined
    try {
      await sendContactRequest(apiUrl, {
        name: form.name.trim(),
        company: form.company.trim(),
        email: form.email.trim(),
        phone: optional(form.phone),
        sector: optional(form.sector),
        current_system: optional(form.system),
        message: optional(form.message),
        locale: lang,
        website: optional(form.website),
      })
      setSentTo(form.email.trim())
      setForm(EMPTY)
      setState('sent')
    } catch (error) {
      const failure = error instanceof ContactError ? error : new ContactError('failed')
      if (failure.kind === 'invalid') {
        check({ name: failure.fields.includes('name'), company: failure.fields.includes('company'), email: failure.fields.includes('email') })
      }
      setState(failure.kind)
    }
  }

  // Sin API: correo si está configurado (o si no hay ningún canal: borrador sin destinatario); si solo hay WhatsApp, ese.
  const primaryLink: ContactChannel = contactEmail || !whatsapp ? 'email' : 'whatsapp'

  function onSubmit(event: FormEvent<HTMLFormElement>): void {
    event.preventDefault()
    if (usesApi) void sendToApi()
    else openLink(primaryLink)
  }

  if (state === 'sent') {
    return (
      <div className="lp-card lp-form lp-sent" role="status" data-testid="contact-sent">
        <span className="lp-icon"><Icon name="check" size={22} /></span>
        <h3 ref={successHeading} className="lp-h3" tabIndex={-1}>{copy.successTitle}</h3>
        <p className="lp-text">{copy.successText.replace('{email}', sentTo)}</p>
        <div>
          <button type="button" className="btn btn-secondary" onClick={() => setState('idle')}>{copy.sendAnother}</button>
        </div>
      </div>
    )
  }

  const failed = state === 'failed' || state === 'tooMany' || state === 'invalid'
  const optionalTag = <span className="lp-optional">({copy.optional})</span>

  return (
    <form className="lp-card lp-form" noValidate data-testid="contact-form" onSubmit={onSubmit}>
      <h3 className="lp-h3">{copy.title}</h3>
      <div className="lp-form__row">
        <div>
          <label className="label" htmlFor="lp-name">{copy.name}</label>
          <input id="lp-name" className="input" type="text" autoComplete="name" maxLength={150} required value={form.name} onChange={set('name')} aria-invalid={invalid.name} aria-describedby={invalid.name ? 'lp-name-error' : undefined} />
          {invalid.name && <p id="lp-name-error" className="field-error">{copy.errors.name}</p>}
        </div>
        <div>
          <label className="label" htmlFor="lp-company">{copy.company}</label>
          <input id="lp-company" className="input" type="text" autoComplete="organization" maxLength={150} required value={form.company} onChange={set('company')} aria-invalid={invalid.company} aria-describedby={invalid.company ? 'lp-company-error' : undefined} />
          {invalid.company && <p id="lp-company-error" className="field-error">{copy.errors.company}</p>}
        </div>
      </div>
      <div className="lp-form__row">
        <div>
          <label className="label" htmlFor="lp-email">{copy.email}</label>
          <input id="lp-email" className="input" type="email" autoComplete="email" maxLength={191} required value={form.email} onChange={set('email')} aria-invalid={invalid.email} aria-describedby={invalid.email ? 'lp-email-error' : undefined} />
          {invalid.email && <p id="lp-email-error" className="field-error">{copy.errors.email}</p>}
        </div>
        <div>
          <label className="label" htmlFor="lp-phone">{copy.phone} {optionalTag}</label>
          <input id="lp-phone" className="input" type="tel" autoComplete="tel" maxLength={40} value={form.phone} onChange={set('phone')} />
        </div>
      </div>
      <div className="lp-form__row">
        <div>
          <label className="label" htmlFor="lp-sector">{copy.sector} {optionalTag}</label>
          <select id="lp-sector" className="select" value={form.sector} onChange={set('sector')}>
            <option value="">{copy.sectorPlaceholder}</option>
            {copy.sectorOptions.map((option) => (
              <option key={option} value={option}>{option}</option>
            ))}
          </select>
        </div>
        <div>
          <label className="label" htmlFor="lp-system">{copy.system} {optionalTag}</label>
          <input id="lp-system" className="input" type="text" maxLength={150} value={form.system} onChange={set('system')} aria-describedby="lp-system-hint" />
          <p id="lp-system-hint" className="hint">{copy.systemHint}</p>
        </div>
      </div>
      <div>
        <label className="label" htmlFor="lp-message">{copy.message} {optionalTag}</label>
        <textarea id="lp-message" className="textarea" rows={4} maxLength={2000} value={form.message} onChange={set('message')} aria-describedby="lp-message-hint" />
        <p id="lp-message-hint" className="hint">{copy.messageHint}</p>
      </div>
      {/* Campo trampa contra envíos automáticos: oculto para las personas y para los lectores de pantalla. */}
      <div className="lp-trap" aria-hidden="true">
        <label htmlFor="lp-website">Website</label>
        <input id="lp-website" type="text" name="website" tabIndex={-1} autoComplete="off" value={form.website} onChange={set('website')} />
      </div>

      <div className="lp-form__actions">
        {usesApi ? (
          <button type="submit" className="btn btn-primary btn-lg" disabled={state === 'sending'}>
            {state === 'sending' ? copy.sending : copy.submit}
          </button>
        ) : (
          <>
            <button type="submit" className="btn btn-primary btn-lg">{primaryLink === 'email' ? copy.submitEmail : copy.submitWhatsapp}</button>
            {primaryLink === 'email' && whatsapp && (
              <button type="button" className="btn btn-secondary btn-lg" onClick={() => openLink('whatsapp')}>{copy.submitWhatsapp}</button>
            )}
          </>
        )}
      </div>
      {failed && (
        <p className="lp-form__error" role="alert" data-testid="contact-error">
          {copy[state]}
          {state !== 'invalid' && contactEmail && <a href={linkTo('email')}>{copy.fallbackEmail}</a>}
        </p>
      )}
      {state === 'opened' && <p className="lp-form__status" role="status" data-testid="contact-opened">{copy.opened}</p>}
      <p className="hint">{usesApi ? copy.privacy : `${copy.linkHint} ${copy.privacy}`}</p>
      {import.meta.env.DEV && !usesApi && !contactEmail && !whatsapp && (
        <p className="lp-form__dev" data-testid="contact-unconfigured">Defina VITE_CONTACT_EMAIL o VITE_CONTACT_WHATSAPP en landing/.env (aviso visible solo en desarrollo).</p>
      )}
    </form>
  )
}
