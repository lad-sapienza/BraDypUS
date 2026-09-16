<template>
  <AppLayout>
    <div class="charts-view">

      <!-- ── List ─────────────────────────────────────────────────────── -->
      <template v-if="mode === 'list'">
        <div class="page-header">
          <h2>{{ t('charts') }}</h2>
          <AButton type="primary" size="small" @click="openWizard(null)">
            <template #icon><PlusOutlined /></template>
            {{ t('new_chart') }}
          </AButton>
        </div>

        <ASpin v-if="loadingCharts" size="large" class="loading-spinner" />

        <template v-else>
          <div v-if="charts.length === 0" class="empty-state">
            <BarChartOutlined class="empty-icon" />
            <p>{{ t('no_charts_yet') }}</p>
          </div>

          <div v-else class="charts-grid">
            <ACard v-for="c in charts" :key="c.id" class="chart-card">
              <div class="card-title-row">
                <a class="card-title" @click="runSavedChart(c)">{{ c.name }}</a>
                <span v-if="c.is_global" class="chart-shared-badge">
                  <ShareAltOutlined :title="t('shared')" />
                </span>
              </div>
              <div v-if="c.tb_label" class="card-meta">{{ c.tb_label }}</div>

              <template #actions>
                <AButton
                  type="text"
                  size="small"
                  :title="t('execute_query')"
                  @click="runSavedChart(c)"
                >
                  <template #icon><PlayCircleOutlined /></template>
                </AButton>
                <AButton
                  v-if="c.owned_by_me"
                  type="text"
                  size="small"
                  :title="t('edit_chart')"
                  @click="openWizard(c)"
                >
                  <template #icon><EditOutlined /></template>
                </AButton>
                <AButton
                  v-if="c.owned_by_me"
                  type="text"
                  size="small"
                  :title="c.is_global ? t('unshare') : t('share')"
                  :loading="pendingId === c.id && pendingAction === 'share'"
                  @click="toggleShare(c)"
                >
                  <template #icon><component :is="c.is_global ? LockOutlined : GlobalOutlined" /></template>
                </AButton>
                <AButton
                  v-if="c.owned_by_me"
                  type="text"
                  danger
                  size="small"
                  :title="t('delete')"
                  :loading="pendingId === c.id && pendingAction === 'delete'"
                  @click="confirmDelete(c)"
                >
                  <template #icon><DeleteOutlined /></template>
                </AButton>
              </template>

              <!-- Inline confirm -->
              <div v-if="confirmingId === c.id" class="chart-confirm">
                <span class="chart-confirm-text">{{ t('confirm_delete') }}</span>
                <AButton danger size="small" @click="doDelete(c)">{{ t('yes') }}</AButton>
                <AButton type="text" size="small" @click="confirmingId = null">{{ t('no') }}</AButton>
              </div>
            </ACard>
          </div>
        </template>
      </template>

      <!-- ── Result (run a saved chart, full width) ──────────────────── -->
      <template v-else-if="mode === 'result'">
        <div class="result-header">
          <AButton type="text" size="small" @click="backToList">
            <template #icon><ArrowLeftOutlined /></template>
          </AButton>
          <span class="result-title">{{ resultChart?.name }}</span>
          <ATag v-if="resultChart?.is_global" color="success">{{ t('shared') }}</ATag>
          <div class="result-header-actions">
            <AButton
              v-if="resultChart?.owned_by_me"
              type="text"
              size="small"
              :title="t('edit_chart')"
              @click="openWizard(resultChart)"
            >
              <template #icon><EditOutlined /></template>
            </AButton>
          </div>
        </div>

        <div v-if="resultLoading" class="result-loading"><ASpin size="large" /></div>
        <div v-else class="result-chart-wrap">
          <ChartResult :result="resultData" :style="resultChart?.definition?.style ?? {}" />
        </div>
      </template>

      <!-- ── Wizard (create / edit) ───────────────────────────────────── -->
      <template v-else-if="mode === 'wizard'">
        <div class="result-header">
          <AButton type="text" size="small" @click="backToList">
            <template #icon><ArrowLeftOutlined /></template>
          </AButton>
          <span class="result-title">{{ editingChart ? t('edit_chart') : t('new_chart') }}</span>
        </div>

        <!-- Step 1: table (+ inherited filter, when coming from a search) -->
        <div class="wizard-step1">
          <div class="form-field">
            <label class="field-label">{{ t('select_table') }}</label>
            <ASelect
              v-model:value="wizardTb"
              :options="tableOptions"
              :placeholder="t('select_placeholder')"
              show-search
              :disabled="tableLocked"
              class="w-full"
            />
          </div>
          <div v-if="originalFilter" class="filter-hint">
            <p class="step-hint">
              <FilterOutlined /> {{ t('chart_filter_inherited') }}
              <a href="#" @click.prevent="showFilterJson = !showFilterJson">
                {{ showFilterJson ? t('hide_filter_json') : t('show_filter_json') }}
              </a>
              ·
              <RouterLink :to="filterPreviewLink" target="_blank">
                {{ t('view_matching_records') }}
              </RouterLink>
            </p>
            <pre v-if="showFilterJson" class="filter-json">{{ JSON.stringify(originalFilter, null, 2) }}</pre>
          </div>
        </div>

        <ChartBuilder
          v-if="wizardTb"
          :key="wizardTb + ':' + (editingChart?.id ?? 'new')"
          :tb="wizardTb"
          :filter="originalFilter"
          :initial-definition="editingChart?.definition ?? null"
          :initial-name="editingChart?.name ?? ''"
          @save="onSaveChart"
        />
      </template>

    </div>
  </AppLayout>
