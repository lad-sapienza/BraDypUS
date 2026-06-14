<template>
  <AppLayout>
    <div class="chrono-page">

      <!-- ── Header ─────────────────────────────────────────────────── -->
      <div class="page-header">
        <div class="header-left">
          <Button
            v-if="backUrl"
            :label="backTableLabel || t('back_to_table')"
            icon="pi pi-arrow-left"
            size="small"
            severity="secondary"
            outlined
            @click="router.push(backUrl)"
          />
          <h2>{{ t('chrono_timeline') }}</h2>
        </div>
        <div class="header-filters">
          <!-- Year range -->
          <span class="filter-label">{{ t('filter_years') }}</span>
          <InputNumber
            v-model="filterFrom"
            :placeholder="t('year_from')"
            :useGrouping="false"
            style="width: 110px"
            :inputStyle="{ width: '100%' }"
            @update:modelValue="onFilterChange"
          />
          <span class="filter-sep">→</span>
          <InputNumber
            v-model="filterTo"
            :placeholder="t('year_to')"
            :useGrouping="false"
            style="width: 110px"
            :inputStyle="{ width: '100%' }"
            @update:modelValue="onFilterChange"
          />

          <!-- Table selector -->
          <MultiSelect
            v-if="allTableOptions.length > 1"
            v-model="selectedTables"
            :options="allTableOptions"
            optionLabel="label"
            optionValue="value"
            :placeholder="t('all_tables')"
            class="table-select"
            @update:modelValue="loadData"
          />

          <Button
            icon="pi pi-refresh"
            severity="secondary"
            outlined
            size="small"
            :loading="loading"
            @click="loadData"
            :title="t('reload')"
          />
        </div>
      </div>

      <!-- ── Loading / empty states ──────────────────────────────────── -->
      <div v-if="loading" class="state-msg">
        <i class="pi pi-spin pi-spinner" />
      </div>

      <div v-else-if="error" class="state-msg state-error">
        <i class="pi pi-exclamation-triangle" />
        {{ error }}
      </div>

      <div v-else-if="noFuzzyTables" class="state-msg state-info">
        <i class="pi pi-info-circle" />
        {{ t('chrono_timeline_empty') }}
      </div>

      <div v-else-if="visibleGroups.length === 0" class="state-msg state-info">
        <i class="pi pi-calendar-times" />
        {{ t('no_chrono_data') }}
      </div>

      <!-- ── Timeline canvas ─────────────────────────────────────────── -->
      <div v-else class="timeline-outer" ref="outerRef">

        <!-- Legend -->
        <div class="legend">
          <div
            v-for="(group, idx) in visibleGroups"
            :key="group.tb_id"
            class="legend-item"
          >
            <span class="legend-dot" :style="{ background: tableColor(idx) }" />
            {{ group.tb_label }}
          </div>
          <div class="legend-item">
            <span class="legend-dot" style="opacity:1;background:#888" />{{ t('chrono_certain') }}
          </div>
          <div class="legend-item">
            <span class="legend-dot" style="opacity:0.6;background:#888" />{{ t('chrono_probable') }}
          </div>
          <div class="legend-item">
            <span class="legend-dot" style="opacity:0.35;background:#888" />{{ t('chrono_uncertain') }}
          </div>
        </div>

        <!-- Scrollable timeline body -->
        <div class="timeline-body" ref="bodyRef">

          <!-- Axis ticks row -->
          <div class="timeline-row axis-row">
            <div class="row-label" />
            <div class="bar-area">
              <div
                v-for="tick in axisTicks"
                :key="tick.year"
                class="axis-tick"
                :style="{ left: yearToPercent(tick.year) + '%' }"
              >
                <span class="tick-label">{{ formatYear(tick.year) }}</span>
                <div class="tick-line" />
              </div>
            </div>
          </div>

          <!-- Groups -->
          <template v-for="(group, gIdx) in visibleGroups" :key="group.tb_id">
            <!-- Group header -->
            <div class="timeline-row group-header-row">
              <div class="row-label group-label">{{ group.tb_label }}</div>
              <div class="bar-area group-header-bar" />
            </div>

            <!-- Records -->
            <div
              v-for="record in group.records"
              :key="record.id"
              class="timeline-row record-row"
              @mouseenter="showTooltip($event, record, group)"
              @mouseleave="hideTooltip"
              @click="openRecord(group.tb_id, record.id)"
            >
              <div class="row-label record-label" :title="record.label">
                {{ record.label }}
              </div>
              <div class="bar-area">
                <!-- Ante-quem dashed extension to left -->
                <div
                  v-if="record.from === null && record.to !== null"
                  class="bar bar-dashed bar-left-inf"
                  :style="antequemStyle(record, gIdx)"
                />
                <!-- Post-quem dashed extension to right -->
                <div
                  v-if="record.to === null && record.from !== null"
                  class="bar bar-dashed bar-right-inf"
                  :style="postquemStyle(record, gIdx)"
                />
                <!-- Main bar -->
                <div
                  v-if="record.from !== null || record.to !== null"
                  class="bar"
                  :style="barStyle(record, gIdx)"
                />
              </div>
            </div>
          </template>

          <!-- Bottom axis -->
          <div class="timeline-row axis-row axis-bottom">
            <div class="row-label" />
            <div class="bar-area">
              <div
                v-for="tick in axisTicks"
                :key="tick.year"
                class="axis-tick"
                :style="{ left: yearToPercent(tick.year) + '%' }"
              >
                <div class="tick-line" />
                <span class="tick-label tick-label-bottom">{{ formatYear(tick.year) }}</span>
              </div>
            </div>
          </div>

        </div>
      </div>

      <!-- ── Tooltip ─────────────────────────────────────────────────── -->
      <Teleport to="body">
        <div
          v-if="tooltip.visible"
          class="chrono-tooltip"
          :style="{ top: tooltip.y + 'px', left: tooltip.x + 'px' }"
        >
          <div class="tooltip-title">{{ tooltip.record?.label }}</div>
          <div class="tooltip-tb">{{ tooltip.group?.tb_label }}</div>
          <div v-if="tooltip.record?.chrono_label" class="tooltip-row">
            <i class="pi pi-calendar" />
            {{ tooltip.record.chrono_label }}
          </div>
          <div class="tooltip-row">
            <i class="pi pi-arrows-h" />
            {{ formatYear(tooltip.record?.from ?? null) }}
            →
            {{ formatYear(tooltip.record?.to ?? null) }}
          </div>
          <div v-if="tooltip.record?.period" class="tooltip-row">
            <i class="pi pi-tag" />
            {{ tooltip.record.period }}
          </div>
          <div class="tooltip-row certainty-row">
            <i class="pi pi-check-circle" />
            {{ certLabel(tooltip.record?.certainty) }}
          </div>
        </div>
      </Teleport>

    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppLayout from '@/components/AppLayout.vue'
