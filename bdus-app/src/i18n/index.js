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

const STORAGE_KEY = 'bdus_locale'

// Shared reactive locale — one instance for the whole app
const locale = ref(localStorage.getItem(STORAGE_KEY) || 'en')

/**
 * True once the visitor has an explicit locale choice stored (the flag toggle,
 * or a previously applied app default) — used to apply an app's configured
 * default language (Controllers\Info::getAppInfo().lang) only the first time,
 * without overriding a choice the visitor already made.
 */
export function hasStoredLocale() {
  return localStorage.getItem(STORAGE_KEY) !== null
}

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
      localStorage.setItem(STORAGE_KEY, code)
    }
  }

  return { t, locale, setLocale, availableLocales }
}
