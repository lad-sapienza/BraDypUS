<template>
  <div class="chart-panel">

    <!-- ── Section A: Chart builder ────────────────────────────────── -->
    <div class="builder-section">
      <div class="builder-label">{{ t('build_chart') }}</div>

      <!-- Type -->
      <div class="builder-field">
        <label class="builder-field-label">{{ t('chart_type') }}</label>
        <Select
          v-model="selectedType"
          :options="chartTypeOptions"
          optionLabel="label"
          optionValue="value"
          size="small"
          class="builder-select"
        />
      </div>

      <!-- Metric fields -->
      <template v-if="selectedType === 'metric'">
        <div class="builder-field">
          <label class="builder-field-label">{{ t('field') }}</label>
          <Select
            v-model="selectedField"
            :options="tableFieldOptions"
            optionLabel="label"
            optionValue="value"
            :placeholder="t('select_placeholder')"
            size="small"
            class="builder-select"
            :loading="loadingFields"
          />
        </div>
        <div class="builder-field">
          <label class="builder-field-label">{{ t('aggregate_function') }}</label>
          <Select
            v-model="selectedFunction"
            :options="functionOptions"
            optionLabel="label"
            optionValue="value"
            size="small"
            class="builder-select"
          />
        </div>
      </template>

      <!-- Bar/line/pie/doughnut fields -->
      <template v-else>
        <div class="builder-field">
          <label class="builder-field-label">{{ t('x_field') }}</label>
          <Select
            v-model="selectedXField"
            :options="tableFieldOptions"
            optionLabel="label"
            optionValue="value"
            :placeholder="t('select_placeholder')"
            size="small"
            class="builder-select"
            :loading="loadingFields"
          />
        </div>
        <div class="builder-field">
          <label class="builder-field-label">{{ t('y_field') }}</label>
          <Select
            v-model="selectedYField"
            :options="tableFieldOptions"
            optionLabel="label"
            optionValue="value"
            :placeholder="t('select_placeholder')"
            size="small"
            class="builder-select"
            :loading="loadingFields"
          />
        </div>
        <div class="builder-field">
          <label class="builder-field-label">{{ t('aggregate_function') }}</label>
          <Select
            v-model="selectedYFunction"
            :options="functionOptions"
            optionLabel="label"
            optionValue="value"
            size="small"
            class="builder-select"
          />
        </div>
      </template>

      <!-- Use current filter toggle -->
      <div v-if="currentFilter" class="builder-field builder-toggle">
        <ToggleSwitch v-model="useCurrentFilter" inputId="useFilter" />
        <label for="useFilter" class="builder-toggle-label">{{ t('use_current_filter') }}</label>
      </div>

      <!-- Run button -->
      <Button
        :label="t('run_chart')"
        icon="pi pi-play"
        size="small"
        :loading="running"
        @click="runChart"
      />
    </div>

    <!-- ── Chart output ──────────────────────────────────────────────── -->
    <div v-if="result" class="chart-output">
      <div v-if="result.type === 'metric'" class="metric-display">
        <span class="metric-value">{{ result.value }}</span>
        <span class="metric-label">{{ result.label }}</span>
      </div>
      <template v-else-if="result.type">
        <BarChart
          v-if="result.type === 'bar'"
          :data="chartData"
          :options="chartOptions"
          class="chart-canvas"
        />
        <LineChart
          v-else-if="result.type === 'line'"
          :data="chartData"
          :options="chartOptions"
          class="chart-canvas"
        />
        <PieChart
          v-else-if="result.type === 'pie'"
          :data="chartData"
          :options="chartOptions"
          class="chart-canvas"
        />
        <DoughnutChart
          v-else-if="result.type === 'doughnut'"
          :data="chartData"
          :options="chartOptions"
          class="chart-canvas"
        />
      </template>

      <!-- Save section (only shown when chart ran successfully) -->
      <div class="save-section">
        <div class="save-section-label">{{ t('save_chart_as') }}</div>
        <div class="save-row">
          <InputText
            v-model="newChartName"
            :placeholder="t('chart_name')"
            size="small"
            class="save-name-input"
            @keyup.enter="doSaveChart"
          />
          <Button
            :label="t('save')"
            icon="pi pi-save"
            size="small"
            :loading="saving"
            :disabled="!newChartName.trim()"
            @click="doSaveChart"
          />
        </div>
      </div>
    </div>

    <Divider />

    <!-- ── Section B: Saved charts list ────────────────────────────── -->
    <div class="builder-label">{{ t('saved_charts') }}</div>

    <div v-if="loadingCharts" class="panel-loading">
      <ProgressSpinner style="width:24px;height:24px" />
    </div>

    <div v-else-if="filteredCharts.length === 0" class="panel-empty">
      {{ t('no_chart_available') }}
    </div>

    <ul v-else class="chart-list">
      <li
        v-for="c in filteredCharts"
        :key="c.id"
        class="chart-item"
        :class="{ 'chart-item--foreign': (c.definition?.tb ?? null) !== currentTb }"
      >
        <div class="chart-main">
          <span class="chart-name" :title="c.name">{{ c.name }}</span>
          <span
            v-if="(c.definition?.tb ?? null) !== currentTb && c.tb_label"
            class="chart-tb-label"
          >{{ c.tb_label }}</span>
          <span v-if="c.is_global" class="chart-shared-badge">
            <i class="pi pi-share-alt" :title="t('share')" />
          </span>
        </div>
        <div class="chart-actions">
          <!-- Run -->
          <Button
            icon="pi pi-play"
            size="small"
            text
            :title="t('run_chart')"
            @click="loadAndRun(c)"
          />
          <!-- Share / Unshare (owner only) -->
          <Button
            v-if="c.owned_by_me"
            :icon="c.is_global ? 'pi pi-star-fill' : 'pi pi-star'"
            size="small"
            text
            :title="c.is_global ? t('unshare') : t('share')"
            :loading="pendingId === c.id && pendingAction === 'share'"
            @click="toggleShare(c)"
          />
          <!-- Delete (owner only) -->
          <Button
            v-if="c.owned_by_me"
            icon="pi pi-trash"
            size="small"
            text
            severity="danger"
            :title="t('delete')"
            :loading="pendingId === c.id && pendingAction === 'delete'"
            @click="confirmDelete(c)"
          />
        </div>

        <!-- Inline confirm -->
        <div v-if="confirmingId === c.id" class="chart-confirm">
          <span class="chart-confirm-text">{{ t('confirm_delete') }}</span>
          <Button :label="t('yes')" size="small" severity="danger" @click="doDelete(c)" />
          <Button :label="t('no')" size="small" severity="secondary" text @click="confirmingId = null" />
        </div>
      </li>
    </ul>

  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useToast } from 'primevue/usetoast'
