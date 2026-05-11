<template>
  <AppLayout>
    <div class="data-layout">

      <!-- ── Table sidebar ─────────────────────────────────── -->
      <aside class="table-sidebar app-table-sidebar">
        <div class="table-sidebar-header">
          <span>{{ t('data_mng') }}</span>
          <ProgressSpinner v-if="loadingTables" style="width:18px;height:18px" />
        </div>
        <div v-if="tables.length === 0 && !loadingTables" class="table-sidebar-empty">
          {{ t('no_record_found') }}
        </div>
        <nav class="table-nav">
          <button
            v-for="tbl in tables"
            :key="tbl.name"
            class="table-nav-item"
            :class="{ active: selectedTable?.name === tbl.name }"
            @click="selectTable(tbl)"
          >
            <i class="pi pi-table" />
            <span>{{ tbl.label }}</span>
          </button>
        </nav>
      </aside>

      <!-- ── Records panel ──────────────────────────────────── -->
      <div class="records-panel">

        <div v-if="!selectedTable" class="records-placeholder">
          <i class="pi pi-arrow-left" />
          <p>{{ t('choose_db') }}</p>
        </div>

        <template v-else>

          <!-- ── Search bar ──────────────────────────────────── -->
          <div class="search-area">

            <!-- Row 1: always visible -->
            <div class="search-bar">
              <IconField class="search-input-wrap">
                <InputIcon class="pi pi-search" />
                <InputText
                  v-model="fastSearch"
                  :placeholder="t('fast_search')"
                  @keyup.enter="runFastSearch"
                  fluid
                />
              </IconField>
              <Button :label="t('send')" size="small" @click="runFastSearch" />

              <Divider layout="vertical" />

              <Button
                icon="pi pi-sliders-h"
                :title="t('advanced_search')"
                size="small"
                :severity="openPanel === 'advanced' ? 'primary' : 'secondary'"
                text
                @click="togglePanel('advanced')"
              />
              <Button
                icon="pi pi-code"
                :title="t('sql_expert_search')"
                size="small"
                :severity="openPanel === 'expert' ? 'primary' : 'secondary'"
                text
                @click="togglePanel('expert')"
              />

              <Tag
                v-if="activeSearch"
                severity="warn"
                class="search-active-tag"
              >
                <template #default>
                  <span>{{ activeSearchLabel }}</span>
                  <i class="pi pi-times" style="cursor:pointer;margin-left:0.4rem" @click="resetSearch" />
                </template>
              </Tag>
            </div>

            <!-- ── Advanced search panel ──────────────────────── -->
            <Transition name="slide">
              <div v-if="openPanel === 'advanced'" class="search-panel">

                <div v-if="loadingAdvConfig" class="adv-loading">
                  <ProgressSpinner style="width:22px;height:22px" />
                </div>

                <template v-else>
                  <!-- Row builder -->
                  <div class="adv-rows">
                    <div v-for="(row, idx) in advRows" :key="row._id" class="adv-row">

                      <!-- Connector (hidden for first row) -->
                      <Select
                        v-if="idx > 0"
                        v-model="row.connector"
                        :options="advConnectors"
                        size="small"
                        class="adv-connector"
                      />
                      <span v-else class="adv-connector-placeholder" />

                      <!-- Opening bracket -->
                      <Button
                        :icon="row.open ? 'pi pi-circle-fill' : 'pi pi-circle'"
                        size="small" text
                        :severity="row.open ? 'primary' : 'secondary'"
                        :title="'('"
                        class="adv-bracket"
                        @click="row.open = !row.open"
                      >
                        <template #default><span class="bracket-label">(</span></template>
                      </Button>

                      <!-- Field -->
                      <Select
                        v-model="row.fld"
                        :options="advFields"
                        optionLabel="label"
                        optionValue="value"
                        :placeholder="t('adv_pick_field')"
                        size="small"
                        filter
                        class="adv-field-sel"
                        @change="() => { row.value = ''; row._values = null }"
                      />

                      <!-- Operator: key is the i18n locale key, value is the SQL operator -->
                      <Select
                        v-model="row.operator"
                        :options="advOperatorsForDisplay"
                        optionLabel="label"
                        optionValue="value"
                        size="small"
                        class="adv-operator"
                      />

                      <!-- Value -->
                      <AutoComplete
                        v-if="!['is_empty','is_not_empty'].includes(row.operator)"
                        v-model="row.value"
                        :suggestions="row._suggestions ?? []"
                        :disabled="['is_empty','is_not_empty'].includes(row.operator)"
                        size="small"
                        class="adv-value"
                        @complete="q => loadSuggestions(row, q.query)"
                      />
                      <span v-else class="adv-value" />

                      <!-- Closing bracket -->
                      <Button
                        size="small" text
                        :severity="row.close ? 'primary' : 'secondary'"
                        :title="')'"
                        class="adv-bracket"
                        @click="row.close = !row.close"
                      >
                        <template #default><span class="bracket-label">)</span></template>
                      </Button>

                      <!-- Remove row -->
                      <Button
                        icon="pi pi-minus"
                        size="small" text severity="danger"
                        :disabled="advRows.length === 1"
                        @click="removeAdvRow(idx)"
                      />
                    </div>
                  </div>

                  <!-- Actions -->
                  <div class="search-panel-actions">
                    <Button
                      icon="pi pi-plus"
                      :label="t('adv_add_row')"
                      size="small" severity="secondary" outlined
                      @click="addAdvRow"
                    />
                    <Button
                      :label="t('advanced_search')"
                      icon="pi pi-search"
                      size="small"
                      @click="runAdvancedSearch"
                    />
                    <Button
                      :label="t('reset')"
                      icon="pi pi-times"
                      size="small" severity="secondary" text
                      @click="resetSearch"
                    />
                  </div>
                </template>
              </div>
            </Transition>

            <!-- ── SQL Expert panel ────────────────────────────── -->
            <Transition name="slide">
              <div v-if="openPanel === 'expert'" class="search-panel">
                <label class="expert-label">{{ t('sql_expert_search') }} — WHERE …</label>
                <Textarea
                  v-model="expertQuery"
                  :placeholder="`cmclid LIKE 'P.Bodm%' AND dialect = 'coptic'`"
                  rows="3"
                  fluid
                  class="expert-textarea"
                />
                <div class="search-panel-actions">
                  <Button :label="t('send')" icon="pi pi-search" size="small" @click="runExpertSearch" />
                  <Button :label="t('reset')" icon="pi pi-times" size="small" severity="secondary" text @click="resetSearch" />
                </div>
              </div>
            </Transition>

          </div>
          <!-- /search-area -->

          <!-- ── Results header ──────────────────────────────── -->
          <div class="records-header">
            <h3>{{ selectedTable.label }}</h3>
            <span class="records-count" v-if="!loadingRecords">
              {{ t('x_record_found', String(totalRecords)) }}
            </span>
          </div>

          <!-- ── DataTable ───────────────────────────────────── -->
          <DataTable
            :value="records"
            lazy
            paginator
            :rows="perPage"
            :totalRecords="totalRecords"
            :loading="loadingRecords"
            :rowsPerPageOptions="[15, 30, 50, 100]"
            sortMode="single"
            removableSort
            scrollable
            scrollHeight="flex"
            size="small"
            class="records-table clickable-rows"
            @page="onPage"
            @sort="onSort"
            @row-click="onRowClick"
          >
            <template #empty>{{ t('no_record_found') }}</template>
            <Column
              v-for="col in columns"
              :key="col.name"
              :field="col.name"
              :header="col.label"
              sortable
              style="min-width: 120px"
            >
              <template #body="{ data }">
                <span class="cell-value" :title="data[col.name]">{{ data[col.name] }}</span>
              </template>
            </Column>
          </DataTable>

        </template>
      </div>
    </div>

  </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import { api } from '@/api'
