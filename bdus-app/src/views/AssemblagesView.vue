<template>
  <AppLayout>
    <div class="assemblages-view">

      <!-- ── Page header ───────────────────────────────────────────────── -->
      <div class="page-header">
        <h2>{{ t('assemblage_analysis') }}</h2>
        <Button icon="pi pi-plus" :label="t('new_analysis')" size="small" @click="openWizard(null)" />
      </div>

      <ProgressSpinner v-if="loading" class="loading-spinner" />

      <template v-else>

        <!-- ── Result view ─────────────────────────────────────────────── -->
        <div v-if="openResult" class="result-view">
          <div class="result-header">
            <Button icon="pi pi-arrow-left" text size="small" @click="closeResult" />
            <span class="result-title">{{ openResult.name }}</span>
            <Tag v-if="openResult.is_global" :value="t('shared')" severity="success" />
            <div class="result-header-actions">
              <Button icon="pi pi-pencil" text size="small" :title="t('edit_analysis')" @click="openWizard(openResult)" />
            </div>
          </div>

          <div v-if="resultLoading" class="result-loading"><ProgressSpinner /></div>
          <div v-else-if="resultError" class="result-error">{{ resultError }}</div>

          <template v-else-if="resultData && resultData.groups.length > 0">
            <Tabs value="table" class="result-tabs">
              <TabList>
                <Tab value="table">{{ t('pivot_table') }}</Tab>
                <Tab value="chart">{{ t('bar_chart') }}</Tab>
              </TabList>
              <TabPanels>
                <!-- Pivot table -->
                <TabPanel value="table">
                  <div class="pivot-toolbar">
                    <label class="heatmap-toggle">
                      <ToggleSwitch v-model="heatmapEnabled" inputId="heatmap-sw" />
                      <span>{{ t('heatmap') }}</span>
                    </label>
                  </div>
                  <div class="pivot-scroll">
                    <table class="pivot-table">
                      <thead>
                        <tr>
                          <th class="pivot-corner"></th>
                          <th v-for="char in resultData.chars" :key="char" class="pivot-char-header">
                            {{ char || '—' }}
                          </th>
                          <th class="pivot-total-header">Tot</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr v-for="group in resultData.groups" :key="group">
                          <th class="pivot-group-header">{{ group || '—' }}</th>
                          <td
                            v-for="char in resultData.chars"
                            :key="char"
                            class="pivot-cell"
                            :style="cellBgStyle(resultData.data[group]?.[char] ?? 0)"
                          >
                            {{ resultData.data[group]?.[char] ?? 0 }}
                          </td>
                          <td class="pivot-total">{{ rowTotal(group) }}</td>
                        </tr>
                      </tbody>
                      <tfoot>
                        <tr class="pivot-footer">
                          <th>Tot</th>
                          <td v-for="char in resultData.chars" :key="char" class="pivot-total">
                            {{ colTotal(char) }}
                          </td>
                          <td class="pivot-total pivot-grand">{{ grandTotal }}</td>
                        </tr>
                      </tfoot>
                    </table>
                  </div>
                  <div class="result-actions">
                    <Button icon="pi pi-download" :label="t('export_csv')" size="small" text @click="exportCsv" />
                  </div>
                </TabPanel>

                <!-- Stacked bar chart -->
                <TabPanel value="chart">
                  <div class="chart-wrapper">
                    <Bar :data="chartData" :options="chartOptions" />
                  </div>
                </TabPanel>
              </TabPanels>
            </Tabs>
          </template>

          <div v-else-if="resultData" class="result-empty">
            {{ t('no_results') }}
          </div>
        </div>

        <!-- ── Analysis list ────────────────────────────────────────────── -->
        <template v-else>
          <div v-if="analyses.length === 0" class="empty-state">
            <i class="pi pi-chart-bar empty-icon" />
            <p>{{ t('no_analyses_yet') }}</p>
          </div>

          <div v-else class="analyses-grid">
            <Card v-for="a in analyses" :key="a.id" class="analysis-card">
              <template #content>
                <div class="card-title">{{ a.name }}</div>
                <div class="card-meta">
                  <span>{{ a.definition?.source_tb }}</span>
                  <span class="meta-sep">·</span>
                  <span>{{ a.definition?.char_field }}</span>
                  <span class="meta-sep">→</span>
                  <span>{{ a.definition?.group_field }}</span>
                </div>
                <Tag v-if="a.is_global" :value="t('shared')" severity="success" class="card-tag" />
              </template>
              <template #footer>
                <div class="card-actions">
                  <Button icon="pi pi-play" :label="t('run')" size="small" @click="openAnalysis(a)" />
                  <Button icon="pi pi-pencil" text size="small" :title="t('edit_analysis')" @click="openWizard(a)" />
                  <Button
                    v-if="a.owned_by_me"
                    :icon="a.is_global ? 'pi pi-lock' : 'pi pi-globe'"
                    text size="small"
                    :title="a.is_global ? t('unshare') : t('share')"
                    @click="toggleShare(a)"
                  />
                  <Button
                    v-if="a.owned_by_me"
                    icon="pi pi-trash" text size="small" severity="danger"
                    :title="t('delete')"
                    @click="confirmDelete(a)"
                  />
                </div>
              </template>
            </Card>
          </div>
        </template>
      </template>

      <!-- ── Wizard Dialog ─────────────────────────────────────────────── -->
      <Dialog
        v-model:visible="wizardVisible"
        :header="editingId ? t('edit_analysis') : t('new_analysis')"
        modal
        :style="{ width: '700px', maxWidth: '95vw' }"
        :closable="true"
      >
        <div class="wizard">

          <!-- Step indicator + breadcrumb -->
          <div class="wizard-header">
            <div class="step-bar">
              <div
                v-for="s in TOTAL_STEPS"
                :key="s"
                class="step-dot"
                :class="{ active: currentStep === s, done: currentStep > s }"
                :title="s < currentStep ? stepCrumbLabels[s - 1] : undefined"
                @click="s < currentStep ? goToStep(s) : undefined"
              >{{ s }}</div>
            </div>
            <nav v-if="currentStep > 1" class="wizard-breadcrumb">
              <template v-for="s in currentStep" :key="s">
                <i v-if="s > 1" class="pi pi-angle-right breadcrumb-sep" />
                <button
                  v-if="s < currentStep"
                  class="breadcrumb-item breadcrumb-done"
                  @click="goToStep(s)"
                  :title="stepCrumbLabels[s - 1]"
                >{{ stepCrumbLabels[s - 1] }}</button>
                <span v-else class="breadcrumb-item breadcrumb-current">
                  {{ t(`wizard_step${s}_title`) }}
                </span>
              </template>
            </nav>
          </div>

          <!-- ── Step 1: Name + source type ─────────────────────────── -->
          <div v-if="currentStep === 1" class="wizard-step">
            <h3 class="step-title">{{ t('wizard_step1_title') }}</h3>
            <div class="form-field">
              <label class="field-label">{{ t('analysis_name') }}</label>
              <InputText
                v-model="wizard.name"
                :placeholder="t('analysis_name_placeholder')"
                class="w-full"
                autofocus
              />
            </div>
            <div class="form-field">
              <label class="field-label">{{ t('source_type') }}</label>
              <div class="radio-group">
                <div class="radio-item">
                  <RadioButton v-model="wizard.source_type" value="table" inputId="st-table" />
                  <label for="st-table">{{ t('source_table') }}</label>
                </div>
                <div class="radio-item">
                  <RadioButton v-model="wizard.source_type" value="plugin" inputId="st-plugin" />
                  <label for="st-plugin">{{ t('source_plugin') }}</label>
                </div>
              </div>
            </div>
          </div>

          <!-- ── Step 2: Source table ────────────────────────────────── -->
          <div v-if="currentStep === 2" class="wizard-step">
            <h3 class="step-title">{{ t('wizard_step2_title') }}</h3>
            <div class="form-field">
              <label class="field-label">
                {{ wizard.source_type === 'plugin' ? t('select_plugin') : t('select_table') }}
              </label>
              <Select
                v-model="wizard.source_tb"
                :options="availableSourceOptions"
                optionLabel="label"
                optionValue="name"
                :placeholder="t('select_placeholder')"
                class="w-full"
                :loading="sourcesLoading"
                filter
              >
                <template #option="{ option }">
                  <div>
                    <div>{{ option.label }}</div>
                    <div v-if="option.parent_label" class="option-sub">{{ option.parent_label }}</div>
                  </div>
                </template>
              </Select>
            </div>
          </div>

          <!-- ── Step 3: Char field ──────────────────────────────────── -->
          <div v-if="currentStep === 3" class="wizard-step">
            <h3 class="step-title">{{ t('wizard_step3_title') }}</h3>
            <p class="step-hint">{{ t('wizard_step3_hint') }}</p>
            <div class="form-field">
              <Select
                v-model="wizard.char_field"
                :options="sourceFieldOptions"
                optionLabel="label"
                optionValue="name"
                :placeholder="t('select_field')"
                class="w-full"
                :loading="metaLoading"
                filter
              />
            </div>
          </div>

          <!-- ── Step 4: Group path builder ─────────────────────────── -->
          <div v-if="currentStep === 4" class="wizard-step">
            <h3 class="step-title">{{ t('wizard_step4_title') }}</h3>
            <p class="step-hint">{{ t('wizard_step4_hint') }}</p>

            <!-- Current path breadcrumb -->
            <div class="path-crumb">
              <span class="path-node">{{ wizard.source_tb }}</span>
              <template v-for="step in wizard.group_path" :key="step.join_tb">
                <i class="pi pi-chevron-right path-arrow" />
                <span class="path-node">{{ step.join_tb }}</span>
              </template>
            </div>

            <!-- Field selector for current table -->
            <div class="form-field">
              <label class="field-label">{{ t('field_in_table', pathCurrentTb) }}</label>
              <Select
                v-model="pathSelectedField"
                :options="pathCurrentFieldOptions"
                optionLabel="label"
                optionValue="name"
                :placeholder="t('select_field')"
                class="w-full"
                :loading="pathMetaLoading"
                filter
              />
            </div>

            <!-- FK action buttons -->
            <div v-if="pathSelectedField" class="path-actions">
              <div v-if="selectedFieldIsFK" class="fk-info">
                <i class="pi pi-link" />
                {{ t('fk_field_info', selectedFieldFkLabel) }}
              </div>
              <div class="action-row">
                <Button
                  :label="t('use_as_group_field')"
                  :icon="wizard.group_field === pathSelectedField ? 'pi pi-check-circle' : 'pi pi-check'"
                  size="small"
                  :severity="wizard.group_field === pathSelectedField ? 'success' : 'primary'"
                  @click="useAsGroupField"
                />
                <Button
                  v-if="selectedFieldIsFK && wizard.group_path.length < 4"
                  :label="t('traverse_to', selectedFieldFkTb)"
                  icon="pi pi-arrow-right"
                  iconPos="right"
                  size="small"
                  severity="secondary"
                  @click="addPathStep"
                />
              </div>
            </div>

            <!-- Confirmation + remove step -->
            <div class="path-footer">
              <div v-if="wizard.group_field" class="group-field-selected">
                <i class="pi pi-check-circle" style="color: var(--p-green-500)" />
                {{ t('group_field_selected') }}: <strong>{{ wizard.group_field }}</strong>
                <span v-if="wizard.group_path.length > 0"> in {{ pathCurrentTb }}</span>
              </div>
              <Button
                v-if="wizard.group_path.length > 0"
                :label="t('remove_last_step')"
                icon="pi pi-undo"
                text size="small"
                severity="secondary"
                @click="removeLastStep"
              />
            </div>
          </div>

          <!-- ── Step 5: Measure ─────────────────────────────────────── -->
          <div v-if="currentStep === 5" class="wizard-step">
            <h3 class="step-title">{{ t('wizard_step5_title') }}</h3>
            <div class="form-field">
              <div class="radio-group">
                <div class="radio-item">
                  <RadioButton v-model="wizard.measure" value="count" inputId="m-count" />
                  <label for="m-count">{{ t('measure_count') }}</label>
                </div>
                <div class="radio-item">
                  <RadioButton v-model="wizard.measure" value="sum" inputId="m-sum" />
                  <label for="m-sum">{{ t('measure_sum') }}</label>
                </div>
                <div class="radio-item">
                  <RadioButton v-model="wizard.measure" value="count_distinct" inputId="m-cd" />
                  <label for="m-cd">{{ t('measure_count_distinct') }}</label>
                </div>
              </div>
            </div>
            <div v-if="wizard.measure !== 'count'" class="form-field">
              <label class="field-label">{{ t('measure_field') }}</label>
              <Select
                v-model="wizard.measure_field"
                :options="measureFieldOptions"
                optionLabel="label"
                optionValue="name"
                :placeholder="t('select_field')"
                class="w-full"
                filter
              />
            </div>
            <div class="form-field">
              <label class="field-label">{{ t('measure_label') }}</label>
              <InputText
                v-model="wizard.measure_label"
                :placeholder="t('measure_label_placeholder')"
                class="w-full"
              />
            </div>
          </div>

          <!-- ── Step 6: Filters ─────────────────────────────────────── -->
          <div v-if="currentStep === 6" class="wizard-step">
            <h3 class="step-title">{{ t('wizard_step6_title') }}</h3>
            <p class="step-hint">{{ t('wizard_step6_hint') }}</p>
            <div v-for="(filter, i) in wizard.filters" :key="i" class="filter-row">
              <Select
                v-model="filter.field"
                :options="sourceFieldOptions"
                optionLabel="label"
                optionValue="name"
                :placeholder="t('field')"
                size="small"
                class="filter-field"
              />
              <Select
                v-model="filter.op"
                :options="filterOpOptions"
                optionLabel="label"
                optionValue="value"
                size="small"
                class="filter-op"
              />
              <InputText
                v-model="filter.value"
                :placeholder="t('value')"
                size="small"
                class="filter-value"
              />
              <Button
                icon="pi pi-times"
                text size="small"
                severity="danger"
                @click="wizard.filters.splice(i, 1)"
              />
            </div>
            <Button
              icon="pi pi-plus"
              :label="t('add_filter')"
              size="small"
              text
              @click="wizard.filters.push({ field: '', op: '_eq', value: '' })"
            />
          </div>

          <!-- ── Step 7: Preview + save ──────────────────────────────── -->
          <div v-if="currentStep === 7" class="wizard-step">
            <h3 class="step-title">{{ t('wizard_step7_title') }}</h3>
            <div class="preview-summary">
              <div><strong>{{ t('analysis_name') }}:</strong> {{ wizard.name }}</div>
              <div><strong>{{ t('source') }}:</strong> {{ wizard.source_tb }}</div>
              <div><strong>{{ t('char_field') }}:</strong> {{ wizard.char_field }}</div>
              <div><strong>{{ t('group_by') }}:</strong> {{ pathDescription }}</div>
              <div>
                <strong>{{ t('measure') }}:</strong>
                {{ wizard.measure }}<span v-if="wizard.measure_field"> ({{ wizard.measure_field }})</span>
              </div>
              <div v-if="wizard.filters.length > 0">
                <strong>{{ t('active_filters') }}:</strong> {{ wizard.filters.length }}
              </div>
            </div>

            <Button
              :label="t('run_preview')"
              icon="pi pi-play"
              size="small"
              class="preview-btn"
              :loading="previewLoading"
              @click="runPreview"
            />

            <div v-if="previewResult" class="preview-result">
              <p class="preview-stats">
                {{ previewResult.groups.length }} {{ t('groups') }},
                {{ previewResult.chars.length }} {{ t('characteristics') }}
              </p>
              <div class="pivot-scroll small">
                <table class="pivot-table">
                  <thead>
                    <tr>
                      <th class="pivot-corner"></th>
                      <th v-for="char in previewResult.chars.slice(0, 6)" :key="char" class="pivot-char-header">
                        {{ char || '—' }}
                      </th>
                      <th v-if="previewResult.chars.length > 6" class="pivot-ellipsis">…</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="group in previewResult.groups.slice(0, 5)" :key="group">
                      <th class="pivot-group-header">{{ group || '—' }}</th>
                      <td v-for="char in previewResult.chars.slice(0, 6)" :key="char" class="pivot-cell">
                        {{ previewResult.data[group]?.[char] ?? 0 }}
                      </td>
                      <td v-if="previewResult.chars.length > 6" class="pivot-ellipsis">…</td>
                    </tr>
                    <tr v-if="previewResult.groups.length > 5">
                      <td :colspan="Math.min(previewResult.chars.length, 6) + 2" class="pivot-ellipsis">…</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- ── Wizard navigation ───────────────────────────────────── -->
          <Divider />
          <div class="wizard-nav">
            <Button
              v-if="currentStep > 1"
              :label="t('back')"
              icon="pi pi-chevron-left"
              size="small"
              text
              @click="currentStep--"
            />
            <div class="nav-spacer" />
            <Button
              v-if="currentStep < TOTAL_STEPS"
              :label="t('next')"
              icon="pi pi-chevron-right"
              iconPos="right"
              size="small"
              :disabled="!canGoNext"
              @click="goNext"
            />
            <Button
              v-else
              :label="t('save_analysis')"
              icon="pi pi-check"
              size="small"
              :loading="saving"
              :disabled="!wizard.name || !previewResult"
              @click="doSave"
            />
          </div>
        </div>
      </Dialog>

    </div>
  </AppLayout>
