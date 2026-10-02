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

          <!-- ── Search bar ──────────────────────────────────── -->
          <div class="search-area">

            <!-- Row 1: always visible -->
            <div class="search-bar" :class="{ 'is-compact': compact }">
              <!-- Live search: runs as you type (debounced); Enter runs it at once -->
              <AInput
                v-model:value="fastSearch"
                :placeholder="inputPlaceholder"
                :disabled="inputLocked"
                :title="inputLocked && !filtersOpen ? t('qb_remove_to_type') : undefined"
                allow-clear
                class="search-input-wrap"
                @change="onFastInput"
                @press-enter="onFastEnter"
              >
                <template #prefix><SearchOutlined /></template>
              </AInput>

              <!-- Filters: the builder and SQL live behind this one button -->
              <ABadge :count="appliedCount" :offset="[-2, 2]" :number-style="{ backgroundColor: 'var(--p-primary-color)' }">
                <AButton
                  :type="filtersOpen ? 'primary' : 'default'"
                  :size="compact ? 'middle' : 'small'"
                  :title="t('qb_filters')"
                  :aria-expanded="filtersOpen"
                  class="filters-btn"
                  @click="toggleFilters"
                >
                  <ControlOutlined />
                  <span v-if="!compact">{{ t('qb_filters') }}</span>
                </AButton>
              </ABadge>

              <!-- Small screens: every secondary action behind one menu -->
              <ADropdown v-if="compact" :trigger="['click']" placement="bottomRight">
                <AButton type="text" :title="t('more_actions')"><EllipsisOutlined /></AButton>
                <template #overlay>
                  <AMenu @click="onCompactMenu">
                    <AMenuItem key="saved"><PushpinOutlined /> {{ t('saved_queries') }}</AMenuItem>
                    <AMenuDivider />
                    <AMenuItem v-if="columns.length" key="columns"><TableOutlined /> {{ t('preview_fields') }}</AMenuItem>
                    <ASubMenu v-if="totalRecords > 0" key="export">
                      <template #title><DownloadOutlined /> {{ t('export') }}</template>
                      <AMenuItem key="export-csv">CSV</AMenuItem>
                      <AMenuItem key="export-xlsx">XLSX</AMenuItem>
                      <AMenuItem key="export-json">JSON</AMenuItem>
                    </ASubMenu>
                    <AMenuDivider />
                    <AMenuItem key="chart"><BarChartOutlined /> {{ t('create_chart_from_search') }}</AMenuItem>
                    <AMenuItem key="map"><CompassOutlined /> {{ t('view_on_map') }}</AMenuItem>
                    <AMenuItem v-if="selectedTable?.fuzzy_date" key="timeline"><CalendarOutlined /> {{ t('chrono_timeline') }}</AMenuItem>
                    <AMenuItem v-if="selectedTable?.rs" key="matrix"><ApartmentOutlined /> {{ t('harris_matrix') }}</AMenuItem>
                  </AMenu>
                </template>
              </ADropdown>

              <template v-else>
              <ADivider type="vertical" />

              <!-- Column visibility toggler -->
              <APopover v-if="columns.length" v-model:open="colTogglerOpen" trigger="click" placement="bottom">
                <template #content>
                  <div class="col-toggler-popover">
                    <div class="col-toggler-header">{{ t('preview_fields') }}</div>
                    <div class="col-toggler-list">
                      <div
                        v-for="col in allAvailableColumns"
                        :key="col.name"
                        class="col-toggler-item"
                        @click="toggleColumn(col.name)"
                      >
                        <component :is="visibleColumnNames.includes(col.name) ? CheckSquareOutlined : BorderOutlined" />
                        <span>{{ col.label }}</span>
                      </div>
                    </div>
                    <div class="col-toggler-actions">
                      <AButton type="text" size="small" @click="selectAllColumns">{{ t('select_all') }}</AButton>
                      <AButton type="text" size="small" @click="resetColumns">{{ t('reset') }}</AButton>
                    </div>
                  </div>
                </template>
                <AButton type="text" :title="t('preview_fields')" size="small"><TableOutlined /></AButton>
              </APopover>

              <!-- Export -->
              <APopover v-if="totalRecords > 0" v-model:open="exportPopoverOpen" trigger="click" placement="bottom">
                <template #content>
                  <div class="col-toggler-popover">
                    <div class="col-toggler-header">{{ t('export') }} ({{ totalRecords }} {{ t('records') }})</div>
                    <div class="col-toggler-list">
                      <div class="col-toggler-item" @click="doExport('csv')">
                        <FileOutlined />
                        <span>CSV</span>
                      </div>
                      <div class="col-toggler-item" @click="doExport('xlsx')">
                        <FileExcelOutlined />
                        <span>XLSX</span>
                      </div>
                      <div class="col-toggler-item" @click="doExport('json')">
                        <FileTextOutlined />
                        <span>JSON</span>
                      </div>
                    </div>
                  </div>
                </template>
                <AButton type="text" :title="t('export')" size="small"><DownloadOutlined /></AButton>
              </APopover>
              </template>

              <template v-if="!compact">
              <!-- Saved searches -->
              <AButton type="text" :title="t('saved_queries')" size="small" @click="savedQueriesDialog = true">
                <PushpinOutlined />
              </AButton>

              <!-- Create chart from this view/search -->
              <AButton type="text" :title="t('create_chart_from_search')" size="small" @click="createChartFromSearch">
                <BarChartOutlined />
              </AButton>

              <!-- View on map -->
              <AButton type="text" :title="t('view_on_map')" size="small" @click="openGeoface">
                <CompassOutlined />
              </AButton>

              <!-- Chronological timeline — only for tables with fuzzy_date plugin -->
              <AButton
                v-if="selectedTable?.fuzzy_date"
                type="text"
                :title="t('chrono_timeline')"
                size="small"
                @click="openTimeline"
              ><CalendarOutlined /></AButton>

              <!-- Harris Matrix — only for tables with RS plugin enabled -->
              <AButton
                v-if="selectedTable?.rs"
                type="text"
                :title="t('harris_matrix')"
                size="small"
                @click="openMatrix"
              ><ApartmentOutlined /></AButton>

              <!-- Add record — only for users with add_new privilege; below the
                   compact breakpoint the floating + button covers it. -->
              <AButton
                v-if="canAdd"
                type="primary"
                size="small"
                class="add-record-btn"
                @click="addRecord"
              ><PlusOutlined /> {{ t('new_record') }}</AButton>
              </template>
            </div>

            <!-- What is applied, as removable chips (a group is one chip) -->
            <div v-if="queryChips.length" class="query-chips">
              <template v-for="(chip, k) in queryChips" :key="chip.key">
                <span v-if="k > 0" class="chip-join">{{ chipJoin }}</span>
                <ATag
                  color="warning"
                  closable
                  class="query-chip"
                  @close="e => { e.preventDefault(); removeChip(chip) }"
                >{{ chip.label }}</ATag>
              </template>
              <AButton type="link" size="small" @click="resetSearch">{{ t('qb_remove_filters') }}</AButton>
            </div>

            <!-- Modals live outside the toolbar so they work from both layouts -->
            <AModal
              v-model:open="savedQueriesDialog"
              :title="t('saved_queries')"
              :footer="null"
              width="36rem"
            >
              <SavedQueriesPanel
                :currentSearch="currentSearch"
                :currentTb="selectedTable?.name ?? ''"
                @load-query="onLoadQuery"
              />
            </AModal>
            <AModal
              v-model:open="columnsDialog"
              :title="t('preview_fields')"
              :footer="null"
              width="28rem"
            >
              <div class="col-toggler-list">
                <div
                  v-for="col in allAvailableColumns"
                  :key="col.name"
                  class="col-toggler-item"
                  @click="toggleColumn(col.name)"
                >
                  <component :is="visibleColumnNames.includes(col.name) ? CheckSquareOutlined : BorderOutlined" />
                  <span>{{ col.label }}</span>
                </div>
              </div>
              <div class="col-toggler-actions">
                <AButton type="text" size="small" @click="selectAllColumns">{{ t('select_all') }}</AButton>
                <AButton type="text" size="small" @click="resetColumns">{{ t('reset') }}</AButton>
              </div>
            </AModal>

            <!-- ── Filters panel: builder or SQL (alternatives to the text box) ── -->
            <Transition name="slide">
              <div v-if="filtersOpen" class="search-panel">
                <ASegmented :value="openPanel" :options="filtersTabs" size="small" class="filters-tabs" @change="setFiltersTab" />

                <template v-if="openPanel === 'advanced'">
                  <div v-if="loadingAdvConfig" class="adv-loading">
                    <ASpin size="small" />
                  </div>
                  <!-- Query builder: a tree of groups and conditions -->
                  <FilterBuilder
                    v-else
                    :tree="advTree"
                    :fields="advFields"
                    :operators="advOperatorsForDisplay"
                  />
                </template>

                <template v-else>
                  <label class="expert-label">{{ t('sql_expert_search') }} — WHERE …</label>
                  <p class="expert-hint">{{ t('sql_expert_search_hint') }}</p>
                  <ATextarea
                    v-model:value="expertQuery"
                    :rows="3"
                    class="expert-textarea"
                  />
                </template>

                <div class="search-panel-actions">
                  <AButton type="primary" size="small" @click="openPanel === 'advanced' ? runAdvancedSearch() : runExpertSearch()">
                    <SearchOutlined /> {{ t('qb_apply') }}
                  </AButton>
                  <AButton size="small" @click="closeFilters">{{ t('qb_close') }}</AButton>
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

          <!-- ── Table ────────────────────────────────────────── -->
          <!--
            AntD's core Table has no built-in drag-to-reorder-columns
            (PrimeVue's `reorderableColumns` was a single boolean prop) — it
            would need a custom header + a drag library. Dropped rather than
            reimplemented; column visibility toggling (separate feature, the
            popover above) is unaffected and still works.
          -->
          <!--
            PrimeVue's DataTable had `scrollHeight="flex"` — it auto-fills
            whatever space its flex parent gives it. AntD's Table has no such
            option: `scroll.y` wants a concrete px number, so filling the
            remaining flex space takes a ResizeObserver measuring the wrapper
            (see tableWrap / measureTableHeight below) instead of a single
            boolean-ish prop.
          -->
          <div ref="tableWrap" class="records-table-wrap">
            <ATable
              :columns="antdColumns"
              :dataSource="records"
              :loading="loadingRecords"
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
          @click="addRecord"
        >
          <PlusOutlined />
        </button>

      </div>
    </div>

  </AppLayout>
</template>

<script setup>
import { ApartmentOutlined, ArrowLeftOutlined, BarChartOutlined, BorderOutlined, CalendarOutlined, CheckSquareOutlined, CloseOutlined, CompassOutlined, ControlOutlined, DownloadOutlined, EllipsisOutlined, FileExcelOutlined, FileOutlined, FileTextOutlined, PlusOutlined, PushpinOutlined, SearchOutlined, TableOutlined } from '@ant-design/icons-vue'
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from '@/composables/useNotify'
import { api, apiUrl, filterToSearchParams } from '@/api'
import { useI18n } from '@/i18n'
import { useTables } from '@/composables/useTables'
import { useMediaQuery } from '@/composables/useMediaQuery'
import { appStorage } from '@/utils/storage'
import { useListNavigation } from '@/stores/listNavigation'
import AppLayout from '@/components/AppLayout.vue'
import {
  Table as ATable,
  Input,
  Divider as ADivider,
  Tag as ATag,
  Spin as ASpin,
  Popover as APopover,
  Modal as AModal,
  Button as AButton,
  Badge as ABadge,
  Segmented as ASegmented,
  Dropdown as ADropdown,
  Menu as AMenu,
} from 'ant-design-vue'
import SavedQueriesPanel from '@/components/SavedQueriesPanel.vue'
import FilterBuilder from '@/components/query/FilterBuilder.vue'
import { listBody, searchParams, geofaceQuery } from '@/utils/recordQuery'
import { emptyTree, cloneTree, countActive, formula, treeToFilter, serializeTree, restoreTree } from '@/utils/filterTree'

const AInput       = Input
const ATextarea    = Input.TextArea
const AMenuItem    = AMenu.Item
const ASubMenu     = AMenu.SubMenu
const AMenuDivider = AMenu.Divider

const { t } = useI18n()
const toast  = useToast()
const route  = useRoute()
const router = useRouter()
const listNav = useListNavigation()
const { responseMessage } = api

// ── Tables (shared singleton composable) ─────────────────────
const { tables, loadTables } = useTables()

// ── Selected table: derived from route query ──────────────────
const selectedTable = computed(() =>
  tables.value.find(tbl => tbl.name === route.query.tb) ?? null
)

// ── Column visibility & order ────────────────────────────────
// Below this width the toolbar collapses its secondary actions into one menu
// and the floating + button replaces the "New record" button.
const compact             = useMediaQuery('(max-width: 640px)')
const columnsDialog       = ref(false)
watch(compact, v => { if (!v) columnsDialog.value = false })   // that dialog only exists in the compact layout
const colTogglerOpen      = ref(false)
const exportPopoverOpen   = ref(false)
const savedQueriesDialog  = ref(false)

// Unlike PrimeVue's Popover (imperative ref.toggle(), one instance
// implicitly closes when another opens because they share the same overlay
// stack), AntD's Popover is a plain declarative v-model per instance — two
// independent triggers can end up open at once with no built-in mutual
// exclusivity. Enforce it manually.
watch(colTogglerOpen,    v => { if (v) exportPopoverOpen.value = false })
watch(exportPopoverOpen, v => { if (v) colTogglerOpen.value = false })

/** AntD Select/AutoComplete: filter must be supplied explicitly (see FieldEditor.vue). */
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

/**
 * Per-app storage key for a table's column prefs. Namespacing by application
 * (bdus:<app>:data:columns:<tb>, see utils/storage.js) is essential: two
 * databases can each have a table called `siti` with different columns, and a
 * pref saved against one must never be replayed against the other — that sent
 * a non-existent column to getRecords and 500'd the whole list.
 */
function colStorageKey(tbName) { return `data:columns:${tbName}` }

/**
 * Called when there are no saved prefs (initial first visit to a table).
 * Populates visibleColumnNames from the preview columns the backend returned.
 */
function initVisibleColumns(tbName) {
  const arr = appStorage.getJSON(colStorageKey(tbName))
  if (Array.isArray(arr) && arr.length) {
    visibleColumnNames.value = arr
    return
  }
  // No saved prefs: use whatever the backend returned as default (preview fields)
  visibleColumnNames.value = columns.value.map(c => c.name)
}

function saveColumnPrefs(tbName) {
  appStorage.setJSON(colStorageKey(tbName), visibleColumnNames.value)
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

function selectAllColumns() {
  visibleColumnNames.value = allAvailableColumns.value.map(c => c.name)
  saveColumnPrefs(selectedTable.value?.name)
  fetchRecords()
}

function resetColumns() {
  if (!selectedTable.value) return
  appStorage.remove(colStorageKey(selectedTable.value.name))
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
const activeFilter   = ref(null)   // JSON filter object from link navigation (not user-editable)
const activeSearch   = ref(null)   // null | 'fast' | 'advanced' | 'expert' | 'filter'

// ── Advanced search config (lazy-loaded per table) ───────────
const loadingAdvConfig = ref(false)
const advFields        = ref([])
const advOperators     = ref([])   // raw from backend: [{ value, key }, ...]
let   advConfigFor     = null      // track which table the config was loaded for

// Operators with translated labels for the dropdown
const advOperatorsForDisplay = computed(() =>
  advOperators.value.map(op => ({ value: op.value, label: t(op.key) }))
)

// ── Advanced search: the builder's tree (see utils/filterTree.js) ─────────────
const advTree     = ref(emptyTree())   // the draft being edited in the panel
const appliedTree = ref(null)          // the tree the current results come from (null: none)

/**
 * The builder's tree as a Directus-style filter object (null when no condition
 * is complete). Lookup fields (id_from_tb) are wrapped in a traversal on
 * ref_field: the column stores the referenced record's id while the user types
 * the referenced table's display value.
 */
function buildAdvFilter() {
  return treeToFilter(advTree.value, {
    mainTb: selectedTable.value?.name,
    fieldMeta: fld => advFields.value.find(f => f.value === fld),
  })
}

// ── Unified query bar ───────────────────────────────────────
// One entry point: the text box searches as you type; the Filters button opens
// a panel with the builder (openPanel 'advanced') or raw SQL ('expert'). They
// are alternatives — opening the filters empties and disables the text box, and
// it stays disabled while a filter is applied (remove the filter to type again).
const filtersOpen = computed(() => openPanel.value !== null)
const inputLocked = computed(() =>
  filtersOpen.value || ['advanced', 'expert', 'filter'].includes(activeSearch.value)
)

/** Conditions behind the current results — the badge on the Filters button. */
const appliedCount = computed(() => {
  if (activeSearch.value === 'advanced') return appliedTree.value ? countActive(appliedTree.value) : 0
  if (activeSearch.value === 'expert' || activeSearch.value === 'filter') return 1
  return 0
})

const inputPlaceholder = computed(() => {
  if (filtersOpen.value) return t('qb_use_filters')
  if (activeSearch.value === 'advanced') {
    return appliedCount.value === 1 ? t('qb_active_condition') : t('qb_active_conditions', { n: appliedCount.value })
  }
  if (activeSearch.value === 'expert')   return t('qb_active_sql')
  if (activeSearch.value === 'filter')   return t('qb_active_linked')
  return t('fast_search')
})

const filtersTabs = computed(() => [
  { value: 'advanced', label: t('qb_tab_builder') },
  { value: 'expert',   label: t('qb_tab_sql') },
])

/** Removable chips for what is applied; a group is one chip, with parentheses. */
const queryChips = computed(() => {
  if (activeSearch.value === 'expert') {
    return [{ key: 'sql', label: `SQL: ${expertQuery.value}` }]
  }
  if (activeSearch.value === 'filter') {
    return [{ key: 'filter', label: t('linked_records') }]
  }
  if (activeSearch.value !== 'advanced' || !appliedTree.value) return []
  const ctx = {
    fieldLabel:    fld => advFields.value.find(f => f.value === fld)?.label ?? fld,
    operatorLabel: op  => (advOperatorsForDisplay.value.find(o => o.value === op)?.label ?? op).toLowerCase(),
    and: t('qb_and'), or: t('qb_or'),
  }
  return appliedTree.value.c
    .map((child, index) => ({ child, index }))
    .filter(({ child }) => countActive(child) > 0)
    .map(({ child, index }) => {
      const text = formula(child, ctx)
      return { key: `n${index}`, index, label: child.t === 'g' && countActive(child) > 1 ? `(${text})` : text }
    })
})
const chipJoin = computed(() => (appliedTree.value?.op === 'OR' ? t('qb_or') : t('qb_and')))

// ── Saved queries: current search payload ────────────────────
/**
 * Returns the current active search payload suitable for storing as a
 * saved query, or null when there is no meaningful search to save.
 */
const currentSearch = computed(() => {
  if (activeSearch.value === 'advanced') {
    const filter = activeFilter.value   // what is applied, not the draft being edited
    if (!filter) return null
    return {
      filter,
      sort_field: sortField.value ?? '',
      sort_dir:   sortDir.value,
    }
  }
  if (activeSearch.value === 'expert' && expertQuery.value.trim()) {
    return {
      search_type: 'sqlExpert',
      querytext:   expertQuery.value,
      join:        '',
      sort_field:  sortField.value ?? '',
      sort_dir:    sortDir.value,
    }
  }
  return null
})

/**
 * Called when SavedQueriesPanel emits `load-query`.
 * Restores the search state from the stored payload and fetches records.
 */
function onLoadQuery(payload) {
  if (!payload?.search_type && !payload?.filter) return

  savedQueriesDialog.value = false
  page.value = 1

  if (payload.search_type === 'sqlExpert' && payload.querytext) {
    expertQuery.value  = payload.querytext
    fastSearch.value   = ''
    activeSearch.value = 'expert'
    openPanel.value    = null
    updateFilterUrl('expert', payload.querytext)
    fetchRecords()
  } else if (payload.filter && typeof payload.filter === 'object') {
    activeFilter.value = payload.filter
    fastSearch.value   = ''
    activeSearch.value = 'filter'
    openPanel.value    = null
    updateFilterUrl('filter', JSON.stringify(payload.filter))
    fetchRecords()
  }
}

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
 * Apply `tb`, `filter`, `qt` and `q` query params from the current URL.
 *
 * Called on mount (tables freshly loaded) and whenever the route query changes.
 *
 * `filter` is a JSON-encoded Directus-style filter object produced by
 * Record\Read::getLinks() / getBackLinks() — e.g. {"id":{"_eq":1}}.
 * `qt`/`q` carry fast, advanced, and expert search state.
 */
// Track last applied params to avoid redundant fetches triggered by our own
// router.replace() calls (filter URL persistence). The guard compares all four
// relevant params; if all match, the change originated from updateFilterUrl()
// and there is nothing to re-fetch.
let lastAppliedTb     = null
let lastAppliedFilter = null   // JSON string or null (replaces lastAppliedWhere)
let lastAppliedQt     = null
let lastAppliedQ      = null

/**
 * Push the current filter type + value into the URL (for bookmarking / back-nav).
 * Uses router.replace() so the URL changes without adding a history entry.
 * Pre-updates lastAppliedQt/Q so the watcher's guard skips the resulting change.
 */
function updateFilterUrl(type, query) {
  const newQuery = { tb: route.query.tb }
  // Keep JSON filter in URL when switching to/from other search types
  if (route.query.filter) newQuery.filter = route.query.filter
  if (type && query != null) { newQuery.qt = type; newQuery.q = query }
  lastAppliedQt = newQuery.qt ?? null
  lastAppliedQ  = newQuery.q  ?? null
  router.replace({ query: newQuery })
}

function applyRouteParams() {

  const tbParam     = route.query.tb
  const filterParam = route.query.filter ?? null  // JSON-encoded filter object
  const qtParam     = route.query.qt     ?? null
  const qParam      = route.query.q      ?? null

  if (!tbParam) return

  // Guard: skip if nothing changed
  if (
    tbParam     === lastAppliedTb     &&
    filterParam === lastAppliedFilter &&
    qtParam     === lastAppliedQt     &&
    qParam      === lastAppliedQ
  ) return

  // Table must exist in the list (permissions or typo check)
  const tbl = tables.value.find(t => t.name === tbParam)
  if (!tbl) return

  const tableChanged = lastAppliedTb !== tbParam
  lastAppliedTb     = tbParam
  lastAppliedFilter = filterParam
  lastAppliedQt     = qtParam
  lastAppliedQ      = qParam

  if (tableChanged) {
    advConfigFor = null

    // Restore saved column preferences NOW so that the first fetchRecords()
    // call already uses the full saved set as colParam.
    // If we left visibleColumnNames empty here and restored only inside
    // initVisibleColumns() (which runs after the fetch), the first fetch would
    // use preview mode and columns.value would only contain preview fields —
    // causing a mismatch where saved non-preview columns appear checked in the
    // toggler but are absent from the DataTable.
    const saved = appStorage.getJSON(colStorageKey(tbParam))
    let restoredFromStorage = false
    if (Array.isArray(saved) && saved.length) {
      visibleColumnNames.value = saved
      restoredFromStorage = true
    }
    if (!restoredFromStorage) {
      // No saved prefs: leave empty so initVisibleColumns() can populate
      // from the backend preview fields after the first fetch.
      visibleColumnNames.value = []
    }

    loadAdvConfig()   // eager load all fields for the column toggler
  }

  if (filterParam) {
    try { activeFilter.value = JSON.parse(filterParam) } catch { activeFilter.value = null }
    activeSearch.value = 'filter'
    openPanel.value    = null
    page.value         = 1
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
      fastSearch.value   = ''
      activeSearch.value = 'expert'
      openPanel.value    = null
      page.value         = 1
      fetchRecords()
    } else if (qtParam === 'advanced') {
      try {
        const parsed = JSON.parse(qParam)
        advTree.value       = restoreTree(parsed)   // new { tree } or the previous { rows }
        appliedTree.value   = cloneTree(advTree.value)
        activeFilter.value  = parsed.filter ?? null
      } catch { activeFilter.value = null; appliedTree.value = null }
      fastSearch.value   = ''
      activeSearch.value = 'advanced'
      openPanel.value    = null
      page.value         = 1
      loadAdvConfig()
      fetchRecords()
    } else if (qtParam === 'filter') {
      try { activeFilter.value = JSON.parse(qParam) } catch { activeFilter.value = null }
      activeSearch.value = 'filter'
      openPanel.value    = null
      page.value         = 1
      fetchRecords()
    }
    return
  }

  if (tableChanged) {
    resetSearch()   // also calls fetchRecords
  }
}

// Re-apply whenever route query changes
watch(() => route.query, applyRouteParams)

// ── Filters panel ─────────────────────────────────────────────
async function openFilters() {
  // The text box and the filters are alternatives: a running text search ends.
  if (activeSearch.value === 'fast') { clearTimeout(fastTimer); resetSearch() }
  fastSearch.value = ''
  // Start from what is applied, dropping an abandoned draft.
  if (appliedTree.value) advTree.value = cloneTree(appliedTree.value)
  openPanel.value = activeSearch.value === 'expert' ? 'expert' : 'advanced'
  if (openPanel.value === 'advanced') await loadAdvConfig()
}

function closeFilters() {
  openPanel.value = null
  if (appliedTree.value) advTree.value = cloneTree(appliedTree.value)
}

function toggleFilters() {
  return filtersOpen.value ? closeFilters() : openFilters()
}

async function setFiltersTab(tab) {
  openPanel.value = tab
  if (tab === 'advanced') await loadAdvConfig()
}

/** Chip × : drop one top-level condition or group, and run what is left. */
function removeChip(chip) {
  if (chip.index === undefined) return resetSearch()   // SQL / linked-records chips
  const tree = cloneTree(appliedTree.value)
  tree.c.splice(chip.index, 1)
  if (countActive(tree) === 0) return resetSearch()
  advTree.value = tree
  runAdvancedSearch()
}

// ── Lazy-load advanced config (fields, operators, connectors) ─
async function loadAdvConfig() {
  const tb = selectedTable.value?.name
  if (!tb || advConfigFor === tb) return
  loadingAdvConfig.value = true
  try {
    const res = await api.get(`/api/search/${tb}/config`)
    if (res.status === 'error') throw new Error(responseMessage(res, t))
    advFields.value     = res.fields     ?? []
    advOperators.value  = res.operators  ?? []   // [{ value, key }]
    advConfigFor = tb
  } catch (e) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.message, life: 4000 })
  } finally {
    loadingAdvConfig.value = false
  }
}

