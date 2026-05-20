<template>
  <div class="cfg-panel">
    <div class="cfg-panel-header">
      <h2><i class="pi pi-key" /> {{ t('api_keys') }}</h2>
    </div>

    <div class="cfg-api-body">
      <p class="hint">{{ t('api_keys_hint') }}</p>

      <!-- ── Create form ──────────────────────────────────────────── -->
      <section class="cfg-section">
        <div class="cfg-section-header">
          <span>{{ t('create_api_key') }}</span>
        </div>
        <div class="create-form">
          <InputText
            v-model="newLabel"
            :placeholder="t('api_key_label')"
            fluid
            @keydown.enter="createKey"
          />
          <Select
            v-model="newPrivilege"
            :options="privilegeOptions"
            option-label="label"
            option-value="value"
            :placeholder="t('privilege')"
            style="min-width: 12rem"
          />
          <Button
            :label="t('create_api_key')"
            icon="pi pi-plus"
            :loading="creating"
            @click="createKey"
          />
        </div>

        <!-- New key shown once after creation -->
        <Message v-if="newKey" severity="warn" :closable="false" class="new-key-box">
          <p>{{ t('api_key_copy_now') }}</p>
          <div class="key-row">
            <code class="key-code">{{ newKey }}</code>
            <Button icon="pi pi-copy" text size="small" :title="t('copy')" @click="copyKey" />
          </div>
        </Message>
      </section>

      <!-- ── Keys table ───────────────────────────────────────────── -->
      <section class="cfg-section">
        <div class="cfg-section-header">
          <span>{{ t('api_keys') }}</span>
        </div>

        <div v-if="loading" class="cfg-loading-center">
          <i class="pi pi-spin pi-spinner" />
        </div>

        <Message v-else-if="error" severity="error" :closable="false">{{ error }}</Message>

        <div v-else-if="keys.length === 0" class="cfg-empty-msg-sm">
          {{ t('no_api_keys') }}
        </div>

        <DataTable v-else :value="keys" size="small" class="keys-table">
          <Column field="label" :header="t('label')" />
          <Column field="created_at" :header="t('created_at')">
            <template #body="{ data }">{{ formatDate(data.created_at) }}</template>
          </Column>
          <Column field="last_used_at" :header="t('last_used')">
            <template #body="{ data }">{{ data.last_used_at ? formatDate(data.last_used_at) : '—' }}</template>
          </Column>
          <Column field="privilege" :header="t('privilege')">
            <template #body="{ data }">
              <Tag
                :value="privilegeLabel(data.privilege)"
                :severity="privilegeSeverity(data.privilege)"
              />
            </template>
          </Column>
          <Column field="is_active" :header="t('status')">
            <template #body="{ data }">
              <Tag
                :value="data.is_active ? t('active') : t('revoked')"
                :severity="data.is_active ? 'success' : 'secondary'"
              />
            </template>
          </Column>
          <Column :header="t('actions')">
            <template #body="{ data }">
              <Button
                v-if="data.is_active"
                icon="pi pi-ban"
                text
                severity="warn"
                size="small"
                :title="t('revoke')"
                @click="revokeKey(data.id)"
              />
              <Button
                icon="pi pi-trash"
                text
                severity="danger"
                size="small"
                :title="t('delete')"
                @click="deleteKey(data.id)"
              />
            </template>
          </Column>
        </DataTable>
      </section>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import Button    from 'primevue/button'
import InputText from 'primevue/inputtext'
import Select    from 'primevue/select'
import Message   from 'primevue/message'
import DataTable from 'primevue/datatable'
import Column    from 'primevue/column'
import Tag       from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import { useI18n }  from '@/i18n'
import { api }      from '@/api'

const { t }  = useI18n()
const toast  = useToast()

// Privilege levels mirror UAC constants (lower = more privileged)
const PRIV_ADMIN = 10
const PRIV_EDIT  = 25
const PRIV_READ  = 30

const privilegeOptions = computed(() => [
  { value: PRIV_READ,  label: t('priv_read')  },
  { value: PRIV_EDIT,  label: t('priv_edit')  },
  { value: PRIV_ADMIN, label: t('priv_admin') },
])

function privilegeLabel(priv) {
  if (priv <= PRIV_ADMIN) return t('priv_admin')
  if (priv <= PRIV_EDIT)  return t('priv_edit')
  return t('priv_read')
}

