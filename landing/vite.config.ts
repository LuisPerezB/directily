/// <reference types="vitest/config" />
import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'
import { viteSingleFile } from 'vite-plugin-singlefile'

// La landing se publica como UNA sola página: el script y los estilos quedan dentro de
// dist/index.html (vite-plugin-singlefile), así se puede subir a cualquier alojamiento estático.
// Toda la configuración sale de .env (ver .env.example).
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), 'VITE_')
  const port = Number(env.VITE_DEV_PORT) || 5174

  return {
    plugins: [react(), viteSingleFile()],
    server: { port, strictPort: true },
    preview: { port, strictPort: true },
    build: { target: 'es2022' },
    test: { environment: 'jsdom', include: ['src/**/*.test.{ts,tsx}'], css: false },
  }
})
