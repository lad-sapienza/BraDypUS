<template>
  <AppLayout>
    <div class="users-view">

      <!-- Admins also manage groups and locks -->
      <ATabs v-if="isAdmin" v-model:activeKey="tab" class="users-tabs">
        <ATabPane key="users"  :tab="t('users')" />
        <ATabPane key="groups" :tab="t('grp_groups')" />
        <ATabPane key="locks"  :tab="t('grp_locks')" />
      </ATabs>

      <GroupsPanel v-if="tab === 'groups' && isAdmin" :users="users" @changed="loadUsers" />
      <LocksPanel v-else-if="tab === 'locks' && isAdmin" />

      <template v-else>
      <!-- ── Header ──────────────────────────────────────────── -->
      <div class="users-header">
        <h2>{{ t('users') }}</h2>
        <AInput
          v-if="users.length > 7"
          v-model:value="filterText"
          :placeholder="t('users_filter_placeholder')"
          size="small"
          class="users-filter-input"
        />
        <AButton v-if="isAdmin" type="primary" size="small" @click="openForm(null)">
          <template #icon><PlusOutlined /></template>
          {{ t('new_user') }}
        </AButton>
      </div>

      <!-- How many still rely on a deprecated data level (admins only) -->
      <AAlert
        v-if="isAdmin && (dataLevels.users + dataLevels.keys) > 0"
        type="warning" show-icon class="users-levels-banner"
        :message="t('grp_levels_banner', dataLevels.users, dataLevels.keys)"
      >
        <template #action>
          <AButton size="small" @click="moveAllOpen = true">{{ t('lvl_move_all') }}</AButton>
        </template>
      </AAlert>

      <!-- ── Table ───────────────────────────────────────────── -->
      <ATable
        :columns="columns"
        :dataSource="filteredUsers"
        :loading="loading"
        :pagination="false"
        size="small"
        rowKey="id"
        class="users-table"
        :customRow="rowProps"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'name'">
            {{ record.name }}
            <component
              :is="OAUTH_ICON[record.oauth_provider]"
              v-if="record.oauth_provider"
              class="users-oauth-icon"
              :title="t('linked_via_provider', OAUTH_LABEL[record.oauth_provider] ?? record.oauth_provider)"
            />
          </template>
          <template v-else-if="column.key === 'email'">
            <span class="users-email">{{ record.email }}</span>
          </template>
          <template v-else-if="column.key === 'privilege'">
            <ATag :color="severityToTagColor(privilegeSeverity(record.privilege_value))">
              {{ record.privilege }}
            </ATag>
            <ATag v-if="DATA_LEVELS.includes(record.privilege_value)" :title="t('grp_lvl_deprecated_note')">{{ t('grp_level_deprecated_tag') }}</ATag>
            <ATag v-if="record.legacy_privilege && record.privilege_value === 39" color="blue">{{ t('lvl_moved_tag', LEVEL_GROUP[record.legacy_privilege] ?? record.legacy_privilege) }}</ATag>
          </template>
          <template v-else-if="column.key === 'groups'">
            <ATag v-for="gid in record.groups" :key="gid" class="users-group-tag">{{ groupName(gid) }}</ATag>
          </template>
          <template v-else-if="column.key === 'actions'">
            <AButton
              v-if="record.editable"
              type="text"
              shape="circle"
              size="small"
              :title="t('edit')"
              @click="openForm(record)"
            ><template #icon><EditOutlined /></template></AButton>
            <AButton
              v-if="isAdmin && record.editable && DATA_LEVELS.includes(record.privilege_value)"
              type="text"
              shape="circle"
              size="small"
              :title="t('lvl_move_to_group')"
              @click="move('user', { id: record.id, name: record.name, level: record.privilege_value }, loadUsers)"
            ><template #icon><SwapOutlined /></template></AButton>
            <AButton
              v-if="isAdmin && record.editable && record.legacy_privilege && record.privilege_value === 39"
              type="text"
              shape="circle"
              size="small"
              :title="t('lvl_restore')"
              @click="restore('user', { id: record.id, name: record.name, legacy: record.legacy_privilege }, loadUsers)"
            ><template #icon><UndoOutlined /></template></AButton>
            <AButton
              v-if="isAdmin && record.editable && record.id !== auth.user?.id"
              type="text"
              shape="circle"
              size="small"
              style="color: var(--p-orange-500, #f97316)"
              :title="t('revoke_session')"
              @click="confirmRevoke(record)"
            ><template #icon><StopOutlined /></template></AButton>
            <AButton
              v-if="isAdmin && record.editable && record.id !== auth.user?.id"
              type="text"
              shape="circle"
              danger
              size="small"
              :title="t('delete')"
              @click="confirmDelete(record)"
            ><template #icon><DeleteOutlined /></template></AButton>
          </template>
        </template>
      </ATable>
      </template>

    </div>

    <LevelMoveModal v-model:open="moveAllOpen" @done="loadUsers" />

    <!-- ── User form dialog ───────────────────────────────────── -->
    <AModal
      v-model:open="formVisible"
      :title="formData.id ? t('edit_user') : t('new_user')"
      width="420px"
      :footer="null"
    >
      <UserForm
        :initial="formData"
        :group-options="isAdmin ? groupOptions : null"
        :saving="saving"
        @save="saveUser"
        @cancel="formVisible = false"
      />
    </AModal>

  </AppLayout>