</template>

<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { useToast }   from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import { useI18n }    from '@/i18n'
import { api }        from '@/api'
import AppLayout      from '@/components/AppLayout.vue'
import Button         from 'primevue/button'
import Card           from 'primevue/card'
import Dialog         from 'primevue/dialog'
import Divider        from 'primevue/divider'
import InputText      from 'primevue/inputtext'
import ProgressSpinner from 'primevue/progressspinner'
import RadioButton    from 'primevue/radiobutton'
import Select         from 'primevue/select'
import ToggleSwitch   from 'primevue/toggleswitch'
import Tab            from 'primevue/tab'
import TabList        from 'primevue/tablist'
import TabPanel       from 'primevue/tabpanel'
import TabPanels      from 'primevue/tabpanels'
import Tabs           from 'primevue/tabs'
import Tag            from 'primevue/tag'
import { Bar }        from 'vue-chartjs'
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  BarElement,
  Title,
  Tooltip,
  Legend,
} from 'chart.js'

ChartJS.register(CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend)

const toast   = useToast()
const confirm = useConfirm()
const { t }   = useI18n()

const TOTAL_STEPS = 7
const PALETTE = [
  '#4f81bd','#c0504d','#9bbb59','#8064a2','#4bacc6','#f79646',
  '#1f497d','#833c00','#4f6228','#3f3151','#17375e','#984807',
]