import { api } from '@/api'
import { useI18n } from '@/i18n'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Divider from 'primevue/divider'
import ProgressSpinner from 'primevue/progressspinner'
import Select from 'primevue/select'
import ToggleSwitch from 'primevue/toggleswitch'

import {
  Bar as BarChart,
  Line as LineChart,
  Pie as PieChart,
  Doughnut as DoughnutChart,
} from 'vue-chartjs'
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  BarElement,
  LineElement,
  PointElement,
  ArcElement,
  Title,
  Tooltip,
  Legend,
} from 'chart.js'

ChartJS.register(
  CategoryScale,
  LinearScale,
  BarElement,
  LineElement,
  PointElement,
  ArcElement,
  Title,
  Tooltip,
  Legend
)

// ── Props ──────────────────────────────────────────────────────────────
const props = defineProps({
  currentTb:     { type: String,  default: '' },
  currentFilter: { type: Object,  default: null },
})

// ── State ──────────────────────────────────────────────────────────────
const { t }              = useI18n()
const toast              = useToast()
const { responseMessage } = api

// builder
const selectedType     = ref('bar')
const selectedField    = ref(null)
const selectedFunction = ref('COUNT')
const selectedXField   = ref(null)
const selectedYField   = ref(null)
const selectedYFunction = ref('COUNT')
const useCurrentFilter  = ref(true)
const running           = ref(false)
const result            = ref(null)
const newChartName      = ref('')
const saving            = ref(false)