// ── Reset all search ─────────────────────────────────────────
function resetSearch() {
  fastSearch.value   = ''
  expertQuery.value  = ''
  activeFilter.value = null
  advTree.value      = emptyTree()
  appliedTree.value  = null
  activeSearch.value = null
  openPanel.value    = null
  page.value         = 1
  // Clear both filter and qt/q from URL
  lastAppliedFilter = null
  router.replace({ query: { tb: route.query.tb } })
  fetchRecords()
}

// ── Fast search ───────────────────────────────────────────────
// Live search: runs while typing, after a short pause and once at least
// FAST_MIN_CHARS are typed (the query is a LIKE over every preview field — no
// point firing it for one letter). Enter runs it immediately; emptying the
// input clears the search.
const FAST_MIN_CHARS = 2
const FAST_DEBOUNCE_MS = 300
let fastTimer = null

function onFastInput() {
  clearTimeout(fastTimer)
  const v = (fastSearch.value ?? '').trim()
  if (!v) {
    if (activeSearch.value === 'fast') resetSearch()
    return
  }
  if (v.length < FAST_MIN_CHARS) return
  fastTimer = setTimeout(runFastSearch, FAST_DEBOUNCE_MS)
}

function onFastEnter() {
  clearTimeout(fastTimer)
  runFastSearch()
}