import { useI18n } from '@/i18n'
import { api } from '@/api'
import Button      from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import MultiSelect from 'primevue/multiselect'

const route  = useRoute()
const router = useRouter()
const { t }  = useI18n()

// ── Back-navigation (when launched from DataView) ──────────────────────
const backUrl        = computed(() => route.query.back ?? null)
const backTableLabel = computed(() => route.query.backLabel ?? null)

// ── State ──────────────────────────────────────────────────────────────
const loading       = ref(false)
const error         = ref(null)
const noFuzzyTables = ref(false)
const rawTables     = ref([])

const filterFrom     = ref(null)
const filterTo       = ref(null)
const selectedTables = ref(route.params.tb ? [route.params.tb] : [])

// Debounce year-filter changes
let filterTimer = null
function onFilterChange() {
  clearTimeout(filterTimer)
  filterTimer = setTimeout(loadData, 600)
}

// ── Data loading ───────────────────────────────────────────────────────
async function loadData() {
  loading.value = true
  error.value   = null
  try {
    const params = {}
    if (filterFrom.value !== null) params.from = filterFrom.value
    if (filterTo.value   !== null) params.to   = filterTo.value
    if (selectedTables.value.length > 0) {
      params.tb = selectedTables.value
    }

    const res = await api.get('/api/chrono/timeline', params)

    if (res.status !== 'success') {
      error.value = t(res.code ?? 'error')
      return
    }

    rawTables.value     = res.tables ?? []
    noFuzzyTables.value = rawTables.value.length === 0 && selectedTables.value.length === 0
  } catch (e) {
    error.value = String(e)
  } finally {
    loading.value = false
  }
}

onMounted(loadData)

// ── Derived ────────────────────────────────────────────────────────────
const allTableOptions = computed(() =>
  rawTables.value.map(g => ({ label: g.tb_label, value: g.tb_id }))
)

const visibleGroups = computed(() => {
  if (selectedTables.value.length === 0) return rawTables.value
  return rawTables.value.filter(g => selectedTables.value.includes(g.tb_id))
})

// Compute the actual displayed year extent across all visible records
const extent = computed(() => {
  let min = Infinity
  let max = -Infinity
  for (const group of visibleGroups.value) {
    for (const r of group.records) {
      if (r.from !== null) { min = Math.min(min, r.from); max = Math.max(max, r.from) }
      if (r.to   !== null) { min = Math.min(min, r.to);   max = Math.max(max, r.to)   }
    }
  }
  if (!isFinite(min)) return { min: -500, max: 500 }
  // Add 5% padding on each side
  const pad = Math.max(50, Math.round((max - min) * 0.05))
  return { min: min - pad, max: max + pad }
})