import { useI18n } from '@/i18n'
import AppLayout from '@/components/AppLayout.vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import IconField from 'primevue/iconfield'
import InputIcon from 'primevue/inputicon'
import Textarea from 'primevue/textarea'
import Divider from 'primevue/divider'
import Tag from 'primevue/tag'
import Select from 'primevue/select'
import AutoComplete from 'primevue/autocomplete'
import ProgressSpinner from 'primevue/progressspinner'

const { t } = useI18n()
const toast  = useToast()
const route  = useRoute()
const router = useRouter()
const { responseMessage } = api

// ── Tables ───────────────────────────────────────────────────
const tables        = ref([])
const selectedTable = ref(null)
const loadingTables = ref(false)

// ── Records ──────────────────────────────────────────────────
const records        = ref([])
const columns        = ref([])
const totalRecords   = ref(0)
const loadingRecords = ref(false)
const page           = ref(1)
const perPage        = ref(30)
const sortField      = ref(null)
const sortDir        = ref('asc')

// ── Search state ─────────────────────────────────────────────
const openPanel      = ref(null)   // null | 'advanced' | 'expert'
const fastSearch     = ref('')
const expertQuery    = ref('')
const shortSqlWhere  = ref('')     // ShortSQL WHERE from link navigation (not user-editable)
const activeSearch   = ref(null)   // null | 'fast' | 'advanced' | 'expert' | 'shortSql'