function onCompactMenu({ key }) {
  switch (key) {
    case 'saved':    savedQueriesDialog.value = true; break
    case 'columns':  columnsDialog.value = true; break
    case 'chart':    createChartFromSearch(); break
    case 'map':      openGeoface(); break
    case 'timeline': openTimeline(); break
    case 'matrix':   openMatrix(); break
    default:
      if (key.startsWith('export-')) doExport(key.slice('export-'.length))
  }
}

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
  const filter = buildAdvFilter()
  if (!filter) { resetSearch(); return }
  activeFilter.value = filter
  appliedTree.value  = cloneTree(advTree.value)
  fastSearch.value   = ''
  activeSearch.value = 'advanced'
  openPanel.value    = null
  page.value         = 1
  updateFilterUrl('advanced', JSON.stringify({ tree: serializeTree(advTree.value), filter }))
  fetchRecords()
}

// ── Expert search ─────────────────────────────────────────────
function runExpertSearch() {
  if (!expertQuery.value.trim()) { resetSearch(); return }
  fastSearch.value   = ''
  activeSearch.value = 'expert'
  openPanel.value    = null
  page.value         = 1
  updateFilterUrl('expert', expertQuery.value)
  fetchRecords()
}

// ── Core fetch ────────────────────────────────────────────────
// Body of the last successful list request — handed to the list-navigation
// store on row click so the record view can offer Previous/Next in this list.
let lastListBody = null   // { tb, body }