// fields
const tableFieldOptions = ref([])
const loadingFields     = ref(false)

// saved charts
const charts        = ref([])
const loadingCharts = ref(false)
const pendingId     = ref(null)
const pendingAction = ref(null)
const confirmingId  = ref(null)

// ── Constants ──────────────────────────────────────────────────────────
const chartTypeOptions = computed(() => [
  { value: 'metric',   label: t('metric') },
  { value: 'bar',      label: t('bar') },
  { value: 'line',     label: 'Line' },
  { value: 'pie',      label: 'Pie' },
  { value: 'doughnut', label: 'Doughnut' },
])

const functionOptions = [
  { value: 'COUNT', label: 'COUNT' },
  { value: 'SUM',   label: 'SUM' },
  { value: 'AVG',   label: 'AVG' },
  { value: 'MIN',   label: 'MIN' },
  { value: 'MAX',   label: 'MAX' },
]

// ── Computed ───────────────────────────────────────────────────────────
const chartData = computed(() => ({
  labels: result.value?.labels ?? [],
  datasets: [{
    label: selectedYField.value || 'value',
    data: result.value?.data ?? [],
    backgroundColor: [
      '#4f81bd', '#c0504d', '#9bbb59', '#8064a2', '#4bacc6', '#f79646',
    ].slice(0, result.value?.labels?.length ?? 1),
  }],
}))

const chartOptions = {
  responsive: true,
  maintainAspectRatio: false,
}

const filteredCharts = computed(() => {
  const own   = charts.value.filter(c => (c.definition?.tb ?? null) === props.currentTb)
  const other = charts.value.filter(c => (c.definition?.tb ?? null) !== props.currentTb)
  const sort  = arr => [...arr].sort((a, b) => a.name.localeCompare(b.name))
  return [...sort(own), ...sort(other)]
})

// ── Lifecycle ──────────────────────────────────────────────────────────
onMounted(() => {
  fetchFields()
  fetchCharts()
})

watch(() => props.currentTb, () => {
  fetchFields()
  selectedField.value  = null
  selectedXField.value = null
  selectedYField.value = null
  result.value         = null
})

// ── Methods ────────────────────────────────────────────────────────────
async function fetchFields() {
  if (!props.currentTb) return
  loadingFields.value = true
  try {
    const res = await api.get(`/api/search/${props.currentTb}/config`)
    if (res.status === 'error') throw new Error(responseMessage(res, t))
    // getAdvancedConfig returns { fields: [{value:'tb:fieldname', label:'...'}] }
    // We want only fields belonging to currentTb, stripping the table prefix
    const raw = res.fields ?? []
    const tbPrefix = props.currentTb + ':'
    tableFieldOptions.value = [
      { value: 'id', label: 'id' },
      ...raw
        .filter(f => f.value.startsWith(tbPrefix))
        .map(f => ({ value: f.value.split(':')[1], label: f.label })),
    ]
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 4000 })
  } finally {
    loadingFields.value = false
  }
}

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

function buildDefinition() {
  const def = {
    tb:   props.currentTb,
    type: selectedType.value,
  }
  if (selectedType.value === 'metric') {
    def.field    = selectedField.value
    def.function = selectedFunction.value
  } else {
    def.x_field    = selectedXField.value
    def.y_field    = selectedYField.value
    def.y_function = selectedYFunction.value
  }
  if (useCurrentFilter.value && props.currentFilter) {
    def.filter = props.currentFilter
  }
  return def
}

async function runChart() {
  if (!props.currentTb) return
  running.value = true
  result.value  = null
  try {
    const res = await api.post('/api/chart/data', {
      definition: buildDefinition(),
    })
    if (res.status === 'error') throw new Error(responseMessage(res, t))
    result.value = res
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 4000 })
  } finally {
    running.value = false
  }
}

