import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

const backend = process.env.VITE_BACKEND_URL ?? 'http://127.0.0.1:8000'

// En desarrollo, la SPA y la API comparten origen gracias al proxy (cookies de sesión
// de Sanctum + CSRF). En producción, nginx sirve dist/ y hace proxy de /api y /sanctum.
export default defineConfig({
  plugins: [vue(), tailwindcss()],
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
  server: {
    port: 5173,
    strictPort: true,
    proxy: {
      '/api': { target: backend, changeOrigin: false },
      '/sanctum': { target: backend, changeOrigin: false },
    },
  },
  build: {
    target: 'es2022',
    chunkSizeWarningLimit: 900,
    // Sin agrupación manual de paquetes: con `manualChunks` (vendor/charts/i18n) Rolldown generaba ciclos entre paquetes
    // (api → vendor → charts → api, con su ayudante interno alojado en `api`) y charts se ejecutaba antes de tiempo:
    // «e is not a function» y la SPA no arrancaba en producción. La división automática no deja ciclos y ECharts queda
    // en su propio paquete, cargado solo por las vistas con gráficos. Si se vuelve a agrupar, comprobar que no haya ciclos.
  },
})