</template>

<script setup>
import { DeleteOutlined, EditOutlined, GoogleOutlined, IdcardOutlined, PlusOutlined, StopOutlined, SwapOutlined, UndoOutlined } from '@ant-design/icons-vue'
import { ref, computed, onMounted } from 'vue'
import { useToast, useConfirm } from '@/composables/useNotify'
import AppLayout        from '@/components/AppLayout.vue'
import UserForm         from '@/components/users/UserForm.vue'
import GroupsPanel      from '@/components/users/GroupsPanel.vue'
import LocksPanel       from '@/components/users/LocksPanel.vue'
import LevelMoveModal   from '@/components/users/LevelMoveModal.vue'
import { useLevelMove, LEVEL_GROUP } from '@/composables/useLevelMove'
import { Alert as AAlert, Table as ATable, Button as AButton, Tag as ATag, Modal as AModal, Input as AInput, Tabs as ATabs, TabPane as ATabPane } from 'ant-design-vue'
import { api }          from '@/api'
import { useGroups }    from '@/composables/useGroups'
import { useI18n }      from '@/i18n'
import { useAuthStore } from '@/stores/auth'
import { severityToTagColor } from '@/utils/severity'

const { t }   = useI18n()
const auth    = useAuthStore()
const confirm = useConfirm()
const toast   = useToast()
const { move, restore } = useLevelMove()
const moveAllOpen = ref(false)

// ── Privilege severity map ────────────────────────────────────────
const SEVERITY = { 1: 'danger', 10: 'warning', 20: 'info', 25: 'info', 30: 'success', 40: 'secondary' }
function privilegeSeverity(value) { return SEVERITY[value] ?? 'secondary' }

// ── OAuth identity indicator (see #40 no_account link/register) ────
const OAUTH_ICON  = { google: GoogleOutlined, orcid: IdcardOutlined }
const OAUTH_LABEL = { google: 'Google', orcid: 'ORCID' }

const columns = computed(() => [
  { title: t('name'),  dataIndex: 'name',  key: 'name',
    sorter: (a, b) => (a.name ?? '').localeCompare(b.name ?? ''), width: 160 },
  { title: t('email'), dataIndex: 'email', key: 'email',
    sorter: (a, b) => (a.email ?? '').localeCompare(b.email ?? ''), width: 220 },
  { title: t('global_privilege'), key: 'privilege', width: 170 },
  ...(isAdmin.value ? [{ title: t('grp_groups'), key: 'groups' }] : []),
  { title: '',                    key: 'actions',   width: 210 },
])

// ── Tabs (admins) ─────────────────────────────────────────────────
const tab = ref('users')

// ── Groups, for the column and the user form ──────────────────────
const groupsApi  = useGroups()
const groupList  = ref([])
const groupName  = id => groupList.value.find(g => g.id === id)?.name ?? `#${id}`
const groupOptions = computed(() => groupList.value.map(g => ({ value: g.id, label: g.name })))

// ── State ─────────────────────────────────────────────────────────
const users            = ref([])
const loading          = ref(false)
const isAdmin          = ref(false)
const dataLevels       = ref({ users: 0, keys: 0 })
const DATA_LEVELS      = [20, 25, 30]   // deprecated: level Enter + a system group
const formVisible      = ref(false)
const saving           = ref(false)
const formData         = ref({})
const filterText       = ref('')


const filteredUsers = computed(() => {
  const q = filterText.value.trim().toLowerCase()
  if (!q) return users.value
  return users.value.filter(u =>
    (u.name ?? '').toLowerCase().includes(q) || (u.email ?? '').toLowerCase().includes(q)
  )
})

