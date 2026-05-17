/**
 * JWT token manager — sessionStorage backed, so each browser tab is
 * independent. This is what enables multiple BraDypUS applications open
 * simultaneously without interfering with each other.
 *
 * The token is sent as  Authorization: Bearer <token>  on every request.
 * No cookies are used.
 */

const KEY = 'bdus_token'

export const getToken  = ()  => sessionStorage.getItem(KEY)
export const setToken  = (t) => sessionStorage.setItem(KEY, t)
export const clearToken = () => sessionStorage.removeItem(KEY)

/**
 * Decode the JWT payload (base64url → JSON).
 * Does NOT verify the signature — verification is PHP-side.
 */
export function decodePayload(token = null) {
  const t = token ?? getToken()
  if (!t) return null
  try {
    const part = t.split('.')[1]
    return JSON.parse(atob(part.replace(/-/g, '+').replace(/_/g, '/')))
  } catch {
    return null
  }
}

/** True if the token is missing or its exp claim is in the past. */
export function isExpired(token = null) {
  const p = decodePayload(token)
  if (!p?.exp) return true
  return Math.floor(Date.now() / 1000) >= p.exp
}

/**
 * True when less than 30 minutes remain before expiry.
 * api.js uses this to trigger a proactive silent refresh.
 */
export function needsRefresh(token = null) {
  const p = decodePayload(token)
  if (!p?.exp) return false
  return (p.exp - Math.floor(Date.now() / 1000)) < 30 * 60
}
