import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  server: {
    // Lets a Cloudflare quick tunnel (a random *.trycloudflare.com host)
    // reach this dev server - Vite's default host-header allowlist only
    // covers localhost/127.0.0.1 and rejects anything else outright.
    allowedHosts: ['.trycloudflare.com'],
    // Proxies the API/websocket through this same origin instead of
    // pointing the frontend at separate tunnel hostnames for each backend
    // process - keeps everything same-origin from the browser's
    // perspective, so Sanctum's cookie-based auth needs no cross-site
    // SameSite/CORS changes at all.
    proxy: {
      '/api': 'http://localhost:8000',
      '/sanctum': 'http://localhost:8000',
      '/broadcasting': 'http://localhost:8000',
      '/storage': 'http://localhost:8000',
      '/app': {
        target: 'ws://localhost:8080',
        ws: true,
      },
    },
  },
})