const timeRange = computed(() => extent.value.max - extent.value.min || 1)

function yearToPercent(year) {
  return ((year - extent.value.min) / timeRange.value * 100).toFixed(3)
}

// ── Axis ticks ─────────────────────────────────────────────────────────
const axisTicks = computed(() => {
  const range = timeRange.value
  let step
  if      (range > 3000) step = 1000
  else if (range > 1500) step = 500
  else if (range > 500)  step = 200
  else if (range > 200)  step = 100
  else if (range > 100)  step = 50
  else if (range > 30)   step = 20
  else if (range > 10)   step = 5
  else                   step = 1

  const start = Math.ceil(extent.value.min / step) * step
  const ticks = []
  for (let y = start; y <= extent.value.max; y += step) {
    ticks.push({ year: y })
  }
  return ticks
})

// ── Year formatting ────────────────────────────────────────────────────
function formatYear(year) {
  if (year === null || year === undefined) return '?'
  if (year < 0)  return `${Math.abs(year)} ${t('bce')}`
  if (year === 0) return '0'
  return `${year} ${t('ce')}`
}

// ── Colors ─────────────────────────────────────────────────────────────
const PALETTE = [
  '#3b82f6', // blue
  '#f97316', // orange
  '#22c55e', // green
  '#a855f7', // purple
  '#ef4444', // red
  '#14b8a6', // teal
  '#eab308', // yellow
  '#ec4899', // pink
]

function tableColor(groupIndex) {
  return PALETTE[groupIndex % PALETTE.length]
}

function certOpacity(certainty) {
  if (certainty === 1) return 0.85
  if (certainty === 2) return 0.55
  return 0.3
}

function certLabel(certainty) {
  if (certainty === 1) return t('chrono_certain')
  if (certainty === 2) return t('chrono_probable')
  return t('chrono_uncertain')
}

// ── Bar styles ─────────────────────────────────────────────────────────
function barStyle(record, gIdx) {
  const from = record.from ?? extent.value.min
  const to   = record.to   ?? extent.value.max
  const left  = yearToPercent(from)
  const right = yearToPercent(to)
  const width = Math.max(0.3, right - left)
  return {
    left:    `${left}%`,
    width:   `${width}%`,
    background: tableColor(gIdx),
    opacity: certOpacity(record.certainty),
    minWidth: '3px',
  }
}

function antequemStyle(record, gIdx) {
  // ante quem: from = −∞ → to; draw from left edge to to, dashed
  const right = yearToPercent(record.to)
  return {
    left: '0%',
    width: `${Math.max(0.5, right)}%`,
    background: tableColor(gIdx),
    opacity: certOpacity(record.certainty) * 0.6,
  }
}

function postquemStyle(record, gIdx) {
  // post quem: from → +∞; draw from from to right edge, dashed
  const left = yearToPercent(record.from)
  return {
    left: `${left}%`,
    width: `${Math.max(0.5, 100 - left)}%`,
    background: tableColor(gIdx),
    opacity: certOpacity(record.certainty) * 0.6,
  }
}

// ── Navigation ─────────────────────────────────────────────────────────
function openRecord(tbId, recordId) {
  router.push(`/${route.params.app}/record/${tbId}/${recordId}`)
}

// ── Tooltip ────────────────────────────────────────────────────────────
const tooltip = ref({ visible: false, x: 0, y: 0, record: null, group: null })

function showTooltip(evt, record, group) {
  tooltip.value = {
    visible: true,
    x: evt.clientX + 16,
    y: evt.clientY + 12,
    record,
    group,
  }
}

function hideTooltip() {
  tooltip.value.visible = false
}
</script>

<style scoped>
.chrono-page {
  display: flex;
  flex-direction: column;
  height: 100%;
  overflow: hidden;
  padding: 1.5rem;
  gap: 1rem;
}

/* ── Header ────────────────────────────────────────────────── */
.page-header {
  display: flex;
  align-items: center;
  gap: 1rem;
  flex-wrap: wrap;
  flex-shrink: 0;
}

.header-left {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  flex-shrink: 0;
}

.header-left h2 {
  font-size: 1.2rem;
  font-weight: 600;
  margin: 0;
  white-space: nowrap;
}

.header-filters {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex-wrap: wrap;
  flex: 1;
  min-width: 0;
}

.filter-label {
  font-size: 0.82rem;
  color: var(--p-text-muted-color);
  white-space: nowrap;
}

.filter-sep {
  color: var(--p-text-muted-color);
}

:deep(.table-select) {
  min-width: 160px;
  max-width: 260px;
  font-size: 0.85rem;
}

