/**
 * useDarkMode — singleton dark-mode composable.
 *
 * Backed by a three-way `mode` ('light' | 'dark' | 'system'), even though
 * only light/dark are reachable from the UI toggle for now — 'system'
 * follows the OS preference live (via a matchMedia listener, not just a
 * one-time read) and is what a fresh install defaults to. Exposing `mode`
 * already means a future settings control can offer all three without any
 * change to this file.
 *
 * `isDark` is the resolved boolean ('system' collapses to whatever the OS
 * currently prefers) and is what the rest of the app should keep consuming
 * directly — it applies the `.dark-mode` class to <html> immediately.
 */

import { ref, computed, watch } from 'vue'

const STORAGE_KEY = 'bdus-dark-mode'

const media = window.matchMedia?.('(prefers-color-scheme: dark)')

// ── Determine initial mode ──────────────────────────────────────────────────
function readMode() {
  const stored = localStorage.getItem(STORAGE_KEY)
  if (stored === 'light' || stored === 'dark' || stored === 'system') return stored
  // Legacy boolean value ('true'/'false') stored under this same key before
  // the three-way mode existed — migrate it to an explicit light/dark so
  // the user's choice survives.
  if (stored === 'true') return 'dark'
  if (stored === 'false') return 'light'
  return 'system'
}

// ── Module-level singletons (shared across all component instances) ────────
const mode      = ref(readMode())
const systemIsDark = ref(media?.matches ?? false)

const isDark = computed(() => mode.value === 'system' ? systemIsDark.value : mode.value === 'dark')

function applyClass(dark) {
  document.documentElement.classList.toggle('dark-mode', dark)
}

// Apply immediately on module load (before any component mounts)
applyClass(isDark.value)

// Keep the class + storage in sync whenever the resolved value changes.
watch(isDark, applyClass)
watch(mode, m => localStorage.setItem(STORAGE_KEY, m))

// Live-follow the OS preference while in 'system' mode.
media?.addEventListener?.('change', e => { systemIsDark.value = e.matches })

export function useDarkMode() {
  function toggle() {
    // Only light/dark are reachable this way for now — flips whatever is
    // currently showing, so clicking out of 'system' always does the
    // expected thing regardless of what the OS preference resolved to.
    mode.value = isDark.value ? 'light' : 'dark'
  }

  return { isDark, mode, toggle }
}
