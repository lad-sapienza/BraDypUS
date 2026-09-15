<template>
  <div class="chart-builder">

    <!-- ── Builder form ─────────────────────────────────────────────── -->
    <div class="builder-section">
      <div class="builder-label">{{ t('build_chart') }}</div>

      <!-- Type -->
      <div class="builder-field">
        <label class="builder-field-label">{{ t('chart_type') }}</label>
        <ASelect
          v-model:value="selectedType"
          :options="chartTypeOptions"
          size="small"
          class="builder-select"
        />
      </div>

      <!-- Metric fields -->
      <template v-if="selectedType === 'metric'">
        <div class="builder-field">
          <label class="builder-field-label">{{ t('field') }}</label>
          <ASelect
            v-model:value="selectedField"
            :options="tableFieldOptions"
            :placeholder="t('select_placeholder')"
            size="small"
            class="builder-select"
            :loading="loadingFields"
          />
        </div>
        <div class="builder-field">
          <label class="builder-field-label">{{ t('aggregate_function') }}</label>
          <ASelect
            v-model:value="selectedFunction"
            :options="functionOptions"
            size="small"
            class="builder-select"
          />
        </div>
      </template>

      <!-- Bar/line/pie/doughnut fields -->
      <template v-else>
        <div class="builder-field">
          <label class="builder-field-label">{{ t('x_field') }}</label>
          <ASelect
            v-model:value="selectedXField"
            :options="tableFieldOptions"
            :placeholder="t('select_placeholder')"
            size="small"
            class="builder-select"
            :loading="loadingFields"
          />
        </div>
        <div class="builder-field">
          <label class="builder-field-label">{{ t('y_field') }}</label>
          <ASelect
            v-model:value="selectedYField"
            :options="tableFieldOptions"
            :placeholder="t('select_placeholder')"
            size="small"
            class="builder-select"
            :loading="loadingFields"
          />
        </div>
        <div class="builder-field">
          <label class="builder-field-label">{{ t('aggregate_function') }}</label>
          <ASelect
            v-model:value="selectedYFunction"
            :options="functionOptions"
            size="small"
            class="builder-select"
          />
        </div>
      </template>

      <!-- Style options (collapsible) -->
      <AButton type="text" size="small" class="style-toggle" @click="showStyle = !showStyle">
        <template #icon><BgColorsOutlined /></template>
        {{ t('style_options') }}
      </AButton>
      <div v-if="showStyle" class="style-options">
        <div v-if="selectedType !== 'metric'" class="builder-field builder-field--inline">
          <label class="builder-field-label">{{ t('style_legend') }}</label>
          <ASelect
            v-model:value="styleLegendPosition"
            :options="legendPositionOptions"
            size="small"
            class="builder-select"
          />
        </div>
        <div v-if="!['metric', 'pie', 'doughnut'].includes(selectedType)" class="builder-field builder-field--inline">
          <label class="builder-field-label">{{ t('style_color') }}</label>
          <input v-model="styleColor" type="color" class="style-color-input" />
          <AButton type="text" size="small" :title="t('reset')" @click="styleColor = ''">
            <template #icon><CloseOutlined /></template>
          </AButton>
        </div>
        <div v-if="selectedType === 'bar'" class="builder-field builder-toggle">
          <ASwitch v-model:checked="styleHorizontal" id="styleHorizontal" />
          <label for="styleHorizontal" class="builder-toggle-label">{{ t('style_horizontal') }}</label>
        </div>
        <div v-if="!['metric', 'pie', 'doughnut'].includes(selectedType)" class="builder-field builder-field--inline">
          <label class="builder-field-label">{{ t('style_y_min') }}</label>
          <AInputNumber v-model:value="styleYMin" size="small" class="style-number-input" />
          <label class="builder-field-label">{{ t('style_y_max') }}</label>
          <AInputNumber v-model:value="styleYMax" size="small" class="style-number-input" />
        </div>
        <div class="builder-field builder-field--inline">
          <label class="builder-field-label">{{ t('style_decimals') }}</label>
          <AInputNumber v-model:value="styleDecimals" size="small" :min="0" :max="10" class="style-number-input" />
        </div>
      </div>

      <!-- Run button -->
      <AButton type="primary" size="small" :loading="running" @click="runChart">
        <template #icon><PlayCircleOutlined /></template>
        {{ t('run_chart') }}
      </AButton>
    </div>

    <!-- ── Chart output ──────────────────────────────────────────────── -->
    <div v-if="result" class="chart-output">
      <div class="chart-canvas-wrap">
        <ChartResult :result="result" :style="lastRunDefinition?.style ?? {}" />
      </div>

      <!-- Save section -->
      <div class="save-section">
        <div class="save-section-label">{{ t('save_chart_as') }}</div>
        <div class="save-row">
          <AInput
            v-model:value="newChartName"
            :placeholder="t('chart_name')"
            size="small"
            class="save-name-input"
            @keyup.enter="doSave"
          />
          <AButton type="primary" size="small" :disabled="!newChartName.trim()" @click="doSave">
            <template #icon><SaveOutlined /></template>
            {{ t('save') }}
          </AButton>
        </div>
      </div>
    </div>

  </div>
