<template>
  <AppLayout>
    <div class="users-view">

      <!-- ── Header ──────────────────────────────────────────── -->
      <div class="users-header">
        <h2>{{ t('users') }}</h2>
        <Button
          v-if="isAdmin"
          :label="t('new_user')"
          icon="pi pi-plus"
          size="small"
          @click="openForm(null)"
        />
      </div>

      <!-- ── Table ───────────────────────────────────────────── -->
      <DataTable
        :value="users"
        :loading="loading"
        v-model:expandedRows="expandedRows"
        dataKey="id"
        stripedRows
        size="small"
        class="users-table"
      >
        <!-- Expand toggle (admin only, only for editable users with overrides panel) -->
        <Column v-if="isAdmin" expander style="width: 3rem" />

        <Column :header="t('name')" field="name" sortable style="min-width:9rem" />

        <Column :header="t('email')" field="email" sortable style="min-width:12rem">
          <template #body="{ data }">
            <a :href="`mailto:${data.email}`" class="users-email">{{ data.email }}</a>
          </template>
        </Column>

        <Column :header="t('global_privilege')" style="min-width:9rem">
          <template #body="{ data }">
            <div class="users-priv-cell">
              <Tag
                :value="data.privilege"
                :severity="privilegeSeverity(data.privilege_value)"
                rounded
              />
              <Badge
                v-if="data.override_count > 0"
                :value="data.override_count"
                severity="secondary"
                class="users-badge"
                :title="t('table_overrides')"
              />
            </div>
          </template>
        </Column>

        <Column style="width:7rem">
          <template #body="{ data }">
            <Button
              v-if="data.editable"
              icon="pi pi-pencil"
              text rounded size="small"
              :title="t('edit')"
              @click="openForm(data)"
            />
            <Button
              v-if="isAdmin && data.editable && data.id !== auth.user?.id"
              icon="pi pi-trash"
              text rounded size="small"
              severity="danger"
              :title="t('delete')"
              @click="confirmDelete(data)"
            />
          </template>
        </Column>

        <!-- Row expansion: per-table privileges panel -->
        <template #expansion="{ data }">
          <UserPrivilegesPanel
            :userId="data.id"
            :callerPrivilege="callerPrivilege"
          />
        </template>
      </DataTable>

    </div>

    <!-- ── User form dialog ───────────────────────────────────── -->
    <Dialog
      v-model:visible="formVisible"
      :header="formData.id ? t('edit_user') : t('new_user')"
      modal
      :style="{ width: '420px' }"
      :draggable="false"
    >
      <UserForm
        :initial="formData"
        :saving="saving"
        @save="saveUser"
        @cancel="formVisible = false"
      />
    </Dialog>

  </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useConfirm }   from 'primevue/useconfirm'
import { useToast }     from 'primevue/usetoast'
import AppLayout        from '@/components/AppLayout.vue'
import UserForm         from '@/components/users/UserForm.vue'
import UserPrivilegesPanel from '@/components/users/UserPrivilegesPanel.vue'
import DataTable        from 'primevue/datatable'
import Column           from 'primevue/column'
import Button           from 'primevue/button'
import Badge            from 'primevue/badge'
import Tag              from 'primevue/tag'
import Dialog           from 'primevue/dialog'
import { api }          from '@/api'
import { useI18n }      from '@/i18n'
import { useAuthStore } from '@/stores/auth'

const { t }   = useI18n()
const auth    = useAuthStore()
const confirm = useConfirm()
const toast   = useToast()

// ── Privilege severity map ────────────────────────────────────────
const SEVERITY = { 1: 'danger', 10: 'warning', 20: 'info', 25: 'info', 30: 'success', 40: 'secondary' }
function privilegeSeverity(value) { return SEVERITY[value] ?? 'secondary' }

// ── State ─────────────────────────────────────────────────────────
const users        = ref([])
const loading      = ref(false)
const isAdmin      = ref(false)
const expandedRows = ref({})
const formVisible  = ref(false)
const saving       = ref(false)
const formData     = ref({})

/** The privilege level of the logged-in user (for gating what overrides they can assign) */
const callerPrivilege = computed(() => auth.user?.privilege_value ?? 1)

// ── Load users ────────────────────────────────────────────────────
async function loadUsers() {
  loading.value = true
  try {
    const res = await api.get('user_ctrl', 'showList')
    users.value   = res.users ?? []
    isAdmin.value = res.admin ?? false
  } catch (e) {
    toast.add({ severity: 'error', summary: t('users'), detail: String(e), life: 4000 })
  } finally {
    loading.value = false
  }
}

// ── Open user form ────────────────────────────────────────────────
async function openForm(user) {
  const res = await api.get('user_ctrl', 'showUserForm', user ? { id: user.id } : {})
  formData.value = res
  formVisible.value = true
}

// ── Save user ─────────────────────────────────────────────────────
async function saveUser(data) {
  saving.value = true
  try {
    const res = await api.post('user_ctrl', 'saveUserData', data)
    if (res.status !== 'success') throw new Error(t(res.code ?? 'error'))
    toast.add({ severity: 'success', summary: t('users'), detail: t('user_data_saved'), life: 3000 })
    formVisible.value = false
    await loadUsers()
  } catch (e) {
    toast.add({ severity: 'error', summary: t('users'), detail: e.message, life: 4000 })
  } finally {
    saving.value = false
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
    const res = await api.get('user_ctrl', 'deleteOne', { id })
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
.users-view {
  display: flex;
  flex-direction: column;
  height: 100%;
}

.users-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1.25rem 1.5rem 0.75rem;
  flex-shrink: 0;
}

.users-header h2 {
  font-size: 1.2rem;
  font-weight: 600;
}

.users-table {
  margin: 0 1.5rem;
}

.users-email {
  color: var(--p-text-color);
  text-decoration: none;
  font-size: 0.875rem;
}
.users-email:hover { text-decoration: underline; }

.users-priv-cell {
  display: flex;
  align-items: center;
  gap: 0.4rem;
}

.users-badge {
  font-size: 0.7rem;
}
</style>