// ── Advanced search config (lazy-loaded per table) ───────────
const loadingAdvConfig = ref(false)
const advFields        = ref([])
const advOperators     = ref([])   // raw from backend: [{ value, key }, ...]
const advConnectors    = ref([])   // raw from backend: ['AND', 'OR', 'XOR']
let   advConfigFor     = null      // track which table the config was loaded for

// Operators with translated labels for the dropdown
const advOperatorsForDisplay = computed(() =>
  advOperators.value.map(op => ({ value: op.value, label: t(op.key) }))
)

// ── Advanced search rows ─────────────────────────────────────
let _rowId = 0
function newAdvRow() {
  return { _id: _rowId++, connector: 'AND', open: false, fld: '', operator: 'LIKE', value: '', close: false, _suggestions: null }
}
const advRows = ref([newAdvRow()])

function addAdvRow()        { advRows.value.push(newAdvRow()) }
function removeAdvRow(idx)  { advRows.value.splice(idx, 1) }

// ── Labels ───────────────────────────────────────────────────
const activeSearchLabel = computed(() => {
  if (activeSearch.value === 'fast')     return `"${fastSearch.value}"`
  if (activeSearch.value === 'advanced') return t('advanced_search')
  if (activeSearch.value === 'expert')   return t('sql_expert_search')
  if (activeSearch.value === 'shortSql') return t('linked_records')
  return ''
})

// ── Load tables, then apply any URL params ─────────────────────
onMounted(async () => {
  loadingTables.value = true
  try {
    const res = await api.get('home_ctrl', 'listTables')
    tables.value = res.tables ?? []
    applyRouteParams()
  } catch {
    toast.add({ severity: 'error', summary: 'Error', detail: 'Could not load tables', life: 3000 })
  } finally {
    loadingTables.value = false
  }
})

/**
 * Apply `tb` and `where` query params from the current URL.
 *
 * Called on mount (tables freshly loaded) and whenever the route query
 * changes (user navigates from one record's links to another record's links
 * while DataView is already mounted).
 *
 * `where` is a ShortSQL expression produced by Record\Read::getLinks() /
 * getBackLinks() — e.g. "id|=|713" or "^ctx_id|=|42||and|site|=|Rome".
 * We feed it as an expert query to record_ctrl::getRecords().
 */
function applyRouteParams() {
  const tbParam    = route.query.tb
  const whereParam = route.query.where

  if (!tbParam) return

  const tbl = tables.value.find(t => t.name === tbParam)
  if (!tbl) return   // unknown table (permissions or typo)

  selectedTable.value = tbl
  advConfigFor        = null

  if (whereParam) {
    shortSqlWhere.value = whereParam
    activeSearch.value  = 'shortSql'
    openPanel.value     = null
  } else {
    resetSearch()
    return   // resetSearch already calls fetchRecords
  }

  page.value = 1
  fetchRecords()
}

// Re-apply whenever the user follows another link while DataView is alive
watch(() => route.query, applyRouteParams)

// ── Select table ─────────────────────────────────────────────
function selectTable(tbl) {
  selectedTable.value = tbl
  advConfigFor = null   // force config reload for new table
  resetSearch()
  fetchRecords()
}

// ── Panel toggle ──────────────────────────────────────────────
async function togglePanel(name) {
  openPanel.value = openPanel.value === name ? null : name
  if (openPanel.value === 'advanced') {
    await loadAdvConfig()
  }
}

// ── Lazy-load advanced config (fields, operators, connectors) ─
async function loadAdvConfig() {
  const tb = selectedTable.value?.name
  if (!tb || advConfigFor === tb) return
  loadingAdvConfig.value = true
  try {
    const res = await api.get('search_ctrl', 'getAdvancedConfig', { tb })
    if (res.status === 'error') throw new Error(responseMessage(res, t))
    advFields.value     = res.fields     ?? []
    advOperators.value  = res.operators  ?? []   // [{ value, key }]
    advConnectors.value = res.connectors ?? []   // ['AND', 'OR', 'XOR']
    advConfigFor = tb
  } catch (e) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.message, life: 4000 })
  } finally {
    loadingAdvConfig.value = false
  }
}

// ── Autocomplete: load distinct values for a field ───────────
async function loadSuggestions(row, query) {
  if (!row.fld) return
  const [tb, fld] = row.fld.split(':')
  try {
    const values = await api.get('search_ctrl', 'getUsedValues', { tb, fld })
    row._suggestions = (Array.isArray(values) ? values : [])
      .filter(v => v != null && String(v).toLowerCase().includes(query.toLowerCase()))
      .slice(0, 50)
  } catch {
    row._suggestions = []
  }
}

