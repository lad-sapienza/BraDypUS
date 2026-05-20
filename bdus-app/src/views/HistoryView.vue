<template>
  <AppLayout>
  <div class="history-view">

    <!-- ── Toolbar ────────────────────────────────────────────── -->
    <div class="history-toolbar">
      <span class="history-title">{{ t('history') }}</span>

      <!-- Filter by table -->
      <InputText
        v-model="filterTb"
        :placeholder="t('history_filter_tb')"
        size="small"
        class="filter-input"
        @keyup.enter="reload"
      />

      <!-- Filter by user -->
      <InputText
        v-model="filterUser"
        :placeholder="t('history_filter_user')"
        size="small"
        class="filter-input"
        @keyup.enter="reload"
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
      class="history-table"
      dataKey="id"
      v-model:expandedRows="expandedRows"
      @page="onPage"
    >
      <template #empty>
        <span class="empty-msg">{{ t('history_no_entries') }}</span>
      </template>

      <!-- Expand toggle -->
      <Column expander style="width: 2.5rem; flex-shrink: 0;" />

      <!-- Time -->
      <Column field="time"  :header="t('history_col_time')"  style="width: 10rem; flex-shrink: 0;" />

      <!-- User -->
      <Column field="user"  :header="t('history_col_user')"  style="width: 8rem;  flex-shrink: 0;" />

      <!-- Table -->
      <Column field="tb"    :header="t('history_col_tb')"    style="width: 10rem; flex-shrink: 0;" />

      <!-- Row id -->
      <Column field="rowid" :header="t('history_col_rowid')" style="width: 5rem;  flex-shrink: 0;" />

      <!-- Content preview -->
      <Column field="content" :header="t('history_col_content')" style="min-width: 0; flex: 1;">
        <template #body="{ data }">
          <span class="content-preview">{{ truncate(data.content) }}</span>
        </template>
      </Column>

      <!-- Expanded row: full detail -->
      <template #expansion="{ data }">
        <div class="history-detail">
          <div v-if="data.content" class="detail-block">
            <div class="detail-label">{{ t('history_col_content') }}</div>
            <pre class="detail-pre">{{ data.content }}</pre>
          </div>
          <div v-if="data.editsql" class="detail-block">
            <div class="detail-label">SQL</div>
            <pre class="detail-pre">{{ data.editsql }}</pre>
          </div>
          <div v-if="data.editvalues" class="detail-block">
            <div class="detail-label">{{ t('history_col_values') }}</div>
            <pre class="detail-pre">{{ data.editvalues }}</pre>
          </div>
        </div>
      </template>
    </DataTable>

  </div>
  </AppLayout>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useToast } from 'primevue/usetoast'
import AppLayout  from '@/components/AppLayout.vue'
import DataTable  from 'primevue/datatable'
import Column     from 'primevue/column'
import Button     from 'primevue/button'
import InputText  from 'primevue/inputtext'
import { api }    from '@/api'
import { useI18n } from '@/i18n'

const { t }   = useI18n()
const toast   = useToast()
const { responseMessage } = api

// ── State ──────────────────────────────────────────────────────
const rows         = ref([])
const total        = ref(0)
const loading      = ref(false)
const currentPage  = ref(1)
const perPage      = ref(50)
const filterTb     = ref('')
const filterUser   = ref('')
const expandedRows = ref([])

// ── Fetch ───────────────────────────────────────────────────────
async function fetchHistory() {
  loading.value = true
  try {
    const params = { page: currentPage.value, per_page: perPage.value }
    if (filterTb.value)   params.tb   = filterTb.value
    if (filterUser.value) params.user = filterUser.value

    const data = await api.get('/api/history', params)
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
  currentPage.value  = 1
  expandedRows.value = []
  fetchHistory()
}

function onPage(event) {
  currentPage.value  = event.page + 1
  perPage.value      = event.rows
  expandedRows.value = []
  fetchHistory()
}

// ── Helpers ─────────────────────────────────────────────────────
function truncate(str, len = 100) {
  if (!str) return ''
  const first = str.split('\n')[0]
  return first.length > len ? first.slice(0, len) + '…' : first
}

onMounted(fetchHistory)
</script>

<style scoped>
.history-view {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-height: 0;
  overflow: hidden;
}

/* ── Toolbar ──────────────────────────────────────────────────── */
.history-toolbar {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.6rem 1rem;
  border-bottom: 1px solid var(--p-content-border-color);
  flex-shrink: 0;
  flex-wrap: wrap;
}

.history-title {
  font-weight: 700;
  font-size: 1rem;
  margin-right: 0.5rem;
}

.filter-input { width: 12rem; }

/* push refresh to the right */
.history-toolbar .p-button:last-child { margin-left: auto; }

/* ── Table ────────────────────────────────────────────────────── */
.history-table {
  flex: 1;
  overflow: hidden;
  font-size: 0.8rem;
}

:deep(.p-datatable-tbody > tr > td) {
  vertical-align: top;
  padding: 0.35rem 0.6rem;
}

.content-preview {
  display: block;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 100%;
  font-family: monospace;
  font-size: 0.78rem;
  color: var(--p-text-muted-color);
}

/* ── Expanded detail ──────────────────────────────────────────── */
.history-detail {
  padding: 0.75rem 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.detail-block { display: flex; flex-direction: column; gap: 0.25rem; }

.detail-label {
  font-size: 0.7rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--p-text-muted-color);
}

.detail-pre {
  margin: 0;
  padding: 0.5rem 0.75rem;
  white-space: pre-wrap;
  word-break: break-all;
  font-size: 0.78rem;
  font-family: monospace;
  background: var(--bdus-bg);
  border-radius: 6px;
  max-height: 300px;
  overflow-y: auto;
  line-height: 1.5;
}

.empty-msg {
  padding: 2rem;
  color: var(--p-text-muted-color);
  font-style: italic;
}
</style>
