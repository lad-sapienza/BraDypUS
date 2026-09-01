/**
 * useDarkMode — singleton dark-mode composable.
 *
 * Reads the user's preference from localStorage on first import
 * (falling back to the OS prefers-color-scheme), applies the
 * `.dark-mode` class to <html> immediately, and exposes a
 * reactive `isDark` ref plus a `toggle()` helper.
 *
 * PrimeVue is configured with `darkModeSelector: '.dark-mode'`
 * so toggling the class is all that's needed.
 */

import { ref, watch } from 'vue'

const STORAGE_KEY = 'bdus-dark-mode'

// ── Determine initial value ────────────────────────────────────────────────
function readPreference() {
  const stored = localStorage.getItem(STORAGE_KEY)
  if (stored !== null) return stored === 'true'
  // Fall back to OS preference
  return window.matchMedia?.('(prefers-color-scheme: dark)').matches ?? false
}

// ── Module-level singleton (shared across all component instances) ─────────
const isDark = ref(readPreference())

function applyClass(dark) {
  document.documentElement.classList.toggle('dark-mode', dark)
}

// Apply immediately on module load (before any component mounts)
applyClass(isDark.value)

// Keep the class in sync whenever isDark changes
watch(isDark, dark => {
  applyClass(dark)
  localStorage.setItem(STORAGE_KEY, String(dark))
})

export function useDarkMode() {
  function toggle() {
    isDark.value = !isDark.value
  }

  return { isDark, toggle }
}
