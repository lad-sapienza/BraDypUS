<template>
  <AppLayout>
    <div class="data-layout">

      <!-- ── Table sidebar ─────────────────────────────────── -->
      <aside class="table-sidebar app-table-sidebar" :class="{ 'sidebar-hidden': sidebarHidden }">
        <div class="table-sidebar-header">
          <span>{{ t('data_mng') }}</span>
          <ProgressSpinner v-if="loadingTables" style="width:18px;height:18px" />
          <!-- close button — small screens only -->
          <button class="sidebar-close-btn" @click="sidebarHidden = true" :title="t('close')">
            <i class="pi pi-times" />
          </button>
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
            :title="tbl.label"
            @click="selectTable(tbl)"
          >
            <i class="pi pi-table" />
            <span>{{ tbl.label }}</span>
          </button>
        </nav>
      </aside>

      <!-- ── Records panel ──────────────────────────────────── -->
      <div class="records-panel">

        <!-- Reopen sidebar button: always visible on small screens when sidebar is collapsed -->
        <div v-if="sidebarHidden" class="sidebar-reopen-bar">
          <button
            class="sidebar-open-btn"
            :title="t('data_mng')"
            @click="sidebarHidden = false"
          >
            <i class="pi pi-list" />
          </button>
        </div>

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

              <!-- Column visibility toggler -->
              <Button
                v-if="columns.length"
                icon="pi pi-table"
                :title="t('preview_fields')"
                size="small"
                severity="secondary"
                text
                @click="colToggler.toggle($event)"
              />
              <Popover ref="colToggler" class="col-toggler-popover">
                <div class="col-toggler-header">{{ t('preview_fields') }}</div>
                <div class="col-toggler-list">
                  <div
                    v-for="col in allAvailableColumns"
                    :key="col.name"
                    class="col-toggler-item"
                    @click="toggleColumn(col.name)"
                  >
                    <i :class="['pi', visibleColumnNames.has(col.name) ? 'pi-check-square' : 'pi-stop']" />
                    <span>{{ col.label }}</span>
                  </div>
                </div>
                <div class="col-toggler-actions">
                  <Button :label="t('select_all')" text size="small" @click="selectAllColumns" />
                  <Button :label="t('reset')" text size="small" severity="secondary" @click="resetColumns" />
                </div>
              </Popover>

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
              v-for="col in displayColumns"
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
import Popover from 'primevue/popover'

const { t } = useI18n()
const toast  = useToast()
const route  = useRoute()
const router = useRouter()
const { responseMessage } = api

// ── Tables ───────────────────────────────────────────────────
const tables        = ref([])
const selectedTable = ref(null)
const loadingTables = ref(false)

// ── Sidebar collapse (small screens only) ────────────────────
const sidebarHidden = ref(false)
const SMALL_SCREEN  = 900  // px — below this the sidebar auto-collapses on selection

// ── Column visibility ─────────────────────────────────────────
const colToggler         = ref()
const visibleColumnNames = ref(new Set())   // Set of field names the user wants to see

/** All fields available for this table (from advConfig, loaded eagerly on table select) */
const allAvailableColumns = computed(() =>
  advFields.value
    .map(f => {
      // advFields value format is "tablename:fieldname"
      const [, name] = f.value.includes(':') ? f.value.split(':') : [null, f.value]
      return { name, label: f.label }
    })
    .filter(f => f.name && f.name !== 'id')
)

/** localStorage key for a given table's column prefs */
function colStorageKey(tbName) { return `bradypus:columns:${tbName}` }

/**
 * Called when a table is selected or advConfig is freshly loaded.
 * Restores saved preferences, or defaults to the preview columns
 * returned by the last getRecords call.
 */
function initVisibleColumns(tbName) {
  const saved = localStorage.getItem(colStorageKey(tbName))
  if (saved) {
    try {
      const arr = JSON.parse(saved)
      if (Array.isArray(arr) && arr.length) {
        visibleColumnNames.value = new Set(arr)
        return
      }
    } catch { /* ignore */ }
  }
  // No saved prefs: use whatever the backend returned as default (preview fields)
  visibleColumnNames.value = new Set(columns.value.map(c => c.name))
}

function saveColumnPrefs(tbName) {
  localStorage.setItem(colStorageKey(tbName), JSON.stringify([...visibleColumnNames.value]))
}