async function doSaveChart() {
  const name = newChartName.value.trim()
  if (!name || !result.value) return
  saving.value = true
  try {
    const res = await api.post('/api/charts', {
      name,
      definition: buildDefinition(),
    })
    if (res.status === 'error') throw new Error(responseMessage(res, t))
    charts.value.unshift(res.chart)
    newChartName.value = ''
    toast.add({ severity: 'success', summary: t('ok_save_chart'), life: 2500 })
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 4000 })
  } finally {
    saving.value = false
  }
}

function loadAndRun(c) {
  if (!c.definition) return
  const def = c.definition
  selectedType.value = def.type ?? 'bar'
  if (def.type === 'metric') {
    selectedField.value    = def.field    ?? null
    selectedFunction.value = def.function ?? 'COUNT'
  } else {
    selectedXField.value   = def.x_field    ?? null
    selectedYField.value   = def.y_field    ?? null
    selectedYFunction.value = def.y_function ?? 'COUNT'
  }
  // Don't restore filter toggle — user decides
  runChart()
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
</script>

<style scoped>
.chart-panel {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

/* ── Builder ──────────────────────────────────────────── */
.builder-label {
  font-size: 0.78rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--p-text-muted-color);
  margin-bottom: 0.2rem;
}

.builder-section {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.builder-field {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
}

.builder-field-label {
  font-size: 0.75rem;
  color: var(--p-text-muted-color);
}

.builder-select { width: 100%; }

.builder-toggle {
  flex-direction: row;
  align-items: center;
  gap: 0.5rem;
}

.builder-toggle-label {
  font-size: 0.85rem;
}

/* ── Chart output ─────────────────────────────────────── */
.chart-output {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.chart-canvas {
  max-height: 260px;
}

.metric-display {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 1rem;
  gap: 0.3rem;
  background: var(--p-content-hover-background);
  border-radius: 8px;
}

.metric-value {
  font-size: 2rem;
  font-weight: 700;
  color: var(--p-primary-color);
}

.metric-label {
  font-size: 0.82rem;
  color: var(--p-text-muted-color);
}

/* ── Save section ─────────────────────────────────────── */
.save-section-label {
  font-size: 0.78rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--p-text-muted-color);
  margin-bottom: 0.4rem;
}

.save-row {
  display: flex;
  gap: 0.4rem;
  align-items: center;
}

.save-name-input { flex: 1; min-width: 0; }

/* ── Loading / empty ──────────────────────────────────── */
.panel-loading {
  display: flex;
  justify-content: center;
  padding: 1.5rem 0;
}

.panel-empty {
  font-size: 0.85rem;
  color: var(--p-text-muted-color);
  text-align: center;
  padding: 1rem 0;
}

/* ── Chart list ───────────────────────────────────────── */
.chart-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.chart-item {
  border: 1px solid var(--p-content-border-color);
  border-radius: 6px;
  padding: 0.4rem 0.6rem;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  background: var(--bdus-surface);
}

.chart-item--foreign { opacity: 0.85; }

.chart-main {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  flex-wrap: wrap;
  min-width: 0;
}

.chart-name {
  font-size: 0.88rem;
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  flex: 1;
  min-width: 0;
}

.chart-tb-label {
  font-size: 0.75rem;
  color: var(--p-text-muted-color);
  background: var(--p-content-hover-background);
  border-radius: 4px;
  padding: 0.1rem 0.35rem;
  flex-shrink: 0;
}

.chart-shared-badge {
  color: var(--p-primary-color);
  font-size: 0.8rem;
  flex-shrink: 0;
}

.chart-actions {
  display: flex;
  gap: 0.1rem;
  align-items: center;
}

.chart-confirm {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  padding-top: 0.2rem;
  border-top: 1px solid var(--p-content-border-color);
}

.chart-confirm-text {
  font-size: 0.8rem;
  flex: 1;
}
</style>
