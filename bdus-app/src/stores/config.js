import { defineStore } from 'pinia'
import { ref }         from 'vue'
import { api }         from '@/api'

/**
 * Config store — tracks the super-admin password gate and caches the table
 * list so sidebar and sub-components don't each fetch it independently.
 *
 * `unlocked` is intentionally NOT persisted: it is reset on every page load,
 * forcing the user to re-enter their password each session (same behaviour
 * as the v4 Bootstrap modal gate).
 */
export const useConfigStore = defineStore('config', () => {
  // ── State ──────────────────────────────────────────────────────────────
  const unlocked = ref(false)
  const tables   = ref([])   // [{name, label, is_plugin}]
  const loading  = ref(false)
  const error    = ref(null)

  // ── Password gate ──────────────────────────────────────────────────────

  /**
   * Verify the current user's password against the backend.
   * On success, set `unlocked = true`.
   * Throws an Error with a translatable code on failure.
   */
  async function unlock(password) {
    const res = await api.post('confirm_super_adm_pwd_ctrl', 'check_pwd', { pwd: password })
    if (res.status !== 'success') {
      throw new Error(res.code ?? res.text ?? 'invalid_password')
    }
    unlocked.value = true
  }

  function lock() {
    unlocked.value = false
  }

  // ── Table list cache ───────────────────────────────────────────────────

  /**
   * Fetch the config table list (all tables, including plugins).
   * Subsequent calls are no-ops unless force=true.
   */
  async function loadTables(force = false) {
    if (tables.value.length && !force) return
    loading.value = true
    error.value   = null
    try {
      const res = await api.get('config_ctrl', 'getTableList')
      if (res.status === 'error') throw new Error(res.code)
      tables.value = res.tables ?? []
    } catch (e) {
      error.value = e.message
    } finally {
      loading.value = false
    }
  }

  function invalidateTables() {
    tables.value = []
  }

  return { unlocked, tables, loading, error, unlock, lock, loadTables, invalidateTables }
})