function privilegeSeverity(priv) {
  if (priv <= PRIV_ADMIN) return 'danger'
  if (priv <= PRIV_EDIT)  return 'warn'
  return 'info'
}

const loading      = ref(false)
const creating     = ref(false)
const error        = ref(null)
const keys         = ref([])
const newLabel     = ref('')
const newPrivilege = ref(PRIV_READ)
const newKey       = ref(null)

// ── Load ──────────────────────────────────────────────────────────────────

async function load() {
  loading.value = true
  error.value   = null
  try {
    const res = await api.get('api_ctrl', 'listKeys')
    if (res.status === 'error') throw new Error(t(res.code) || res.code)
    keys.value = res.keys ?? []
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

// ── Create ────────────────────────────────────────────────────────────────

async function createKey() {
  const label = newLabel.value.trim()
  if (!label) return

  creating.value = true
  newKey.value   = null
  try {
    const res = await api.post('api_ctrl', 'createKey', { label, privilege: newPrivilege.value })
    if (res.status === 'error') {
      toast.add({ severity: 'error', summary: t(res.code) || res.code, life: 5000 })
      return
    }
    newKey.value      = res.key
    newLabel.value    = ''
    newPrivilege.value = PRIV_READ
    toast.add({ severity: 'success', summary: t('ok_api_key_created'), life: 4000 })
    await load()
  } catch (e) {
    toast.add({ severity: 'error', summary: e.message, life: 5000 })
  } finally {
    creating.value = false
  }
}

// ── Revoke ────────────────────────────────────────────────────────────────

async function revokeKey(id) {
  try {
    const res = await api.post('api_ctrl', 'revokeKey', { id })
    toast.add({
      severity: res.status === 'success' ? 'success' : 'error',
      summary:  t(res.status === 'success' ? 'ok_api_key_revoked' : res.code),
      life: 4000,
    })
    if (res.status === 'success') await load()
  } catch (e) {
    toast.add({ severity: 'error', summary: e.message, life: 5000 })
  }
}

// ── Delete ────────────────────────────────────────────────────────────────

async function deleteKey(id) {
  try {
    const res = await api.post('api_ctrl', 'deleteKey', { id })
    toast.add({
      severity: res.status === 'success' ? 'success' : 'error',
      summary:  t(res.status === 'success' ? 'ok_api_key_deleted' : res.code),
      life: 4000,
    })
    if (res.status === 'success') await load()
  } catch (e) {
    toast.add({ severity: 'error', summary: e.message, life: 5000 })
  }
}

// ── Helpers ───────────────────────────────────────────────────────────────

function formatDate(ts) {
  return new Date(ts * 1000).toLocaleDateString()
}

async function copyKey() {
  if (!newKey.value) return
  try {
    await navigator.clipboard.writeText(newKey.value)
    toast.add({ severity: 'info', summary: t('copied'), life: 2000 })
  } catch {
    // Clipboard not available — user can copy manually
  }
}

onMounted(load)
</script>

<style scoped>
.cfg-panel {
  flex: 1;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.cfg-panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 1rem 1.25rem 0.75rem;
  border-bottom: 1px solid var(--p-content-border-color);
  flex-shrink: 0;
}
.cfg-panel-header h2 {
  margin: 0;
  font-size: 1rem;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
.cfg-api-body {
  flex: 1;
  overflow-y: auto;
  padding: 1rem 1.25rem;
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}
.hint {
  margin: 0;
  font-size: 0.875rem;
  color: var(--p-text-muted-color);
}
.cfg-section {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}
.cfg-section-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-weight: 600;
  font-size: 0.85rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--p-text-muted-color);
  border-bottom: 1px solid var(--p-content-border-color);
  padding-bottom: 0.4rem;
}
.create-form {
  display: flex;
  gap: 0.75rem;
  align-items: center;
  flex-wrap: wrap;
}
.new-key-box {
  margin-top: 0.5rem;
}
.key-row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-top: 0.25rem;
}
.key-code {
  font-family: monospace;
  font-size: 0.875rem;
  background: var(--p-content-background);
  border: 1px solid var(--p-content-border-color);
  border-radius: 4px;
  padding: 0.25rem 0.5rem;
  word-break: break-all;
}
.cfg-loading-center {
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 2rem;
  color: var(--p-text-muted-color);
  padding: 2rem;
}
.cfg-empty-msg-sm {
  font-size: 0.85rem;
  color: var(--p-text-muted-color);
  padding: 0.5rem 0;
}
</style>