// ── State ─────────────────────────────────────────────────────────────────

const loading     = ref(false)
const analyses    = ref([])
const openResult  = ref(null)
const resultData  = ref(null)
const resultLoading = ref(false)
const resultError   = ref(null)

// Wizard state
const wizardVisible = ref(false)
const editingId     = ref(null)
const currentStep   = ref(1)
const saving        = ref(false)
const previewLoading = ref(false)
const previewResult  = ref(null)

const wizard = reactive({
  name:         '',
  source_type:  'table',
  source_tb:    '',
  char_field:   '',
  group_path:   [],
  group_field:  '',
  measure:      'count',
  measure_field: '',
  measure_label: '',
  filters:      [],
})

// Sources (for step 2)
const sourcesLoading = ref(false)
const allSources     = ref({ tables: [], plugins: [] })

// Source table meta (for step 3 char field + step 5 measure field)
const metaLoading  = ref(false)
const sourceMeta   = ref(null)

// Path builder (for step 4)
const pathSelectedField = ref('')
const pathMetaLoading   = ref(false)
const pathCurrentMeta   = ref(null)

// ── Computed ──────────────────────────────────────────────────────────────

const availableSourceOptions = computed(() => {
  if (wizard.source_type === 'plugin') {
    return allSources.value.plugins
  }
  return allSources.value.tables
})

