/**
 * A char map (see Config\CharPalette) is edited as plain text. Stored, it is one of:
 * a string of characters separated by spaces, a JSON list, or a JSON map label → text.
 */

/** The text shown in the editor for a stored value. */
export function paletteToText(value) {
  if (value === null || value === undefined || value === '') return ''
  if (typeof value === 'string') return value
  try {
    return JSON.stringify(value)
  } catch {
    return ''
  }
}

/**
 * The value to store for what was typed.
 * @returns {{ value: (string|Array|Object|null) }|{ error: string }}
 *   value null = no palette (the key is dropped); error = the text starts like JSON but is not.
 */
export function textToPalette(text) {
  const s = String(text ?? '').trim()
  if (s === '') return { value: null }
  if (s[0] === '{' || s[0] === '[') {
    let parsed
    try {
      parsed = JSON.parse(s)
    } catch {
      return { error: 'invalid_json' }
    }
    if (parsed === null || typeof parsed !== 'object') return { error: 'invalid_json' }
    return Array.isArray(parsed) && parsed.length === 0 ? { value: null }
      : !Array.isArray(parsed) && Object.keys(parsed).length === 0 ? { value: null }
      : { value: parsed }
  }
  return { value: s }
}

/** Names of char maps: 2-40 characters, lowercase letters, digits and hyphens, starting with a letter. */
export const CHAR_MAP_NAME = /^[a-z][a-z0-9-]{1,39}$/

const MAX_LABEL = 24
const MAX_VALUE = 80

/**
 * The buttons a stored char map gives — the client twin of Config\CharPalette::normalize()
 * (for the preview; the server decides what is really shown).
 * @returns {Array<{label: string, value: string}>}
 */
export function charMapItems(raw) {
  let entries
  if (typeof raw === 'string') {
    entries = raw.split(/\s+/).filter(Boolean).map(v => [v, v])
  } else if (Array.isArray(raw)) {
    entries = raw.map(v => (v && typeof v === 'object')
      ? [v.label ?? v.value, v.value]
      : [v, v])
  } else if (raw && typeof raw === 'object') {
    entries = Object.entries(raw)
  } else {
    return []
  }
  const items = []
  for (const [label, value] of entries) {
    if (typeof value !== 'string' || value === '') continue
    const l = typeof label === 'string' ? label : null
    if (!l || [...l].length > MAX_LABEL || [...value].length > MAX_VALUE) continue
    items.push({ label: l, value })
    if (items.length >= 300) break
  }
  return items
}
