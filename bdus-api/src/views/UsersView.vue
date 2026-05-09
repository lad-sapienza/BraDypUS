<template>
  <AppLayout>
    <div class="view-header">
      <h2>Users</h2>
      <Button
        v-if="isAdmin"
        label="New user"
        icon="pi pi-plus"
        @click="openForm(null)"
      />
    </div>

    <DataTable
      :value="users"
      :loading="loading"
      stripedRows
      size="small"
    >
      <Column field="name" header="Name" sortable />
      <Column field="email" header="Email" sortable />
      <Column field="privilege" header="Role" />
      <Column header="" style="width: 6rem">
        <template #body="{ data }">
          <Button
            v-if="data.editable"
            icon="pi pi-pencil"
            text
            rounded
            size="small"
            @click="openForm(data)"
          />
          <Button
            v-if="isAdmin && data.editable && data.id !== auth.user?.id"
            icon="pi pi-trash"
            text
            rounded
            size="small"
            severity="danger"
            @click="confirmDelete(data)"
          />
        </template>
      </Column>
    </DataTable>

    <!-- User form dialog -->
    <Dialog
      v-model:visible="formVisible"
      :header="formData.id ? 'Edit user' : 'New user'"
      modal
      :style="{ width: '420px' }"
    >
      <UserForm
        :initial="formData"
        :saving="saving"
        @save="saveUser"
        @cancel="formVisible = false"
      />
    </Dialog>

    <ConfirmDialog />
  </AppLayout>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'
import AppLayout from '@/components/AppLayout.vue'
import UserForm from '@/components/users/UserForm.vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import ConfirmDialog from 'primevue/confirmdialog'
import { api } from '@/api'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const confirm = useConfirm()
const toast = useToast()

const users = ref([])
const loading = ref(false)
const isAdmin = ref(false)
const formVisible = ref(false)
const saving = ref(false)
const formData = ref({})

async function loadUsers() {
  loading.value = true
  try {
    const res = await api.get('user_ctrl', 'showList')
    users.value = res.users ?? []
    isAdmin.value = res.admin ?? false
  } catch (e) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.message, life: 4000 })
  } finally {
    loading.value = false
  }
}

async function openForm(user) {
  if (user) {
    const res = await api.get('user_ctrl', 'showUserForm', { id: user.id })
    formData.value = res
  } else {
    const res = await api.get('user_ctrl', 'showUserForm', {})
    formData.value = res
  }
  formVisible.value = true
}

async function saveUser(data) {
  saving.value = true
  try {
    const res = await api.post('user_ctrl', 'saveUserData', data)
    if (res.status !== 'success') throw new Error(res.text)
    toast.add({ severity: 'success', summary: 'Saved', detail: res.text, life: 3000 })
    formVisible.value = false
    await loadUsers()
  } catch (e) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.message, life: 4000 })
  } finally {
    saving.value = false
  }
}

function confirmDelete(user) {
  confirm.require({
    message: `Delete user ${user.name}?`,
    header: 'Confirm',
    icon: 'pi pi-exclamation-triangle',
    rejectProps: { label: 'Cancel', severity: 'secondary', outlined: true },
    acceptProps: { label: 'Delete', severity: 'danger' },
    accept: () => deleteUser(user.id)
  })
}

async function deleteUser(id) {
  try {
    const res = await api.get('user_ctrl', 'deleteOne', { id })
    if (res.status !== 'success') throw new Error(res.text)
    toast.add({ severity: 'success', summary: 'Deleted', detail: res.text, life: 3000 })
    await loadUsers()
  } catch (e) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.message, life: 4000 })
  }
}

onMounted(loadUsers)
</script>

<style scoped>
.view-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1.5rem 2rem 1rem;
}

.view-header h2 {
  font-size: 1.4rem;
  font-weight: 600;
}

:deep(.p-datatable) {
  margin: 0 2rem;
}
</style>
