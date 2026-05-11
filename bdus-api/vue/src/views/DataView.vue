<template>
  <AppLayout>
    <div class="data-layout">

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

              <!-- Export -->
              <Button
                v-if="totalRecords > 0"
                icon="pi pi-download"
                :title="t('export')"
                size="small"
                severity="secondary"
                text
                @click="exportPopover.toggle($event)"
              />
              <Popover ref="exportPopover" class="export-popover">
                <div class="col-toggler-header">{{ t('export') }} ({{ totalRecords }} {{ t('records') }})</div>
                <div class="col-toggler-list">
                  <div class="col-toggler-item" @click="doExport('csv')">
                    <i class="pi pi-file" />
                    <span>CSV</span>
                  </div>
                  <div class="col-toggler-item" @click="doExport('xlsx')">
                    <i class="pi pi-file-excel" />
                    <span>XLSX</span>
                  </div>
                  <div class="col-toggler-item" @click="doExport('json')">
                    <i class="pi pi-file-code" />
                    <span>JSON</span>
                  </div>
                </div>
              </Popover>
              <Popover ref="colToggler" class="col-toggler-popover">
                <div class="col-toggler-header">{{ t('preview_fields') }}</div>
                <div class="col-toggler-list">
                  <div
                    v-for="col in allAvailableColumns"
                    :key="col.name"
                    class="col-toggler-item"
                    @click="toggleColumn(col.name)"
                  >
                    <i :class="['pi', visibleColumnNames.includes(col.name) ? 'pi-check-square' : 'pi-stop']" />
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

              <!-- Add record — only for users with add_new privilege -->
              <Button
                v-if="canAdd"
                :label="t('new_record')"
                icon="pi pi-plus"
                size="small"
                severity="primary"
                class="add-record-btn"
                @click="addRecord"
              />
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
          <!--
            :key uses the SORTED column set, so:
            • Adding/removing a column → set changes → full remount (fixes PrimeVue
              scrollable header/body sync issue when columns are added dynamically).
            • Reordering columns → sorted set is unchanged → no remount, PrimeVue's
              native drag-reorder works within the existing DataTable instance.
          -->
          <DataTable
            :key="visibleColumnNames.slice().sort().join(',')"
            :value="records"
            lazy
            paginator
            :rows="perPage"
            :totalRecords="totalRecords"
            :loading="loadingRecords"
            :rowsPerPageOptions="[15, 30, 50, 100]"
            sortMode="single"
            removableSort
            reorderableColumns
            scrollable
            scrollHeight="flex"
            size="small"
            class="records-table clickable-rows"
            @page="onPage"
            @sort="onSort"
            @row-click="onRowClick"
            @column-reorder="onColumnReorder"
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

        <!-- FAB — only for users with add_new privilege -->
        <button
          v-if="canAdd && selectedTable"
          class="fab-add"
          :title="t('new_record')"
          @click="addRecord"
        >
          <i class="pi pi-plus" />
        </button>

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
import { useTables } from '@/composables/useTables'
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

// ── Tables (shared singleton composable) ─────────────────────
const { tables, loadTables } = useTables()

// ── Selected table: derived from route query ──────────────────
const selectedTable = computed(() =>
  tables.value.find(tbl => tbl.name === route.query.tb) ?? null
)

// ── Column visibility & order ────────────────────────────────
const colToggler    = ref()
const exportPopover = ref()

/**
 * Ordered array of visible field names.
 * Using an ordered array (not a Set) so that both visibility and column
 * order are stored in a single structure and persisted to localStorage.
 */
const visibleColumnNames = ref([])

/**
 * All main-table fields available for column toggling.
 * advFields value format: "tablename:fieldname" — for both main table
 * and plugin tables. We keep only entries belonging to the selected table
 * (prefix = selectedTable.name + ':') to avoid passing plugin field names
 * to getRecords where they would cause SQL errors.
 */
const allAvailableColumns = computed(() => {
  const tb = selectedTable.value?.name
  if (!tb || !advFields.value.length) return []
  return advFields.value
    .filter(f => f.value.startsWith(tb + ':'))
    .map(f => ({ name: f.value.split(':')[1], label: f.label }))
    .filter(f => f.name && f.name !== 'id')
})

