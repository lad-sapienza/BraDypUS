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
