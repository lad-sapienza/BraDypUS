<template>
  <AppLayout>
    <div class="data-layout">

      <!-- ── Records panel ──────────────────────────────────── -->
      <div class="records-panel">

        <div v-if="!selectedTable" class="records-placeholder">
          <ArrowLeftOutlined />
          <p>{{ t('choose_db') }}</p>
        </div>

        <template v-else>

          <!-- ── Query bar: text box, Filters, chips — and the result actions ── -->
          <QueryBar :query="vm.query" :config="vm.config" :compact="compact">
            <template #actions>
              <ResultActions
                :compact="compact"
                :table="selectedTable"
                :list="vm.list"
                :prefs="vm.prefs"
                :query="vm.query"
                :actions="actions"
              />
            </template>
          </QueryBar>

          <!-- ── Results header ──────────────────────────────── -->
          <div class="records-header">
            <h3>{{ selectedTable.label }}</h3>
            <span class="records-count" v-if="!loading">
              {{ t('x_record_found', String(total)) }}
            </span>
          </div>

          <!-- ── Table ────────────────────────────────────────── -->
          <!--
            AntD's Table has no `scrollHeight="flex"`: `scroll.y` wants a concrete
            px number, so filling the remaining flex space takes a ResizeObserver
            measuring the wrapper (see useFlexTableHeight). It also has no
            drag-to-reorder columns; visibility toggling (ResultActions) still works.
          -->
          <div ref="tableWrap" class="records-table-wrap">
            <ATable
              :columns="antdColumns"
              :dataSource="records"
              :loading="loading"
              :pagination="paginationConfig"
              :customRow="customRow"
              :locale="{ emptyText: t('no_record_found') }"
              :scroll="{ y: tableScrollY }"
              size="small"
              rowKey="id"
              class="clickable-rows"
              @change="onTableChange"
            />
          </div>

        </template>

        <!-- FAB — only for users with add_new privilege -->
        <button
          v-if="canAdd && selectedTable"
          class="fab-add"
          :title="t('new_record')"
          @click="actions.addRecord"
        >
          <PlusOutlined />
        </button>

      </div>
    </div>

  </AppLayout>
</template>