// Only the latest list request may update the screen: a new one (typing,
// paging, sorting) cancels the one still in flight, and the sequence number is
// a second guard against a response that slipped past the abort.
let fetchCtrl = null
let fetchSeq  = 0

async function fetchRecords() {
  if (!selectedTable.value) return
  fetchCtrl?.abort()
  fetchCtrl = new AbortController()
  const { signal } = fetchCtrl
  const seq = ++fetchSeq
  loadingRecords.value = true
  try {
    const tbName = selectedTable.value.name

    const body = listBody({
      page:      page.value,
      perPage:   perPage.value,
      sortField: sortField.value,
      sortDir:   sortDir.value,
      search:    searchParams({
        activeSearch: activeSearch.value,
        fastSearch:   fastSearch.value,
        expertQuery:  expertQuery.value,
        activeFilter: activeFilter.value,
      }),
      columns: visibleColumnNames.value,   // empty → the backend's preview defaults
    })

    const res = await api.post(`/api/records/${tbName}`, body, { signal })
    if (seq !== fetchSeq) return   // superseded while waiting

    if (res.status === 'error') {
      toast.add({ severity: 'error', summary: t('generic_error'),
        detail: responseMessage(res, t), life: 6000 })
      return
    }

    lastListBody = { tb: tbName, body }
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
    if (e.name === 'AbortError' || seq !== fetchSeq) return   // cancelled by a newer request
    toast.add({ severity: 'error', summary: 'Error', detail: e.message, life: 4000 })
  } finally {
    if (seq === fetchSeq) loadingRecords.value = false
  }
}