const sourceFieldOptions = computed(() => {
  return (sourceMeta.value?.fields ?? []).map(f => ({
    name:  f.name,
    label: f.label || f.name,
  }))
})

const measureFieldOptions = computed(() => {
  const fields = sourceMeta.value?.fields ?? []
  if (wizard.measure === 'sum') {
    return fields.filter(f => f.is_numeric).map(f => ({ name: f.name, label: f.label || f.name }))
  }
  return fields.map(f => ({ name: f.name, label: f.label || f.name }))
})

const pathCurrentTb = computed(() => {
  if (wizard.group_path.length === 0) return wizard.source_tb
  return wizard.group_path[wizard.group_path.length - 1].join_tb
})

const pathCurrentFieldOptions = computed(() => {
  return (pathCurrentMeta.value?.fields ?? []).map(f => ({
    name:  f.name,
    label: f.is_fk ? `${f.label || f.name} → ${f.fk_label}` : (f.label || f.name),
  }))
})

const selectedFieldMeta = computed(() =>
  (pathCurrentMeta.value?.fields ?? []).find(f => f.name === pathSelectedField.value) ?? null
)
const selectedFieldIsFK  = computed(() => selectedFieldMeta.value?.is_fk ?? false)
const selectedFieldFkTb  = computed(() => selectedFieldMeta.value?.fk_tb ?? '')
const selectedFieldFkPk  = computed(() => selectedFieldMeta.value?.fk_pk ?? 'id')
const selectedFieldFkLabel = computed(() => selectedFieldMeta.value?.fk_label ?? selectedFieldFkTb.value)

