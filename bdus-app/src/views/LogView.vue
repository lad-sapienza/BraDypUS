<template>
  <AppLayout>
  <div class="log-view">

    <!-- ── Toolbar ────────────────────────────────────────────── -->
    <div class="log-toolbar">
      <span class="log-title">{{ t('app_log') }}</span>

      <!-- Level filter -->
      <Select
        v-model="filterLevel"
        :options="levelOptions"
        optionLabel="label"
        optionValue="value"
        size="small"
        class="level-select"
        @change="onFilterChange"
      />

      <!-- Search -->
      <InputText
        v-model="filterSearch"
        :placeholder="t('log_search_placeholder')"
        size="small"
        class="search-input"
        @keyup.enter="onFilterChange"
      />

      <!-- Refresh -->
      <Button
        :label="t('log_refresh')"
        icon="pi pi-refresh"
        size="small"
        severity="secondary"
        :loading="loading"
        @click="reload"
      />

      <!-- Purge -->
      <Button
        :label="t('log_purge')"
        icon="pi pi-trash"
        size="small"
        severity="danger"
        outlined
        @click="purgeDialogVisible = true"
      />
    </div>

    <!-- ── DataTable ───────────────────────────────────────────── -->
    <DataTable
      :value="rows"
      lazy
      paginator
      :rows="perPage"
      :totalRecords="total"
      :loading="loading"
      :rowsPerPageOptions="[25, 50, 100]"
      paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink RowsPerPageDropdown CurrentPageReport"
      :currentPageReportTemplate="`{first}–{last} / {totalRecords}`"
      scrollable
      scrollHeight="flex"
      size="small"
      class="log-table"
      dataKey="id"
      expandedRowIcon="pi pi-minus"
      collapsedRowIcon="pi pi-plus"
      v-model:expandedRows="expandedRows"
      @page="onPage"
    >
      <template #empty>
        <span class="empty-msg">{{ t('log_no_entries') }}</span>
      </template>

      <!-- Expand toggle -->
      <Column expander style="width: 2.5rem; flex-shrink: 0;" />

      <!-- Time -->
      <Column field="time" :header="t('log_col_time')" style="width: 10rem; flex-shrink: 0;" />

      <!-- Level badge -->
      <Column field="level_name" :header="t('log_col_level')" style="width: 7rem; flex-shrink: 0;">
        <template #body="{ data }">
          <Tag :value="data.level_name" :severity="levelSeverity(data.level)" />
        </template>
      </Column>

      <!-- Channel -->
      <Column field="channel" :header="t('log_col_channel')" style="width: 6rem; flex-shrink: 0;" />

      <!-- Message (truncated) -->
      <Column field="message" :header="t('log_col_message')" style="min-width: 0; flex: 1;">
        <template #body="{ data }">
          <span class="msg-preview">{{ truncate(data.message) }}</span>
        </template>
      </Column>

      <!-- Expanded row: full message -->
      <template #expansion="{ data }">
        <pre class="msg-full">{{ data.message }}</pre>
      </template>
    </DataTable>

    <!-- ── Purge dialog ────────────────────────────────────────── -->
    <Dialog
      v-model:visible="purgeDialogVisible"
      modal
      :header="t('log_purge')"
      style="width: 28rem"
    >
      <div class="purge-body">
        <Select
          v-model="purgeDays"
          :options="purgeOptions"
          optionLabel="label"
          optionValue="value"
          style="width: 100%"
        />
      </div>
      <template #footer>
        <Button
          :label="t('confirm')"
          icon="pi pi-check"
          severity="danger"
          :loading="purging"
          @click="doPurge"
        />
        <Button
          :label="t('cancel')"
          icon="pi pi-times"
          severity="secondary"
          text
          @click="purgeDialogVisible = false"
        />
      </template>
    </Dialog>

  </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useToast } from 'primevue/usetoast'
import AppLayout from '@/components/AppLayout.vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Select from 'primevue/select'
import InputText from 'primevue/inputtext'
import Tag from 'primevue/tag'
import Dialog from 'primevue/dialog'
import { api } from '@/api'
const { responseMessage } = api
import { useI18n } from '@/i18n'

const { t } = useI18n()
const toast  = useToast()

// ── State ──────────────────────────────────────────────────────
const rows         = ref([])
const total        = ref(0)
const loading      = ref(false)
const currentPage  = ref(1)
const perPage      = ref(50)
const filterLevel  = ref(0)
const filterSearch = ref('')
const expandedRows = ref([])

