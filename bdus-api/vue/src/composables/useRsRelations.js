/**
 * Shared RS relation definitions.
 * Used by RsSection (record panel) and MatrixView (full graph + edit dialog).
 */

/** Map relation code → i18n key */
export const REL_KEYS = {
  1: 'is_covered_by',
  2: 'is_cut_by',
  3: 'carries',
  4: 'is_filled_by',
  5: 'covers',
  6: 'cuts',
  7: 'leans_against',
  8: 'fills',
  9: 'is_the_same_as',
  10: 'is_bound_to',
}

/** Abbreviated labels for Cytoscape edge labels */
export const REL_LABELS = {
  1: 'cov.by', 2: 'cut by', 3: 'carries', 4: 'fill.by',
  5: 'covers',  6: 'cuts',   7: 'leans',   8: 'fills',
  9: '=',       10: '~',
}

/** Relations 9 and 10 are undirected (symmetric, self-inverse) */
export const UNDIRECTED = new Set([9, 10])

/**
 * Inversion map: given a relation code, returns its inverse.
 * Pairs: 1↔5, 2↔6, 3↔7, 4↔8.  9 and 10 are self-inverse.
 */
export const REL_INVERSE = {
  1: 5, 2: 6, 3: 7, 4: 8,
  5: 1, 6: 2, 7: 3, 8: 4,
  9: 9, 10: 10,
}

/**
 * Returns a computed array of { value, label } options for a Select component.
 * Requires the t() function from useI18n.
 */
export function buildRelationOptions(t) {
  return Object.entries(REL_KEYS).map(([val, key]) => ({
    value: parseInt(val, 10),
    label: t(key),
  }))
}