function toggleColumn(name) {
  const s = new Set(visibleColumnNames.value)
  if (s.has(name)) {
    if (s.size === 1) return   // keep at least one column visible
    s.delete(name)
  } else {
    s.add(name)
  }
  visibleColumnNames.value = s
  saveColumnPrefs(selectedTable.value?.name)
  fetchRecords()   // refetch with updated column list
}

function selectAllColumns() {
  visibleColumnNames.value = new Set(allAvailableColumns.value.map(c => c.name))
  saveColumnPrefs(selectedTable.value?.name)
  fetchRecords()
}

function resetColumns() {
  if (!selectedTable.value) return
  localStorage.removeItem(colStorageKey(selectedTable.value.name))
  visibleColumnNames.value = new Set()   // empty → backend uses preview defaults
  fetchRecords()
}

/** Filtered column list actually rendered in the DataTable (from what the backend returned) */
const displayColumns = computed(() =>
  columns.value.filter(c => visibleColumnNames.value.has(c.name))
)

function autoCollapseSidebar() {
  if (window.innerWidth < SMALL_SCREEN) sidebarHidden.value = true
}

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

  const tableChanged = selectedTable.value?.name !== tbParam
  selectedTable.value = tbl

  if (tableChanged) {
    advConfigFor = null
    visibleColumnNames.value = new Set()   // reset → will re-init from prefs/defaults
    autoCollapseSidebar()
    loadAdvConfig()   // eager load all fields for the column toggler
  }

  if (whereParam) {
    shortSqlWhere.value = whereParam
    activeSearch.value  = 'shortSql'
    openPanel.value     = null
    page.value = 1
    fetchRecords()
  } else if (tableChanged) {
    resetSearch()   // also calls fetchRecords
  }
  // If same table, no where param, no change → do nothing (avoids double fetch)
}

// Re-apply whenever the user follows another link while DataView is alive
watch(() => route.query, applyRouteParams)

// ── Select table ─────────────────────────────────────────────
function selectTable(tbl) {
  // Update the URL — applyRouteParams (via watch) will handle the rest.
  // We strip 'where' on manual table selection (user starts fresh).
  router.replace({ query: { tb: tbl.name } })
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
    const tbName   = selectedTable.value.name
    const urlParams = { tb: tbName }

    // Custom column list: passed when the user has an explicit selection.
    // Empty set means "use backend preview defaults" (no param sent).
    const colParam = visibleColumnNames.value.size > 0
      ? [...visibleColumnNames.value]
      : null

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
      const body = {
        page: page.value, per_page: perPage.value,
        sort_field: sortField.value ?? '', sort_dir: sortDir.value,
        search_type: 'advanced', adv,
      }
      if (colParam) body.columns = colParam
      res = await api.post('record_ctrl', 'getRecords', body, urlParams)

    } else if (activeSearch.value === 'expert') {
      const body = {
        page: page.value, per_page: perPage.value,
        sort_field: sortField.value ?? '', sort_dir: sortDir.value,
        search_type: 'sqlExpert', querytext: expertQuery.value, join: '',
      }
      if (colParam) body.columns = colParam
      res = await api.post('record_ctrl', 'getRecords', body, urlParams)

    } else if (activeSearch.value === 'shortSql') {
      // ShortSQL WHERE produced by Record\Read::getLinks() / getBackLinks().
      // Sent as a GET param so the URL is bookmarkable.
      const params = {
        tb:          tbName,
        page:        page.value,
        per_page:    perPage.value,
        sort_field:  sortField.value ?? '',
        sort_dir:    sortDir.value,
        search_type: 'shortSql',
        where:       shortSqlWhere.value,
      }
      if (colParam) params.columns = colParam
      res = await api.get('record_ctrl', 'getRecords', params)

    } else {
      const params = {
        tb:          tbName,
        page:        page.value,
        per_page:    perPage.value,
        sort_field:  sortField.value ?? '',
        sort_dir:    sortDir.value,
        search_type: activeSearch.value === 'fast' ? 'fast' : 'all',
        search:      activeSearch.value === 'fast' ? fastSearch.value : '',
      }
      if (colParam) params.columns = colParam
      res = await api.get('record_ctrl', 'getRecords', params)
    }

    if (res.status === 'error') {
      toast.add({ severity: 'error', summary: t('generic_error'),
        detail: responseMessage(res, t), life: 6000 })
      return
    }

    totalRecords.value = res.total ?? 0
    if (res.fields?.length) {
      columns.value = res.fields.filter(f => f.name !== 'id')
      // If no saved preference yet, initialise from returned preview columns
      if (visibleColumnNames.value.size === 0) {
        initVisibleColumns(selectedTable.value?.name)
      }
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
  flex: 1;          /* fill the flex-column main-content area */
  min-height: 0;    /* allow shrink below content size */
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
  transition: width 0.2s ease, opacity 0.2s ease;
}

