<template>
  <div ref="container" class="dynamic-widget" />
</template>

<script setup>
/**
 * Loads and mounts a per-app widget served by the backend.
 *
 * The widget JS is fetched from /api/widget/{name} as a native ES module.
 * It must export a default object with:
 *   mount(container: HTMLElement, value: string): void
 *   unmount?(container: HTMLElement): void   // optional cleanup
 *
 * Example widget file (projects/{app}/widgets/quirematrix.js):
 *
 *   export default {
 *     mount(container, value) {
 *       // render into container
 *     },
 *     unmount(container) {
 *       container.innerHTML = ''
 *     }
 *   }
 */
import { ref, onMounted, onBeforeUnmount, watch } from 'vue'
import { getToken } from '@/token'

const props = defineProps({
  /** Widget name — must match a file in projects/{app}/widgets/{name}.js */
  name:  { type: String, required: true },
  /** The field value to render */
  value: { type: String, default: '' },
})

const container = ref(null)
let widgetMod   = null

/**
 * Module-level cache: widget name → Promise<module default>.
 *
 * The promise is stored in the cache SYNCHRONOUSLY (before the first await)
 * so that concurrent calls for the same name all receive the same promise
 * and therefore the same module instance.  Without this, all DynamicWidget
 * instances mounted at the same time would each see an empty cache, do their
 * own fetch, and import separate blob URLs — giving each a fresh module scope
 * with its own singleton state (_uid counter, CDN-load promise, etc.).
 */
const _cache = new Map()

/** Inner async worker — only ever called once per widget name. */
async function _load(name) {
  const token = getToken()
  const res   = await fetch(`/api/widget/${encodeURIComponent(name)}`, {
    headers: token ? { Authorization: `Bearer ${token}` } : {},
  })
  if (!res.ok) throw new Error(`widget ${name} — HTTP ${res.status}`)
  const code    = await res.text()
  const blob    = new Blob([code], { type: 'application/javascript' })
  const blobUrl = URL.createObjectURL(blob)
  try {
    /* @vite-ignore — intentional dynamic import of a runtime blob URL */
    return (await import(blobUrl)).default
  } finally {
    URL.revokeObjectURL(blobUrl)
  }
}

/**
 * Returns a promise that resolves to the widget's default export.
 * The promise is cached synchronously on the first call so that every
 * concurrent caller gets the exact same promise — and thus the same module.
 */
function fetchWidgetModule(name) {
  if (!_cache.has(name)) {
    _cache.set(name, _load(name))   // store promise BEFORE any await
  }
  return _cache.get(name)
}

async function load() {
  if (!props.name) return
  try {
    widgetMod = await fetchWidgetModule(props.name)
    mount()
  } catch (e) {
    if (container.value) {
      container.value.textContent = props.value   // graceful fallback
    }
  }
}

function mount() {
  if (!widgetMod?.mount || !container.value) return
  container.value.innerHTML = ''
  widgetMod.mount(container.value, props.value)
}

function unmount() {
  if (widgetMod?.unmount && container.value) {
    widgetMod.unmount(container.value)
  }
}

onMounted(load)
onBeforeUnmount(unmount)
watch(() => props.value, mount)
</script>

<style scoped>
.dynamic-widget { width: 100%; }
</style>
