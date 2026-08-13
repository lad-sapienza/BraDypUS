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
          <AInput
            v-model:value="newLabel"
            :placeholder="t('api_key_label')"
            style="flex: 1; min-width: 200px"
            @keydown.enter="createKey"
          />
          <ASelect
            v-model:value="newPrivilege"
            :options="privilegeOptions"
            :placeholder="t('privilege')"
            style="min-width: 12rem"
          />
          <AButton :loading="creating" @click="createKey">
            <template #icon><i class="pi pi-plus" /></template>
            {{ t('create_api_key') }}
          </AButton>
        </div>

        <!-- New key shown once after creation -->
        <AAlert v-if="newKey" type="warning" :closable="false" show-icon class="new-key-box">
          <template #message>
            <p>{{ t('api_key_copy_now') }}</p>
            <div class="key-row">
              <code class="key-code">{{ newKey }}</code>
              <AButton type="text" size="small" :title="t('copy')" @click="copyKey">
                <template #icon><i class="pi pi-copy" /></template>
              </AButton>
            </div>
          </template>
        </AAlert>
      </section>

      <!-- ── Keys table ───────────────────────────────────────────── -->
      <section class="cfg-section">
        <div class="cfg-section-header">
          <span>{{ t('api_keys') }}</span>
        </div>

        <div v-if="loading" class="cfg-loading-center">
          <i class="pi pi-spin pi-spinner" />
        </div>

        <AAlert v-else-if="error" type="error" :message="error" :closable="false" show-icon />

        <div v-else-if="keys.length === 0" class="cfg-empty-msg-sm">
          {{ t('no_api_keys') }}
        </div>

        <ATable
          v-else
          :columns="columns"
          :dataSource="keys"
          :pagination="false"
          size="small"
          rowKey="id"
          class="keys-table"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'created_at'">{{ formatDate(record.created_at) }}</template>
            <template v-else-if="column.key === 'last_used_at'">{{ record.last_used_at ? formatDate(record.last_used_at) : '—' }}</template>
            <template v-else-if="column.key === 'privilege'">
              <ATag :color="severityToTagColor(privilegeSeverity(record.privilege))">
                {{ privilegeLabel(record.privilege) }}
              </ATag>
            </template>
            <template v-else-if="column.key === 'is_active'">
              <ATag :color="record.is_active ? 'success' : 'default'">
                {{ record.is_active ? t('active') : t('revoked') }}
              </ATag>
            </template>
            <template v-else-if="column.key === 'actions'">
              <AButton
                v-if="record.is_active"
                type="text"
                danger
                size="small"
                :title="t('revoke')"
                @click="revokeKey(record.id)"
              ><template #icon><i class="pi pi-ban" /></template></AButton>
              <AButton
                type="text"
                danger
                size="small"
                :title="t('delete')"
                @click="deleteKey(record.id)"
              ><template #icon><i class="pi pi-trash" /></template></AButton>
            </template>
          </template>
        </ATable>
      </section>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { Table as ATable, Button as AButton, Input as AInput, Select as ASelect, Alert as AAlert, Tag as ATag } from 'ant-design-vue'
import { useToast } from '@/composables/useNotify'
import { useI18n }  from '@/i18n'
import { api }      from '@/api'
import { severityToTagColor } from '@/utils/severity'

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

const columns = computed(() => [
  { title: t('label'),       dataIndex: 'label',        key: 'label' },
  { title: t('created_at'),  dataIndex: 'created_at',   key: 'created_at' },
  { title: t('last_used'),   dataIndex: 'last_used_at', key: 'last_used_at' },
  { title: t('privilege'),   dataIndex: 'privilege',    key: 'privilege' },
  { title: t('status'),      dataIndex: 'is_active',    key: 'is_active' },
  { title: t('actions'),     key: 'actions' },
])

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
    const res = await api.get('/api/api-keys')
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
    const res = await api.post('/api/api-keys', { label, privilege: newPrivilege.value })
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
    const res = await api.post(`/api/api-key/${id}/revoke`)
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
    const res = await api.delete(`/api/api-key/${id}`)
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