/* Collapsed state — only active below 900 px (enforced via JS flag) */
.table-sidebar.sidebar-hidden {
  width: 0;
  opacity: 0;
  pointer-events: none;
  border-right: none;
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
  gap: 0.5rem;
}

/* Close button: visible only on small screens */
.sidebar-close-btn {
  display: none;
  border: none;
  background: transparent;
  cursor: pointer;
  color: var(--p-text-muted-color);
  padding: 0.2rem;
  border-radius: 4px;
  line-height: 1;
  margin-left: auto;
  flex-shrink: 0;
}
.sidebar-close-btn:hover { color: var(--p-text-color); background: var(--p-surface-hover); }

@media (max-width: 899px) {
  .sidebar-close-btn { display: flex; align-items: center; }
}

/* Reopen bar: sits at top of records-panel, visible on small screens when sidebar is hidden */
.sidebar-reopen-bar {
  display: none;
  flex-shrink: 0;
  padding: 0.35rem 0.75rem;
  border-bottom: 1px solid var(--p-surface-border);
}
@media (max-width: 899px) {
  .sidebar-reopen-bar { display: flex; }
}

/* Reopen button */
.sidebar-open-btn {
  display: none;
  border: none;
  background: transparent;
  cursor: pointer;
  color: var(--p-text-muted-color);
  padding: 0.3rem 0.4rem;
  border-radius: 4px;
  font-size: 1rem;
  line-height: 1;
  flex-shrink: 0;
}
.sidebar-open-btn:hover { color: var(--p-text-color); background: var(--p-surface-hover); }

@media (max-width: 899px) {
  .sidebar-open-btn { display: flex; align-items: center; }
}

.table-sidebar-empty {
  padding: 1rem;
  font-size: 0.85rem;
  color: var(--p-text-muted-color);
}

.table-nav { flex: 1; overflow-y: auto; padding: 0.25rem 0; }

.table-nav-item {
  display: flex;
  align-items: flex-start;  /* icon stays top-aligned on multi-line labels */
  gap: 0.6rem;
  width: 100%;
  padding: 0.5rem 1rem;
  border: none;
  background: transparent;
  text-align: left;
  font-size: 0.875rem;
  line-height: 1.35;
  color: var(--p-text-color);
  cursor: pointer;
  transition: background 0.15s;
}

/* Icon: aligned top, fixed size, nudge for optical alignment with first line */
.table-nav-item .pi {
  flex-shrink: 0;
  font-size: 0.85rem;
  margin-top: 0.1rem;
}

/* Label: wrap naturally within sidebar width, cap at two lines */
.table-nav-item span {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.table-nav-item:hover  { background: var(--p-surface-hover); }
.table-nav-item.active { background: var(--p-primary-50); color: var(--p-primary-color); font-weight: 600; }

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

/* ── Column toggler popover ──────────────────────────────── */
:deep(.col-toggler-popover) { min-width: 200px; }

.col-toggler-header {
  font-size: 0.7rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--p-text-muted-color);
  padding: 0.5rem 0.75rem 0.25rem;
}

.col-toggler-list {
  max-height: 260px;
  overflow-y: auto;
  padding: 0.25rem 0;
}

.col-toggler-item {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.35rem 0.75rem;
  cursor: pointer;
  font-size: 0.875rem;
  border-radius: 4px;
  transition: background 0.12s;
}
.col-toggler-item:hover { background: var(--p-surface-hover); }
.col-toggler-item .pi   { color: var(--p-primary-color); font-size: 0.95rem; }

.col-toggler-actions {
  display: flex;
  gap: 0.25rem;
  padding: 0.4rem 0.5rem 0.25rem;
  border-top: 1px solid var(--p-surface-border);
}
</style>
