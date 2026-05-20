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
 *
 * ── URL resolution ──────────────────────────────────────────────────────────
 * All call sites use the legacy  api.get(ctrl, method, params) / api.post(ctrl, method, data)
 * signatures.  Internally, ROUTE_MAP translates (ctrl, method) → (HTTP verb, path).
 * If no entry exists the request falls back to the legacy ?obj=&method= format.
 *
 * Path params (e.g. {tb}, {id}) are extracted from the caller's params/data
 * and substituted into the URL; the remaining values go as query string (GET)
 * or request body (POST / PUT / PATCH / DELETE).
 */

import { getToken, setToken, clearToken, needsRefresh } from '@/token'

/**
 * Base URL of the PHP backend.
 * In development (same origin) this is empty, so all paths stay relative.
 * In production with a separate backend, set VITE_API_BASE=https://api.example.com
 */
const API_BASE = import.meta.env.VITE_API_BASE ?? ''
const PHP      = API_BASE + '/index.php'

/**
 * Resolve a backend-relative asset path to a full URL.
 * Usage: assetUrl('projects/myapp/files/42.jpg')
 */
export function assetUrl(path) {
  return API_BASE + '/' + path
}

// ── Route map ────────────────────────────────────────────────────────────────
// Each entry: [httpMethod, pathTemplate, [pathParamNames…]]
// Path params are extracted (in priority order) from urlParams then from data.
//
// IMPORTANT: the httpMethod here is the authoritative verb used on the wire.
// Whether the caller used api.get() or api.post() only determines how the
// remaining (non-path) params are treated: query-string vs request body.

