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
    rollupOptions: {
      output: {
        manualChunks(id: string) {
          if (id.includes('node_modules/echarts') || id.includes('node_modules/zrender')) return 'charts'
          if (id.includes('node_modules/vue-i18n') || id.includes('node_modules/@intlify')) return 'i18n'
          if (id.includes('node_modules/')) return 'vendor'
          return undefined
        },
      },
    },
  },
})
