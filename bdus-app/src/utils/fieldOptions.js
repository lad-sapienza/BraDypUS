/**
 * Helpers for the options of the select-family fields whose list is served
 * in pages (API: GET /api/record/{tb}/field-options → { options, truncated }).
 */

/** Option values as strings: the stored value may be a number (an FK) or a string. */
export function normalizeOptions(list) {
  return (Array.isArray(list) ? list : []).map(o => ({
    ...o,
    value: o.value !== null && o.value !== undefined ? String(o.value) : o.value,
  }))
}

/** The values currently selected in a field, as strings (multi_select is "a;b;c"). */
export function selectedValues(modelValue, type) {
  if (modelValue === null || modelValue === undefined || modelValue === '') return []
  const s = String(modelValue)
  return type === 'multi_select' ? s.split(';').map(v => v.trim()).filter(Boolean) : [s]
}

/**
 * `options` plus, for each selected value that is not among them, the option
 * seen earlier for it (`known`: Map value → option) or, failing that, the value
 * itself as label. A partial or searched list must never hide what is selected.
 */
export function withSelected(options, known, selected) {
  const have = new Set(options.map(o => o.value))
  const extra = []
  for (const v of selected) {
    if (!have.has(v)) {
      extra.push(known.get(v) ?? { value: v, label: v })
      have.add(v)
    }
  }
  return extra.length ? [...options, ...extra] : options
}