const purgeDialogVisible = ref(false)
const purging            = ref(false)
const purgeDays          = ref(30)

// ── Level options ───────────────────────────────────────────────
const levelOptions = computed(() => [
  { value: 0,   label: t('log_level_all') },
  { value: 100, label: t('log_level_debug') },
  { value: 200, label: t('log_level_info') },
  { value: 250, label: t('log_level_notice') },
  { value: 300, label: t('log_level_warning') },
  { value: 400, label: t('log_level_error') },
  { value: 500, label: t('log_level_critical') },
  { value: 550, label: t('log_level_alert') },
  { value: 600, label: t('log_level_emergency') },
])

const purgeOptions = computed(() => [1, 7, 14, 30, 90, 365].map(d => ({
  value: d,
  label: t('log_purge_days', d),
})))

// ── Severity map ────────────────────────────────────────────────
function levelSeverity(level) {
  if (level >= 400) return 'danger'
  if (level >= 300) return 'warn'
  if (level >= 200) return 'info'
  return 'secondary'
}

// ── Fetch ───────────────────────────────────────────────────────
async function fetchLogs() {
  loading.value = true
  try {
    const params = {
      page:     currentPage.value,
      per_page: perPage.value,
    }
    if (filterLevel.value > 0) params.level  = filterLevel.value
    if (filterSearch.value)    params.search = filterSearch.value

    const data = await api.get('debug_ctrl', 'getLogs', params)
    if (data.status === 'error') throw new Error(responseMessage(data, t))
    rows.value  = data.data  ?? []
    total.value = data.total ?? 0
  } catch (e) {
    toast.add({ severity: 'error', summary: t('db_error'), detail: e.message, life: 5000 })
  } finally {
    loading.value = false
  }
}

function reload() {
  currentPage.value = 1
  expandedRows.value = []
  fetchLogs()
}

function onFilterChange() {
  reload()
}

function onPage(event) {
  currentPage.value = event.page + 1
  perPage.value     = event.rows
  expandedRows.value = []
  fetchLogs()
}

// ── Purge ───────────────────────────────────────────────────────
async function doPurge() {
  purging.value = true
  try {
    const data = await api.post('debug_ctrl', 'purgeLogs', { days: purgeDays.value })
    if (data.status === 'error') throw new Error(responseMessage(data, t))
    toast.add({ severity: 'success', summary: t('log_purge'),
      detail: t('log_purge_success', data.deleted, data.days), life: 4000 })
    purgeDialogVisible.value = false
    reload()
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 5000 })
  } finally {
    purging.value = false
  }
}

// ── Helpers ─────────────────────────────────────────────────────
function truncate(str, len = 120) {
  if (!str) return ''
  const first = str.split('\n')[0]
  return first.length > len ? first.slice(0, len) + '…' : first
}

onMounted(fetchLogs)
</script>

<style scoped>
.log-view {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-height: 0;
  overflow: hidden;
}

/* ── Toolbar ──────────────────────────────────────────────────── */
.log-toolbar {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.6rem 1rem;
  border-bottom: 1px solid var(--p-surface-border);
  flex-shrink: 0;
  flex-wrap: wrap;
}

.log-title {
  font-weight: 700;
  font-size: 1rem;
  margin-right: 0.5rem;
}

.level-select { width: 9rem; }
.search-input { width: 14rem; }

/* push refresh+purge to the right */
.log-toolbar .p-button:nth-last-child(2) { margin-left: auto; }

/* ── Table ────────────────────────────────────────────────────── */
.log-table {
  flex: 1;
  overflow: hidden;
  font-size: 0.8rem;
}

:deep(.p-datatable-tbody > tr > td) {
  vertical-align: top;
  padding: 0.35rem 0.6rem;
}

.msg-preview {
  display: block;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 100%;
  font-family: monospace;
  font-size: 0.78rem;
  color: var(--p-text-muted-color);
}

/* Expanded row: full message */
.msg-full {
  margin: 0;
  padding: 0.75rem 1rem;
  white-space: pre-wrap;
  word-break: break-all;
  font-size: 0.78rem;
  font-family: monospace;
  background: var(--p-surface-ground);
  border-radius: 6px;
  max-height: 400px;
  overflow-y: auto;
  line-height: 1.5;
}

.empty-msg {
  padding: 2rem;
  color: var(--p-text-muted-color);
  font-style: italic;
}

/* ── Purge dialog ─────────────────────────────────────────────── */
.purge-body {
  padding: 0.5rem 0;
}
</style>
