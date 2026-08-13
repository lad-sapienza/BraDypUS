<template>
  <AppLayout>
  <div class="deleted-view">

    <!-- ── Toolbar ──────────────────────────────────────────────── -->
    <div class="deleted-toolbar">
      <span class="deleted-title">{{ t('deleted_records') }}</span>

      <ASelect
        v-model:value="selectedTb"
        :options="tableOptions"
        :placeholder="t('deleted_select_table')"
        size="small"
        class="tb-select"
        allow-clear
        @change="onTableChange"
      />

      <AButton
        v-if="selectedTb"
        size="small"
        :loading="loading"
        @click="fetchDeleted"
      >
        <template #icon><ReloadOutlined /></template>
        {{ t('log_refresh') }}
      </AButton>
    </div>

    <!-- ── Placeholder (no table selected) ─────────────────────── -->
    <div v-if="!selectedTb" class="deleted-placeholder">
      <InboxOutlined class="deleted-placeholder-icon" />
      <p>{{ t('deleted_choose_table') }}</p>
    </div>

    <!-- ── Loading ──────────────────────────────────────────────── -->
    <div v-else-if="loading" class="deleted-loading">
      <ASpin size="large" />
    </div>

    <!-- ── Error ────────────────────────────────────────────────── -->
    <AAlert v-else-if="error" type="error" :message="error" show-icon class="deleted-error" />

    <!-- ── Empty ────────────────────────────────────────────────── -->
    <div v-else-if="!rows.length" class="deleted-placeholder">
      <CheckCircleOutlined style="color: var(--p-green-500)" class="deleted-placeholder-icon" />
      <p>{{ t('deleted_no_records') }}</p>
    </div>

    <!-- ── Table ────────────────────────────────────────────────── -->
    <div v-else ref="tableWrap" class="deleted-table">
      <ATable
        :columns="columns"
        :dataSource="rows"
        :pagination="false"
        :scroll="{ y: tableScrollY }"
        size="small"
        rowKey="rowid"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'preview'">
            <span class="row-preview">{{ rowPreview(record) }}</span>
          </template>
          <template v-else-if="column.key === 'actions'">
            <AButton
              type="text"
              size="small"
              style="color: var(--p-orange-500, #f97316)"
              @click="askRestore(record)"
            >
              <template #icon><HistoryOutlined /></template>
              {{ t('version_restore') }}
            </AButton>
          </template>
        </template>
      </ATable>
    </div>

  </div>
  </AppLayout>

  <!-- ── Restore confirmation dialog ─────────────────────────── -->
  <AModal
    v-model:open="confirmVisible"
    :title="t('version_restore_confirm_title')"
    width="420px"
  >
    <div class="confirm-body" v-if="restoreTarget">
      <WarningOutlined class="confirm-icon" />
      <div>
        <p>{{ t('version_restore_confirm_msg') }}</p>
        <p class="confirm-detail">
          {{ t('deleted_restore_detail', restoreTarget.rowid) }}
        </p>
      </div>
    </div>
    <template #footer>
      <AButton type="text" @click="confirmVisible = false">{{ t('cancel') }}</AButton>
      <AButton
        type="primary"
        style="background: var(--p-orange-500, #f97316); border-color: var(--p-orange-500, #f97316)"
        :loading="restoring"
        @click="doRestore"
      >
        <template #icon><HistoryOutlined /></template>
        {{ t('version_restore') }}
      </AButton>
    </template>
  </AModal>
</template>

<script setup>
import { CheckCircleOutlined, HistoryOutlined, InboxOutlined, ReloadOutlined, WarningOutlined } from '@ant-design/icons-vue'
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useRoute, useRouter }  from 'vue-router'
import { useToast } from '@/composables/useNotify'
import AppLayout      from '@/components/AppLayout.vue'
import { Table as ATable, Button as AButton, Select as ASelect, Alert as AAlert, Spin as ASpin, Modal as AModal } from 'ant-design-vue'
import { api }         from '@/api'
import { useI18n }     from '@/i18n'
import { useTables }   from '@/composables/useTables'

const { t }    = useI18n()
const toast    = useToast()
const route    = useRoute()
const router   = useRouter()
const { tables, loadTables } = useTables()

const columns = computed(() => [
  { title: t('deleted_col_id'),      dataIndex: 'rowid',  key: 'rowid',   width: 80 },
  { title: t('deleted_col_preview'), key: 'preview' },
  { title: t('deleted_col_time'),    dataIndex: 'time',   key: 'time',    width: 176 },
  { title: t('deleted_col_user'),    dataIndex: 'userid', key: 'userid',  width: 128 },
  { title: t('deleted_col_actions'), key: 'actions',      width: 128, fixed: 'right' },
])

// ── AntD Table: fill available flex space (no scrollHeight="flex" equivalent) ──
const tableWrap    = ref(null)
const tableScrollY = ref(400)
let resizeObs = null

function measureTableHeight() {
  if (!tableWrap.value) return
  const total   = tableWrap.value.clientHeight
  const headerH = tableWrap.value.querySelector('.ant-table-thead')?.getBoundingClientRect().height ?? 40
  tableScrollY.value = Math.max(200, total - headerH - 2)
}

