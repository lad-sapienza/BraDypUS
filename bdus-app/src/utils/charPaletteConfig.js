/**
 * The `char_palette` setting of a long_text / md field is edited as plain text.
 * Stored, it is one of: a preset name ("greek-polytonic"), a string of characters
 * separated by spaces, a JSON list, or a JSON map label → value (see Config\CharPalette).
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