<script setup>
// The record list page. It wires the pieces together and owns only what is the
// table's own: its columns, paging and rows. The search is useRecordQuery /
// QueryBar, the fetch useRecordList, the columns useColumnPrefs, and what the
// results can be sent to (export, map, …) useResultActions / ResultActions.
import { computed, reactive, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { ArrowLeftOutlined, PlusOutlined } from '@ant-design/icons-vue'
import { Table as ATable } from 'ant-design-vue'
import { useToast } from '@/composables/useNotify'
import { useI18n } from '@/i18n'
import { useTables } from '@/composables/useTables'
import { useMediaQuery } from '@/composables/useMediaQuery'
import { useSearchConfig } from '@/composables/useSearchConfig'
import { useColumnPrefs } from '@/composables/useColumnPrefs'
import { useRecordList } from '@/composables/useRecordList'
import { useRecordQuery } from '@/composables/useRecordQuery'
import { useResultActions } from '@/composables/useResultActions'
import { useFlexTableHeight } from '@/composables/useFlexTableHeight'
import AppLayout from '@/components/AppLayout.vue'
import QueryBar from '@/components/query/QueryBar.vue'
import ResultActions from '@/components/query/ResultActions.vue'

const { t }  = useI18n()
const toast  = useToast()
const route  = useRoute()

// Below this width the toolbar collapses its secondary actions into one menu
// and the floating + button replaces the "New record" button.
const compact = useMediaQuery('(max-width: 640px)')

// ── Tables (shared singleton) and the selected one, from the URL ────────
const { tables, loadTables } = useTables()
const selectedTable = computed(() =>
  tables.value.find(tbl => tbl.name === route.query.tb) ?? null
)

// ── The pieces ───────────────────────────────────────────────────────────
// They lean on each other — a column change refetches; a new query goes back to
// page 1 and refetches; the list asks the query what to search for — so `list`
// is declared first and the others close over it.
let list
const config = useSearchConfig(selectedTable)
const prefs = useColumnPrefs({
  table: selectedTable,
  fields: config.fields,
  onChange: () => list.fetch(),
})
const query = useRecordQuery({
  table: selectedTable,
  config,
  onApply: () => list.refresh(),
  getSort: () => ({ sortField: list.sortField.value, sortDir: list.sortDir.value }),
})
list = useRecordList({
  table: selectedTable,
  prefs,
  getSearch: () => query.searchParams.value,
})
const actions = useResultActions({ table: selectedTable, query, list })

// Reactive views for the child components: refs nested in them read as plain values.
const vm = reactive({ config, prefs, query, list })
const { records, displayColumns, total, loading, canAdd, page, perPage, sortField, sortDir } = list

// ── Follow the URL ───────────────────────────────────────────────────────
function onTableChanged(tb) {
  config.invalidate()
  prefs.restoreFor(tb)   // before the first fetch, so it already asks for the saved columns
  config.load()
}
const syncRoute = () => query.syncFromRoute({ tables: tables.value, onTableChanged })

onMounted(async () => {
  try {
    await loadTables()
    syncRoute()
  } catch {
    toast.add({ severity: 'error', summary: 'Error', detail: 'Could not load tables', life: 3000 })
  }
})
watch(() => route.query, syncRoute)

// ── The table: columns, paging, rows ─────────────────────────────────────
const antdColumns = computed(() =>
  displayColumns.value.map(col => ({
    title: col.label,
    dataIndex: col.name,
    key: col.name,
    sorter: true,
    ellipsis: true,
    sortOrder: sortField.value === col.name
      ? (sortDir.value === 'desc' ? 'descend' : 'ascend')
      : null,
    // FK (id_from_tb) fields carry the resolved target label under "@name"
    // alongside the raw id (see Record.php::getRecords) — prefer it, as the
    // single-record view and the guided search already do.
    customRender: ({ record, text }) => record['@' + col.name] ?? text,
  }))
)

const paginationConfig = computed(() => ({
  current: page.value,
  pageSize: perPage.value,
  total: total.value,
  showSizeChanger: true,
  pageSizeOptions: ['15', '30', '50', '100'],
  // AntD defaults to bottomRight, which sits under the fixed add-record FAB.
  position: ['bottomCenter'],
}))

const customRow = record => ({ onClick: () => actions.openRecord(record.id) })
const onTableChange = (pagination, _filters, sorter) => list.changeTable(pagination, sorter)

const { wrap: tableWrap, scrollY: tableScrollY } = useFlexTableHeight(records)
</script>

<style scoped>
.data-layout {
  display: flex;
  flex: 1;
  min-height: 0;
  overflow: hidden;
  flex-direction: column;   /* records-panel is the only child now */
}


/* ── Records panel ───────────────────────────────────────── */
.records-panel {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  padding: 1rem;
  gap: 0.75rem;
}

.records-placeholder {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.75rem;
  color: var(--p-text-muted-color);
}
.records-placeholder .anticon { font-size: 2rem; }

/* ── Results header ──────────────────────────────────────── */
.records-header {
  display: flex;
  align-items: baseline;
  gap: 1rem;
  flex-shrink: 0;
}
.records-header h3 { font-size: 1.1rem; font-weight: 700; }
.records-count     { font-size: 0.82rem; color: var(--p-text-muted-color); }

.records-table-wrap { flex: 1; min-height: 0; overflow: hidden; }
.clickable-rows :deep(.ant-table-tbody > tr) { cursor: pointer; }
.clickable-rows :deep(.ant-table-tbody > tr:hover > td) { background: var(--p-content-hover-background) !important; }

/* FAB: fixed to the viewport bottom-right corner, always reachable */
.fab-add {
  position: fixed;
  bottom: 1.75rem;
  right: 1.75rem;
  z-index: 10;
  width: 3.25rem;
  height: 3.25rem;
  border-radius: 50%;
  background: var(--p-primary-color);
  color: var(--p-primary-contrast-color, #fff);
  border: none;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 4px 12px rgba(0,0,0,0.20);
  transition: background 0.15s, transform 0.15s, box-shadow 0.15s;
}
.fab-add:hover {
  background: var(--p-primary-hover-color, var(--p-primary-color));
  transform: scale(1.08);
  box-shadow: 0 6px 18px rgba(0,0,0,0.25);
}
.fab-add .anticon { font-size: 1.3rem; }
</style>
