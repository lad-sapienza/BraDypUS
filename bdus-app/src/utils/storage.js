/**
 * Per-application browser storage.
 *
 * `localStorage` is shared by every same-origin page, so a preference saved
 * while looking at one BraDypUS database (e.g. the visible columns of a table
 * called `siti`) used to leak into a *different* database that happens to have
 * a table with the same name but different columns — the backend then built
 * `SELECT siti.<missing_column>` and the record list 500'd.
 *
 * Every key written through this module is therefore prefixed with
 *   bdus:<app>:
 * where <app> is the application slug taken straight from the URL path
 * (`/<app>/...`, see router). It is read live on every call so switching app
 * without a full reload still resolves to the right bucket. Public routes
 * (`/login`, `/new-app`, `/oauth-callback`) and reserved API prefixes map to a
 * neutral `bdus:_:` bucket.
 *
 * All accessors swallow errors so a locked-down browser (private mode, storage
 * disabled) degrades to "no preferences" instead of throwing.
 */

// First path segment that is NOT an application (mirrors router public routes
// and the API prefixes CreateApp::validateData rejects as app names).
const RESERVED_SEGMENTS = new Set([
  '', 'login', 'oauth-callback', 'new-app',
  'api', 'index.php', 'projects', 'cache',
])

/** Current application slug, or '_' when there is no app context. */
export function currentApp() {
  try {
    const seg = window.location.pathname.split('/').filter(Boolean)[0] || ''
    if (RESERVED_SEGMENTS.has(seg)) return '_'
    return decodeURIComponent(seg)
  } catch {
    return '_'
  }
}

function k(key) {
  return `bdus:${currentApp()}:${key}`
}

export const appStorage = {
  /** @returns {string|null} */
  get(key) {
    try { return localStorage.getItem(k(key)) } catch { return null }
  },
  set(key, value) {
    try { localStorage.setItem(k(key), value) } catch { /* storage unavailable */ }
  },
  remove(key) {
    try { localStorage.removeItem(k(key)) } catch { /* storage unavailable */ }
  },
  /** Convenience JSON wrappers — return `fallback` on missing/corrupt data. */
  getJSON(key, fallback = null) {
    const raw = this.get(key)
    if (raw === null) return fallback
    try { return JSON.parse(raw) } catch { return fallback }
  },
  setJSON(key, value) {
    this.set(key, JSON.stringify(value))
  },
}