// ── AntD Table: columns/pagination/row-click/sort+page (unified) ─────
// PrimeVue split pagination (@page) and sorting (@sort) into two events;
// AntD's Table fires one @change with pagination + sorter together, so a
// single handler now covers what used to be two.
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
    // alongside the raw id (see Record.php::getRecords) — prefer it, same
    // as the single-record view and the guided search already do.
    customRender: ({ record, text }) => record['@' + col.name] ?? text,
  }))
)

const paginationConfig = computed(() => ({
  current: page.value,
  pageSize: perPage.value,
  total: totalRecords.value,
  showSizeChanger: true,
  pageSizeOptions: ['15', '30', '50', '100'],
  // AntD defaults to bottomRight, which sits under the fixed FAB add-record
  // button (bottom-right corner). PrimeVue's paginator was centered by
  // default and never had this conflict — match that instead of moving the FAB.
  position: ['bottomCenter'],
}))

function customRow(record) {
  return { onClick: () => onRowClick({ data: record }) }
}

function onTableChange(pagination, _filters, sorter) {
  const newSortField = sorter.order ? sorter.field : null
  const newSortDir    = sorter.order === 'descend' ? 'desc' : 'asc'
  const sortChanged   = newSortField !== sortField.value || newSortDir !== sortDir.value

  sortField.value = newSortField
  sortDir.value   = newSortDir
  page.value      = sortChanged ? 1 : pagination.current
  perPage.value   = pagination.pageSize
  fetchRecords()
}

