<template>
  <AppLayout>
    <div class="backup-view">

      <div class="backup-header">
        <h2 class="backup-title">{{ t('backup') }}</h2>
        <Button
          :label="t('backup_now')"
          icon="pi pi-save"
          :loading="creating"
          @click="createBackup"
        />
      </div>

      <!-- Loading -->
      <div v-if="loading" class="backup-loading">
        <ProgressSpinner style="width:32px;height:32px" />
      </div>

      <!-- Error -->
      <Message v-else-if="fetchError" severity="error">{{ fetchError }}</Message>

      <!-- Empty -->
      <Message v-else-if="!loading && backups.length === 0" severity="info">
        {{ t('no_bup_present') }}
      </Message>

      <!-- Table -->
      <DataTable
        v-else
        :value="backups"
        class="backup-table"
        size="small"
        striped-rows
      >
        <Column field="app"            :header="t('application')" />
        <Column field="engine"         :header="t('engine')" />
        <Column field="formatted_time" :header="t('date')" />
        <Column field="size_mb"        :header="t('size')">
          <template #body="{ data }">{{ data.size_mb }} MB</template>
        </Column>
        <Column :header="t('actions')" style="width:14rem">
          <template #body="{ data }">
            <div class="row-actions">
              <Button
                icon="pi pi-download"
                :title="t('download')"
                size="small"
                severity="secondary"
                text
                @click="downloadBackup(data.file)"
              />
              <Button
                v-if="canDelete"
                icon="pi pi-trash"
                :title="t('erase')"
                size="small"
                severity="danger"
                text
                @click="confirmDelete(data.file)"
              />
              <Button
                v-if="canRestore && data.engine === engine"
                icon="pi pi-undo"
                :title="t('restore')"
                size="small"
                severity="warn"
                text
                @click="confirmRestore(data.file)"
              />
            </div>
          </template>
        </Column>
      </DataTable>

    </div>

    <ConfirmDialog />
  </AppLayout>
</template>

<script setup>
import { ref, onMounted }        from 'vue'
import { useToast }              from 'primevue/usetoast'
import { useConfirm }            from 'primevue/useconfirm'
import AppLayout      from '@/components/AppLayout.vue'
import Button         from 'primevue/button'
import Message        from 'primevue/message'
import ProgressSpinner from 'primevue/progressspinner'
import DataTable      from 'primevue/datatable'
import Column         from 'primevue/column'
import ConfirmDialog  from 'primevue/confirmdialog'
import { api }        from '@/api'
import { useI18n }    from '@/i18n'

const { t }   = useI18n()
const toast   = useToast()
const confirm = useConfirm()
const { responseMessage } = api

// ── State ─────────────────────────────────────────────────────────────────
const loading    = ref(false)
const creating   = ref(false)
const fetchError = ref(null)
const backups    = ref([])
const engine     = ref('')
const canDelete  = ref(false)
const canRestore = ref(false)

// ── Load list ─────────────────────────────────────────────────────────────
async function loadBackups() {
  loading.value    = true
  fetchError.value = null
  try {
    const res = await api.get('backup_ctrl', 'listBackups')
    if (res.status === 'error') throw new Error(responseMessage(res, t))
    backups.value    = res.backups    ?? []
    engine.value     = res.engine     ?? ''
    canDelete.value  = res.can_delete  ?? false
    canRestore.value = res.can_restore ?? false
  } catch (e) {
    fetchError.value = e.message
  } finally {
    loading.value = false
  }
}

onMounted(loadBackups)

// ── Create backup ─────────────────────────────────────────────────────────
async function createBackup() {
  creating.value = true
  try {
    const res = await api.get('backup_ctrl', 'doBackup')
    if (res.status === 'error') {
      const msg = responseMessage(res, t)
      const detail = res.detail ? `${msg}: ${res.detail}` : msg
      toast.add({ severity: 'error', summary: t('error'), detail, life: 0 })
    } else {
      toast.add({ severity: 'success', summary: t('backup'), detail: t('ok_backup'), life: 3000 })
      await loadBackups()
    }
  } catch (e) {
    toast.add({ severity: 'error', summary: t('error'), detail: e.message, life: 5000 })
  } finally {
    creating.value = false
  }
}

// ── Download ──────────────────────────────────────────────────────────────
function downloadBackup(file) {
  const params = new URLSearchParams({
    obj:    'backup_ctrl',
    method: 'downloadBackup',
    file,
  })
  window.open('/index.php?' + params.toString(), '_blank')
}

// ── Delete ────────────────────────────────────────────────────────────────
function confirmDelete(file) {
  confirm.require({
    message:       t('are_you_sure'),
    header:        t('erase') + ' ' + file,
    icon:          'pi pi-exclamation-triangle',
    rejectLabel:   t('cancel'),
    acceptLabel:   t('erase'),
    acceptClass:   'p-button-danger',
    accept: async () => {
      try {
        const res = await api.get('backup_ctrl', 'deleteBackup', { file })
        if (res.status === 'error') {
          toast.add({ severity: 'error', summary: t('error'), detail: responseMessage(res, t), life: 5000 })
        } else {
          toast.add({ severity: 'success', summary: t('erase'), detail: t('success_erasing_file'), life: 3000 })
          await loadBackups()
        }
      } catch (e) {
        toast.add({ severity: 'error', summary: t('error'), detail: e.message, life: 5000 })
      }
    },
  })
}

// ── Restore ───────────────────────────────────────────────────────────────
function confirmRestore(file) {
  confirm.require({
    message:       t('restore_warning'),
    header:        t('restore') + ' — ' + file,
    icon:          'pi pi-exclamation-triangle',
    rejectLabel:   t('cancel'),
    acceptLabel:   t('restore'),
    acceptClass:   'p-button-warning',
    accept: async () => {
      try {
        const res = await api.get('backup_ctrl', 'restoreBackup', { file })
        if (res.status === 'error') {
          toast.add({ severity: 'error', summary: t('error'), detail: responseMessage(res, t), life: 6000 })
        } else {
          toast.add({ severity: 'success', summary: t('restore'), detail: t('ok_backup_restored'), life: 4000 })
        }
      } catch (e) {
        toast.add({ severity: 'error', summary: t('error'), detail: e.message, life: 5000 })
      }
    },
  })
}
</script>

<style scoped>
.backup-view {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-height: 0;
  padding: 1.5rem;
  gap: 1.25rem;
  overflow-y: auto;
}

.backup-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-shrink: 0;
}

.backup-title {
  margin: 0;
  font-size: 1.25rem;
  font-weight: 600;
}

.backup-loading {
  display: flex;
  justify-content: center;
  padding: 2rem;
}

.row-actions {
  display: flex;
  gap: 0.25rem;
}
</style>
