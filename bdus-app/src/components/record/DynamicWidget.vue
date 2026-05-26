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
 * All DynamicWidget instances that use the same widget name share one import,
 * which means they share the same module scope and its singleton state
 * (e.g. the _uid counter and the CDN-load promise in quirematrix.js).
 * Without this, each instance gets its own module scope, _uid resets to 0
 * for every row, IDs collide, and the CDN script is injected multiple times.
 */
const _cache = new Map()

/**
 * Fetches the widget JS with the Bearer token (dynamic import() cannot send
 * custom headers), turns the response text into a blob URL, imports from that,
 * then revokes the blob URL.  The resolved module is cached so subsequent
 * calls for the same name skip the network round-trip entirely.
 */
async function fetchWidgetModule(name) {
  if (_cache.has(name)) return _cache.get(name)

  const token = getToken()
  const url   = `/api/widget/${encodeURIComponent(name)}`
  const res   = await fetch(url, {
    headers: token ? { Authorization: `Bearer ${token}` } : {},
  })
  if (!res.ok) throw new Error(`widget ${name} — HTTP ${res.status}`)

  const code    = await res.text()
  const blob    = new Blob([code], { type: 'application/javascript' })
  const blobUrl = URL.createObjectURL(blob)

  /* @vite-ignore — intentional dynamic import of a runtime blob URL */
  const promise = import(blobUrl).then(mod => {
    URL.revokeObjectURL(blobUrl)
    return mod.default
  })

  _cache.set(name, promise)
  return promise
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