// ── AntD Table: fill available flex space (see template note) ────────
const tableWrap    = ref(null)
const tableScrollY = ref(400)
let resizeObs = null

function measureTableHeight() {
  if (!tableWrap.value) return
  const total      = tableWrap.value.clientHeight
  const headerH    = tableWrap.value.querySelector('.ant-table-thead')?.getBoundingClientRect().height ?? 40
  const paginationH = tableWrap.value.querySelector('.ant-pagination')?.getBoundingClientRect().height ?? 32
  tableScrollY.value = Math.max(200, total - headerH - paginationH - 16)
}

onMounted(() => {
  resizeObs = new ResizeObserver(measureTableHeight)
  if (tableWrap.value) resizeObs.observe(tableWrap.value)
})
onUnmounted(() => {
  resizeObs?.disconnect()
  clearTimeout(fastTimer)
  fetchCtrl?.abort()
})
watch(records, () => { measureTableHeight() })

function addRecord() {
  const tb = selectedTable.value?.name
  if (tb) {
    router.push(`/${route.params.app}/record/${encodeURIComponent(tb)}/new`)
  }
}

/**
 * Navigate to the GeoFace (map) view for the current table,
 * forwarding the current active filter as query parameters so the map
 * shows only the records matching the current search.
 */
function openGeoface() {
  const tb = selectedTable.value?.name
  if (!tb) return
  const query = geofaceQuery({
    activeSearch: activeSearch.value,
    activeFilter: activeFilter.value,
    expertQuery:  expertQuery.value,
  })
  router.push({ path: `/${route.params.app}/geoface/${encodeURIComponent(tb)}`, query })
}