</template>

<script setup>
import { BgColorsOutlined, CloseOutlined, PlayCircleOutlined, SaveOutlined } from '@ant-design/icons-vue'
import { ref, computed, onMounted, watch } from 'vue'
import { useToast } from '@/composables/useNotify'
import { api } from '@/api'
import { useI18n } from '@/i18n'
import {
  Button as AButton,
  Input as AInput,
  InputNumber as AInputNumber,
  Select as ASelect,
  Switch as ASwitch,
} from 'ant-design-vue'
import ChartResult from '@/components/ChartResult.vue'

// ── Props / Emits ──────────────────────────────────────────────────────
const props = defineProps({
  tb:                { type: String,  default: '' },
  filter:            { type: Object,  default: null },
  initialDefinition: { type: Object,  default: null },
  initialName:       { type: String,  default: '' },
})

const emit = defineEmits(['save'])

// ── State ──────────────────────────────────────────────────────────────
const { t }              = useI18n()
const toast              = useToast()
const { responseMessage } = api

const selectedType      = ref('bar')
const selectedField     = ref(null)
const selectedFunction  = ref('COUNT')
const selectedXField    = ref(null)
const selectedYField    = ref(null)
const selectedYFunction = ref('COUNT')
const running           = ref(false)
const result            = ref(null)
const newChartName      = ref(props.initialName)

// style options
const showStyle             = ref(false)
const styleColor            = ref('')
const styleLegendPosition   = ref('top')
const styleHorizontal       = ref(false)
const styleYMin             = ref(null)
const styleYMax             = ref(null)
const styleDecimals         = ref(null)

// fields
const tableFieldOptions = ref([])
const loadingFields     = ref(false)

// ── Constants ──────────────────────────────────────────────────────────
const chartTypeOptions = computed(() => [
  { value: 'metric',   label: t('metric') },
  { value: 'bar',      label: t('bar') },
  { value: 'line',     label: 'Line' },
  { value: 'pie',      label: 'Pie' },
  { value: 'doughnut', label: 'Doughnut' },
])

const functionOptions = [
  { value: 'COUNT',          label: 'COUNT' },
  { value: 'COUNT_DISTINCT', label: 'COUNT DISTINCT' },
  { value: 'SUM',            label: 'SUM' },
  { value: 'AVG',            label: 'AVG' },
  { value: 'MIN',            label: 'MIN' },
  { value: 'MAX',            label: 'MAX' },
]

const legendPositionOptions = computed(() => [
  { value: 'top',    label: t('style_legend_top') },
  { value: 'bottom', label: t('style_legend_bottom') },
  { value: 'left',   label: t('style_legend_left') },
  { value: 'right',  label: t('style_legend_right') },
  { value: 'hidden', label: t('style_legend_hidden') },
])

