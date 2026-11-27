// Logic of the files page that does not need Vue: which columns exist, what is edited, how the
// choice of view and columns is remembered. Pure (no imports) so it can be tested with `node --test`.
//
// The server describes the fields of the files table (`fields` in GET /api/files): the system ones and
// any custom field an administrator added. The page is built from that description.

export const VIEWS = ['table', 'cards']

/** Field types shown and edited as plain text. */
const TEXT_TYPES = ['text', 'long_text', 'md']

/** Fields that never get a column of their own: the id is internal, the uuid is technical, the extension is shown with the filename. */
const NO_COLUMN = ['id', 'uuid', 'ext']

export function isTextual(type) {
  return TEXT_TYPES.includes(type)
}

export function normalizeView(v) {
  return VIEWS.includes(v) ? v : 'table'
}

/** The fields that can be a column, in the configured order. */
export function columnFields(fields) {
  return (fields ?? []).filter(f => !NO_COLUMN.includes(f.name))
}

/**
 * Columns shown when the user has not chosen: the descriptive system fields and every custom field
 * (the point of adding one), not the technical flags.
 */
export function defaultVisible(fields) {
  const base = ['filename', 'description', 'keywords', 'attribution']
  return columnFields(fields)
    .filter(f => base.includes(f.name) || !f.system)
    .map(f => f.name)
}

/** The visible columns, in the configured order; names that no longer exist (a removed custom field) are dropped. */
export function visibleColumns(fields, visible) {
  const wanted = new Set(visible && visible.length ? visible : defaultVisible(fields))
  return columnFields(fields).filter(f => wanted.has(f.name))
}

/** Names of the fields a request may write. */
export function editableNames(fields) {
  return (fields ?? []).filter(f => f.editable).map(f => f.name)
}

/** The editable values of a file, copied: what was last saved. */
export function snapshotOf(file, fields) {
  const out = {}
  for (const name of editableNames(fields)) out[name] = file?.[name] ?? null
  return out
}

/** The fields whose value differs from the snapshot: exactly what to send, nothing for a no-op. */
export function changedFields(file, snapshot, fields) {
  const out = {}
  for (const name of editableNames(fields)) {
    const now = file?.[name] ?? null
    const was = snapshot?.[name] ?? null
    if (now !== was) out[name] = now
  }
  return out
}

/** The name of a file without its extension (the extension is shown apart and never edited). */
export function stripExt(name, ext) {
  const suffix = '.' + (ext ?? '')
  return ext && (name ?? '').toLowerCase().endsWith(suffix.toLowerCase())
    ? name.slice(0, -suffix.length)
    : (name ?? '')
}

/**
 * The few descriptive lines of a card: the first non-empty textual visible fields, the filename aside.
 *
 * @returns {{label: string, value: string}[]}
 */
export function cardLines(file, fields, visible, max = 3) {
  const out = []
  for (const f of visibleColumns(fields, visible)) {
    if (f.name === 'filename' || !isTextual(f.type)) continue
    const v = file?.[f.name]
    if (v === null || v === undefined || String(v).trim() === '') continue
    out.push({ label: f.label, value: String(v) })
    if (out.length >= max) break
  }
  return out
}

/** Of the selected files, the ones the principal may delete (the rest are left alone). */
export function deletable(files, selectedIds) {
  const sel = new Set(selectedIds)
  return (files ?? []).filter(f => sel.has(f.id) && f.can_delete)
}

/** The files of the selection the principal may change. */
export function updatable(files, selectedIds) {
  const sel = new Set(selectedIds)
  return (files ?? []).filter(f => sel.has(f.id) && f.can_update)
}

// ── What the user chose is remembered, per application ───────────────────────────────────────

const key = app => `bdus_files_page:${app || ''}`

/** @returns {{view: string, visible: string[]}} */
export function loadPrefs(storage, app) {
  try {
    const raw = JSON.parse(storage?.getItem(key(app)) ?? 'null')
    return {
      view:    normalizeView(raw?.view),
      visible: Array.isArray(raw?.visible) ? raw.visible.filter(n => typeof n === 'string') : [],
    }
  } catch {
    return { view: 'table', visible: [] }
  }
}

export function savePrefs(storage, app, prefs) {
  try {
    storage?.setItem(key(app), JSON.stringify({ view: normalizeView(prefs?.view), visible: prefs?.visible ?? [] }))
  } catch {
    /* storage full or unavailable: the choice is simply not remembered */
  }
}
