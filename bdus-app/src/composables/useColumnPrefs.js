import { ref, computed } from 'vue'
import { appStorage } from '@/utils/storage'

/**
 * Which columns the record list shows, in which order — remembered per
 * application and table.
 *
 * @param {object} deps
 * @param {import('vue').Ref<{name: string}|null>} deps.table    the selected table
 * @param {import('vue').Ref<Array>}                deps.fields   search-config fields ("table:field")
 * @param {() => void}                              deps.onChange called when the visible set changed
 */
export function useColumnPrefs({ table, fields, onChange }) {
  /**
   * Ordered array of visible field names. An ordered array (not a Set) so that
   * visibility and order live in one structure persisted to localStorage;
   * empty means "the backend's preview columns".
   */
  const visible = ref([])

  /**
   * Every main-table field available for toggling. Search-config values look
   * like "table:field" for the main table and plugin tables alike; only the
   * selected table's are kept, so a plugin's field is never sent to getRecords
   * (where it would be a SQL error).
   */
  const available = computed(() => {
    const tb = table.value?.name
    if (!tb || !fields.value.length) return []
    return fields.value
      .filter(f => f.value.startsWith(tb + ':'))
      .map(f => ({ name: f.value.split(':')[1], label: f.label }))
      .filter(f => f.name && f.name !== 'id')
  })

  /**
   * Per-app storage key. Namespacing by application (bdus:<app>:data:columns:<tb>,
   * see utils/storage.js) is essential: two databases can each have a table
   * called `siti` with different columns, and a pref saved against one must
   * never be replayed against the other — that sent a non-existent column to
   * getRecords and 500'd the whole list.
   */
  const storageKey = tb => `data:columns:${tb}`

  /** The saved choice for a table, or [] when there is none. */
  function saved(tb) {
    const arr = appStorage.getJSON(storageKey(tb))
    return Array.isArray(arr) && arr.length ? arr : []
  }

  /**
   * On opening a table: restore its saved columns BEFORE the first fetch, so
   * that fetch already asks for the full saved set (otherwise it would run in
   * preview mode and the picker would show saved columns the table lacks).
   */
  function restoreFor(tb) {
    visible.value = saved(tb)
  }

  /** After a fetch with no saved choice: adopt the columns the backend returned. */
  function initFrom(returnedColumns, tb) {
    const arr = saved(tb)
    visible.value = arr.length ? arr : returnedColumns.map(c => c.name)
  }

  function persist() {
    appStorage.setJSON(storageKey(table.value?.name), visible.value)
  }

  function toggle(name) {
    const arr = [...visible.value]
    const i = arr.indexOf(name)
    if (i >= 0) {
      if (arr.length === 1) return   // keep at least one column visible
      arr.splice(i, 1)
    } else {
      arr.push(name)
    }
    visible.value = arr
    persist()
    onChange()
  }

  function selectAll() {
    visible.value = available.value.map(c => c.name)
    persist()
    onChange()
  }

  /** Back to the backend's preview columns. */
  function reset() {
    if (!table.value) return
    appStorage.remove(storageKey(table.value.name))
    visible.value = []
    onChange()
  }

  return { visible, available, restoreFor, initFrom, toggle, selectAll, reset }
}
