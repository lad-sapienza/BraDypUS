import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig({
  // Vue source lives in vue/ to avoid conflicts with the PHP app at the root
  root: 'vue',

  plugins: [vue()],

  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./vue/src', import.meta.url)),
      '@locale': fileURLToPath(new URL('./locale', import.meta.url))
    }
  },

  server: {
    host: '0.0.0.0',   // required for Docker
    port: 5173,
    proxy: {
      // Forward all PHP calls to the Apache container.
      // In Docker Compose, 'app' is the PHP service hostname.
      // Outside Docker (direct npm run vue:dev), change to http://localhost:8080
      '/index.php': {
        target: 'http://app:80',
        changeOrigin: true
      },
      // Forward static assets served by PHP (uploaded files, cached thumbnails).
      // In dev the Vite server has no access to projects/ or cache/; Apache does.
      '/projects/': {
        target: 'http://app:80',
        changeOrigin: true
      },
      '/cache/': {
        target: 'http://app:80',
        changeOrigin: true
      }
    }
  },

  build: {
    outDir: '../dist/vue',
    manifest: true
  }
})