</template>

<script setup>
import {
  ArrowLeftOutlined, BarChartOutlined, DeleteOutlined, EditOutlined,
  FilterOutlined, GlobalOutlined, LockOutlined, PlayCircleOutlined,
  PlusOutlined, ShareAltOutlined,
} from '@ant-design/icons-vue'
import { ref, computed, onMounted } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useToast } from '@/composables/useNotify'
import { useI18n } from '@/i18n'
import { api } from '@/api'
import { useTables } from '@/composables/useTables'
import { useAuthStore } from '@/stores/auth'
import AppLayout from '@/components/AppLayout.vue'
import {
  Button as AButton,
  Card as ACard,
  Select as ASelect,
  Spin as ASpin,
  Tag as ATag,
} from 'ant-design-vue'
import ChartBuilder from '@/components/ChartBuilder.vue'
import ChartResult from '@/components/ChartResult.vue'

const { t }               = useI18n()
const toast               = useToast()
const route               = useRoute()
const router              = useRouter()
const auth                = useAuthStore()
const { responseMessage } = api
const { tables, loadTables } = useTables()
const appName = computed(() => auth.user?.app ?? route.params.app)

// ── State ──────────────────────────────────────────────────────────────
const mode          = ref('list') // 'list' | 'result' | 'wizard'
const charts        = ref([])
const loadingCharts = ref(false)
const pendingId      = ref(null)
const pendingAction  = ref(null)
const confirmingId   = ref(null)

const resultChart   = ref(null)
const resultData    = ref(null)
const resultLoading = ref(false)

const editingChart     = ref(null)
const fromSearch       = ref(false) // arrived via DataView's "create chart from this search"
const wizardTb          = ref('')
const originalFilter    = ref(null)
const showFilterJson    = ref(false)

// Lets the user check the inherited filter against real data — opens DataView
// on the same table with this filter applied, reusing its own filter-URL
// contract (see DataView.vue applyRouteParams()) instead of building a
// second, separate results table just for this.
const filterPreviewLink = computed(() => ({
  path:  `/${appName.value}/data`,
  query: { tb: wizardTb.value, filter: JSON.stringify(originalFilter.value) },
}))

// ── Computed ───────────────────────────────────────────────────────────
const tableOptions = computed(() => tables.value.map(tb => ({ value: tb.name, label: tb.label })))

// The table can't be changed once a chart already has a definition (editing)
// or once it was picked implicitly by the search it was created from.
const tableLocked = computed(() => !!editingChart.value || fromSearch.value)

// ── Lifecycle ──────────────────────────────────────────────────────────
onMounted(async () => {
  await loadTables()
  fetchCharts()

  // Arrived from DataView's "create chart from this search" — pre-fill the
  // wizard with the table + filter already built there, matching what
  // Chart.php::getData() already accepts (search_type/querytext/join, or a
  // Directus-style filter array) — no new backend shape involved.
  const tb = route.query.tb
  if (tb && tables.value.some(entry => entry.name === tb)) {
    let filter = null
    if (route.query.filter) {
      try { filter = JSON.parse(route.query.filter) } catch { filter = null }
    }
    openWizard(null, { tb, filter })
    router.replace({ path: route.path })
  }
})

// ── List ───────────────────────────────────────────────────────────────
async function fetchCharts() {
  loadingCharts.value = true
  try {
    const res = await api.get('/api/charts')
    if (res.status === 'error') throw new Error(responseMessage(res, t))
    charts.value = res.charts ?? []
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 4000 })
  } finally {
    loadingCharts.value = false
  }
}

async function toggleShare(c) {
  pendingId.value     = c.id
  pendingAction.value = 'share'
  const shareAction = c.is_global ? 'unshare' : 'share'
  try {
    const res = await api.post(`/api/chart/${c.id}/${shareAction}`)
    if (res.status === 'error') throw new Error(responseMessage(res, t))
    c.is_global = c.is_global ? 0 : 1
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 4000 })
  } finally {
    pendingId.value     = null
    pendingAction.value = null
  }
}

function confirmDelete(c) {
  confirmingId.value = confirmingId.value === c.id ? null : c.id
}