/* ── State messages ────────────────────────────────────────── */
.state-msg {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 2rem;
  color: var(--p-text-muted-color);
  font-size: 0.95rem;
  justify-content: center;
}

.state-error { color: var(--p-red-400); }
.state-info  { color: var(--p-text-muted-color); }

/* ── Legend ────────────────────────────────────────────────── */
.legend {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  flex-shrink: 0;
  font-size: 0.78rem;
  color: var(--p-text-muted-color);
}

.legend-item {
  display: flex;
  align-items: center;
  gap: 0.35rem;
}

.legend-dot {
  display: inline-block;
  width: 12px;
  height: 12px;
  border-radius: 2px;
  flex-shrink: 0;
}

/* ── Timeline outer + body ─────────────────────────────────── */
.timeline-outer {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  flex: 1;
  overflow: hidden;
}

.timeline-body {
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
}

/* ── Rows ──────────────────────────────────────────────────── */
.timeline-row {
  display: flex;
  align-items: center;
  min-height: 28px;
}

.row-label {
  width: 170px;
  min-width: 170px;
  max-width: 170px;
  padding-right: 0.5rem;
  font-size: 0.78rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  text-align: right;
  color: var(--p-text-muted-color);
  flex-shrink: 0;
}

.bar-area {
  flex: 1;
  position: relative;
  height: 100%;
  min-width: 0;
  border-left: 1px solid var(--p-content-border-color);
}

/* ── Axis ──────────────────────────────────────────────────── */
.axis-row {
  min-height: 24px;
  position: sticky;
  z-index: 2;
  background: var(--p-content-background);
}

.axis-row:first-child { top: 0; }
.axis-bottom { bottom: 0; }

.axis-tick {
  position: absolute;
  top: 0;
  height: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  transform: translateX(-50%);
  pointer-events: none;
}

.tick-line {
  width: 1px;
  flex: 1;
  background: var(--p-content-border-color);
}

.tick-label {
  font-size: 0.65rem;
  color: var(--p-text-muted-color);
  white-space: nowrap;
  line-height: 1.2;
  padding-bottom: 2px;
}

.tick-label-bottom {
  padding-bottom: 0;
  padding-top: 2px;
}

/* ── Group header row ──────────────────────────────────────── */
.group-header-row {
  background: var(--p-surface-ground);
  border-top: 1px solid var(--p-content-border-color);
  min-height: 26px;
  margin-top: 0.25rem;
}

.group-label {
  font-weight: 600;
  font-size: 0.8rem;
  color: var(--p-text-color);
  text-align: right;
}

.group-header-bar {
  background: var(--p-surface-ground);
}

/* ── Record rows ───────────────────────────────────────────── */
.record-row {
  cursor: pointer;
  transition: background 0.12s;
  min-height: 26px;
}

.record-row:hover {
  background: var(--p-content-hover-background);
}

.record-label {
  font-size: 0.75rem;
  color: var(--p-text-color);
}

/* ── Bars ──────────────────────────────────────────────────── */
.bar {
  position: absolute;
  top: 50%;
  transform: translateY(-50%);
  height: 14px;
  border-radius: 3px;
  cursor: pointer;
  transition: filter 0.15s;
}

.record-row:hover .bar {
  filter: brightness(1.15);
}

.bar-dashed {
  border-radius: 0;
  background-image: repeating-linear-gradient(
    90deg,
    currentColor 0,
    currentColor 4px,
    transparent 4px,
    transparent 8px
  );
  background-color: transparent !important;
}

/* ── Tooltip ───────────────────────────────────────────────── */
</style>

<style>
/* Global — tooltip is Teleported to body */
.chrono-tooltip {
  position: fixed;
  z-index: 9999;
  background: rgba(255, 255, 255, 0.92);
  border: 1px solid var(--p-content-border-color);
  border-radius: 6px;
  padding: 0.6rem 0.75rem;
  font-size: 0.8rem;
  max-width: 260px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.15);
  pointer-events: none;
  color: var(--p-text-color);
  line-height: 1.5;
}

.dark-mode .chrono-tooltip {
  background: rgba(30, 41, 59, 0.92);
}

.chrono-tooltip .tooltip-title {
  font-weight: 600;
  font-size: 0.88rem;
  margin-bottom: 0.15rem;
}

.chrono-tooltip .tooltip-tb {
  font-size: 0.72rem;
  color: var(--p-text-muted-color);
  margin-bottom: 0.35rem;
}

.chrono-tooltip .tooltip-row {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.78rem;
}

.chrono-tooltip .tooltip-row .pi {
  font-size: 0.72rem;
  color: var(--p-text-muted-color);
  flex-shrink: 0;
}


.chrono-tooltip .certainty-row {
  margin-top: 0.15rem;
}
</style>