/** localStorage key for a given table's column prefs */
function colStorageKey(tbName) { return `bradypus:columns:${tbName}` }

/**
 * Called when there are no saved prefs (initial first visit to a table).
 * Populates visibleColumnNames from the preview columns the backend returned.
 */
function initVisibleColumns(tbName) {
  const saved = localStorage.getItem(colStorageKey(tbName))
  if (saved) {
    try {
      const arr = JSON.parse(saved)
      if (Array.isArray(arr) && arr.length) {
        visibleColumnNames.value = arr
        return
      }
    } catch { /* ignore */ }
  }
  // No saved prefs: use whatever the backend returned as default (preview fields)
  visibleColumnNames.value = columns.value.map(c => c.name)
}

function saveColumnPrefs(tbName) {
  localStorage.setItem(colStorageKey(tbName), JSON.stringify(visibleColumnNames.value))
}

function toggleColumn(name) {
  const arr = [...visibleColumnNames.value]
  const idx = arr.indexOf(name)
  if (idx >= 0) {
    if (arr.length === 1) return   // keep at least one column visible
    arr.splice(idx, 1)
  } else {
    arr.push(name)
  }
  visibleColumnNames.value = arr
  saveColumnPrefs(selectedTable.value?.name)
  fetchRecords()   // refetch with updated column list
}

/**
 * Called when the user drags a column header to reorder.
 * dragIndex / dropIndex are positions in the rendered displayColumns array.
 * We reorder visibleColumnNames to match and save — no refetch needed.
 */
function onColumnReorder(event) {
  const arr = [...visibleColumnNames.value]
  const moved = arr.splice(event.dragIndex, 1)[0]
  arr.splice(event.dropIndex, 0, moved)
  visibleColumnNames.value = arr
  saveColumnPrefs(selectedTable.value?.name)
}

function selectAllColumns() {
  visibleColumnNames.value = allAvailableColumns.value.map(c => c.name)
  saveColumnPrefs(selectedTable.value?.name)
  fetchRecords()
}

function resetColumns() {
  if (!selectedTable.value) return
  localStorage.removeItem(colStorageKey(selectedTable.value.name))
  visibleColumnNames.value = []   // empty → backend uses preview defaults
  fetchRecords()
}

/**
 * Columns actually rendered in the DataTable.
 * Order follows visibleColumnNames (user's saved/drag order).
 * Only entries that the backend actually returned are included —
 * newly-added columns appear here after the fetch completes.
 */
const displayColumns = computed(() =>
  visibleColumnNames.value
    .map(name => columns.value.find(c => c.name === name))
    .filter(Boolean)
)

// ── Records ──────────────────────────────────────────────────
const records        = ref([])
const columns        = ref([])
const totalRecords   = ref(0)
const loadingRecords = ref(false)
const canAdd         = ref(false)   // backend-controlled: utils::canUser('add_new')
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

