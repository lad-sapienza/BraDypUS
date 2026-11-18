/**
 * Inserts `text` at the cursor of a <textarea>/<input> (replacing the selection)
 * and tells Vue's v-model about it. Returns false when there is no element.
 *
 * `execCommand('insertText')` is used first because it keeps the browser's undo
 * history (Ctrl+Z removes the insertion); it fires a real `input` event.
 * Where it does not work, `setRangeText` plus a synthetic `input` event.
 * The text is put in Unicode NFC, so the same letter is always the same bytes
 * (a precomposed ῆ, not η + a combining mark).
 */
export function insertAtCursor(el, text) {
  if (!el) return false
  const value = String(text ?? '').normalize('NFC')
  if (value === '') return true
  el.focus()
  let done = false
  try {
    done = document.execCommand('insertText', false, value)
  } catch { done = false }
  if (!done) {
    const start = el.selectionStart ?? el.value.length
    const end   = el.selectionEnd   ?? el.value.length
    el.setRangeText(value, start, end, 'end')
    el.dispatchEvent(new Event('input', { bubbles: true }))
  }
  return true
}
