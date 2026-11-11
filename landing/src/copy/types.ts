/**
 * Copy de la landing. Español e inglés implementan esta interfaz, así que una clave
 * faltante en uno de los dos idiomas es un error de tipos.
 *
 * Regla editorial: aquí solo se afirma lo que el producto hace hoy. Nada de
 * clientes, ROI ni certificaciones que no existan; la única cifra de precisión
 * es la de los datos de demostración ficticios y va rotulada como tal.
 */
export type LandingLang = 'es' | 'en'

/** Título en dos partes: texto normal + remate resaltado con el color de marca. */
export type SplitTitle = readonly [plain: string, accent: string]

export interface TitledText {
  title: string
  text: string
}

export interface Stat {
  value: string
  label: string
}

export interface LandingCopy {
  meta: { title: string; description: string }
  nav: {
    label: string
    product: string
    plan: string
    security: string
    faq: string
    signIn: string
    goToApp: string
    cta: string
    menu: string
    langLabel: string
    skip: string
  }
  theme: { system: string; light: string; dark: string }
  hero: {
    badge: string
    title: SplitTitle
    lead: string
    cta: string
    ctaSecondary: string
    chips: readonly string[]
  }
  /** Franja «se conecta con lo que ya usa». */
  sources: { label: string; items: readonly string[] }
  /** Video corto del producto para el sitio. */
  video: { kicker: string; title: SplitTitle; lead: string }
  /** Vista previa interactiva del producto (datos ficticios, rotulada como ilustrativa). */
  preview: {
    label: string
    note: string
    tabs: { forecast: string; inventory: string; decisions: string }
    forecast: {
      product: string
      horizon: string
      /** Lleva `{n}`. */
      days: string
      kpiDemand: string
      kpiRange: string
      kpiError: string
      units: string
      history: string
      forecast: string
      band: string
      today: string
      /** Llevan `{n}`. */
      dayPast: string
      dayFuture: string
      chartLabel: string
    }
    inventory: {
      cols: readonly [string, string, string, string]
      rows: readonly { name: string; cover: number; risk: 'high' | 'medium' | 'low'; buy: number }[]
      risk: { high: string; medium: string; low: string }
      /** Lleva `{n}`. */
      days: string
      noAction: string
      units: string
    }
    decisions: {
      items: readonly { title: string; detail: string; impact: string }[]
      approve: string
      approved: string
      /** Lleva `{n}`. */
      priority: string
    }
  }
  problem: {
    kicker: string
    title: SplitTitle
    lead: string
    cards: readonly (TitledText & { tag: string })[]
  }
  platform: {
    kicker: string
    title: SplitTitle
    lead: string
    layers: readonly (TitledText & { tag: string; name: string })[]
    modulesTitle: string
    modules: readonly TitledText[]
  }
  trust: {
    kicker: string
    empathyTag: string
    empathyTitle: string
    empathyText: readonly string[]
    proofTag: string
    proofTitle: string
    stats: readonly Stat[]
    footnote: string
    methodTitle: string
    method: readonly string[]
  }
  plan: {
    kicker: string
    title: SplitTitle
    lead: string
    steps: readonly (TitledText & { tag: string })[]
  }
  compare: {
    kicker: string
    title: SplitTitle
    lead: string
    isLabel: string
    isNotLabel: string
    rows: readonly { is: string; isNot: string }[]
  }
  sectors: {
    kicker: string
    title: SplitTitle
    lead: string
    items: readonly TitledText[]
    other: TitledText & { cta: string }
  }
  security: {
    kicker: string
    title: SplitTitle
    lead: string
    items: readonly TitledText[]
    note: string
  }
  contact: {
    kicker: string
    /** El llamado principal de la página: «Hablemos». */
    title: string
    lead: string
    nextTitle: string
    next: readonly string[]
    directTitle: string
    form: {
      title: string
      name: string
      company: string
      email: string
      phone: string
      optional: string
      sector: string
      sectorPlaceholder: string
      sectorOptions: readonly string[]
      system: string
      systemHint: string
      message: string
      messageHint: string
      submit: string
      sending: string
      privacy: string
      errors: { name: string; company: string; email: string }
      /** El envío falló: qué pasó y cómo seguir. */
      failed: string
      tooMany: string
      invalid: string
      fallbackEmail: string
      successTitle: string
      /** Lleva `{email}`: el correo al que se responderá. */
      successText: string
      sendAnother: string
      /** Asunto y saludo del mensaje que se arma para correo o WhatsApp. */
      subject: string
      bodyIntro: string
      /** Sin API de contacto: el formulario entrega el mensaje a la aplicación de la persona. */
      submitEmail: string
      submitWhatsapp: string
      linkHint: string
      opened: string
      /** Sin ningún canal de contacto configurado: el formulario lo dice en vez de simular un envío. */
      unavailable: string
    }
  }
  faq: {
    kicker: string
    title: SplitTitle
    items: readonly { q: string; a: string }[]
  }
  footer: { tagline: string; note: string; rights: string }
}
