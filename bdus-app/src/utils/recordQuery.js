/**
 * Pure helpers for the record list's query: what the current search sends to
 * the list endpoint, to the export, and to the map. No Vue, no DOM — the
 * state lives in useRecordQuery; this is the translation to request shapes.
 */

/** The ways the list can be narrowed. One at a time. */
export const SEARCH = Object.freeze({
  FAST:     'fast',       // the text box
  ADVANCED: 'advanced',   // the query builder
  EXPERT:   'expert',     // raw SQL
  FILTER:   'filter',     // a ready-made filter (links from a record, a saved search)
})

/** A filter-shaped search carries a ready JsonFilter object. */
const isFilterShaped = mode => mode === SEARCH.ADVANCED || mode === SEARCH.FILTER

/**
 * The search part of a getRecords request, for the current search mode.
 *
 * @param {{ activeSearch: string|null, fastSearch: string, expertQuery: string, activeFilter: object|null }} s
 */
export function searchParams({ activeSearch, fastSearch, expertQuery, activeFilter }) {
  if (isFilterShaped(activeSearch)) return { filter: activeFilter }
  if (activeSearch === SEARCH.EXPERT) return { search_type: 'sqlExpert', querytext: expertQuery, join: '' }
  if (activeSearch === SEARCH.FAST)   return { search_type: 'fast', search: fastSearch }
  return { search_type: 'all', search: '' }
}

/**
 * The full getRecords request body: paging, sort, the search and — only when
 * the user picked some — the visible columns (an empty list means "the
 * backend's preview defaults", so nothing is sent).
 */
export function listBody({ page, perPage, sortField, sortDir, search, columns }) {
  const body = {
    page,
    per_page:   perPage,
    sort_field: sortField ?? '',
    sort_dir:   sortDir,
    ...search,
  }
  if (columns?.length) body.columns = columns
  return body
}

/**
 * Query string entries for the map (GeoFace), which accepts the same filter
 * shapes as the list.
 *
 * @returns {Record<string, string>}
 */
export function geofaceQuery({ activeSearch, activeFilter, expertQuery }) {
  if (isFilterShaped(activeSearch) && activeFilter) return { filter: JSON.stringify(activeFilter) }
  if (activeSearch === SEARCH.EXPERT && expertQuery) return { search_type: 'sqlExpert', querytext: expertQuery }
  return {}
}