const pathDescription = computed(() => {
  const parts = [wizard.source_tb]
  for (const s of wizard.group_path) parts.push(s.join_tb)
  return parts.join(' → ') + (wizard.group_field ? ` : ${wizard.group_field}` : '')
})

const stepCrumbLabels = computed(() => {
  const sourceTbLabel = wizard.source_type === 'plugin'
    ? (allSources.value.plugins.find(p => p.name === wizard.source_tb)?.label ?? wizard.source_tb)
    : (allSources.value.tables.find(t => t.name === wizard.source_tb)?.label ?? wizard.source_tb)

  const charFieldLabel = (sourceMeta.value?.fields ?? []).find(f => f.name === wizard.char_field)?.label ?? wizard.char_field

  const measureMap = {
    count:          t('measure_count'),
    sum:            t('measure_sum'),
    count_distinct: t('measure_count_distinct'),
  }

  return [
    `${wizard.name}`,
    sourceTbLabel || '—',
    charFieldLabel || '—',
    pathDescription.value || '—',
    measureMap[wizard.measure] ?? wizard.measure,
    wizard.filters.length ? `${wizard.filters.length} ${t('active_filters').toLowerCase()}` : '—',
    t('wizard_step7_title'),
  ]
})

const filterOpOptions = computed(() => [
  { value: '_eq',          label: t('is_exactly') },
  { value: '_icontains',   label: t('contains') },
  { value: '_ncontains',   label: t('doesnt_contain') },
  { value: '_starts_with', label: t('starts_with') },
  { value: '_ends_with',   label: t('ends_with') },
  { value: '_empty',       label: t('is_empty') },
  { value: '_nempty',      label: t('is_not_empty') },
  { value: '_gt',          label: t('bigger') },
  { value: '_lt',          label: t('smaller') },
])

const canGoNext = computed(() => {
  switch (currentStep.value) {
    case 1: return wizard.name.trim() !== ''
    case 2: return wizard.source_tb !== ''
    case 3: return wizard.char_field !== ''
    case 4: return wizard.group_field !== ''
    case 5: return wizard.measure === 'count' || wizard.measure_field !== ''
    default: return true
  }
})

// ── Chart data ────────────────────────────────────────────────────────────

const chartData = computed(() => {
  if (!resultData.value) return { labels: [], datasets: [] }
  const { chars, groups, data } = resultData.value
  return {
    labels: groups.map(g => g || '—'),
    datasets: chars.map((char, i) => ({
      label:           char || '—',
      data:            groups.map(g => data[g]?.[char] ?? 0),
      backgroundColor: PALETTE[i % PALETTE.length] + 'bb',
      borderColor:     PALETTE[i % PALETTE.length],
      borderWidth:     1,
    })),
  }
})

const chartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  scales: {
    x: { stacked: true },
    y: { stacked: true, beginAtZero: true },
  },
  plugins: {
    legend:  { position: 'bottom' },
    tooltip: { mode: 'index', intersect: false },
  },
}

// ── Pivot helpers ─────────────────────────────────────────────────────────

function rowTotal(group) {
  if (!resultData.value) return 0
  return resultData.value.chars.reduce((s, c) => s + (resultData.value.data[group]?.[c] ?? 0), 0)
}

function colTotal(char) {
  if (!resultData.value) return 0
  return resultData.value.groups.reduce((s, g) => s + (resultData.value.data[g]?.[char] ?? 0), 0)
}

const grandTotal = computed(() => {
  if (!resultData.value) return 0
  return resultData.value.groups.reduce((s, g) => s + rowTotal(g), 0)
})

// ── Heatmap ───────────────────────────────────────────────────────────────

const heatmapEnabled = ref(false)

const maxCellValue = computed(() => {
  if (!resultData.value) return 1
  let max = 0
  for (const group of resultData.value.groups) {
    for (const char of resultData.value.chars) {
      const v = resultData.value.data[group]?.[char] ?? 0
      if (v > max) max = v
    }
  }
  return max || 1
})

function cellBgStyle(val) {
  if (!heatmapEnabled.value || val === 0) return {}
  const ratio = val / maxCellValue.value
  // HSL: lightness goes from 95% (near-white) down to 30% (deep blue) as ratio → 1
  const l = Math.round(95 - ratio * 65)
  return {
    backgroundColor: `hsl(210, 65%, ${l}%)`,
    color: ratio > 0.55 ? 'white' : 'inherit',
    transition: 'background-color 0.2s',
  }
}

// ── Data loading ──────────────────────────────────────────────────────────

async function loadAnalyses() {
  loading.value = true
  try {
    const res = await api.get('/api/assemblages')
    analyses.value = res.analyses ?? []
  } catch (e) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.message, life: 4000 })
  } finally {
    loading.value = false
  }
}

async function loadSources() {
  sourcesLoading.value = true
  try {
    const res = await api.get('/api/assemblage/sources')
    allSources.value = { tables: res.tables ?? [], plugins: res.plugins ?? [] }
  } catch { /* silent */ } finally {
    sourcesLoading.value = false
  }
}

