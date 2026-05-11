/**
 * Minimal API client for BraDypUS PHP backend.
 * All calls send Accept: application/json to trigger the wantsJson() bridge.
 */

const PHP = '/index.php'

/**
 * GET request to a controller method.
 * @param {string} obj   - controller name (e.g. 'user_ctrl')
 * @param {string} method - method name (e.g. 'showList')
 * @param {object} params - additional query params
 */
async function get(obj, method, params = {}) {
  const url = new URL(PHP, window.location.origin)
  url.searchParams.set('obj', obj)
  url.searchParams.set('method', method)
  Object.entries(params).forEach(([k, v]) => {
    if (Array.isArray(v)) {
      v.forEach(item => url.searchParams.append(k + '[]', item))
    } else {
      url.searchParams.set(k, v)
    }
  })

  const res = await fetch(url, {
    headers: { Accept: 'application/json' },
    credentials: 'same-origin'   // keep PHP session cookie
  })

  if (!res.ok) throw new Error(`${obj}::${method} — HTTP ${res.status}`)
  return res.json()
}

/**
 * POST request to a controller method.
 *
 * Simple scalar values go as FormData (populates $_POST in PHP).
 * If any value is an object/array, the whole body is sent as JSON
 * and PHP must read php://input — handled by the Controller base class.
 *
 * @param {string} obj
 * @param {string} method
 * @param {object} data       - request body
 * @param {object} urlParams  - extra key=value pairs appended to the URL query string
 */
async function post(obj, method, data = {}, urlParams = {}) {
  const url = new URL(PHP, window.location.origin)
  url.searchParams.set('obj', obj)
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
    headers: { Accept: 'application/json', ...extraHeaders },
    credentials: 'same-origin',
    body
  })

  if (!res.ok) throw new Error(`${obj}::${method} — HTTP ${res.status}`)
  return res.json()
}

/**
 * Converts a v5 backend response to a human-readable message string.
 *
 * Backend v5 convention:
 *   { status, code, ...semanticFields }       — normal response
 *   { status: 'error', code: 'db_error', detail: '...' } — DB/exception error
 *
 * The caller provides a `t` function from useI18n() and optional extra args
 * for %s interpolation (positional, same as tr::get in PHP).
 *
 * @param {object} res      - parsed JSON response
 * @param {function} t      - translation function from useI18n()
 * @param {...*} args       - extra interpolation args for %s placeholders
 * @returns {string}
 */
function responseMessage(res, t, ...args) {
  if (!res?.code) return ''
  if (res.code === 'db_error') {
    return `${t('db_error')}: ${res.detail ?? ''}`
  }
  return t(res.code, ...args)
}

/**
 * Upload a file to a controller method via multipart/form-data.
 *
 * @param {string} obj
 * @param {string} method
 * @param {File}   file        - the File object from an <input type="file">
 * @param {string} [field]     - form field name (default: 'file')
 * @param {object} [urlParams] - extra key=value pairs appended to the URL query string
 */
async function upload(obj, method, file, field = 'file', urlParams = {}) {
  const url = new URL(PHP, window.location.origin)
  url.searchParams.set('obj', obj)
  url.searchParams.set('method', method)
  Object.entries(urlParams).forEach(([k, v]) => url.searchParams.set(k, v))

  const fd = new FormData()
  fd.append(field, file)

  const res = await fetch(url, {
    method: 'POST',
    headers: { Accept: 'application/json' },   // NO Content-Type — browser sets multipart boundary
    credentials: 'same-origin',
    body: fd,
  })

  if (!res.ok) throw new Error(`${obj}::${method} — HTTP ${res.status}`)
  return res.json()
}

export const api = { get, post, upload, responseMessage }
