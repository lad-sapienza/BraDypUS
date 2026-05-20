import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '@/api'
import { getToken, setToken, clearToken, decodePayload, isExpired } from '@/token'

/**
 * Build a normalised user object from a decoded JWT payload.
 * Privilege logic: lower numeric value = higher privilege.
 * Writers (prv ≤ 25) can create / edit records.
 */
function userFromPayload(p) {
  if (!p) return null
  return {
    id:              p.sub,
    name:            p.name  ?? '',
    email:           p.eml   ?? '',
    app:             p.app   ?? '',
    privilege_value: p.prv,
    can_write:       p.prv <= 25,
  }
}

export const useAuthStore = defineStore('auth', () => {
  // Restore user from an existing, non-expired token on store init so that
  // page reloads within the same tab don't require a full re-login.
  const stored = getToken()
  const user   = ref((!stored || isExpired(stored)) ? null : userFromPayload(decodePayload(stored)))

  // ── Login ────────────────────────────────────────────────────────

  async function login(email, password, appName) {
    const res = await api.post('/api/auth/login', { email, password, app: appName })
    if (res.status !== 'success') throw new Error(res.code ?? res.text ?? 'generic_error')
    _applyToken(res.token)
  }

  // ── Silent refresh ───────────────────────────────────────────────

  async function refresh() {
    try {
      const res = await api.get('/api/auth/refresh')
      if (res.token) _applyToken(res.token)
    } catch {
      // If refresh fails the user is eventually redirected to /login
      // when the current token expires.
    }
  }

  // ── Logout ───────────────────────────────────────────────────────

  async function logout() {
    // Fire-and-forget: PHP endpoint only logs the event.
    api.get('/api/auth/logout').catch(() => {})
    clearToken()
    user.value = null
  }

  // ── Auth check ───────────────────────────────────────────────────

  const isAuthenticated = () => {
    const token = getToken()
    return !!(user.value?.id) && !!token && !isExpired(token)
  }

  // ── Internal ─────────────────────────────────────────────────────

  function _applyToken(token) {
    setToken(token)
    user.value = userFromPayload(decodePayload(token))
  }

  /** Patch local user fields after a profile save (no API roundtrip needed). */
  function updateProfile(fields) {
    if (user.value) user.value = { ...user.value, ...fields }
  }

  return { user, login, refresh, logout, isAuthenticated, updateProfile }
})
