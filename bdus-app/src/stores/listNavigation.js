import { defineStore } from 'pinia'
import { ref }         from 'vue'
import { api }         from '@/api'
import { currentApp }  from '@/utils/storage'

/**
 * List navigation — remembers *which list* the user opened a record from, so
 * the record view can offer Previous / Next within that list.
 *
 * The list's sort, fast-search and column choice live in DataView's local
 * state, not in the URL, so the context cannot be rebuilt from `?back=`. It is
 * captured at click time instead: DataView hands over the request body it last
 * sent to the list endpoint, and the ordered id list is fetched lazily (once)
 * by replaying that body with `ids_only=1` — same filter, columns and sort, so
 * the order is exactly the one on screen.
 *
 * Kept in sessionStorage (per app) so a page refresh on a record keeps its
 * Previous/Next; a direct link or bookmark has no context and shows no bar.
 */

const KEY = () => `bdus:${currentApp()}:listnav`

function restore() {
  try {
    const raw = sessionStorage.getItem(KEY())
    return raw ? JSON.parse(raw) : null
  } catch {
    return null
  }
}

function persist(value) {
  try {
    if (value) sessionStorage.setItem(KEY(), JSON.stringify(value))
    else sessionStorage.removeItem(KEY())
  } catch { /* storage unavailable: navigation just won't survive a refresh */ }
}

export const useListNavigation = defineStore('listNavigation', () => {
  /** { tb, back, body, ids: number[]|null, truncated: boolean } | null */
  const record = ref(restore())

  /**
   * DataView calls this on row click with the body of its last list request and
   * the list's own URL (`back`). The record view only shows the bar when its
   * `?back=` equals that URL, so a record opened from somewhere else (a link, a
   * map popup) never inherits a stale list.
   */
  function rememberRecordList(tb, body, back) {
    // page / per_page are irrelevant: ids_only returns the whole result set.
    const { page, per_page, ...rest } = body ?? {}
    record.value = { tb, back, body: rest, ids: null, truncated: false }
    persist(record.value)
  }

  function clearRecordList() {
    record.value = null
    persist(null)
  }

  /** Fetch the ordered id list once; no-op without a context matching `tb` and `back`. */
  async function ensureRecordIds(tb, back) {
    const ctx = record.value
    if (!ctx || ctx.tb !== tb || ctx.back !== back || ctx.ids) return
    const res = await api.post(`/api/records/${encodeURIComponent(tb)}`, { ...ctx.body, ids_only: 1 })
    // The context may have been replaced while the request was in flight.
    if (record.value !== ctx) return
    if (res.status !== 'success' || !Array.isArray(res.ids)) return
    ctx.ids = res.ids
    ctx.truncated = !!res.truncated
    persist(ctx)
  }

  /**
   * Position of record `id` in the remembered list, or null when there is no
   * usable context (not loaded yet, other table or list, record not in the list
   * — e.g. a direct link, or data changed since).
   * @returns {{ index: number, total: number, truncated: boolean,
   *             prev: number|null, next: number|null } | null}
   */
  function recordNeighbours(tb, back, id) {
    const ctx = record.value
    if (!ctx || ctx.tb !== tb || ctx.back !== back || !ctx.ids) return null
    const index = ctx.ids.indexOf(Number(id))
    if (index < 0) return null
    return {
      index,
      total:     ctx.ids.length,
      truncated: ctx.truncated,
      prev:      index > 0 ? ctx.ids[index - 1] : null,
      next:      index < ctx.ids.length - 1 ? ctx.ids[index + 1] : null,
    }
  }

  return { record, rememberRecordList, clearRecordList, ensureRecordIds, recordNeighbours }
})
