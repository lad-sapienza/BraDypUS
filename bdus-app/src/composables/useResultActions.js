import { useRoute, useRouter } from 'vue-router'
import { apiUrl, filterToSearchParams } from '@/api'
import { useListNavigation } from '@/stores/listNavigation'
import { SEARCH, geofaceQuery } from '@/utils/recordQuery'

/**
 * What the record list can do with its results and its table: open a record,
 * add one, export, chart, map, timeline, matrix. All of them are navigation
 * (or a download) carrying the current query along.
 *
 * @param {object} deps
 * @param {import('vue').Ref<{name: string, label: string}|null>} deps.table
 * @param {ReturnType<import('./useRecordQuery').useRecordQuery>}  deps.query
 * @param {ReturnType<import('./useRecordList').useRecordList>}    deps.list
 */
export function useResultActions({ table, query, list }) {
  const route   = useRoute()
  const router  = useRouter()
  const listNav = useListNavigation()

  const app = () => route.params.app
  const tb  = () => table.value?.name

  function addRecord() {
    if (tb()) router.push(`/${app()}/record/${encodeURIComponent(tb())}/new`)
  }

  /** Open a record from the list, remembering the list for Previous/Next. */
  function openRecord(id) {
    if (!tb() || id == null) return
    const last = list.request()
    // Only if the remembered request belongs to the table being opened.
    if (last?.tb === tb()) listNav.rememberRecordList(tb(), last.body, route.fullPath)
    else listNav.clearRecordList()
    router.push({
      path:  `/${app()}/record/${encodeURIComponent(tb())}/${id}`,
      query: { back: route.fullPath },
    })
  }

  /** The map shows only the records matching the current search. */
  function openGeoface() {
    if (!tb()) return
    router.push({
      path:  `/${app()}/geoface/${encodeURIComponent(tb())}`,
      query: geofaceQuery({
        activeSearch: query.activeSearch.value,
        activeFilter: query.activeFilter.value,
        expertQuery:  query.expertQuery.value,
      }),
    })
  }

  /**
   * The chart wizard, pre-filled with the table and — when a builder / SQL
   * search is applied — the filter shape Chart.php::getData() already takes, so
   * the chart runs over what is on screen rather than the whole table.
   */
  function createChart() {
    if (!tb()) return
    const q = { tb: tb() }
    if (query.currentSearch.value) q.filter = JSON.stringify(query.currentSearch.value)
    router.push({ path: `/${app()}/charts/new`, query: q })
  }

  function openTimeline() {
    if (!tb()) return
    router.push({
      path:  `/${app()}/chrono/${encodeURIComponent(tb())}`,
      query: { back: route.fullPath, backLabel: table.value.label },
    })
  }

  /** MatrixView / getRsMatrix() take the same search params as getRecords(): forward the whole query. */
  function openMatrix() {
    if (!tb()) return
    router.push({
      path:  `/${app()}/matrix/${encodeURIComponent(tb())}`,
      query: { ...route.query, back: route.fullPath },
    })
  }

  /**
   * Download what the list shows. The URL is built from the route query
   * (which already encodes the search); the browser navigates to it and PHP
   * answers with Content-Disposition: attachment.
   */
  function exportAs(format) {
    const qs = new URLSearchParams({ format })
    if (query.activeSearch.value === SEARCH.FILTER && query.activeFilter.value) {
      // Bracket notation, so PHP parses the filter as a nested array.
      filterToSearchParams(query.activeFilter.value).forEach((v, k) => qs.set(k, v))
    } else {
      if (route.query.qt) qs.set('qt', route.query.qt)
      if (route.query.q)  qs.set('q',  route.query.q)
    }
    window.open(apiUrl(`/api/records/${encodeURIComponent(tb() ?? '')}/export`) + '?' + qs.toString(), '_blank')
  }

  return { addRecord, openRecord, openGeoface, createChart, openTimeline, openMatrix, exportAs }
}