async function loadSourceMeta() {
  if (!wizard.source_tb) return
  metaLoading.value = true
  try {
    const res = await api.get('/api/assemblage/table-meta', { tb: wizard.source_tb })
    if (res?.status === 'success') sourceMeta.value = res
  } catch { /* silent */ } finally {
    metaLoading.value = false
  }
}

async function loadPathMeta() {
  if (!pathCurrentTb.value) return
  pathMetaLoading.value = true
  try {
    pathCurrentMeta.value = await api.get('/api/assemblage/table-meta', { tb: pathCurrentTb.value })
  } catch { /* silent */ } finally {
    pathMetaLoading.value = false
  }
}

// ── Wizard helpers ────────────────────────────────────────────────────────

function resetWizard() {
  wizard.name          = ''
  wizard.source_type   = 'table'
  wizard.source_tb     = ''
  wizard.char_field    = ''
  wizard.group_path    = []
  wizard.group_field   = ''
  wizard.measure       = 'count'
  wizard.measure_field = ''
  wizard.measure_label = ''
  wizard.filters       = []
  pathSelectedField.value  = ''
  pathCurrentMeta.value    = null
  sourceMeta.value         = null
  previewResult.value      = null
}

async function openWizard(analysis) {
  if (analysis) {
    editingId.value = analysis.id
    const def = analysis.definition ?? {}
    wizard.name          = analysis.name
    wizard.source_type   = def.source_type   ?? 'table'
    wizard.source_tb     = def.source_tb     ?? ''
    wizard.char_field    = def.char_field    ?? ''
    wizard.group_path    = JSON.parse(JSON.stringify(def.group_path ?? []))
    wizard.group_field   = def.group_field   ?? ''
    wizard.measure       = def.measure       ?? 'count'
    wizard.measure_field = def.measure_field ?? ''
    wizard.measure_label = def.measure_label ?? ''
    wizard.filters       = JSON.parse(JSON.stringify(def.filters ?? []))
    pathSelectedField.value = ''
    previewResult.value  = null
    if (wizard.source_tb) await loadSourceMeta()
    await loadPathMeta()
  } else {
    editingId.value = null
    resetWizard()
  }
  currentStep.value   = 1
  wizardVisible.value = true
}

async function goToStep(s) {
  if (s >= currentStep.value) return
  currentStep.value = s
  if (s === 4) {
    pathSelectedField.value = ''
    if (wizard.group_path.length === 0) {
      pathCurrentMeta.value = sourceMeta.value
    } else {
      await loadPathMeta()
    }
  }
}

async function goNext() {
  if (currentStep.value === 2 && wizard.source_tb) {
    await loadSourceMeta()
  }
  if (currentStep.value === 3) {
    pathSelectedField.value = ''
    // Use source meta when path is empty; otherwise load the last joined table's meta
    if (wizard.group_path.length === 0) {
      pathCurrentMeta.value = sourceMeta.value
    } else {
      await loadPathMeta()
    }
  }
  currentStep.value++
}

function useAsGroupField() {
  wizard.group_field = pathSelectedField.value
}

async function addPathStep() {
  if (!pathSelectedField.value || !selectedFieldIsFK.value) return
  wizard.group_path.push({
    local_field: pathSelectedField.value,
    join_tb:     selectedFieldFkTb.value,
    join_pk:     selectedFieldFkPk.value,
  })
  wizard.group_field      = ''
  pathSelectedField.value = ''
  await loadPathMeta()
}

async function removeLastStep() {
  if (wizard.group_path.length === 0) return
  wizard.group_path.pop()
  wizard.group_field      = ''
  pathSelectedField.value = ''
  await loadPathMeta()
}

function buildDefinition() {
  return {
    source_tb:     wizard.source_tb,
    source_type:   wizard.source_type,
    char_field:    wizard.char_field,
    group_path:    wizard.group_path.map(s => ({
      local_field: s.local_field,
      join_tb:     s.join_tb,
      join_pk:     s.join_pk,
    })),
    group_field:   wizard.group_field,
    measure:       wizard.measure,
    measure_field: wizard.measure !== 'count' ? wizard.measure_field : null,
    measure_label: wizard.measure_label,
    filters:       wizard.filters.filter(f => f.field && f.op),
  }
}

async function runPreview() {
  previewLoading.value = true
  try {
    const res = await api.post('/api/assemblage/data', { definition: buildDefinition() })
    if (res.status !== 'success') throw new Error(res.code ?? 'generic_error')
    previewResult.value = res
  } catch (e) {
    toast.add({ severity: 'error', summary: t('error_save_analysis'), detail: t(e.message) || e.message, life: 5000 })
  } finally {
    previewLoading.value = false
  }
}

async function doSave() {
  saving.value = true
  try {
    const payload = { name: wizard.name, definition: buildDefinition() }
    let res
    if (editingId.value) {
      res = await api.post(`/api/assemblage/${editingId.value}`, payload)
    } else {
      res = await api.post('/api/assemblages', payload)
    }
    if (res.status !== 'success') throw new Error(res.code ?? 'generic_error')
    toast.add({ severity: 'success', summary: t('ok_save_analysis'), life: 3000 })
    wizardVisible.value = false
    await loadAnalyses()
  } catch (e) {
    toast.add({ severity: 'error', summary: t('error_save_analysis'), detail: t(e.message) || e.message, life: 5000 })
  } finally {
    saving.value = false
  }
}

