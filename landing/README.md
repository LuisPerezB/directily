# Predictily · Landing (React)

Página pública bilingüe (español e inglés) de Predictily. Proyecto independiente del resto del
repositorio: React + TypeScript + Vite, sin Tailwind ni dependencias de la aplicación.

`npm run build` genera `dist/index.html`, con el script, los estilos, la tipografía y el icono
dentro, y la carpeta `dist/media/` con el video del producto, su portada y la imagen para compartir.
Se suben los dos (el archivo y la carpeta) a cualquier alojamiento estático.

El logotipo, las imágenes y el video salen de `../branding/` (ver su README): si se regeneran allí,
se copian a `public/media/`.

## Uso

```bash
npm install
cp .env.example .env    # complete las variables (se leen al compilar)
npm run dev             # http://localhost:5174
npm test -- --run       # pruebas
npm run build           # dist/index.html (con CSP)
npm run preview         # sirve dist/ para probar la página compilada
```

## Variables (`.env`)

Toda la configuración está en `.env` (plantilla: `.env.example`). La página es solo frontend:
no necesita el backend.

| Variable | Para qué sirve |
|---|---|
| `VITE_CONTACT_EMAIL`, `VITE_CONTACT_WHATSAPP` | A dónde llegan las solicitudes: el formulario arma el mensaje y abre el correo o WhatsApp de la persona. Defina al menos uno antes de publicar. |
| `VITE_DEFAULT_LANG` | Idioma de quien llega sin preferencia (`es` o `en`). Vacío = el de su navegador. |
| `VITE_APP_URL` | URL de la aplicación; muestra el enlace «Iniciar sesión». |
| `VITE_SITE_URL` | Dirección pública del sitio, sin barra final. **Imprescindible para Google**: con ella se generan `canonical`, `hreflang`, `sitemap.xml` y la imagen para compartir. |
| `VITE_GOOGLE_SITE_VERIFICATION` | Código de Google Search Console (método «Etiqueta HTML»); solo el valor de `content`. |
| `VITE_MEDIA_BASE_URL` | Carpeta o URL del video y su portada. Vacío = `media/`, junto a `index.html`. |
| `VITE_DEV_PORT` | Puerto de `npm run dev` y `npm run preview` (5174). |
| `VITE_CONTACT_API_URL` | Opcional. URL completa de `POST /api/v1/contact-requests`: el formulario envía a la API en vez de abrir correo o WhatsApp. El backend debe incluir el dominio de la landing en `CORS_ALLOWED_ORIGINS`. |

Los cambios en `.env` se aplican al reiniciar `npm run dev` o al volver a compilar.

## Buscadores (SEO)

`npm run build` deja la página lista para que Google la lea sin ejecutar scripts:

- **Contenido en el HTML.** El build ejecuta la aplicación y escribe todo su texto dentro de la página (unas 2.000
  palabras por idioma). En el navegador React lo reemplaza por la versión interactiva.
- **Una dirección por idioma.** `dist/index.html` es el español (`/`) y `dist/en/index.html` el inglés (`/en/`). El
  idioma lo decide la URL, no el navegador de quien llega; el selector ES/EN navega entre las dos.
- **Metadatos por página:** título, descripción, `canonical`, `hreflang` (es, en, x-default), Open Graph y Twitter.
- **Datos estructurados** (schema.org): organización, sitio, aplicación y las preguntas frecuentes. Sin valoraciones
  ni precios, porque no existen.
- **`robots.txt` y `sitemap.xml`** en la raíz de `dist/`.
- **Agentes de IA.** El build publica `llms.txt` y `llms-full.txt` (inglés) y `llms-es.txt` y `llms-full-es.txt`
  (español): un resumen en texto plano de qué es el producto, para quién, dónde se instala, cómo se conecta y cómo
  contactar. Sale del mismo copy que la página. `robots.txt` da la bienvenida por nombre a los agentes de IA y los
  datos estructurados declaran funciones, público, modalidades de instalación y correo de contacto.

Para que la página aparezca en Google hace falta, además (no lo hace el build):

