import { createI18n } from 'vue-i18n'
import es from './locales/es.json'

export type MessageSchema = typeof es

/** Español como idioma inicial; la estructura permite agregar más idiomas (p. ej. en.json). */
export const i18n = createI18n<[MessageSchema], 'es'>({
  legacy: false,
  locale: 'es',
  fallbackLocale: 'es',
  messages: { es },
  missingWarn: import.meta.env.DEV,
  fallbackWarn: false,
})
