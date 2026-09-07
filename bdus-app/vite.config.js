import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'
import { readFileSync, copyFileSync } from 'node:fs'
import { join } from 'node:path'
import { createRequire } from 'node:module'

const require = createRequire(import.meta.url)

const { version: APP_VERSION } = JSON.parse(
  readFileSync(new URL('./package.json', import.meta.url), 'utf-8')
)

// maplibre-gl 6.x starts its Web Worker (GeoJSON / vector-tile parsing) at
// runtime from a sibling URL built as `new URL('./maplibre-gl-worker.mjs',
// import.meta.url)`, with the filename picked dynamically. Rollup's static
// worker detection never sees it, so `vite build` leaves the file out of the
// bundle. At runtime the bundled maplibre chunk lands in dist/assets/, its
// import.meta.url resolves to /assets/<chunk>.js, and the follow-up request to
// /assets/maplibre-gl-worker.mjs 404s -> the SPA nginx fallback returns
// index.html as text/html -> the module worker is blocked (nosniff) -> GeoJSON
// sources are never parsed and no geometry renders, while the raster base layer
// (no worker needed) still does. Dev is unaffected: optimizeDeps.exclude below
// serves the package raw from node_modules, where the worker is a real sibling.
//
// Fix: copy the worker and the shared chunk it imports (by that exact relative
// name) into dist/assets/ verbatim, so the runtime URL resolves to a real file.
function copyMaplibreWorker () {
  const files = ['maplibre-gl-worker.mjs', 'maplibre-gl-shared.mjs']
  let assetsDir
  return {
    name: 'copy-maplibre-worker',
    apply: 'build',
    configResolved (cfg) {
      assetsDir = join(cfg.root, cfg.build.outDir, cfg.build.assetsDir)
    },
    closeBundle () {
      for (const f of files) {
        copyFileSync(require.resolve(`maplibre-gl/dist/${f}`), join(assetsDir, f))
      }
    }
  }
}

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
    // Inject the app version from package.json as a compile-time constant.
    // Use __APP_VERSION__ anywhere in the Vue source without an extra HTTP call.
    define: {
      __APP_VERSION__: JSON.stringify(APP_VERSION)
    },

    plugins: [vue(), copyMaplibreWorker()],

    resolve: {
      alias: {
        '@':       fileURLToPath(new URL('./src', import.meta.url)),
        '@locale': fileURLToPath(new URL('./src/locale', import.meta.url))
      }
    },

    // maplibre-gl spins up its renderer/tile-parsing worker via
    // `new Worker(url, { type: 'module' })`. Vite's dev-time optimizeDeps
    // pre-bundling re-homes the package into node_modules/.vite/deps/ but
    // doesn't carry the separate worker file along correctly — the browser
    // ends up requesting node_modules/.vite/deps/maplibre-gl-worker.mjs,
    // which Vite itself logs as missing ("The file does not exist ... Try
    // adding it to optimizeDeps.exclude"). Reported symptom: GeoFace's base
    // raster layer still renders, but every record/site geometry (a GeoJSON
    // source, which does need the worker) never does.
    // Excluding maplibre-gl from pre-bundling serves the real package straight
    // from node_modules instead, where the worker file sits next to the file
    // that references it and resolves correctly. Only a dev-server concern —
    // `vite build` bundles workers through a different, unaffected path.
    optimizeDeps: {
      exclude: ['maplibre-gl', 'maplibre-gl-draw']
    },

    server: {
      host: '0.0.0.0',
      port: 5173,
      proxy: {
        // Keys starting with '^' are treated as RegExp by Vite. The app-scoped
        // rule must come first so /{app}/api/... reaches the backend instead of
        // the SPA. The negative lookahead keeps Vite's own dev roots
        // (notably /src/api/index.js) from being proxied. Mirrors
        // bdus-app/nginx.conf.template.
        '^/(?!src/|node_modules/|@)[^/]+/api/': { target: proxyTarget, changeOrigin: true },
        '/api/':        { target: proxyTarget, changeOrigin: true },
        '/index.php':   { target: proxyTarget, changeOrigin: true },
        '/projects/':   { target: proxyTarget, changeOrigin: true },
        '/cache/':      { target: proxyTarget, changeOrigin: true }
      }
    },

    build: {
      outDir:   'dist',
      manifest: true
    }
  }
})
