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
 * Relations where the Harris matrix arrow must be reversed.
 * For these, `first` is the older (substrate) unit and `second` is the newer.
 * Arrow must go second → first so that newer units appear above older in the
 * top-to-bottom dagre layout.
 *
 *   1 "is covered by": first is covered by second → second is newer → swap
 *   2 "is cut by":     first is cut by second     → second is newer → swap
 *   3 "carries":       first carries second        → second is newer → swap
 *   4 "is filled by":  first is filled by second   → second is newer → swap
 *
 * Relations 5,6,7,8: first is the actor (newer unit) → no swap needed.
 */
export const SWAP_DIRECTION = new Set([1, 2, 3, 4])

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
