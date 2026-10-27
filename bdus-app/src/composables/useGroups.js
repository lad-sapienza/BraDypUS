import { api } from '@/api'
import { useI18n } from '@/i18n'

/**
 * Groups, group rules, memberships and table locks (admin API, #78).
 *
 * Every call returns the parsed response and throws an Error whose message is
 * already translated (code, plus the server's detail when it explains a refused
 * filter), so callers only need a try/catch around a toast.
 */
export function useGroups() {
  const { t } = useI18n()

  function unwrap(res) {
    if (res?.status === 'error') {
      const base = api.responseMessage(res, t) || res.code || t('error')
      const err  = new Error(res.detail && res.code !== 'db_error' ? `${base}: ${res.detail}` : base)
      err.code = res.code
      throw err
    }
    return res
  }

  return {
    listGroups:   async ()               => unwrap(await api.get('/api/groups')).groups ?? [],
    getGroup:     async id               => unwrap(await api.get(`/api/group/${id}`)).group,
    createGroup:  async (name, description) => unwrap(await api.post('/api/groups', { name, description })),
    updateGroup:  async (id, name, description) => unwrap(await api.put(`/api/group/${id}`, { name, description })),
    deleteGroup:  async id               => unwrap(await api.delete(`/api/group/${id}`)),
    saveMembers:  async (id, userIds)    => unwrap(await api.put(`/api/group/${id}/members`, { user_ids: userIds })),
    saveRules:    async (id, rules)      => unwrap(await api.put(`/api/group/${id}/rules`, { rules })),
    saveUserGroups: async (userId, groupIds) => unwrap(await api.put(`/api/user/${userId}/groups`, { group_ids: groupIds })),

    listLocks:    async ()               => unwrap(await api.get('/api/locks')).locks ?? [],
    createLock:   async lock             => unwrap(await api.post('/api/locks', lock)),
    updateLock:   async (id, lock)       => unwrap(await api.put(`/api/lock/${id}`, lock)),
    deleteLock:   async id               => unwrap(await api.delete(`/api/lock/${id}`)),

    /** How many records of `table` match `filter` (what a lock freezes). */
    countRecords: async (table, filter) => {
      const res = await api.get(`/api/records/${table}`, { filter, per_page: 1 })
      return res?.status === 'error' ? null : (res.total ?? 0)
    },
  }
}
