/// <reference types="vite/client" />

interface ImportMetaEnv {
  /** URL completa del endpoint que recibe las solicitudes de contacto. */
  readonly VITE_CONTACT_API_URL?: string
  readonly VITE_CONTACT_EMAIL?: string
  /** Con código de país y solo dígitos, p. ej. 5939XXXXXXXX. */
  readonly VITE_CONTACT_WHATSAPP?: string
  /** URL de la aplicación (para el enlace «Iniciar sesión»). */
  readonly VITE_APP_URL?: string
  /** Idioma de quien llega sin preferencia: `es` o `en`. Vacío = el del navegador. */
  readonly VITE_DEFAULT_LANG?: string
  /** Dirección pública del sitio, sin barra final; la usa la imagen para compartir. */
  readonly VITE_SITE_URL?: string
  /** Carpeta o URL del video y su portada (por defecto `./media`). */
  readonly VITE_MEDIA_BASE_URL?: string
  /** Código de verificación de Google Search Console (solo el valor de `content`). */
  readonly VITE_GOOGLE_SITE_VERIFICATION?: string
  /** Puerto del servidor de desarrollo (por defecto 5174). */
  readonly VITE_DEV_PORT?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