// ── Lifecycle ──────────────────────────────────────────────────────────
onMounted(async () => {
  await fetchFields()
  if (props.initialDefinition) {
    loadDefinition(props.initialDefinition)
    runChart()
  }
})

watch(() => props.tb, () => {
  fetchFields()
  selectedField.value  = null
  selectedXField.value = null
  selectedYField.value = null
  result.value         = null
})

// ── Methods ────────────────────────────────────────────────────────────
async function fetchFields() {
  if (!props.tb) return
  loadingFields.value = true
  try {
    const res = await api.get(`/api/search/${props.tb}/config`)
    if (res.status === 'error') throw new Error(responseMessage(res, t))
    // getAdvancedConfig returns { fields: [{value:'tb:fieldname', label:'...'}] }
    // We want only fields belonging to props.tb, stripping the table prefix
    const raw = res.fields ?? []
    const tbPrefix = props.tb + ':'
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

function buildDefinition() {
  const def = {
    tb:   props.tb,
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
  if (props.filter) {
    def.filter = props.filter
  }
  const style = buildStyle()
  if (style) {
    def.style = style
  }
  return def
}

/** Collects active style overrides into a plain object, omitting unset ones. Returns null if none are set. */
function buildStyle() {
  const style = {}
  if (styleColor.value)                        style.color          = styleColor.value
  if (styleLegendPosition.value !== 'top')      style.legendPosition = styleLegendPosition.value
  if (styleHorizontal.value)                    style.horizontal     = true
  if (styleYMin.value != null)                  style.yMin           = styleYMin.value
  if (styleYMax.value != null)                  style.yMax           = styleYMax.value
  if (styleDecimals.value != null)              style.decimals       = styleDecimals.value
  return Object.keys(style).length ? style : null
}

/** Restores builder refs (type, fields, style) from an existing chart definition. */
function loadDefinition(def) {
  selectedType.value = def.type ?? 'bar'
  if (def.type === 'metric') {
    selectedField.value    = def.field    ?? null
    selectedFunction.value = def.function ?? 'COUNT'
  } else {
    selectedXField.value    = def.x_field    ?? null
    selectedYField.value    = def.y_field    ?? null
    selectedYFunction.value = def.y_function ?? 'COUNT'
  }
  const style = def.style ?? {}
  styleColor.value          = style.color ?? ''
  styleLegendPosition.value = style.legendPosition ?? 'top'
  styleHorizontal.value     = style.horizontal ?? false
  styleYMin.value           = style.yMin ?? null
  styleYMax.value           = style.yMax ?? null
  styleDecimals.value       = style.decimals ?? null
}

const lastRunDefinition = ref(null)

async function runChart() {
  if (!props.tb) return
  running.value = true
  result.value  = null
  const definition = buildDefinition()
  try {
    const res = await api.post('/api/chart/data', { definition })
    if (res.status === 'error') throw new Error(responseMessage(res, t))
    result.value            = res
    lastRunDefinition.value = definition
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 4000 })
  } finally {
    running.value = false
  }
}

function doSave() {
  const name = newChartName.value.trim()
  if (!name || !result.value) return
  emit('save', { name, definition: lastRunDefinition.value ?? buildDefinition() })
}
</script>

<style scoped>
.chart-builder {
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

.builder-field--inline {
  flex-direction: row;
  align-items: center;
  gap: 0.5rem;
}
.builder-field--inline .builder-field-label { flex-shrink: 0; }
.builder-field--inline .builder-select { width: auto; flex: 1; }

.style-toggle { align-self: flex-start; }

.style-options {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  padding: 0.5rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: 6px;
}

.style-color-input {
  width: 2.2rem;
  height: 1.7rem;
  padding: 0;
  border: 1px solid var(--p-content-border-color);
  border-radius: 4px;
  background: none;
  cursor: pointer;
}

.style-number-input { width: 6rem; }

/* ── Chart output ─────────────────────────────────────── */
.chart-output {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.chart-canvas-wrap {
  height: 320px;
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
</style>
