// Helpers for the "record layouts" editor of the groups panel: {table: [template]}
// edited as rows of one table with the templates chosen for it.
// An interface convenience, not a permission (the groups' rules decide that).

/** {table: [template]} → [{ table, templates }] */
export function layoutsToRows(layouts) {
  return Object.entries(layouts ?? {}).map(([table, templates]) => ({
    table,
    templates: Array.isArray(templates) ? [...templates] : [],
  }))
}

/** Rows → { layouts } ({} when none) or { error: <i18n key> }. */
export function rowsToLayouts(rows) {
  const out = {}
  for (const r of rows) {
    const table = (r.table ?? '').trim()
    const templates = [...new Set((r.templates ?? []).filter(Boolean))]
    if (!table && !templates.length) continue          // an empty row is ignored
    if (!table) return { error: 'grp_layout_no_table' }
    if (!templates.length) return { error: 'grp_layout_no_template' }
    if (table in out) return { error: 'grp_layout_duplicate' }
    out[table] = templates
  }
  return { layouts: out }
}

/**
 * What the record view does with the answer of GET /api/record/{tb}/templates.
 * `assigned` = the list comes from the user's groups; `locked` = one layout only,
 * so there is nothing to choose.
 * Returns { options, selected, showSelect }: `options` are the template names
 * (the "default layout" entry is offered only when nothing is assigned).
 */
export function resolveTemplateChoice(res, saved) {
  const templates = res?.templates ?? []
  const assigned  = !!res?.assigned
  const locked    = assigned && !!res?.locked
  let selected = saved && templates.includes(saved) ? saved : null
  if (assigned && selected === null) selected = templates[0] ?? null
  if (locked) selected = templates[0] ?? null
  return {
    templates,
    selected,
    allowDefault: !assigned,
    showSelect: !locked && templates.length > 0,
  }
}
