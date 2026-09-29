import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// Laravel dev server. In dev the SPA proxies `/api` and `/sanctum` to it so
// the browser is same-origin and Sanctum session cookies + CSRF work without
// CORS. Production is expected to serve the SPA and API from the same origin.
const LARAVEL_DEV_SERVER = 'http://127.0.0.1:8000'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    proxy: {
      '/api': { target: LARAVEL_DEV_SERVER },
      '/sanctum': { target: LARAVEL_DEV_SERVER },
    },
  },
})