// ── Init: load tables (from singleton) then apply URL params ──
onMounted(async () => {
  try {
    await loadTables()
    applyRouteParams()
  } catch {
    toast.add({ severity: 'error', summary: 'Error', detail: 'Could not load tables', life: 3000 })
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
// Track last applied params to avoid redundant fetches triggered by our own
// router.replace() calls (filter URL persistence). The guard compares all four
// relevant params; if all match, the change originated from updateFilterUrl()
// and there is nothing to re-fetch.
//
// NOTE: we intentionally do NOT use a boolean "ignoreNextRouteChange" flag here.
// Vue 3 batches reactive updates within the same tick, so if the user switches
// tables immediately after a filter update, the watcher fires only once with the
// final URL. A boolean flag would have caused that table switch to be silently
// ignored. The lastApplied* comparison is safe in all cases:
//   - updateFilterUrl() pre-updates lastAppliedQt/Q before router.replace(), so
//     when the watcher fires for the replace, all four values match → skip ✓
//   - a genuine table switch changes tbParam → mismatch → proceeds ✓
let lastAppliedTb    = null
let lastAppliedWhere = null
let lastAppliedQt    = null
let lastAppliedQ     = null

/**
 * Push the current filter type + value into the URL (for bookmarking / back-nav).
 * Uses router.replace() so the URL changes without adding a history entry.
 * Pre-updates lastAppliedQt/Q so the watcher's guard skips the resulting change.
 */
function updateFilterUrl(type, query) {
  const newQuery = { tb: route.query.tb }
  if (route.query.where) newQuery.where = route.query.where
  if (type && query != null) { newQuery.qt = type; newQuery.q = query }
  lastAppliedQt = newQuery.qt ?? null
  lastAppliedQ  = newQuery.q  ?? null
  router.replace({ query: newQuery })
}

function applyRouteParams() {

  const tbParam    = route.query.tb
  const whereParam = route.query.where ?? null
  const qtParam    = route.query.qt    ?? null
  const qParam     = route.query.q     ?? null

  if (!tbParam) return

  // Guard: skip if nothing changed
  if (
    tbParam    === lastAppliedTb    &&
    whereParam === lastAppliedWhere &&
    qtParam    === lastAppliedQt    &&
    qParam     === lastAppliedQ
  ) return

  // Table must exist in the list (permissions or typo check)
  const tbl = tables.value.find(t => t.name === tbParam)
  if (!tbl) return

  const tableChanged = lastAppliedTb !== tbParam
  lastAppliedTb    = tbParam
  lastAppliedWhere = whereParam
  lastAppliedQt    = qtParam
  lastAppliedQ     = qParam

  if (tableChanged) {
    advConfigFor = null

    // Restore saved column preferences NOW so that the first fetchRecords()
    // call already uses the full saved set as colParam.
    // If we left visibleColumnNames empty here and restored only inside
    // initVisibleColumns() (which runs after the fetch), the first fetch would
    // use preview mode and columns.value would only contain preview fields —
    // causing a mismatch where saved non-preview columns appear checked in the
    // toggler but are absent from the DataTable.
    const saved = localStorage.getItem(colStorageKey(tbParam))
    let restoredFromStorage = false
    if (saved) {
      try {
        const arr = JSON.parse(saved)
        if (Array.isArray(arr) && arr.length) {
          visibleColumnNames.value = arr
          restoredFromStorage = true
        }
      } catch { /* ignore corrupted data */ }
    }
    if (!restoredFromStorage) {
      // No saved prefs: leave empty so initVisibleColumns() can populate
      // from the backend preview fields after the first fetch.
      visibleColumnNames.value = []
    }

    loadAdvConfig()   // eager load all fields for the column toggler
  }

  if (whereParam) {
    shortSqlWhere.value = whereParam
    activeSearch.value  = 'shortSql'
    openPanel.value     = null
    page.value = 1
    fetchRecords()
    return
  }

  // Restore persisted filter from URL params (e.g. when navigating back from a record)
  if (qtParam && qParam != null) {
    if (qtParam === 'fast') {
      fastSearch.value   = qParam
      activeSearch.value = 'fast'
      openPanel.value    = null
      page.value         = 1
      fetchRecords()
    } else if (qtParam === 'expert') {
      expertQuery.value  = qParam
      activeSearch.value = 'expert'
      openPanel.value    = 'expert'
      page.value         = 1
      fetchRecords()
    } else if (qtParam === 'advanced') {
      try {
        const rows = JSON.parse(atob(qParam))
        if (Array.isArray(rows) && rows.length) {
          advRows.value      = rows.map(r => ({ _id: _rowId++, _suggestions: null, ...r }))
          activeSearch.value = 'advanced'
          openPanel.value    = 'advanced'
          page.value         = 1
          fetchRecords()
          return
        }
      } catch { /* fall through to resetSearch */ }
    }
    return
  }

  if (tableChanged) {
    resetSearch()   // also calls fetchRecords
  }
}

// Re-apply whenever route query changes
watch(() => route.query, applyRouteParams)

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
  updateFilterUrl(null, null)
  fetchRecords()
}

// ── Fast search ───────────────────────────────────────────────
function runFastSearch() {
  if (!fastSearch.value.trim()) { resetSearch(); return }
  activeSearch.value = 'fast'
  openPanel.value    = null
  page.value         = 1
  updateFilterUrl('fast', fastSearch.value)
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
  updateFilterUrl('advanced', btoa(JSON.stringify(adv)))
  fetchRecords()
}

// ── Expert search ─────────────────────────────────────────────
function runExpertSearch() {
  if (!expertQuery.value.trim()) { resetSearch(); return }
  activeSearch.value = 'expert'
  openPanel.value    = null
  page.value         = 1
  updateFilterUrl('expert', expertQuery.value)
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

    // Custom column list (comma-separated string for GET, array for POST/JSON).
    // Empty array means "use backend preview defaults" (no param sent).
    const colParam = visibleColumnNames.value.length > 0
      ? visibleColumnNames.value
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
      // columns sent as comma-separated string to avoid URL array-encoding issues
      if (colParam) params.columns = colParam.join(',')
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
      // columns sent as comma-separated string to avoid URL array-encoding issues
      if (colParam) params.columns = colParam.join(',')
      res = await api.get('record_ctrl', 'getRecords', params)
    }

    if (res.status === 'error') {
      toast.add({ severity: 'error', summary: t('generic_error'),
        detail: responseMessage(res, t), life: 6000 })
      return
    }

    totalRecords.value = res.total ?? 0
    canAdd.value       = res.can_add ?? false
    if (res.fields?.length) {
      columns.value = res.fields.filter(f => f.name !== 'id')
      // If no saved preference yet, initialise from returned preview columns
      if (visibleColumnNames.value.length === 0) {
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

function addRecord() {
  const tb = selectedTable.value?.name
  if (tb) {
    router.push(`/record/${encodeURIComponent(tb)}/new`)
  }
}

function onRowClick(event) {
  const tb = selectedTable.value?.name
  const id = event.data?.id
  if (tb && id != null) {
    router.push({
      path:  `/record/${encodeURIComponent(tb)}/${id}`,
      query: { back: route.fullPath },
    })
  }
}

/**
 * Trigger a file download by building the export URL from the current
 * route query (which already encodes the active filter via updateFilterUrl).
 * The browser navigates to the URL; PHP responds with Content-Disposition: attachment.
 */
function doExport(format) {
  exportPopover.value?.hide()

  const params = new URLSearchParams()
  params.set('obj',    'record_ctrl')
  params.set('method', 'exportRecords')
  params.set('tb',     selectedTable.value?.name ?? '')
  params.set('format', format)

  // Pass through whichever filter params are currently in the URL
  if (route.query.qt)    params.set('qt',    route.query.qt)
  if (route.query.q)     params.set('q',     route.query.q)
  if (route.query.where) params.set('where', route.query.where)

  window.open('/index.php?' + params.toString(), '_blank')
}
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
.records-placeholder .pi { font-size: 2rem; }

/* ── Search area ─────────────────────────────────────────── */
.search-area {
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  gap: 0;
  border: 1px solid var(--p-content-border-color);
  border-radius: 6px;
  overflow: hidden;
}

.search-bar {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.5rem 0.75rem;
  background: var(--bdus-surface);
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
  border-top: 1px solid var(--p-content-border-color);
  background: var(--bdus-bg);
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
.clickable-rows :deep(tbody tr:hover td) { background: var(--p-content-hover-background); }

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
.col-toggler-item:hover { background: var(--p-content-hover-background); }
.col-toggler-item .pi   { color: var(--p-primary-color); font-size: 0.95rem; }

.col-toggler-actions {
  display: flex;
  gap: 0.25rem;
  padding: 0.4rem 0.5rem 0.25rem;
  border-top: 1px solid var(--p-content-border-color);
}

/* ── Add record ──────────────────────────────────────────── */
/* Toolbar button: pushed to the right by the active-search tag's margin-left:auto */
.add-record-btn {
  margin-left: auto;
  flex-shrink: 0;
}

/* When no active filter tag is shown, the button still needs to sit on the right */
.search-bar:not(:has(.search-active-tag)) .add-record-btn {
  margin-left: auto;
}

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
.fab-add .pi { font-size: 1.3rem; }
</style>
