import { api } from '@/api'
import { useI18n } from '@/i18n'
import { useToast, useConfirm } from '@/composables/useNotify'

/** The data levels that are being replaced by Enter + the system group of the same name. */
export const LEVEL_GROUP = { 20: 'Writer', 25: 'Self writer', 30: 'Reader' }

/**
 * Move a user or an API key from a data level to level Enter + its system group, and back.
 * The server refuses unless the group gives exactly the same access (`not_equivalent`).
 *
 *   const { move, restore } = useLevelMove()
 *   move('user', record, reload)        // record: { id, name|label, level }
 *   restore('key', record, reload)
 */
export function useLevelMove() {
  const { t }   = useI18n()
  const toast   = useToast()
  const confirm = useConfirm()

  const base = kind => (kind === 'user' ? '/api/user' : '/api/api-key')

  async function call(kind, id, action, okMessage, after) {
    try {
      const res = await api.post(`${base(kind)}/${id}/${action}`)
      if (res.status !== 'success') {
        const extra = res.code === 'not_equivalent' && res.differences?.length
          ? ` (${t('lvl_diff_count', res.differences.length)})` : ''
        toast.add({ severity: 'error', summary: api.responseMessage(res, t) + extra, life: 6000 })
        return
      }
      toast.add({ severity: 'success', summary: okMessage(res), life: 4000 })
      await after?.()
    } catch (e) {
      toast.add({ severity: 'error', summary: e.message, life: 5000 })
    }
  }

  function move(kind, { id, name, level }, after) {
    const group = LEVEL_GROUP[level]
    confirm.require({
      header:  t('lvl_move_to_group'),
      message: t('lvl_move_confirm', name, group, group),
      accept:  () => call(kind, id, 'move-to-group', res => t('lvl_moved_ok', res.group ?? group), after),
    })
  }

  function restore(kind, { id, name, legacy }, after) {
    confirm.require({
      header:  t('lvl_restore'),
      message: t('lvl_restore_confirm', name, LEVEL_GROUP[legacy] ?? legacy),
      accept:  () => call(kind, id, 'restore-level', () => t('lvl_restored_ok'), after),
    })
  }

  return { move, restore }
}
