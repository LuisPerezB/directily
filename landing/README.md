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
npm run build           # dist/index.html
```

## Variables (`.env`)

Toda la configuración está en `.env` (plantilla: `.env.example`). La página es solo frontend:
no necesita el backend.

| Variable | Para qué sirve |
|---|---|
| `VITE_CONTACT_EMAIL`, `VITE_CONTACT_WHATSAPP` | A dónde llegan las solicitudes: el formulario arma el mensaje y abre el correo o WhatsApp de la persona. Defina al menos uno antes de publicar. |
| `VITE_DEFAULT_LANG` | Idioma de quien llega sin preferencia (`es` o `en`). Vacío = el de su navegador. |
| `VITE_APP_URL` | URL de la aplicación; muestra el enlace «Iniciar sesión». |
| `VITE_SITE_URL` | Dirección pública del sitio, sin barra final. Con ella la imagen para compartir funciona en redes y mensajería. |
| `VITE_MEDIA_BASE_URL` | Carpeta o URL del video y su portada. Vacío = `media/`, junto a `index.html`. |
| `VITE_DEV_PORT` | Puerto de `npm run dev` y `npm run preview` (5174). |
| `VITE_CONTACT_API_URL` | Opcional. URL completa de `POST /api/v1/contact-requests`: el formulario envía a la API en vez de abrir correo o WhatsApp. El backend debe incluir el dominio de la landing en `CORS_ALLOWED_ORIGINS`. |

Los cambios en `.env` se aplican al reiniciar `npm run dev` o al volver a compilar.

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
