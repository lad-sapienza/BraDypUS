/**
 * Lightweight i18n composable for BraDypUS Vue frontend.
 *
 * - Reads existing locale/en.json and locale/it.json directly (no vue-i18n needed)
 * - Handles %s interpolation (same as PHP tr::get)
 * - Persists language choice in localStorage
 * - Singleton: locale state is shared across all components
 */

import { ref } from 'vue'
import en from '@locale/en.json'
import it from '@locale/it.json'

const messages = { en, it }

export const availableLocales = [
  { code: 'en', label: 'English', flag: '🇬🇧' },
  { code: 'it', label: 'Italiano', flag: '🇮🇹' },
]

// Shared reactive locale — one instance for the whole app
const locale = ref(localStorage.getItem('bdus_locale') || 'en')

export function useI18n() {
  /**
   * Translate a key, replacing %s placeholders with provided arguments.
   * Falls back to English, then to the raw key if not found.
   *
   * @param {string} key
   * @param {...string} args — values to substitute for %s occurrences in order
   * @returns {string}
   */
  function t(key, ...args) {
    const msg = messages[locale.value]?.[key]
             ?? messages.en?.[key]
             ?? key
    let i = 0
    return String(msg).replace(/%s/g, () => String(args[i++] ?? ''))
  }

  function setLocale(code) {
    if (messages[code]) {
      locale.value = code
      localStorage.setItem('bdus_locale', code)
    }
  }

  return { t, locale, setLocale, availableLocales }
}