const ROUTE_MAP = {
  // ── Auth ──────────────────────────────────────────────────────────────────
  'login_ctrl:listApps': ['GET',  '/api/auth/apps',    []],
  'login_ctrl:auth':     ['POST', '/api/auth/login',   []],
  'login_ctrl:refresh':  ['GET',  '/api/auth/refresh', []],
  'login_ctrl:out':      ['GET',  '/api/auth/logout',  []],

  // ── Tables / home ─────────────────────────────────────────────────────────
  'home_ctrl:listTables': ['GET', '/api/tables', []],

  // ── Info ──────────────────────────────────────────────────────────────────
  'info_ctrl:getInfo':    ['GET', '/api/info',     []],
  'info_ctrl:getAppInfo': ['GET', '/api/info/app', []],

  // ── Records ───────────────────────────────────────────────────────────────
  'record_ctrl:getRecord':              ['GET',    '/api/record/{tb}/{id}',       ['tb', 'id']],
  'record_ctrl:getRecords':             ['POST',   '/api/records/{tb}',           ['tb']],
  'record_ctrl:exportRecords':          ['GET',    '/api/records/{tb}/export',    ['tb']],
  'record_ctrl:saveRecord':             ['POST',   '/api/record/{tb}',            ['tb']],
  'record_ctrl:erase':                  ['DELETE', '/api/record/{tb}/{id}',       ['tb', 'id']],
  'record_ctrl:getTemplates':           ['GET',    '/api/record/{tb}/templates',  ['tb']],
  'record_ctrl:getFieldOptions':        ['GET',    '/api/record/{tb}/field-options',   ['tb']],
  'record_ctrl:searchLinkCandidates':   ['GET',    '/api/record/{tb}/link-candidates', ['tb']],

  // ── Files ─────────────────────────────────────────────────────────────────
  'record_ctrl:uploadFile':  ['POST',   '/api/record/{tb}/{id}/file', ['tb', 'id']],
  'record_ctrl:deleteFile':  ['DELETE', '/api/file/{fileId}',         ['fileId']],
  'file_ctrl:sortFiles':     ['POST',   '/api/files/sort',            []],

  // ── Stratigraphic Relations (RS) ──────────────────────────────────────────
  'record_ctrl:addRs':       ['POST',   '/api/record/{tb}/rs', ['tb']],
  'record_ctrl:deleteRs':    ['DELETE', '/api/rs/{id}',         ['id']],
  'record_ctrl:getRsMatrix': ['GET',    '/api/rs/matrix',       []],

  // ── Manual links ──────────────────────────────────────────────────────────
  'record_ctrl:addManualLink':    ['POST',   '/api/manual-link',       []],
  'record_ctrl:deleteManualLink': ['DELETE', '/api/manual-link/{id}',  ['id']],

  // ── Search ────────────────────────────────────────────────────────────────
  'search_ctrl:getAdvancedConfig': ['GET', '/api/search/{tb}/config', ['tb']],
  'search_ctrl:getUsedValues':     ['GET', '/api/search/{tb}/values', ['tb']],

  // ── Users ─────────────────────────────────────────────────────────────────
  'user_ctrl:showList':             ['GET',    '/api/users',                    []],
  'user_ctrl:showUserForm':         ['GET',    '/api/user',                     []],
  'user_ctrl:saveUserData':         ['POST',   '/api/user',                     []],
  'user_ctrl:deleteOne':            ['DELETE', '/api/user/{id}',                ['id']],
  'user_ctrl:getTablePrivileges':   ['GET',    '/api/user/{user_id}/privileges', ['user_id']],
  'user_ctrl:saveTablePrivilege':   ['POST',   '/api/user/{user_id}/privileges', ['user_id']],
  'user_ctrl:deleteTablePrivilege': ['DELETE', '/api/privilege/{id}',            ['id']],

  // ── Configuration ─────────────────────────────────────────────────────────
  'config_ctrl:getAppProperties':       ['GET', '/api/config/app',               []],
  'config_ctrl:save_app_properties':    ['PUT', '/api/config/app',               []],
  'config_ctrl:getTableList':           ['GET', '/api/config/tables',            []],
  'config_ctrl:add_new_tb':             ['POST',   '/api/config/tables',         []],
  'config_ctrl:save_tb_data':           ['PUT',    '/api/config/table/{tb}',     ['tb']],
  'config_ctrl:delete_tb':              ['DELETE', '/api/config/table/{tb}',     ['tb']],
  'config_ctrl:rename_tb':              ['PATCH',  '/api/config/table/{tb}',     ['tb']],
  'config_ctrl:sortTables':             ['POST',   '/api/config/tables/sort',    []],
  'config_ctrl:getTableConfig':         ['GET',    '/api/config/table/{tb}',     ['tb']],
  'config_ctrl:getFldStructure':        ['GET',    '/api/config/field-structure', []],
  'config_ctrl:getFldList':             ['GET',    '/api/config/table/{tb}/fields', ['tb']],
  'config_ctrl:add_new_fld':            ['POST',   '/api/config/table/{tb}/field',         ['tb']],
  'config_ctrl:save_fld_properties':    ['PUT',    '/api/config/table/{tb}/field/{fld}',   ['tb', 'fld']],
  'config_ctrl:delete_column':          ['DELETE', '/api/config/table/{tb}/field/{fld}',   ['tb', 'fld']],
  'config_ctrl:rename_column':          ['PATCH',  '/api/config/table/{tb}/field/{fld}',   ['tb', 'fld']],
  'config_ctrl:getGeoFaceConfig':       ['GET',    '/api/config/geoface',         []],
  'config_ctrl:save_geoface_properties':['PUT',    '/api/config/geoface',         []],
  'config_ctrl:uploadGeoFile':          ['POST',   '/api/config/geofile',         []],
  'config_ctrl:delete_local_geofile':   ['DELETE', '/api/config/geofile',         []],
  'config_ctrl:getValidationReport':    ['GET',    '/api/config/validation',      []],
  'config_ctrl:fix':                    ['POST',   '/api/config/validation/fix',  []],

  // ── Admin ─────────────────────────────────────────────────────────────────
  'confirm_super_adm_pwd_ctrl:check_pwd': ['POST', '/api/admin/check-password', []],

  // ── Backups ───────────────────────────────────────────────────────────────
  'backup_ctrl:listBackups':   ['GET',    '/api/backups',                  []],
  'backup_ctrl:doBackup':      ['POST',   '/api/backups',                  []],
  'backup_ctrl:deleteBackup':  ['DELETE', '/api/backup/{file}',            ['file']],
  'backup_ctrl:restoreBackup': ['POST',   '/api/backup/{file}/restore',    ['file']],
  'backup_ctrl:downloadBackup':['GET',    '/api/backup/{file}/download',   ['file']],

  // ── Logs ──────────────────────────────────────────────────────────────────
  'debug_ctrl:getLogs':   ['GET',  '/api/logs',       []],
  'debug_ctrl:purgeLogs': ['POST', '/api/logs/purge', []],

  // ── Charts ────────────────────────────────────────────────────────────────
  'chart_ctrl:listCharts':   ['GET',    '/api/charts',                []],
  'chart_ctrl:saveChart':    ['POST',   '/api/charts',                []],
  'chart_ctrl:getData':      ['POST',   '/api/chart/data',            []],
  'chart_ctrl:shareChart':   ['POST',   '/api/chart/{id}/share',      ['id']],
  'chart_ctrl:unshareChart': ['POST',   '/api/chart/{id}/unshare',    ['id']],
  'chart_ctrl:deleteChart':  ['DELETE', '/api/chart/{id}',            ['id']],

  // ── Saved queries ─────────────────────────────────────────────────────────
  'saved_queries_ctrl:listQueries':  ['GET',    '/api/saved-queries',               []],
  'saved_queries_ctrl:saveQuery':    ['POST',   '/api/saved-queries',               []],
  'saved_queries_ctrl:shareQuery':   ['POST',   '/api/saved-query/{id}/share',      ['id']],
  'saved_queries_ctrl:unshareQuery': ['POST',   '/api/saved-query/{id}/unshare',    ['id']],
  'saved_queries_ctrl:deleteQuery':  ['DELETE', '/api/saved-query/{id}',            ['id']],

  // ── API keys ──────────────────────────────────────────────────────────────
  'api_ctrl:listKeys':  ['GET',    '/api/api-keys',               []],
  'api_ctrl:createKey': ['POST',   '/api/api-keys',               []],
  'api_ctrl:revokeKey': ['POST',   '/api/api-key/{id}/revoke',    ['id']],
  'api_ctrl:deleteKey': ['DELETE', '/api/api-key/{id}',           ['id']],

  // ── History ───────────────────────────────────────────────────────────────
  'myHistory_ctrl:getHistory': ['GET', '/api/history', []],

  // ── Welcome / frontpage ───────────────────────────────────────────────────
  'frontpage_editor_ctrl:getWelcome':  ['GET', '/api/welcome', []],
  'frontpage_editor_ctrl:saveWelcome': ['PUT', '/api/welcome', []],

  // ── Print templates ───────────────────────────────────────────────────────
  'templates_ctrl:getTableList':    ['GET',    '/api/templates',                   []],
  'templates_ctrl:getTemplateList': ['GET',    '/api/templates/{tb}',              ['tb']],
  'templates_ctrl:getTemplate':     ['GET',    '/api/template/{tb}/{name}',        ['tb', 'name']],
  'templates_ctrl:saveTemplate':    ['POST',   '/api/template/{tb}/{name}',        ['tb', 'name']],
  'templates_ctrl:deleteTemplate':  ['DELETE', '/api/template/{tb}/{name}',        ['tb', 'name']],
  'templates_ctrl:renameTemplate':  ['POST',   '/api/template/{tb}/{name}/rename', ['tb', 'name']],

  // ── Geoface ───────────────────────────────────────────────────────────────
  'geoface_ctrl:getGeoJson':     ['GET',    '/api/geoface',         []],
  'geoface_ctrl:saveNew':        ['POST',   '/api/geoface/feature', []],
  'geoface_ctrl:updateGeometry': ['PUT',    '/api/geoface/feature', []],
  'geoface_ctrl:eraseGeometry':  ['DELETE', '/api/geoface/feature', []],

  // ── Vocabularies ──────────────────────────────────────────────────────────
  'vocabularies_ctrl:list':  ['GET',    '/api/vocabularies',        []],
  'vocabularies_ctrl:add':   ['POST',   '/api/vocabularies',        []],
  'vocabularies_ctrl:sort':  ['POST',   '/api/vocabularies/sort',   []],
  'vocabularies_ctrl:edit':  ['PATCH',  '/api/vocabulary/{id}',     ['id']],
  'vocabularies_ctrl:erase': ['DELETE', '/api/vocabulary/{id}',     ['id']],

  // ── Search & replace ──────────────────────────────────────────────────────
  'search_replace_ctrl:getTableList': ['GET',  '/api/search-replace/tables',       []],
  'search_replace_ctrl:getFieldList': ['GET',  '/api/search-replace/{tb}/fields',  ['tb']],
  'search_replace_ctrl:doReplace':    ['POST', '/api/search-replace',              []],

  // ── Free SQL ──────────────────────────────────────────────────────────────
  'free_sql_ctrl:verifyPassword': ['POST', '/api/free-sql/verify', []],
  'free_sql_ctrl:runSql':         ['POST', '/api/free-sql/run',    []],

  // ── Data import ───────────────────────────────────────────────────────────
  'import_ctrl:getTableFields': ['GET',  '/api/import/{tb}/fields', ['tb']],
  'import_ctrl:importData':     ['POST', '/api/import/data',         []],
  'import_ctrl:importGeoJson':  ['POST', '/api/import/geojson',      []],
  'import_ctrl:importPhotos':   ['POST', '/api/import/photos',       []],

  // ── New application wizard ────────────────────────────────────────────────
  'new_app_ctrl:getStatus': ['GET',  '/api/new-app/status', []],
  'new_app_ctrl:create':    ['POST', '/api/new-app',         []],
}