// ── Load users ────────────────────────────────────────────────────
async function loadUsers() {
  loading.value = true
  try {
    const res = await api.get('/api/users')
    // Group names first, so the Groups column never flashes bare ids.
    if (res.admin) {
      try { groupList.value = await groupsApi.listGroups() } catch { groupList.value = [] }
    }
    users.value   = res.users ?? []
    isAdmin.value = res.admin ?? false
    dataLevels.value = res.data_levels ?? { users: 0, keys: 0 }
  } catch (e) {
    toast.add({ severity: 'error', summary: t('users'), detail: String(e), life: 4000 })
  } finally {
    loading.value = false
  }
}

// A click anywhere on the row opens the edit form (the pencil stays); buttons keep their own action.
function rowProps(record) {
  if (!record.editable) return {}
  return {
    class: 'users-row-clickable',
    onClick: e => {
      if (e.target.closest('button, a, input, .ant-btn')) return
      openForm(record)
    },
  }
}

// ── Open user form ────────────────────────────────────────────────
async function openForm(user) {
  const res = await api.get(user ? `/api/user/${user.id}` : '/api/user')
  formData.value = { ...res, groups: user?.groups ?? [] }
  formVisible.value = true
}

// ── Save user ─────────────────────────────────────────────────────
async function saveUser(data) {
  saving.value = true
  try {
    const { groups, ...userData } = data
    const res = await api.post('/api/user', userData)
    if (res.status !== 'success') throw new Error(t(res.code ?? 'error'))
    if (res.token && Number(data.id) === Number(auth.user?.id)) auth.loginWithToken(res.token)
    // Memberships are their own endpoint; only admins see (and send) them.
    if (isAdmin.value && Array.isArray(groups)) {
      await groupsApi.saveUserGroups(data.id ?? res.id, groups)
    }
    toast.add({ severity: 'success', summary: t('users'), detail: t('user_data_saved'), life: 3000 })
    formVisible.value = false
    await loadUsers()
  } catch (e) {
    toast.add({ severity: 'error', summary: t('users'), detail: e.message, life: 4000 })
  } finally {
    saving.value = false
  }
}

// ── Revoke session ────────────────────────────────────────────────
function confirmRevoke(user) {
  confirm.require({
    message: t('confirm_revoke_session'),
    header:  t('revoke_session'),
    icon:    'pi pi-ban',
    rejectProps: { label: t('cancel'), severity: 'secondary', outlined: true },
    acceptProps: { label: t('revoke_session'), severity: 'warn' },
    accept: () => revokeSession(user.id),
  })
}

async function revokeSession(id) {
  try {
    const res = await api.post(`/api/user/${id}/revoke`)
    if (res.status !== 'success') throw new Error(t(res.code ?? 'error'))
    toast.add({ severity: 'success', summary: t('revoke_session'), detail: t('session_revoked'), life: 3000 })
  } catch (e) {
    toast.add({ severity: 'error', summary: t('revoke_session'), detail: e.message, life: 4000 })
  }
}

// ── Delete user ───────────────────────────────────────────────────
function confirmDelete(user) {
  confirm.require({
    message: t('confirm_erase_user'),
    header:  t('confirm'),
    icon:    'pi pi-exclamation-triangle',
    rejectProps: { label: t('cancel'), severity: 'secondary', outlined: true },
    acceptProps: { label: t('delete'), severity: 'danger' },
    accept: () => deleteUser(user.id),
  })
}

async function deleteUser(id) {
  try {
    const res = await api.delete(`/api/user/${id}`)
    if (res.status !== 'success') throw new Error(t(res.code ?? 'error'))
    toast.add({ severity: 'success', summary: t('users'), detail: t('user_deleted'), life: 3000 })
    await loadUsers()
  } catch (e) {
    toast.add({ severity: 'error', summary: t('users'), detail: e.message, life: 4000 })
  }
}

onMounted(loadUsers)
</script>

<style scoped>
.users-levels-banner { margin-bottom: 0.75rem; }
.users-view {
  display: flex;
  flex-direction: column;
  height: 100%;
}

.users-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 1rem;
  padding: 1.25rem 1.5rem 0.75rem;
  flex-shrink: 0;
}

.users-header h2 {
  font-size: 1.2rem;
  font-weight: 600;
  flex-shrink: 0;
}

.users-tabs { padding: 0 1.5rem; flex-shrink: 0; }
.users-group-tag { margin-bottom: 2px; }

.users-filter-input {
  flex: 1;
  max-width: 240px;
}

.users-table {
  margin: 0 1.5rem;
}

.users-email {
  font-size: 0.875rem;
}
.users-table :deep(.users-row-clickable) { cursor: pointer; }

.users-oauth-icon {
  margin-left: 0.4rem;
  color: var(--p-text-muted-color);
  font-size: 0.8rem;
}
</style>