// ── List actions ──────────────────────────────────────────────────────────

async function openAnalysis(analysis) {
  openResult.value  = analysis
  resultLoading.value = true
  resultError.value   = null
  resultData.value    = null
  try {
    const res = await api.post('/api/assemblage/data', { definition: analysis.definition })
    if (res.status !== 'success') throw new Error(res.code ?? 'generic_error')
    resultData.value = res
  } catch (e) {
    resultError.value = t(e.message) || e.message
  } finally {
    resultLoading.value = false
  }
}

function closeResult() {
  openResult.value  = null
  resultData.value  = null
  resultError.value = null
}

async function toggleShare(analysis) {
  const action = analysis.is_global ? 'unshare' : 'share'
  try {
    const res = await api.post(`/api/assemblage/${analysis.id}/${action}`)
    if (res.status !== 'success') throw new Error(res.code)
    toast.add({ severity: 'success', summary: t(`ok_${action}_analysis`), life: 3000 })
    await loadAnalyses()
  } catch (e) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.message, life: 4000 })
  }
}

function confirmDelete(analysis) {
  confirm.require({
    message:     t('delete_confirm_message'),
    header:      t('delete'),
    icon:        'pi pi-exclamation-triangle',
    severity:    'danger',
    rejectProps: { label: t('cancel'), severity: 'secondary', outlined: true },
    acceptProps: { label: t('delete'), severity: 'danger' },
    accept:      () => deleteAnalysis(analysis),
  })
}

async function deleteAnalysis(analysis) {
  try {
    const res = await api.delete(`/api/assemblage/${analysis.id}`)
    if (res.status !== 'success') throw new Error(res.code)
    toast.add({ severity: 'success', summary: t('ok_delete_analysis'), life: 3000 })
    await loadAnalyses()
  } catch (e) {
    toast.add({ severity: 'error', summary: t('error_delete_analysis'), detail: e.message, life: 4000 })
  }
}

// ── Export CSV ────────────────────────────────────────────────────────────