// ── Route resolution ─────────────────────────────────────────────────────────

/**
 * Resolve (ctrl, method) against ROUTE_MAP and build the final request config.
 *
 * @param {string}  ctrl       Controller name, e.g. 'record_ctrl'
 * @param {string}  method     Method name, e.g. 'getRecord'
 * @param {Object}  data       Caller's primary params (body for POST, query for GET)
 * @param {Object}  urlParams  Caller's explicit URL/query params (api.post 4th arg)
 * @param {boolean} isGetCall  true when the caller used api.get() — all data → query
 * @returns {{ httpMethod, url, query, body } | null}  null = legacy fallback
 */
function buildRoute(ctrl, method, data = {}, urlParams = {}, isGetCall = false) {
  const entry = ROUTE_MAP[`${ctrl}:${method}`]
  if (!entry) return null

  const [httpMethod, template, pathParams] = entry

  // For GET callers everything goes as query string; for POST callers the
  // primary data goes as body and urlParams as query string.
  const query = isGetCall ? { ...data, ...urlParams } : { ...urlParams }
  const body  = isGetCall ? {}                        : { ...data }

  // Substitute path params — prefer urlParams/query over body.
  let path = template
  for (const p of pathParams) {
    const val = query[p] ?? body[p] ?? ''
    path = path.replace(`{${p}}`, encodeURIComponent(String(val)))
    delete query[p]
    delete body[p]
  }

  return { httpMethod, url: API_BASE + path, query, body }
}

