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