function csvEscape(val) {
  const s = String(val ?? '')
  return (s.includes(',') || s.includes('"') || s.includes('\n'))
    ? '"' + s.replace(/"/g, '""') + '"'
    : s
}

function exportCsv() {
  if (!resultData.value) return
  const { chars, groups, data } = resultData.value
  const rows = []
  rows.push(['', ...chars, 'Tot'].map(csvEscape).join(','))
  for (const g of groups) {
    const vals = chars.map(c => data[g]?.[c] ?? 0)
    rows.push([g, ...vals, vals.reduce((s, v) => s + v, 0)].map(csvEscape).join(','))
  }
  const colTots = chars.map(c => groups.reduce((s, g) => s + (data[g]?.[c] ?? 0), 0))
  rows.push(['Tot', ...colTots, colTots.reduce((s, v) => s + v, 0)].map(csvEscape).join(','))

  const blob = new Blob([rows.join('\n')], { type: 'text/csv;charset=utf-8;' })
  const url  = URL.createObjectURL(blob)
  const a    = document.createElement('a')
  a.href     = url
  a.download = (openResult.value?.name ?? 'analysis') + '.csv'
  a.click()
  URL.revokeObjectURL(url)
}

// ── Watchers ──────────────────────────────────────────────────────────────

watch(() => wizard.source_type, () => {
  wizard.source_tb = ''
  sourceMeta.value = null
})

watch(() => wizard.source_tb, async (tb) => {
  wizard.char_field    = ''
  wizard.group_path    = []
  wizard.group_field   = ''
  pathSelectedField.value = ''
  if (tb) await loadSourceMeta()
})

watch(pathSelectedField, (field) => {
  if (!field) {
    wizard.group_field = ''
    return
  }
  // Auto-select non-FK fields as group_field
  if (!selectedFieldIsFK.value) {
    wizard.group_field = field
  } else {
    wizard.group_field = ''
  }
})

// ── Lifecycle ─────────────────────────────────────────────────────────────

onMounted(async () => {
  await Promise.all([loadAnalyses(), loadSources()])
})
</script>

<style scoped>
.assemblages-view {
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

/* ── Analyses grid ── */
.analyses-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 1rem;
}
.analysis-card { cursor: default; }
.card-title { font-weight: 600; margin-bottom: 0.25rem; }
.card-meta {
  font-size: 0.8rem;
  color: var(--p-text-muted-color);
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem;
  align-items: center;
}
.meta-sep { opacity: 0.4; }
.card-tag { margin-top: 0.5rem; }
.card-actions { display: flex; gap: 0.25rem; flex-wrap: wrap; }

/* ── Empty state ── */
.empty-state {
  text-align: center;
  padding: 4rem 2rem;
  color: var(--p-text-muted-color);
}
.empty-icon { font-size: 3rem; display: block; margin-bottom: 1rem; opacity: 0.3; }

/* ── Result view ── */
.result-view { display: flex; flex-direction: column; gap: 1rem; }
.result-header {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  flex-wrap: wrap;
}
.result-title { font-size: 1.1rem; font-weight: 600; flex: 1; }
.result-header-actions { margin-left: auto; }
.result-loading { text-align: center; padding: 2rem; }
.result-error { color: var(--p-red-500); padding: 1rem; }
.result-empty { color: var(--p-text-muted-color); padding: 2rem; text-align: center; }
.result-tabs { margin-top: 0.5rem; }
.result-actions { margin-top: 0.5rem; }

/* ── Pivot toolbar ── */
.pivot-toolbar {
  display: flex;
  justify-content: flex-end;
  padding: 0.4rem 0;
}
.heatmap-toggle {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.85rem;
  cursor: pointer;
  user-select: none;
}

/* ── Pivot table ── */
.pivot-scroll { overflow-x: auto; }
.pivot-scroll.small { max-height: 200px; overflow-y: auto; }
.pivot-table {
  border-collapse: collapse;
  font-size: 0.85rem;
  white-space: nowrap;
}
.pivot-table th,
.pivot-table td {
  border: 1px solid var(--p-content-border-color);
  padding: 0.3rem 0.6rem;
  text-align: right;
}
.pivot-table th { background: var(--p-surface-100); text-align: left; }
.pivot-corner { min-width: 6rem; }
.pivot-char-header { font-size: 0.78rem; max-width: 8rem; overflow: hidden; text-overflow: ellipsis; }
.pivot-group-header { font-weight: 600; }
.pivot-total, .pivot-total-header { font-weight: 700; background: var(--p-surface-50); }
.pivot-grand { background: var(--p-primary-50); }
.pivot-footer th, .pivot-footer td { background: var(--p-surface-100); font-weight: 700; }
.pivot-ellipsis { color: var(--p-text-muted-color); text-align: center; }

/* ── Chart ── */
.chart-wrapper { height: 350px; position: relative; }

/* ── Wizard ── */
.wizard { display: flex; flex-direction: column; gap: 1.5rem; }
.wizard-header { display: flex; flex-direction: column; gap: 0.6rem; }
.step-bar {
  display: flex;
  gap: 0.5rem;
  justify-content: center;
  flex-wrap: wrap;
}
.step-dot {
  width: 28px; height: 28px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 0.75rem; font-weight: 600;
  background: var(--p-surface-200);
  color: var(--p-text-muted-color);
  border: 2px solid transparent;
  cursor: default;
}
.step-dot.active { background: var(--p-primary-color); color: white; }
.step-dot.done { background: var(--p-green-500); color: white; cursor: pointer; }
.step-dot.done:hover { opacity: 0.8; }

/* Breadcrumb */
.wizard-breadcrumb {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.15rem;
  padding: 0.4rem 0.6rem;
  background: var(--p-surface-50);
  border: 1px solid var(--p-content-border-color);
  border-radius: 6px;
  font-size: 0.78rem;
}
.breadcrumb-sep {
  font-size: 0.65rem;
  color: var(--p-text-muted-color);
  opacity: 0.5;
  margin: 0 0.1rem;
}
.breadcrumb-item {
  max-width: 12rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  line-height: 1.2;
}
.breadcrumb-done {
  background: none;
  border: none;
  padding: 0.1rem 0.3rem;
  border-radius: 4px;
  color: var(--p-primary-color);
  cursor: pointer;
  font-size: inherit;
  font-family: inherit;
}
.breadcrumb-done:hover {
  background: var(--p-primary-50);
  text-decoration: underline;
}
.breadcrumb-current {
  padding: 0.1rem 0.3rem;
  color: var(--p-text-color);
  font-weight: 600;
}

.wizard-step { display: flex; flex-direction: column; gap: 1rem; }
.step-title { margin: 0; font-size: 1rem; }
.step-hint { margin: 0; font-size: 0.85rem; color: var(--p-text-muted-color); }

.form-field { display: flex; flex-direction: column; gap: 0.4rem; }
.field-label { font-size: 0.85rem; font-weight: 500; }
.w-full { width: 100%; }

.radio-group { display: flex; flex-direction: column; gap: 0.5rem; }
.radio-item { display: flex; align-items: center; gap: 0.5rem; cursor: pointer; }

.option-sub { font-size: 0.75rem; color: var(--p-text-muted-color); }

/* Path builder */
.path-crumb {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  flex-wrap: wrap;
  font-size: 0.85rem;
  padding: 0.5rem 0.75rem;
  background: var(--p-surface-100);
  border-radius: 6px;
}
.path-node { font-weight: 600; }
.path-arrow { font-size: 0.7rem; color: var(--p-text-muted-color); }

.path-actions { display: flex; flex-direction: column; gap: 0.75rem; }
.fk-info {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.85rem;
  color: var(--p-primary-color);
}
.action-row { display: flex; gap: 0.5rem; flex-wrap: wrap; }

.path-footer { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem; }
.group-field-selected { display: flex; align-items: center; gap: 0.4rem; font-size: 0.85rem; }

/* Filters */
.filter-row { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; }
.filter-field { flex: 2; min-width: 120px; }
.filter-op    { flex: 1; min-width: 100px; }
.filter-value { flex: 2; min-width: 100px; }

/* Preview */
.preview-summary {
  display: flex; flex-direction: column; gap: 0.25rem;
  font-size: 0.9rem;
  padding: 0.75rem;
  background: var(--p-surface-100);
  border-radius: 6px;
}
.preview-btn { align-self: flex-start; }
.preview-result { display: flex; flex-direction: column; gap: 0.5rem; }
.preview-stats { font-size: 0.85rem; color: var(--p-text-muted-color); margin: 0; }

/* Navigation */
.wizard-nav { display: flex; align-items: center; gap: 0.5rem; }
.nav-spacer { flex: 1; }
</style>