/** Append remaining (non-path) params to a URL object as query string. */
function appendQuery(url, params) {
  for (const [k, v] of Object.entries(params)) {
    if (Array.isArray(v)) {
      v.forEach(item => url.searchParams.append(k + '[]', item))
    } else if (v !== null && v !== undefined) {
      url.searchParams.set(k, v)
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
 * @param {string}     label       Used in error messages  'ctrl::method'
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
  if (!res.ok) throw new Error(`${label} — HTTP ${res.status}`)
  return res.json()
}

// ── GET ──────────────────────────────────────────────────────────────────────
async function get(obj, method, params = {}) {
  await _guardRefresh()

  const r = buildRoute(obj, method, params, {}, true)

  if (r) {
    const url = new URL(r.url, window.location.origin)
    appendQuery(url, r.query)
    return _fetch(url, r.httpMethod, r.body, `${obj}::${method}`)
  }

  // Legacy fallback
  const url = new URL(PHP, window.location.origin)
  url.searchParams.set('obj',    obj)
  url.searchParams.set('method', method)
  appendQuery(url, params)
  return _fetch(url, 'GET', null, `${obj}::${method}`)
}

// ── POST ─────────────────────────────────────────────────────────────────────
async function post(obj, method, data = {}, urlParams = {}) {
  await _guardRefresh()

  const r = buildRoute(obj, method, data, urlParams, false)

  if (r) {
    const url = new URL(r.url, window.location.origin)
    appendQuery(url, r.query)
    return _fetch(url, r.httpMethod, r.body, `${obj}::${method}`)
  }

  // Legacy fallback
  const url = new URL(PHP, window.location.origin)
  url.searchParams.set('obj',    obj)
  url.searchParams.set('method', method)
  appendQuery(url, urlParams)
  return _fetch(url, 'POST', data, `${obj}::${method}`)
}

// ── Upload ───────────────────────────────────────────────────────────────────
async function upload(obj, method, file, field = 'file', urlParams = {}) {
  await _guardRefresh()

  const r = buildRoute(obj, method, {}, urlParams, false)

  const url = r
    ? new URL(r.url, window.location.origin)
    : (() => {
        const u = new URL(PHP, window.location.origin)
        u.searchParams.set('obj',    obj)
        u.searchParams.set('method', method)
        return u
      })()

  if (r) appendQuery(url, r.query)
  else    appendQuery(url, urlParams)

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

// ── Multi-file upload ─────────────────────────────────────────────────────────
/**
 * Upload multiple files plus optional plain-text fields in a single request.
 *
 * @param {string}  obj       Controller name
 * @param {string}  method    Method name
 * @param {Object}  files     { fieldName: File, … }
 * @param {Object}  data      { key: value, … }  (plain strings/numbers)
 * @param {Object}  urlParams Extra query-string params (also checked for path vars)
 */
async function uploadMulti(obj, method, files = {}, data = {}, urlParams = {}) {
  await _guardRefresh()

  const r = buildRoute(obj, method, data, urlParams, false)

  const url = r
    ? new URL(r.url, window.location.origin)
    : (() => {
        const u = new URL(PHP, window.location.origin)
        u.searchParams.set('obj',    obj)
        u.searchParams.set('method', method)
        return u
      })()

  if (r) appendQuery(url, r.query)
  else    appendQuery(url, urlParams)

  const fd = new FormData()
  Object.entries(files).forEach(([k, v]) => { if (v) fd.append(k, v) })

  // For route-resolved requests, non-path data from the body goes into FormData.
  // For legacy requests, use original data.
  const bodySource = r ? r.body : data
  Object.entries(bodySource).forEach(([k, v]) => fd.append(k, v ?? ''))

  const res = await fetch(url, {
    method:  'POST',
    headers: { Accept: 'application/json', ..._bearer() },
    body:    fd,
  })
  if (res.status === 401) { _handle401(); throw new Error('Unauthenticated') }
  if (!res.ok) throw new Error(`${obj}::${method} — HTTP ${res.status}`)
  return res.json()
}

// ── Response helper ──────────────────────────────────────────────────────────
function responseMessage(res, t, ...args) {
  if (!res?.code) return ''
  if (res.code === 'db_error') return `${t('db_error')}: ${res.detail ?? ''}`
  return t(res.code, ...args)
}

export const api = { get, post, upload, uploadMulti, responseMessage }