/**
 * Navigate to the chart wizard, pre-filled with the current table and — when
 * an advanced/expert search is active — the same filter shape already sent
 * to Chart.php::getData() (see currentSearch), so the new chart runs over
 * exactly what's currently on screen instead of the whole table.
 */
function createChartFromSearch() {
  const tb = selectedTable.value?.name
  if (!tb) return
  const query = { tb }
  if (currentSearch.value) {
    query.filter = JSON.stringify(currentSearch.value)
  }
  router.push({ path: `/${route.params.app}/charts/new`, query })
}

/**
 * Navigate to the Harris Matrix view for the current table,
 * passing the active search params from the current route URL.
 * MatrixView / getRsMatrix() accept the same filter/search_type/search params
 * as getRecords(), so we forward the entire current query.
 */
function openTimeline() {
  const tb  = selectedTable.value?.name
  const lbl = selectedTable.value?.label
  if (!tb) return
  router.push({
    path:  `/${route.params.app}/chrono/${encodeURIComponent(tb)}`,
    query: { back: route.fullPath, backLabel: lbl },
  })
}

function openMatrix() {
  const tb = selectedTable.value?.name
  if (!tb) return
  router.push({
    path:  `/${route.params.app}/matrix/${encodeURIComponent(tb)}`,
    query: { ...route.query, back: route.fullPath },
  })
}

