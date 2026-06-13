/**
 * BraDypUS API client — stateless, JWT-based.
 *
 * Every request carries  Authorization: Bearer <token>.
 * No cookies / credentials are sent.
 *
 * Proactive refresh: if the token has < 30 min left, the client silently
 * calls /api/auth/refresh before the actual request so the user never
 * hits a 401 mid-session.
 *
 * On 401 the token is cleared and the page is redirected to #/login.
 *
 * ── URL resolution ──────────────────────────────────────────────────────────
 * All public methods accept a direct REST path and optional params/body.
 * No route map or controller/method translation layer.
 */

import { getToken, setToken, clearToken, needsRefresh } from '@/token'

/**
 * Base URL of the PHP backend.
 * In development (same origin) this is empty, so all paths stay relative.
 * In production with a separate backend, set VITE_API_BASE=https://api.example.com
 */
const API_BASE = import.meta.env.VITE_API_BASE ?? ''

/**
 * Resolve a backend-relative asset path to a full URL.
 * Usage: assetUrl('projects/myapp/files/42.jpg')
 */
export function assetUrl(path) {
  return API_BASE + '/' + path
}

/**
 * Append params to a URL as a query string.
 * Handles nested objects recursively using bracket notation so that
 * { filter: { sigla: { _eq: 'IMP001' } } } becomes
 * filter[sigla][_eq]=IMP001 — the format PHP natively parses back to a
 * nested array via $_GET['filter']['sigla']['_eq'].
 */
function appendQuery(url, params, prefix = '') {
  for (const [k, v] of Object.entries(params)) {
    if (v === null || v === undefined) continue
    const key = prefix ? `${prefix}[${k}]` : k
    if (Array.isArray(v)) {
      v.forEach(item => url.searchParams.append(`${key}[]`, item))
    } else if (typeof v === 'object') {
      appendQuery(url, v, key)
    } else {
      url.searchParams.set(key, v)
    }
  }
}

// ── Refresh lock ─────────────────────────────────────────────────────────────
let _refreshPromise = null