1. Publicar `dist/` completo en el dominio definitivo, con HTTPS, y definir `VITE_SITE_URL` con esa dirección.
2. Dar de alta el dominio en [Google Search Console](https://search.google.com/search-console), verificarlo (por
   DNS, o con `VITE_GOOGLE_SITE_VERIFICATION` y un nuevo build) y enviar `https://<dominio>/sitemap.xml`.
3. Pedir la indexación de `/` y `/en/` con «Inspección de URL». La primera indexación tarda de días a semanas y el
   posicionamiento depende sobre todo de que otros sitios enlacen a la página.

La aplicación (`VITE_APP_URL`) no debe indexarse: es una zona con inicio de sesión.

## Despliegue (Vercel)

`vercel.json` fija el build (`npm run build` → `dist/`), la barra final de las direcciones (`/en/`) y las cabeceras de
seguridad (Vercel no lee `public/_headers`). El proyecto de Vercel apunta a la carpeta `landing/` del repositorio y
publica cada cambio de `main`. Las variables `VITE_*` se definen en el proyecto de Vercel (Settings → Environment
Variables), no en el repositorio. El DNS del dominio vive en Cloudflare: los registros hacia Vercel van **sin proxy**
(nube gris), para que Vercel emita el certificado.

## Seguridad

La página es estática y no guarda nada: sin cookies, sin analítica y sin recursos de terceros.

- **Content-Security-Policy.** `npm run build` inserta la política en `dist/index.html`. El script va en línea (un solo
  archivo), así que cada bloque se autoriza por su SHA-256: sin `unsafe-inline` ni `unsafe-eval` para scripts. Solo se
  permiten imágenes y video del propio sitio (o de `VITE_MEDIA_BASE_URL`) y conexiones a `VITE_CONTACT_API_URL`.
  El servidor de desarrollo no la lleva; se comprueba con `npm run build && npm run preview`.
- **Cabeceras del alojamiento.** `public/_headers` (Netlify, Cloudflare Pages) declara HSTS, `nosniff`,
  `frame-ancestors 'none'`, `Referrer-Policy` y `Permissions-Policy`. Una etiqueta `<meta>` no puede declararlas: en
  nginx, Caddy o CloudFront hay que configurar las mismas (el `Caddyfile` de `infra/caddy/` sirve de referencia).
- **URL de configuración.** `VITE_APP_URL`, `VITE_SITE_URL`, `VITE_CONTACT_API_URL` y `VITE_MEDIA_BASE_URL` deben ser
  `https://` (o `http://` hacia el propio equipo): otra cosa detiene el build y, en ejecución, se descarta.
- **Formulario.** Sin ningún canal configurado queda deshabilitado y lo dice; no abre un correo sin destinatario. Con
  API: sin cookies, sin `Referer`, sin seguir redirecciones, con campo trampa. El endpoint `POST /contact-requests`
  **no existe todavía en el backend**: al crearlo necesita límite de tasa, validación en el servidor y CORS solo para
  el dominio de la landing.
- **El correo de `VITE_CONTACT_EMAIL` queda visible en la página** (cualquier robot lo puede leer): use un buzón de
  contacto, no uno personal.

## Dónde está cada cosa

- `src/copy/` — todo el texto (`es.ts`, `en.ts`); ambos cumplen `types.ts`, así que una clave
  faltante en un idioma rompe la compilación. Regla editorial: solo se afirma lo que el producto
  hace hoy.
- `src/App.tsx` — las secciones de la página.
- `src/components/ProductPreview.tsx` — la vista previa interactiva del producto (pronóstico por
  horizonte, inventario y decisiones). Usa **datos ficticios** y lo dice en pantalla: no es una
  captura con datos de un cliente.
- `src/components/` — formulario de contacto, «Hablemos» flotante, preguntas frecuentes, iconos.
- `src/styles.css` — tokens (claro y oscuro), estilos `lp-*` y animaciones. Casi todo es neutro;
  el color de la marca se reserva para la acción («Hablemos») y para los datos del gráfico. Las
  animaciones se apagan solas si la persona pidió menos movimiento en su sistema.

El idioma inicial sale de `?lang=es|en`, luego de la preferencia guardada y luego del navegador.
El contenido se pinta en el navegador (no hay HTML prerenderizado).
