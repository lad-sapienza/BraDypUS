const WIDTH_MAP = {
  '1/1': '100%',
  '1/2': '50%',
  '1/3': '33.333%',
  '2/3': '66.667%',
  '1/4': '25%',
  '3/4': '75%',
}

/**
 * Convert a template width fraction string to a CSS percentage string.
 * Unknown widths fall back to '100%'.
 *
 * @param  {string} w  e.g. '1/2'
 * @returns {string}   e.g. '50%'
 */
export function widthToCss(w) {
  return WIDTH_MAP[w] ?? '100%'
}

/**
 * Convert a template width fraction string to a 12-column grid span number.
 * Used by TemplateSection to set `grid-column: span N` on a CSS Grid container.
 * Unknown widths fall back to 12 (full row).
 *
 * Grid mapping (out of 12 columns):
 *   1/1 → 12,  1/2 → 6,  1/3 → 4,  2/3 → 8,  1/4 → 3,  3/4 → 9
 *
 * @param  {string} w  e.g. '1/2'
 * @returns {number}   e.g. 6
 */
export function widthToSpan(w) {
  const SPAN_MAP = {
    '1/1': 12,
    '1/2':  6,
    '1/3':  4,
    '2/3':  8,
    '1/4':  3,
    '3/4':  9,
  }
  return SPAN_MAP[w] ?? 12
}

/**
 * The field schema a template item shows: the field's own schema with the item's
 * per-layout options applied. Interface only (the server's group rules still decide
 * what may be written):
 *  - `label`    replaces the field's label (an empty text keeps it);
 *  - `readonly` shows the value but offers no editor (a field already read-only in
 *    the table configuration stays read-only).
 *
 * @param {object} schema  the field's schema from record.schema.fields
 * @param {object} item    the template item { field, width, label?, readonly?, hidden? }
 */
export function itemSchema(schema, item) {
  const out = { ...schema }
  if (typeof item?.label === 'string' && item.label.trim() !== '') out.label = item.label
  if (item?.readonly === true) out.readonly = true
  return out
}

/** True when the template item asks to be left out of the form. */
export function isItemHidden(item) {
  return item?.hidden === true
}
