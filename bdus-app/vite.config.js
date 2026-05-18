import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig(({ mode }) => {
  const env     = loadEnv(mode, process.cwd(), '')
  const apiBase = env.VITE_API_BASE || 'http://localhost:8080'

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
        '/api/':      { target: apiBase, changeOrigin: true },
        '/index.php': { target: apiBase, changeOrigin: true },
        '/projects/': { target: apiBase, changeOrigin: true },
        '/cache/':    { target: apiBase, changeOrigin: true }
      }
    },

    build: {
      outDir:   'dist',
      manifest: true
    }
  }
})