async function _doRefresh() {
  if (_refreshPromise) return _refreshPromise
  _refreshPromise = (async () => {
    try {
      const token = getToken()
      if (!token) return
      const url = new URL(API_BASE + '/api/auth/refresh', window.location.origin)
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

// ── Auth header ──────────────────────────────────────────────────────────────
function _bearer() {
  const t = getToken()
  return t ? { Authorization: `Bearer ${t}` } : {}
}

// ── 401 handler ──────────────────────────────────────────────────────────────
function _handle401() {
  clearToken()
  window.location.hash = '/login'
}

// ── 409 / major_upgrade_required handler ─────────────────────────────────────
// The server returns 409 with { status:'error', code:'major_upgrade_required' }
// when the app needs a v4→v5 upgrade before any authenticated operation can run.
// We clear the session and send the user back to /login, which already knows how
// to detect the upgrade and guide them through it.
function _handleMajorUpgrade() {
  clearToken()
  window.location.hash = '/login'
}

// ── Proactive refresh guard ──────────────────────────────────────────────────
async function _guardRefresh() {
  const t = getToken()
  if (t && needsRefresh(t)) await _doRefresh()
}

// ── Low-level fetch helper ────────────────────────────────────────────────────
/**
 * Fire a fetch request and handle auth / HTTP errors uniformly.
 *
 * @param {string|URL} url
 * @param {string}     httpMethod  e.g. 'GET', 'POST', 'DELETE'
 * @param {Object}     [bodyData]  Plain object for the request body (non-GET)
 * @param {string}     label       Used in error messages, e.g. the path
 */
async function _fetch(url, httpMethod, bodyData, label) {
  const headers = { Accept: 'application/json', ..._bearer() }
  const opts    = { method: httpMethod, headers }

  if (httpMethod !== 'GET' && bodyData && Object.keys(bodyData).length > 0) {
    // Always send JSON — PHP's mergeRequestBody() handles it for all verbs.
    // File uploads go through api.upload() / api.uploadMulti() which use
    // FormData directly; _fetch() never needs FormData.
    opts.body = JSON.stringify(bodyData)
    headers['Content-Type'] = 'application/json'
  }

  const res = await fetch(url, opts)
  if (res.status === 401) { _handle401(); throw new Error('Unauthenticated') }
  if (!res.ok) {
    let body = null
    try { body = await res.json() } catch { /* non-JSON error body */ }
    if (body?.code === 'major_upgrade_required') {
      _handleMajorUpgrade()
      throw new Error('major_upgrade_required')
    }
    throw new Error(`${label} — HTTP ${res.status}`)
  }
  return res.json()
}

// ── GET ──────────────────────────────────────────────────────────────────────
async function get(path, params = {}) {
  await _guardRefresh()
  const url = new URL(API_BASE + path, window.location.origin)
  appendQuery(url, params)
  return _fetch(url, 'GET', null, path)
}

// ── POST ─────────────────────────────────────────────────────────────────────
async function post(path, body = {}) {
  await _guardRefresh()
  const url = new URL(API_BASE + path, window.location.origin)
  return _fetch(url, 'POST', body, path)
}

// ── PUT ──────────────────────────────────────────────────────────────────────
async function put(path, body = {}) {
  await _guardRefresh()
  const url = new URL(API_BASE + path, window.location.origin)
  return _fetch(url, 'PUT', body, path)
}

// ── DELETE ───────────────────────────────────────────────────────────────────
async function _delete(path, body = {}) {
  await _guardRefresh()
  const url = new URL(API_BASE + path, window.location.origin)
  return _fetch(url, 'DELETE', body, path)
}

// ── PATCH ────────────────────────────────────────────────────────────────────
async function patch(path, body = {}) {
  await _guardRefresh()
  const url = new URL(API_BASE + path, window.location.origin)
  return _fetch(url, 'PATCH', body, path)
}

// ── Upload ───────────────────────────────────────────────────────────────────
async function upload(path, file, field = 'file') {
  await _guardRefresh()
  const url = new URL(API_BASE + path, window.location.origin)

  const fd = new FormData()
  fd.append(field, file)

  const res = await fetch(url, {
    method:  'POST',
    headers: { Accept: 'application/json', ..._bearer() },
    body:    fd,
  })
  if (res.status === 401) { _handle401(); throw new Error('Unauthenticated') }
  if (!res.ok) {
    let body = null
    try { body = await res.json() } catch { /* non-JSON error body */ }
    if (body?.code === 'major_upgrade_required') { _handleMajorUpgrade(); throw new Error('major_upgrade_required') }
    throw new Error(`${path} — HTTP ${res.status}`)
  }
  return res.json()
}

// ── Multi-file upload ─────────────────────────────────────────────────────────
/**
 * Upload multiple files plus optional plain-text fields in a single request.
 *
 * @param {string}  path   REST path, e.g. '/api/import/preview'
 * @param {Object}  files  { fieldName: File, … }
 * @param {Object}  data   { key: value, … }  (plain strings/numbers)
 */
async function uploadMulti(path, files = {}, data = {}) {
  await _guardRefresh()
  const url = new URL(API_BASE + path, window.location.origin)

  const fd = new FormData()
  Object.entries(files).forEach(([k, v]) => { if (v) fd.append(k, v) })
  Object.entries(data).forEach(([k, v]) => fd.append(k, v ?? ''))

  const res = await fetch(url, {
    method:  'POST',
    headers: { Accept: 'application/json', ..._bearer() },
    body:    fd,
  })
  if (res.status === 401) { _handle401(); throw new Error('Unauthenticated') }
  if (!res.ok) {
    let body = null
    try { body = await res.json() } catch { /* non-JSON error body */ }
    if (body?.code === 'major_upgrade_required') { _handleMajorUpgrade(); throw new Error('major_upgrade_required') }
    throw new Error(`${path} — HTTP ${res.status}`)
  }
  return res.json()
}

// ── Response helper ──────────────────────────────────────────────────────────
/**
 * Canonical way to turn any API response into a translated human string.
 *
 * API contract: { status: 'success'|'error', code: string, text?: string, ...extra }
 *
 * `code` is the i18n key used for toast detail / error messages.
 * Always use this function rather than reading res.code directly in components.
 */
function responseMessage(res, t, ...args) {
  const key = res?.code
  if (!key) return ''
  if (key === 'db_error') return `${t('db_error')}: ${res.detail ?? ''}`
  return t(key, ...args)
}

/**
 * Serialise a Directus-style filter object to a URLSearchParams with bracket
 * notation — useful for building GET export URLs that include an active filter.
 *
 * filterToSearchParams({ sigla: { _eq: 'X' } })
 * → URLSearchParams { 'filter[sigla][_eq]': 'X' }
 */
export function filterToSearchParams(filter, extraParams = {}) {
  const url = new URL('http://x')
  if (filter) appendQuery(url, { filter })
  Object.entries(extraParams).forEach(([k, v]) => {
    if (v !== null && v !== undefined) url.searchParams.set(k, v)
  })
  return url.searchParams
}

export const api = { get, post, put, delete: _delete, patch, upload, uploadMulti, responseMessage }
