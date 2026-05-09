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
  Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v))

  const res = await fetch(url, {
    headers: { Accept: 'application/json' },
    credentials: 'same-origin'   // keep PHP session cookie
  })

  if (!res.ok) throw new Error(`${obj}::${method} — HTTP ${res.status}`)
  return res.json()
}

/**
 * POST request to a controller method.
 * Data is sent as FormData to match the existing PHP $_POST expectations.
 * @param {string} obj
 * @param {string} method
 * @param {object} data
 */
async function post(obj, method, data = {}) {
  const url = new URL(PHP, window.location.origin)
  url.searchParams.set('obj', obj)
  url.searchParams.set('method', method)

  const body = new FormData()
  Object.entries(data).forEach(([k, v]) => body.append(k, v))

  const res = await fetch(url, {
    method: 'POST',
    headers: { Accept: 'application/json' },
    credentials: 'same-origin',
    body
  })

  if (!res.ok) throw new Error(`${obj}::${method} — HTTP ${res.status}`)
  return res.json()
}

export const api = { get, post }