// ── Reset all search ─────────────────────────────────────────
function resetSearch() {
  fastSearch.value    = ''
  expertQuery.value   = ''
  shortSqlWhere.value = ''
  advRows.value       = [newAdvRow()]
  activeSearch.value  = null
  openPanel.value     = null
  page.value          = 1
  fetchRecords()
}

// ── Fast search ───────────────────────────────────────────────
function runFastSearch() {
  if (!fastSearch.value.trim()) { resetSearch(); return }
  activeSearch.value = 'fast'
  openPanel.value    = null
  page.value         = 1
  fetchRecords()
}

// ── Advanced search ───────────────────────────────────────────
function runAdvancedSearch() {
  const adv = advRows.value
    .filter(r => r.fld && (r.value || ['is_empty', 'is_not_empty'].includes(r.operator)))
    .map((r, i) => ({
      connector: i === 0 ? '' : r.connector,
      '(':       r.open  ? true : false,
      fld:       r.fld,
      operator:  r.operator,
      value:     r.value ?? '',
      ')':       r.close ? true : false,
    }))
  if (adv.length === 0) { resetSearch(); return }
  activeSearch.value = 'advanced'
  openPanel.value    = null
  page.value         = 1
  fetchRecords()
}

// ── Expert search ─────────────────────────────────────────────
function runExpertSearch() {
  if (!expertQuery.value.trim()) { resetSearch(); return }
  activeSearch.value = 'expert'
  openPanel.value    = null
  page.value         = 1
  fetchRecords()
}

// ── Core fetch ────────────────────────────────────────────────
async function fetchRecords() {
  if (!selectedTable.value) return
  loadingRecords.value = true
  try {
    let res
    const urlParams = { tb: selectedTable.value.name }

    if (activeSearch.value === 'advanced') {
      const adv = advRows.value
        .filter(r => r.fld && (r.value || ['is_empty', 'is_not_empty'].includes(r.operator)))
        .map((r, i) => ({
          connector: i === 0 ? '' : r.connector,
          '(':       r.open  ? true : false,
          fld:       r.fld,
          operator:  r.operator,
          value:     r.value ?? '',
          ')':       r.close ? true : false,
        }))
      res = await api.post('record_ctrl', 'getRecords', {
        page: page.value, per_page: perPage.value,
        sort_field: sortField.value ?? '', sort_dir: sortDir.value,
        search_type: 'advanced', adv,
      }, urlParams)

    } else if (activeSearch.value === 'expert') {
      res = await api.post('record_ctrl', 'getRecords', {
        page: page.value, per_page: perPage.value,
        sort_field: sortField.value ?? '', sort_dir: sortDir.value,
        search_type: 'sqlExpert', querytext: expertQuery.value, join: '',
      }, urlParams)

    } else if (activeSearch.value === 'shortSql') {
      // ShortSQL WHERE produced by Record\Read::getLinks() / getBackLinks().
      // Sent as a GET param so the URL is bookmarkable.
      res = await api.get('record_ctrl', 'getRecords', {
        tb:          selectedTable.value.name,
        page:        page.value,
        per_page:    perPage.value,
        sort_field:  sortField.value ?? '',
        sort_dir:    sortDir.value,
        search_type: 'shortSql',
        where:       shortSqlWhere.value,
      })

    } else {
      res = await api.get('record_ctrl', 'getRecords', {
        tb:          selectedTable.value.name,
        page:        page.value,
        per_page:    perPage.value,
        sort_field:  sortField.value ?? '',
        sort_dir:    sortDir.value,
        search_type: activeSearch.value === 'fast' ? 'fast' : 'all',
        search:      activeSearch.value === 'fast' ? fastSearch.value : '',
      })
    }

    if (res.status === 'error') {
      toast.add({ severity: 'error', summary: t('generic_error'),
        detail: responseMessage(res, t), life: 6000 })
      return
    }

    totalRecords.value = res.total ?? 0
    if (res.fields?.length) {
      columns.value = res.fields.filter(f => f.name !== 'id')
    }
    records.value = res.data ?? []

  } catch (e) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.message, life: 4000 })
  } finally {
    loadingRecords.value = false
  }
}

function onPage(event) {
  page.value    = event.page + 1
  perPage.value = event.rows
  fetchRecords()
}

function onSort(event) {
  sortField.value = event.sortField ?? null
  sortDir.value   = event.sortOrder === -1 ? 'desc' : 'asc'
  page.value      = 1
  fetchRecords()
}