onUnmounted(() => resizeObs?.disconnect())

// ── State ──────────────────────────────────────────────────────
const selectedTb    = ref(null)
const rows          = ref([])
const loading       = ref(false)
const error         = ref(null)
const confirmVisible = ref(false)
const restoreTarget  = ref(null)
const restoring      = ref(false)

onMounted(() => {
  resizeObs = new ResizeObserver(measureTableHeight)
  if (tableWrap.value) resizeObs.observe(tableWrap.value)
})
watch(rows, () => { measureTableHeight() })

// ── Tables ─────────────────────────────────────────────────────
const tableOptions = computed(() =>
  tables.value.map(tb => ({ label: tb.label ?? tb.id, value: tb.id }))
)

// ── Fetch deleted records ──────────────────────────────────────
async function fetchDeleted() {
  if (!selectedTb.value) return
  loading.value = true
  error.value   = null
  rows.value    = []
  try {
    const res = await api.get(`/api/record/${selectedTb.value}/deleted`)
    if (res.status === 'error') {
      error.value = api.responseMessage(res, t)
      return
    }
    rows.value = res.deleted ?? []
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

function onTableChange() {
  rows.value  = []
  error.value = null
  fetchDeleted()
}

// ── Row preview ────────────────────────────────────────────────
/** Show the first few non-system field values from the snapshot core. */
function rowPreview(row) {
  const core = row.content?.core ?? {}
  const skip = new Set(['id', 'creator', 'last_edit', 'last_editor'])
  const parts = Object.entries(core)
    .filter(([k, v]) => !skip.has(k) && v !== null && v !== '')
    .slice(0, 3)
    .map(([, v]) => String(v))
  return parts.join(' · ') || `#${row.rowid}`
}

// ── Restore ────────────────────────────────────────────────────
function askRestore(row) {
  restoreTarget.value = row
  confirmVisible.value = true
}

async function doRestore() {
  if (!restoreTarget.value) return
  restoring.value = true
  try {
    const res = await api.post(
      `/api/version/${restoreTarget.value.version_id}/restore`,
      { version_id: restoreTarget.value.version_id }
    )
    if (res.status === 'error') {
      toast.add({ severity: 'error', summary: t('generic_error'), detail: api.responseMessage(res, t), life: 5000 })
      return
    }
    confirmVisible.value = false
    toast.add({ severity: 'success', summary: t('version_restore'), detail: t('version_restored'), life: 3000 })

    // Remove the record from the local list (it's no longer deleted)
    rows.value = rows.value.filter(r => r.rowid !== restoreTarget.value.rowid)

    // Offer navigation to the restored record
    toast.add({
      severity: 'info',
      summary:  t('deleted_restored_nav_title'),
      detail:   t('deleted_restored_nav_msg'),
      life:     6000,
      onClick:  () => router.push(`/${route.params.app}/record/${selectedTb.value}/${restoreTarget.value.rowid}`),
    })
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 5000 })
  } finally {
    restoring.value = false
    restoreTarget.value = null
  }
}

// ── Init ───────────────────────────────────────────────────────
onMounted(async () => {
  await loadTables()
  // Pre-select table if passed via ?tb= query param (shortcut from RecordView)
  const tbParam = route.query.tb
  if (tbParam) {
    selectedTb.value = tbParam
    fetchDeleted()
  }
})
</script>

<style scoped>
.deleted-view {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-height: 0;
  overflow: hidden;
}

/* ── Toolbar ── */
.deleted-toolbar {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.6rem 1rem;
  border-bottom: 1px solid var(--p-content-border-color);
  flex-shrink: 0;
  flex-wrap: wrap;
}
.deleted-title {
  font-weight: 700;
  font-size: 1rem;
  margin-right: 0.5rem;
}
.tb-select { width: 16rem; }

/* ── Placeholder ── */
.deleted-placeholder {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  flex: 1;
  gap: 0.75rem;
  color: var(--p-text-muted-color);
  padding: 3rem;
}
.deleted-placeholder-icon {
  font-size: 3rem;
  opacity: 0.35;
}
.deleted-placeholder p { font-size: 0.9rem; }

/* ── Loading ── */
.deleted-loading {
  display: flex;
  justify-content: center;
  padding: 3rem;
}
.deleted-error { margin: 1rem; }

/* ── Table ── */
.deleted-table {
  flex: 1;
  overflow: hidden;
  font-size: 0.82rem;
}
.row-preview {
  color: var(--p-text-muted-color);
  font-size: 0.8rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  display: block;
  max-width: 100%;
}

/* ── Confirm dialog ── */
.confirm-body {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
}
.confirm-icon {
  font-size: 1.5rem;
  color: var(--p-warning-color);
  flex-shrink: 0;
  margin-top: 0.1rem;
}
.confirm-detail {
  font-size: 0.85rem;
  color: var(--p-text-muted-color);
  margin-top: 0.4rem;
}
</style>
