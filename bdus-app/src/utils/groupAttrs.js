// Helpers for the two small editors of the groups panel: group attributes
// ({key: value | [values]}) and the `enforce` map of a create/update rule
// ({field: value | variable | [values]}). Both are edited as rows of
// "name" + "values typed as text, several separated by commas".

/** Text → a single value (string) or a list when several are typed; null when empty. */
export function parseValues(text) {
  const items = String(text ?? '').split(',').map(s => s.trim()).filter(Boolean)
  if (!items.length) return null
  return items.length === 1 ? items[0] : items
}

/** A value or list → the text shown in the editor. */
export function valuesToText(v) {
  if (v === null || v === undefined) return ''
  return Array.isArray(v) ? v.join(', ') : String(v)
}

/** {key: value} → [{ key, values: 'a, b' }] */
export function attrsToRows(attrs) {
  return Object.entries(attrs ?? {}).map(([key, v]) => ({ key, values: valuesToText(v) }))
}

/** Rows → { attrs } (null when there are none) or { error: <i18n key> }. */
export function rowsToAttrs(rows) {
  const out = {}
  for (const r of rows) {
    const key = (r.key ?? '').trim()
    const val = parseValues(r.values)
    if (!key && !val) continue                                // an empty row is ignored
    if (!/^[A-Za-z0-9_]{1,50}$/.test(key)) return { error: 'grp_attr_bad_key' }
    if (val === null) return { error: 'grp_attr_no_value' }
    if (key in out) return { error: 'grp_attr_duplicate' }
    out[key] = val
  }
  return { attrs: Object.keys(out).length ? out : null }
}

/** {field: value} → [{ field, values }] */
export function enforceToRows(enforce) {
  return Object.entries(enforce ?? {}).map(([field, v]) => ({ field, values: valuesToText(v) }))
}

/** Rows → { enforce } (null when there are none) or { error: <i18n key> }. */
export function rowsToEnforce(rows) {
  const out = {}
  for (const r of rows) {
    const field = (r.field ?? '').trim()
    const val   = parseValues(r.values)
    if (!field && !val) continue
    if (!field || val === null) return { error: 'grp_enforce_incomplete' }
    if (field in out) return { error: 'grp_attr_duplicate' }
    out[field] = val
  }
  return { enforce: Object.keys(out).length ? out : null }
}