function onRowClick(event) {
  const tb = selectedTable.value?.name
  const id = event.data?.id
  if (tb && id != null) {
    router.push(`/record/${encodeURIComponent(tb)}/${id}`)
  }
}
</script>

<style scoped>
.data-layout {
  display: flex;
  height: 100%;
  overflow: hidden;
}

/* ── Table sidebar ───────────────────────────────────────── */
.table-sidebar {
  width: 200px;
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  border-right: 1px solid var(--p-surface-border);
}

.table-sidebar-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.75rem 1rem;
  font-size: 0.7rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.07em;
  color: var(--p-text-muted-color);
  border-bottom: 1px solid var(--p-surface-border);
  flex-shrink: 0;
}

.table-sidebar-empty {
  padding: 1rem;
  font-size: 0.85rem;
  color: var(--p-text-muted-color);
}

.table-nav { flex: 1; overflow-y: auto; padding: 0.25rem 0; }

.table-nav-item {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  width: 100%;
  padding: 0.55rem 1rem;
  border: none;
  background: transparent;
  text-align: left;
  font-size: 0.875rem;
  color: var(--p-text-color);
  cursor: pointer;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  transition: background 0.15s;
}
.table-nav-item:hover  { background: var(--p-surface-hover); }
.table-nav-item.active { background: var(--p-primary-50); color: var(--p-primary-color); font-weight: 600; }
.table-nav-item .pi    { flex-shrink: 0; font-size: 0.85rem; }

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
.records-placeholder .pi { font-size: 2rem; }

/* ── Search area ─────────────────────────────────────────── */
.search-area {
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  gap: 0;
  border: 1px solid var(--p-surface-border);
  border-radius: 6px;
  overflow: hidden;
}

.search-bar {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.5rem 0.75rem;
  background: var(--p-surface-card, #fff);
}

.search-input-wrap { flex: 1; min-width: 0; }

.search-active-tag {
  margin-left: auto;
  display: flex;
  align-items: center;
  gap: 0.3rem;
  white-space: nowrap;
}

/* ── Collapsible panels ──────────────────────────────────── */
.search-panel {
  padding: 0.75rem 1rem;
  border-top: 1px solid var(--p-surface-border);
  background: var(--p-surface-ground, #f8f9fa);
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  max-height: 360px;
  overflow-y: auto;
}

/* ── Advanced search rows ────────────────────────────────── */
.adv-loading {
  display: flex;
  justify-content: center;
  padding: 1rem;
}

.adv-rows {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.adv-row {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  flex-wrap: nowrap;
}

.adv-connector          { width: 5rem;  flex-shrink: 0; }
.adv-connector-placeholder { width: 5rem; flex-shrink: 0; }
.adv-bracket            { flex-shrink: 0; padding: 0.1rem 0.25rem !important; }
.bracket-label          { font-weight: 700; font-size: 1rem; line-height: 1; }
.adv-field-sel          { flex: 2; min-width: 0; }
.adv-operator           { width: 8.5rem; flex-shrink: 0; }
.adv-value              { flex: 1.5; min-width: 0; }

:deep(.adv-value .p-autocomplete),
:deep(.adv-value .p-autocomplete-input) { width: 100%; }

/* ── Expert panel ────────────────────────────────────────── */
.expert-label {
  font-size: 0.82rem;
  font-weight: 500;
  color: var(--p-text-muted-color);
}
.expert-textarea { font-family: monospace; font-size: 0.85rem; }

/* ── Actions row ─────────────────────────────────────────── */
.search-panel-actions {
  display: flex;
  gap: 0.5rem;
  flex-shrink: 0;
}

/* ── Slide transition ────────────────────────────────────── */
.slide-enter-active, .slide-leave-active {
  transition: max-height 0.22s ease, opacity 0.22s ease;
  overflow: hidden;
}
.slide-enter-from, .slide-leave-to { max-height: 0; opacity: 0; }
.slide-enter-to, .slide-leave-from { max-height: 380px; }

/* ── Results header ──────────────────────────────────────── */
.records-header {
  display: flex;
  align-items: baseline;
  gap: 1rem;
  flex-shrink: 0;
}
.records-header h3 { font-size: 1.1rem; font-weight: 700; }
.records-count     { font-size: 0.82rem; color: var(--p-text-muted-color); }

.records-table { flex: 1; min-height: 0; }
.clickable-rows :deep(tbody tr) { cursor: pointer; }
.clickable-rows :deep(tbody tr:hover td) { background: var(--p-surface-hover); }

.cell-value {
  display: block;
  max-width: 260px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
