/**
 * BraDypUS API client — stateless, JWT-based.
 *
 * Every request carries  Authorization: Bearer <token>.
 * No cookies / credentials are sent.
 *
 * Proactive refresh: if the token has < 30 min left, the client silently
 * calls login_ctrl/refresh before the actual request so the user never
 * hits a 401 mid-session.
 *
 * On 401 the token is cleared and the page is redirected to #/login.
 */

import { getToken, setToken, clearToken, needsRefresh } from '@/token'

const PHP = '/index.php'

// ── Refresh lock ─────────────────────────────────────────────────────
// Ensures only one in-flight refresh call at a time even when several
// requests fire concurrently.
let _refreshPromise = null

async function _doRefresh() {
  if (_refreshPromise) return _refreshPromise
  _refreshPromise = (async () => {
    try {
      const token = getToken()
      if (!token) return
      const url = new URL(PHP, window.location.origin)
      url.searchParams.set('obj',    'login_ctrl')
      url.searchParams.set('method', 'refresh')
      const res = await fetch(url, {
        headers: { Accept: 'application/json', ..._bearer() },
      })
      if (res.ok) {
        const data = await res.json()
        if (data.token) setToken(data.token)
      } else {
        clearToken()
      }
    } finally {
      _refreshPromise = null
    }
  })()
  return _refreshPromise
}

// ── Auth header ──────────────────────────────────────────────────────
function _bearer() {
  const t = getToken()
  return t ? { Authorization: `Bearer ${t}` } : {}
}

// ── 401 handler ──────────────────────────────────────────────────────
function _handle401() {
  clearToken()
  window.location.hash = '/login'
}

// ── Proactive refresh guard ──────────────────────────────────────────
async function _guardRefresh() {
  const t = getToken()
  if (t && needsRefresh(t)) await _doRefresh()
}

// ── GET ──────────────────────────────────────────────────────────────
async function get(obj, method, params = {}) {
  await _guardRefresh()

  const url = new URL(PHP, window.location.origin)
  url.searchParams.set('obj',    obj)
  url.searchParams.set('method', method)
  Object.entries(params).forEach(([k, v]) => {
    if (Array.isArray(v)) {
      v.forEach(item => url.searchParams.append(k + '[]', item))
    } else {
      url.searchParams.set(k, v)
    }
  })

  const res = await fetch(url, {
    headers: { Accept: 'application/json', ..._bearer() },
  })

  if (res.status === 401) { _handle401(); throw new Error('Unauthenticated') }
  if (!res.ok) throw new Error(`${obj}::${method} — HTTP ${res.status}`)
  return res.json()
}

// ── POST ─────────────────────────────────────────────────────────────
async function post(obj, method, data = {}, urlParams = {}) {
  await _guardRefresh()

  const url = new URL(PHP, window.location.origin)
  url.searchParams.set('obj',    obj)
  url.searchParams.set('method', method)
  Object.entries(urlParams).forEach(([k, v]) => url.searchParams.set(k, v))

  const hasComplex = Object.values(data).some(
    v => v !== null && typeof v === 'object'
  )

  let body, extraHeaders = {}
  if (hasComplex) {
    body = JSON.stringify(data)
    extraHeaders['Content-Type'] = 'application/json'
  } else {
    body = new FormData()
    Object.entries(data).forEach(([k, v]) => body.append(k, v ?? ''))
  }

  const res = await fetch(url, {
    method: 'POST',
    headers: { Accept: 'application/json', ..._bearer(), ...extraHeaders },
    body,
  })

  if (res.status === 401) { _handle401(); throw new Error('Unauthenticated') }
  if (!res.ok) throw new Error(`${obj}::${method} — HTTP ${res.status}`)
  return res.json()
}

// ── Upload ───────────────────────────────────────────────────────────
async function upload(obj, method, file, field = 'file', urlParams = {}) {
  await _guardRefresh()

  const url = new URL(PHP, window.location.origin)
  url.searchParams.set('obj',    obj)
  url.searchParams.set('method', method)
  Object.entries(urlParams).forEach(([k, v]) => url.searchParams.set(k, v))

  const fd = new FormData()
  fd.append(field, file)

  const res = await fetch(url, {
    method:  'POST',
    headers: { Accept: 'application/json', ..._bearer() },
    body:    fd,
  })

  if (res.status === 401) { _handle401(); throw new Error('Unauthenticated') }
  if (!res.ok) throw new Error(`${obj}::${method} — HTTP ${res.status}`)
  return res.json()
}

// ── Response helper ──────────────────────────────────────────────────
function responseMessage(res, t, ...args) {
  if (!res?.code) return ''
  if (res.code === 'db_error') return `${t('db_error')}: ${res.detail ?? ''}`
  return t(res.code, ...args)
}

export const api = { get, post, upload, responseMessage }
