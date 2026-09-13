/**
 * Lightweight i18n composable for BraDypUS Vue frontend.
 *
 * - Reads existing locale/en.json and locale/it.json directly (no vue-i18n needed)
 * - Handles %s interpolation (same as PHP tr::get), or {name} interpolation when
 *   called with a single plain-object argument (used for generic loops over
 *   heterogeneous data, e.g. rendering a list of { code, ...fields } messages)
 * - Persists language choice in per-application localStorage
 * - Singleton: locale state is shared across all components
 */

import { ref } from 'vue'
import en from '@locale/en.json'
import it from '@locale/it.json'
import { appStorage } from '@/utils/storage'

const messages = { en, it }

export const availableLocales = [
  { code: 'en', label: 'English', flag: '🇬🇧' },
  { code: 'it', label: 'Italiano', flag: '🇮🇹' },
]

// Per-application key (bdus:<app>:locale) — see utils/storage.js.
const STORAGE_KEY = 'locale'

// Shared reactive locale — one instance for the whole app
const locale = ref(appStorage.get(STORAGE_KEY) || 'en')

/**
 * True once the visitor has an explicit locale choice stored (the flag toggle,
 * or a previously applied app default) — used to apply an app's configured
 * default language (Controllers\Info::getAppInfo().lang) only the first time,
 * without overriding a choice the visitor already made.
 */
export function hasStoredLocale() {
  return appStorage.get(STORAGE_KEY) !== null
}

export function useI18n() {
  /**
   * Translate a key, replacing %s placeholders with the given positional
   * arguments — or, when called with a single plain object, replacing
   * {name} placeholders with that object's fields (for generic loops where
   * the caller only has a data object on hand, not the field order).
   * Falls back to English, then to the raw key if not found.
   *
   * @param {string} key
   * @param {...*} args — positional %s values, or a single { name: value } object
   * @returns {string}
   */
  function t(key, ...args) {
    const msg = messages[locale.value]?.[key]
             ?? messages.en?.[key]
             ?? key
    if (args.length === 1 && args[0] !== null && typeof args[0] === 'object' && !Array.isArray(args[0])) {
      const data = args[0]
      return String(msg).replace(/\{(\w+)\}/g, (_, name) => String(data[name] ?? ''))
    }
    let i = 0
    return String(msg).replace(/%s/g, () => String(args[i++] ?? ''))
  }

  function setLocale(code) {
    if (messages[code]) {
      locale.value = code
      appStorage.set(STORAGE_KEY, code)
    }
  }

  return { t, locale, setLocale, availableLocales }
}
