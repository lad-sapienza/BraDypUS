import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig(({ mode }) => {
  // loadEnv with '' prefix loads ALL env vars (not just VITE_).
  const env = loadEnv(mode, process.cwd(), '')

  // API_PROXY_TARGET is intentionally NOT prefixed with VITE_ so it is
  // never injected into the client bundle.  It is used only by Vite's
  // dev-server proxy (Node process inside Docker) to reach the PHP backend.
  // VITE_API_BASE (client-visible) is left empty in dev so the browser uses
  // relative URLs that Vite proxies; set it only for cross-origin production.
  const proxyTarget = env.API_PROXY_TARGET || 'http://localhost:8080'

  return {
    plugins: [vue()],

    resolve: {
      alias: {
        '@':       fileURLToPath(new URL('./src', import.meta.url)),
        '@locale': fileURLToPath(new URL('./src/locale', import.meta.url))
      }
    },

    server: {
      host: '0.0.0.0',
      port: 5173,
      proxy: {
        '/api/':      { target: proxyTarget, changeOrigin: true },
        '/index.php': { target: proxyTarget, changeOrigin: true },
        '/projects/': { target: proxyTarget, changeOrigin: true },
        '/cache/':    { target: proxyTarget, changeOrigin: true }
      }
    },

    build: {
      outDir:   'dist',
      manifest: true
    }
  }
})