function onRowClick(event) {
  const tb = selectedTable.value?.name
  const id = event.data?.id
  if (tb && id != null) {
    // Only if the captured request belongs to the table being opened.
    if (lastListBody?.tb === tb) listNav.rememberRecordList(tb, lastListBody.body, route.fullPath)
    else listNav.clearRecordList()
    router.push({
      path:  `/${route.params.app}/record/${encodeURIComponent(tb)}/${id}`,
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
  exportPopoverOpen.value = false

  const tb = selectedTable.value?.name ?? ''
  const qs = new URLSearchParams({ format })

  // Pass through whichever filter params are currently active.
  // For JSON filter, use bracket notation so PHP parses it as a nested array.
  if (activeSearch.value === 'filter' && activeFilter.value) {
    filterToSearchParams(activeFilter.value).forEach((v, k) => qs.set(k, v))
  } else {
    if (route.query.qt) qs.set('qt', route.query.qt)
    if (route.query.q)  qs.set('q',  route.query.q)
  }

  window.open(apiUrl(`/api/records/${encodeURIComponent(tb)}/export`) + '?' + qs.toString(), '_blank')
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
.records-placeholder .anticon { font-size: 2rem; }

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

.query-chips {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.35rem;
  padding: 0 0.75rem 0.5rem;
  background: var(--bdus-surface);
}
.query-chip { margin-inline-end: 0; max-width: 100%; white-space: normal; overflow-wrap: anywhere; }
.chip-join { font-size: 0.75rem; opacity: 0.7; }
.filters-btn { display: inline-flex; align-items: center; gap: 0.35rem; }
.filters-tabs { align-self: flex-start; }

/* ── Collapsible panels ──────────────────────────────────── */
.search-panel {
  padding: 0.75rem 1rem;
  border-top: 1px solid var(--p-content-border-color);
  background: var(--bdus-bg);
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  /* A nested query tree can run long: leave room for it and Apply below. */
  max-height: min(70vh, 560px);
  overflow-y: auto;
}

/* ── Advanced search rows ────────────────────────────────── */
.adv-loading {
  display: flex;
  justify-content: center;
  padding: 1rem;
}


/* ── Expert panel ────────────────────────────────────────── */
.expert-label {
  font-size: 0.82rem;
  font-weight: 500;
  color: var(--p-text-muted-color);
}
.expert-textarea { font-family: monospace; font-size: 0.85rem; }
.expert-hint {
  font-size: 0.78rem;
  color: var(--p-text-muted-color);
  margin: 0.15rem 0 0.5rem;
}

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

.records-table-wrap { flex: 1; min-height: 0; overflow: hidden; }
.clickable-rows :deep(.ant-table-tbody > tr) { cursor: pointer; }
.clickable-rows :deep(.ant-table-tbody > tr:hover > td) { background: var(--p-content-hover-background) !important; }

/* ── Column toggler popover ──────────────────────────────── */
.col-toggler-popover { min-width: 200px; }

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
.col-toggler-item .anticon { color: var(--p-primary-color); font-size: 0.95rem; }

.col-toggler-actions {
  display: flex;
  gap: 0.25rem;
  padding: 0.4rem 0.5rem 0.25rem;
  border-top: 1px solid var(--p-content-border-color);
}

/* ── Compact toolbar (≤ 640px) ───────────────────────────── */
.search-bar.is-compact { flex-wrap: wrap; }

/* ── Add record ──────────────────────────────────────────── */
/* Toolbar button: pushed to the right by the active-search tag's margin-left:auto */
.add-record-btn {
  margin-left: auto;
  flex-shrink: 0;
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
.fab-add .anticon { font-size: 1.3rem; }
</style>
