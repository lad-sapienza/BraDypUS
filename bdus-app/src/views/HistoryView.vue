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

    <!-- ── Table ───────────────────────────────────────────────── -->
    <div ref="tableWrap" class="history-table">
      <ATable
        :columns="columns"
        :dataSource="rows"
        :loading="loading"
        :pagination="pagination"
        :scroll="{ y: tableScrollY }"
        :locale="{ emptyText: t('history_no_entries') }"
        size="small"
        rowKey="id"
        v-model:expandedRowKeys="expandedRowKeys"
        @change="onTableChange"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'content'">
            <span class="content-preview">{{ truncate(record.content) }}</span>
          </template>
        </template>

        <!-- Expanded row: full detail -->
        <template #expandedRowRender="{ record }">
          <div class="history-detail">
            <div v-if="record.content" class="detail-block">
              <div class="detail-label">{{ t('history_col_content') }}</div>
              <pre class="detail-pre">{{ record.content }}</pre>
            </div>
            <div v-if="record.editsql" class="detail-block">
              <div class="detail-label">SQL</div>
              <pre class="detail-pre">{{ record.editsql }}</pre>
            </div>
            <div v-if="record.editvalues" class="detail-block">
              <div class="detail-label">{{ t('history_col_values') }}</div>
              <pre class="detail-pre">{{ record.editvalues }}</pre>
            </div>
          </div>
        </template>
      </ATable>
    </div>

  </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useToast } from '@/composables/useNotify'
import AppLayout  from '@/components/AppLayout.vue'
import { Table as ATable } from 'ant-design-vue'
import Button     from 'primevue/button'
import InputText  from 'primevue/inputtext'
import { api }    from '@/api'
import { useI18n } from '@/i18n'

const { t }   = useI18n()
const toast   = useToast()
const { responseMessage } = api

const columns = computed(() => [
  { title: t('history_col_time'),    dataIndex: 'time',    key: 'time',    width: 160 },
  { title: t('history_col_user'),    dataIndex: 'user',    key: 'user',    width: 128 },
  { title: t('history_col_tb'),      dataIndex: 'tb',      key: 'tb',      width: 160 },
  { title: t('history_col_rowid'),   dataIndex: 'rowid',   key: 'rowid',   width: 80 },
  { title: t('history_col_content'), key: 'content' },
])

// ── State ──────────────────────────────────────────────────────
const rows            = ref([])
const total           = ref(0)
const loading         = ref(false)
const currentPage     = ref(1)
const perPage         = ref(50)
const filterTb        = ref('')
const filterUser      = ref('')
const expandedRowKeys = ref([])

const pagination = computed(() => ({
  current: currentPage.value,
  pageSize: perPage.value,
  total: total.value,
  showSizeChanger: true,
  pageSizeOptions: ['25', '50', '100'],
  position: ['bottomCenter'],
}))

// ── AntD Table: fill available flex space (no scrollHeight="flex" equivalent) ──
const tableWrap    = ref(null)
const tableScrollY = ref(400)
let resizeObs = null

function measureTableHeight() {
  if (!tableWrap.value) return
  const total_    = tableWrap.value.clientHeight
  const headerH   = tableWrap.value.querySelector('.ant-table-thead')?.getBoundingClientRect().height ?? 40
  const paginationH = tableWrap.value.querySelector('.ant-pagination')?.getBoundingClientRect().height ?? 32
  tableScrollY.value = Math.max(200, total_ - headerH - paginationH - 16)
}

onMounted(() => {
  resizeObs = new ResizeObserver(measureTableHeight)
  if (tableWrap.value) resizeObs.observe(tableWrap.value)
})
onUnmounted(() => resizeObs?.disconnect())
watch(rows, () => { measureTableHeight() })

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
  currentPage.value     = 1
  expandedRowKeys.value = []
  fetchHistory()
}

function onTableChange(paginationEvt) {
  currentPage.value     = paginationEvt.current
  perPage.value         = paginationEvt.pageSize
  expandedRowKeys.value = []
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