async function doDelete(c) {
  pendingId.value     = c.id
  pendingAction.value = 'delete'
  confirmingId.value  = null
  try {
    const res = await api.delete(`/api/chart/${c.id}`)
    if (res.status === 'error') throw new Error(responseMessage(res, t))
    charts.value = charts.value.filter(r => r.id !== c.id)
    toast.add({ severity: 'success', summary: t('ok_chart_erase'), life: 2500 })
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 4000 })
  } finally {
    pendingId.value     = null
    pendingAction.value = null
  }
}

// ── Result ─────────────────────────────────────────────────────────────
async function runSavedChart(c) {
  resultChart.value   = c
  resultData.value    = null
  resultLoading.value = true
  mode.value           = 'result'
  try {
    const res = await api.post('/api/chart/data', { definition: c.definition })
    if (res.status === 'error') throw new Error(responseMessage(res, t))
    resultData.value = res
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 4000 })
  } finally {
    resultLoading.value = false
  }
}

// ── Wizard ─────────────────────────────────────────────────────────────
/**
 * Opens the wizard either to edit an existing chart (`chart` set) or to
 * create one — plain (`chart`/`context` both null) or pre-filled from a
 * DataView search (`context = { tb, filter }`, `chart` null).
 */
function openWizard(chart, context = null) {
  editingChart.value = chart
  fromSearch.value   = !chart && !!context
  wizardTb.value      = chart?.definition?.tb ?? context?.tb ?? ''
  originalFilter.value = chart?.definition?.filter ?? context?.filter ?? null
  showFilterJson.value = false
  mode.value = 'wizard'
}

function backToList() {
  mode.value          = 'list'
  editingChart.value  = null
  fromSearch.value    = false
  wizardTb.value      = ''
  originalFilter.value = null
  showFilterJson.value = false
  resultChart.value   = null
  resultData.value    = null
  fetchCharts()
}

async function onSaveChart({ name, definition }) {
  const isEdit = !!editingChart.value
  try {
    const res = isEdit
      ? await api.post(`/api/chart/${editingChart.value.id}`, { name, definition })
      : await api.post('/api/charts', { name, definition })
    if (res.status === 'error') throw new Error(responseMessage(res, t))
    toast.add({ severity: 'success', summary: t(isEdit ? 'ok_update_chart' : 'ok_save_chart'), life: 2500 })
    backToList()
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 4000 })
  }
}
</script>

<style scoped>
.charts-view {
  height: 100%;
  overflow-y: auto;
  padding: 1rem;
  max-width: 1200px;
  box-sizing: border-box;
}

.page-header {
  display: flex;
  align-items: center;
  gap: 1rem;
  margin-bottom: 1.5rem;
}
.page-header h2 { margin: 0; flex: 1; }

.loading-spinner { display: block; margin: 3rem auto; }

/* ── Charts grid ── */
.charts-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 1rem;
}

.card-title-row {
  display: flex;
  align-items: center;
  gap: 0.4rem;
}

.card-title {
  font-weight: 600;
  cursor: pointer;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.card-meta {
  font-size: 0.8rem;
  color: var(--p-text-muted-color);
  margin-top: 0.25rem;
}

.chart-shared-badge {
  color: var(--p-primary-color);
  font-size: 0.8rem;
  flex-shrink: 0;
}

.chart-confirm {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  padding-top: 0.5rem;
  margin-top: 0.5rem;
  border-top: 1px solid var(--p-content-border-color);
}
.chart-confirm-text { font-size: 0.8rem; flex: 1; }

.empty-state {
  text-align: center;
  padding: 4rem 2rem;
  color: var(--p-text-muted-color);
}
.empty-icon { font-size: 3rem; display: block; margin-bottom: 1rem; opacity: 0.3; }

/* ── Result / wizard header ── */
.result-header {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  flex-wrap: wrap;
  margin-bottom: 1rem;
}
.result-title { font-size: 1.1rem; font-weight: 600; flex: 1; }
.result-header-actions { margin-left: auto; }
.result-loading { text-align: center; padding: 2rem; }

.result-chart-wrap { height: 65vh; }

/* ── Wizard step 1 ── */
.wizard-step1 {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  max-width: 480px;
  margin-bottom: 1.25rem;
}
.form-field {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}
.field-label { font-size: 0.85rem; font-weight: 500; }
.step-hint { font-size: 0.78rem; color: var(--p-text-muted-color); margin: 0; }
.step-hint a { margin-left: 0.35rem; }
.filter-json {
  margin: 0.4rem 0 0;
  padding: 0.6rem 0.75rem;
  font-size: 0.75rem;
  background: var(--p-content-hover-background, #f5f5f5);
  border: 1px solid var(--p-content-border-color);
  border-radius: 4px;
  max-height: 200px;
  overflow: auto;
  white-space: pre-wrap;
  word-break: break-all;
}
.w-full { width: 100%; }
</style>
