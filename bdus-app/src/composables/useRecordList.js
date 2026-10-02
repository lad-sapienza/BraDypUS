import { ref, computed, onScopeDispose } from 'vue'
import { api } from '@/api'
import { useI18n } from '@/i18n'
import { useToast } from '@/composables/useNotify'
import { listBody } from '@/utils/recordQuery'

/**
 * The record list: the current page of records, paging and sorting, and the
 * request that fetches them.
 *
 * @param {object} deps
 * @param {import('vue').Ref<{name: string}|null>} deps.table     the selected table
 * @param {ReturnType<import('./useColumnPrefs').useColumnPrefs>} deps.prefs  visible columns
 * @param {() => object} deps.getSearch the search part of the request (see utils/recordQuery.searchParams)
 */
export function useRecordList({ table, prefs, getSearch }) {
  const { t } = useI18n()
  const toast = useToast()

  const records   = ref([])
  const columns   = ref([])     // the columns the backend returned (without id)
  const total     = ref(0)
  const loading   = ref(false)
  const canAdd    = ref(false)  // backend-controlled: can the user add a record
  const page      = ref(1)
  const perPage   = ref(30)
  const sortField = ref(null)
  const sortDir   = ref('asc')

  /** Columns actually rendered, in the user's order — only those the backend returned. */
  const displayColumns = computed(() =>
    prefs.visible.value
      .map(name => columns.value.find(c => c.name === name))
      .filter(Boolean)
  )

  // The last successful request, so the record view can offer Previous/Next in
  // exactly this list (see stores/listNavigation).
  let lastRequest = null   // { tb, body }

  // Only the latest request may update the screen: a new one (typing, paging,
  // sorting) cancels the one still in flight, and the sequence number is a
  // second guard against a response that slipped past the abort.
  let ctrl = null
  let seq  = 0

  async function fetch() {
    if (!table.value) return
    ctrl?.abort()
    ctrl = new AbortController()
    const { signal } = ctrl
    const mine = ++seq
    loading.value = true
    try {
      const tb = table.value.name
      const body = listBody({
        page:      page.value,
        perPage:   perPage.value,
        sortField: sortField.value,
        sortDir:   sortDir.value,
        search:    getSearch(),
        columns:   prefs.visible.value,   // empty → the backend's preview defaults
      })

      const res = await api.post(`/api/records/${tb}`, body, { signal })
      if (mine !== seq) return   // superseded while waiting

      if (res.status === 'error') {
        toast.add({ severity: 'error', summary: t('generic_error'),
          detail: api.responseMessage(res, t), life: 6000 })
        return
      }

      lastRequest   = { tb, body }
      total.value   = res.total ?? 0
      canAdd.value  = res.can_add ?? false
      if (res.fields?.length) {
        columns.value = res.fields.filter(f => f.name !== 'id')
        // No saved choice yet: start from the preview columns the backend returned.
        if (prefs.visible.value.length === 0) prefs.initFrom(columns.value, tb)
      }
      records.value = res.data ?? []
    } catch (e) {
      if (e.name === 'AbortError' || mine !== seq) return   // cancelled by a newer request
      toast.add({ severity: 'error', summary: 'Error', detail: e.message, life: 4000 })
    } finally {
      if (mine === seq) loading.value = false
    }
  }

  /** AntD Table's single @change: pagination and sorter arrive together. */
  function changeTable(pagination, sorter) {
    const newField = sorter.order ? sorter.field : null
    const newDir   = sorter.order === 'descend' ? 'desc' : 'asc'
    const sortChanged = newField !== sortField.value || newDir !== sortDir.value

    sortField.value = newField
    sortDir.value   = newDir
    page.value      = sortChanged ? 1 : pagination.current
    perPage.value   = pagination.pageSize
    return fetch()
  }

  /** Back to page 1 and refetch — what any change of the query asks for. */
  function refresh() {
    page.value = 1
    return fetch()
  }

  /** The last request, or null when none succeeded yet. */
  function request() { return lastRequest }

  function abort() { ctrl?.abort() }
  onScopeDispose(abort)

  return {
    records, columns, displayColumns, total, loading, canAdd,
    page, perPage, sortField, sortDir,
    fetch, refresh, changeTable, request, abort,
  }
}
