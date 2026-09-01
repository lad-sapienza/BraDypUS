/**
 * useTables — singleton composable for the application table list.
 *
 * The module-level refs ensure that tables are fetched only once per session
 * and shared between AppLayout (nav list) and DataView (selected table logic).
 */
import { ref } from 'vue'
import { api } from '@/api'

const tables  = ref([])
const loading = ref(false)
let   fetched = false

export function useTables() {
  /**
   * Fetch the table list from the backend.
   * Subsequent calls are no-ops if already loaded.
   * Pass force=true to reload (e.g. after a schema change).
   */
  async function loadTables(force = false) {
    if (fetched && !force) return
    loading.value = true
    try {
      const res = await api.get('/api/tables')
      tables.value = res.tables ?? []
      fetched = true
    } finally {
      loading.value = false
    }
  }

  return { tables, loading, loadTables }
}
