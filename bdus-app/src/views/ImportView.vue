<template>
  <AppLayout>
    <div class="import-view">

      <div class="import-header">
        <h2>{{ t('import_data') }}</h2>
      </div>

      <!-- ── Step indicator ───────────────────────────────────────── -->
      <div class="import-steps">
        <div
          v-for="(label, i) in currentStepLabels"
          :key="i"
          :class="['import-step', { active: step === i, done: step > i }]"
        >
          <span class="step-num">{{ i + 1 }}</span>
          <span class="step-label">{{ label }}</span>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════════════════ -->
      <!-- STEP 0 — Setup: type, table, file(s)                      -->
      <!-- ══════════════════════════════════════════════════════════ -->
      <div v-if="step === 0" class="import-panel">

        <!-- Import type -->
        <div class="field-row">
          <label>{{ t('import_type') }}</label>
          <ASegmented
            v-model:value="importType"
            :options="importTypeOptions"
          />
        </div>

        <!-- Table selector (all types) -->
        <div class="field-row">
          <label>{{ t('table') }}</label>
          <ASelect
            v-model:value="selectedTable"
            :options="tableSelectOptions"
            :placeholder="t('select_table')"
            show-search
            :filter-option="filterOption"
          />
        </div>

        <!-- CSV / JSON: single file -->
        <div v-if="importType === 'csv' || importType === 'json'" class="field-row">
          <label>{{ t('import_upload_file') }}</label>
          <input
            ref="fileInputRef"
            type="file"
            :accept="importType === 'csv' ? '.csv' : '.json'"
            @change="onFileChange"
            class="file-input"
          />
          <small class="field-hint">{{ importType === 'csv' ? t('import_hint_csv') : t('import_hint_json') }}</small>
        </div>

        <!-- GeoJSON: single file -->
        <div v-if="importType === 'geojson'" class="field-row">
          <label>{{ t('import_upload_file') }}</label>
          <input
            ref="fileInputRef"
            type="file"
            accept=".geojson,.json"
            @change="onFileChange"
            class="file-input"
          />
          <small class="field-hint">{{ t('import_hint_geojson') }}</small>
        </div>

        <!-- Photos: ZIP + index -->
        <template v-if="importType === 'photos'">
          <div class="field-row">
            <label>{{ t('import_upload_zip') }}</label>
            <input
              ref="zipInputRef"
              type="file"
              accept=".zip"
              @change="onZipChange"
              class="file-input"
            />
          </div>
          <div class="field-row">
            <label>{{ t('import_upload_index') }}</label>
            <input
              ref="indexInputRef"
              type="file"
              accept=".csv"
              @change="onIndexChange"
              class="file-input"
            />
            <small class="field-hint">{{ t('import_hint_index') }}</small>
          </div>
        </template>

        <AAlert v-if="setupError" type="error" :message="setupError" show-icon />

        <div class="step-actions">
          <AButton type="primary" :loading="previewing" :disabled="!canPreview" @click="doPreview">
            <template #icon><EyeOutlined /></template>
            {{ t('preview') }}
          </AButton>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════════════════ -->
      <!-- STEP 1 — Preview + mapping / config                       -->
      <!-- ══════════════════════════════════════════════════════════ -->
      <div v-else-if="step === 1" class="import-panel">

        <!-- ── CSV / JSON: field mapping ─────────────────────── -->
        <template v-if="importType === 'csv' || importType === 'json'">
          <p class="preview-summary">
            {{ t('import_rows_found', previewData.count) }}
          </p>

          <!-- Data preview table -->
          <div class="preview-table-wrap">
            <ATable
              :columns="previewColumns"
              :dataSource="previewRows"
              :pagination="false"
              :scroll="{ y: 220 }"
              size="small"
              rowKey="__key"
              class="preview-table"
            />
          </div>

          <!-- Field mapping -->
          <div class="mapping-header">
            <span>{{ t('import_file_column') }}</span>
            <span>{{ t('import_table_field') }}</span>
          </div>
          <div
            v-for="col in previewData.columns"
            :key="col"
            class="mapping-row"
          >
            <span class="mapping-col-name">{{ col }}</span>
            <ASelect
              v-model:value="fieldMapping[col]"
              :options="mappingOptions"
              :placeholder="t('import_ignore')"
              class="mapping-select"
            />
          </div>

          <!-- Key field selector -->
          <div class="field-row key-field-row">
            <label>{{ t('import_key_field') }}</label>
            <ASelect
              v-model:value="keyField"
              :options="mappedTableFields"
              :placeholder="t('import_select_key')"
            />
            <small class="field-hint">{{ t('import_key_field_hint') }}</small>
          </div>
        </template>

        <!-- ── GeoJSON: property + key field selector ─────────── -->
        <template v-if="importType === 'geojson'">
          <p class="preview-summary">
            {{ t('import_features_found', previewData.count) }}
            <ATag
              v-for="gt in previewData.geometry_types"
              :key="gt"
              class="geo-type-tag"
            >{{ gt }}</ATag>
          </p>
          <AAlert v-if="!previewData.geo_field" type="warning" :message="t('import_error_no_geo_field')" show-icon />
          <template v-else>
            <div class="field-row">
              <label>{{ t('import_geo_prop') }}</label>
              <ASelect
                v-model:value="geoProp"
                :options="geoPropOptions"
                :placeholder="t('import_select_geo_prop')"
              />
              <small class="field-hint">{{ t('import_geo_prop_hint') }}</small>
            </div>
            <div class="field-row">
              <label>{{ t('import_key_table_field') }}</label>
              <ASelect
                v-model:value="keyField"
                :options="tableFieldOptions"
                :placeholder="t('import_select_key')"
              />
              <small class="field-hint">{{ t('import_key_table_field_hint') }}</small>
            </div>
          </template>
        </template>

        <!-- ── Photos: index preview + missing files ──────────── -->
        <template v-if="importType === 'photos'">
          <p class="preview-summary">
            {{ t('import_index_rows', previewData.total) }}
          </p>

          <ATable
            :columns="photosIndexColumns"
            :dataSource="previewData.index_rows"
            :pagination="false"
            size="small"
            rowKey="filename"
            class="preview-table"
          />

          <AAlert v-if="previewData.missing_files?.length" type="warning" show-icon>
            <template #message>
              {{ t('import_missing_files', previewData.missing_files.length) }}:
              {{ previewData.missing_files.join(', ') }}
            </template>
          </AAlert>
        </template>

        <AAlert v-if="configError" type="error" :message="configError" show-icon />

        <div class="step-actions">
          <AButton @click="step = 0">
            <template #icon><ArrowLeftOutlined /></template>
            {{ t('back') }}
          </AButton>
          <AButton type="primary" :loading="importing" :disabled="!canImport" @click="doImport">
            <template #icon><UploadOutlined /></template>
            {{ t('import_run') }}
          </AButton>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════════════════ -->
      <!-- STEP 2 — Result                                           -->
      <!-- ══════════════════════════════════════════════════════════ -->
      <div v-else-if="step === 2" class="import-panel">
        <div v-if="importResult" class="import-result">
          <AAlert
            :type="importResult.status === 'success' ? 'success' : 'error'"
            show-icon
          >
            <template #message>
              {{ t(importResult.code) }}
              <span v-if="importResult.detail"> — {{ importResult.detail }}</span>
            </template>
          </AAlert>

          <div v-if="importResult.status === 'success'" class="result-stats">
            <!-- CSV / JSON stats -->
            <template v-if="importType === 'csv' || importType === 'json'">
              <div class="stat"><span>{{ t('import_total') }}</span><strong>{{ importResult.total }}</strong></div>
              <div class="stat"><span>{{ t('import_inserted') }}</span><strong>{{ importResult.inserted }}</strong></div>
              <div class="stat"><span>{{ t('import_updated') }}</span><strong>{{ importResult.updated }}</strong></div>
            </template>
            <!-- GeoJSON stats -->
            <template v-if="importType === 'geojson'">
              <div class="stat"><span>{{ t('import_total') }}</span><strong>{{ importResult.total }}</strong></div>
              <div class="stat"><span>{{ t('import_updated') }}</span><strong>{{ importResult.updated }}</strong></div>
              <div class="stat"><span>{{ t('import_not_found') }}</span><strong>{{ importResult.not_found }}</strong></div>
            </template>
            <!-- Photos stats -->
            <template v-if="importType === 'photos'">
              <div class="stat"><span>{{ t('import_total') }}</span><strong>{{ importResult.total }}</strong></div>
              <div class="stat"><span>{{ t('import_photos_linked') }}</span><strong>{{ importResult.linked }}</strong></div>
              <div class="stat"><span>{{ t('import_not_found') }}</span><strong>{{ importResult.not_found }}</strong></div>
            </template>
          </div>
        </div>

        <div class="step-actions">
          <AButton @click="reset">
            <template #icon><ReloadOutlined /></template>
            {{ t('import_new') }}
          </AButton>
        </div>
      </div>

    </div>
  </AppLayout>
</template>

<script setup>
import { ArrowLeftOutlined, EyeOutlined, ReloadOutlined, UploadOutlined } from '@ant-design/icons-vue'
import { ref, computed, watch } from 'vue'
import AppLayout     from '@/components/AppLayout.vue'
import { api }       from '@/api'
import { useI18n }   from '@/i18n'
import { useTables } from '@/composables/useTables'
import { Table as ATable, Button as AButton, Select as ASelect, Segmented as ASegmented, Alert as AAlert, Tag as ATag } from 'ant-design-vue'

function filterOption(input, option) {
  return String(option?.label ?? '').toLowerCase().includes(String(input).toLowerCase())
}

const { t } = useI18n()
const { tables, loadTables } = useTables()
loadTables()

// ── State ──────────────────────────────────────────────────────────────
const step         = ref(0)
const importType   = ref('csv')
const selectedTable = ref(null)

// File refs
const fileInputRef  = ref(null)
const zipInputRef   = ref(null)
const indexInputRef = ref(null)
const selectedFile  = ref(null)
const selectedZip   = ref(null)
const selectedIndex = ref(null)

// Preview
const previewing   = ref(false)
const previewData  = ref({})
const setupError   = ref(null)

// Mapping (CSV/JSON)
const fieldMapping = ref({})   // { fileCol: tableField }
const keyField     = ref(null)
const tableFields  = ref([])   // [{name, label, type}]

// GeoJSON
const geoProp = ref(null)

// Import
const importing     = ref(false)
const importResult  = ref(null)
const configError   = ref(null)

// ── Derived ────────────────────────────────────────────────────────────
const importTypeOptions = computed(() => [
  { label: 'CSV',     value: 'csv'     },
  { label: 'JSON',    value: 'json'    },
  { label: 'GeoJSON', value: 'geojson' },
  { label: t('import_type_photos'), value: 'photos' },
])

const currentStepLabels = computed(() => {
  const base = [t('import_step_setup'), t('import_step_config'), t('import_step_result')]
  if (importType.value === 'csv' || importType.value === 'json') {
    return [base[0], t('import_step_mapping'), base[2]]
  }
  if (importType.value === 'geojson') {
    return [base[0], t('import_step_geojson'), base[2]]
  }
  return [base[0], t('import_step_photos'), base[2]]
})

// Preview rows: array-of-arrays → Table wants array-of-objects keyed by column index
const previewRows = computed(() =>
  (previewData.value.rows ?? []).map((row, rowIdx) => ({
    __key: rowIdx,
    ...Object.fromEntries(row.map((v, i) => [String(i), v])),
  }))
)

const previewColumns = computed(() =>
  (previewData.value.columns ?? []).map((col, i) => ({ title: col, dataIndex: String(i), key: String(i) }))
)

const photosIndexColumns = computed(() => [
  { title: t('import_filename'),  dataIndex: 'filename',  key: 'filename' },
  { title: t('import_record_id'), dataIndex: 'record_id', key: 'record_id' },
])

// AntD Select expects {value,label} — remap raw table/field lists once here
const tableSelectOptions = computed(() =>
  tables.value.map(tb => ({ value: tb.name, label: tb.label }))
)
const tableFieldOptions = computed(() =>
  tableFields.value.map(f => ({ value: f.name, label: f.label }))
)
const geoPropOptions = computed(() =>
  (previewData.value.geo_props ?? []).map(v => ({ value: v, label: v }))
)

// Options for the mapping dropdowns: "— ignore —" + all table fields
const mappingOptions = computed(() => [
  { label: `— ${t('import_ignore')} —`, value: null },
  ...(tableFields.value.map(f => ({ label: `${f.label} (${f.name})`, value: f.name }))),
])

// Only mapped table fields (for key-field dropdown)
const mappedTableFields = computed(() => {
  const mapped = new Set(Object.values(fieldMapping.value).filter(Boolean))
  return tableFields.value
    .filter(f => mapped.has(f.name))
    .map(f => ({ label: `${f.label} (${f.name})`, value: f.name }))
})

const canPreview = computed(() => {
  if (!selectedTable.value) return false
  if (importType.value === 'photos') return !!(selectedZip.value && selectedIndex.value)
  return !!selectedFile.value
})

const canImport = computed(() => {
  if (importType.value === 'csv' || importType.value === 'json') {
    return !!keyField.value
  }
  if (importType.value === 'geojson') {
    return !!(geoProp.value && keyField.value && previewData.value.geo_field)
  }
  if (importType.value === 'photos') {
    return !previewData.value.missing_files?.length || true // allow even with some missing
  }
  return true
})

// ── Watchers ───────────────────────────────────────────────────────────

// Reset file selection when type changes
watch(importType, () => {
  selectedFile.value  = null
  selectedZip.value   = null
  selectedIndex.value = null
  setupError.value    = null
  if (fileInputRef.value)  fileInputRef.value.value  = ''
  if (zipInputRef.value)   zipInputRef.value.value   = ''
  if (indexInputRef.value) indexInputRef.value.value = ''
})

// Fetch table fields when table is selected (for mapping UI)
watch(selectedTable, async (tb) => {
  tableFields.value = []
  fieldMapping.value = {}
  keyField.value = null
  geoProp.value  = null
  if (!tb) return
  try {
    const res = await api.get(`/api/import/${tb}/fields`)
    if (res.status === 'success') tableFields.value = res.fields ?? []
  } catch { /* ignore */ }
})

// Auto-initialize mapping when preview columns arrive
watch(() => previewData.value.columns, (cols) => {
  if (!cols) return
  const mapping = {}
  cols.forEach(col => {
    // Try exact name match first, then case-insensitive
    const exact = tableFields.value.find(f => f.name === col)
    const ci    = tableFields.value.find(f => f.name.toLowerCase() === col.toLowerCase())
    mapping[col] = exact?.name ?? ci?.name ?? null
  })
  fieldMapping.value = mapping
})

// ── File input handlers ────────────────────────────────────────────────
function onFileChange(e)  { selectedFile.value  = e.target.files[0] ?? null }
function onZipChange(e)   { selectedZip.value   = e.target.files[0] ?? null }
function onIndexChange(e) { selectedIndex.value = e.target.files[0] ?? null }

// ── Actions ────────────────────────────────────────────────────────────

async function doPreview() {
  setupError.value = null
  previewing.value = true
  try {
    let res
    if (importType.value === 'photos') {
      res = await api.uploadMulti(
        '/api/import/preview-photos',
        { zip: selectedZip.value, index: selectedIndex.value },
        { tb: selectedTable.value }
      )
    } else {
      res = await api.uploadMulti(
        '/api/import/preview-file',
        { file: selectedFile.value },
        { type: importType.value, tb: selectedTable.value }
      )
    }

    if (res.status !== 'success') {
      setupError.value = t(res.code ?? 'generic_error')
      return
    }
    previewData.value = res
    step.value = 1

  } catch (e) {
    setupError.value = e.message
  } finally {
    previewing.value = false
  }
}

async function doImport() {
  configError.value = null
  importing.value = true
  try {
    let res

    if (importType.value === 'csv' || importType.value === 'json') {
      res = await api.post('/api/import/data', {
        temp_id:   previewData.value.temp_id,
        type:      importType.value,
        tb:        selectedTable.value,
        mapping:   fieldMapping.value,
        key_field: keyField.value,
      })

    } else if (importType.value === 'geojson') {
      res = await api.post('/api/import/geojson', {
        temp_id:   previewData.value.temp_id,
        tb:        selectedTable.value,
        geo_prop:  geoProp.value,
        key_field: keyField.value,
      })

    } else if (importType.value === 'photos') {
      res = await api.post('/api/import/photos', {
        temp_id: previewData.value.temp_id,
        tb:      selectedTable.value,
      })
    }

    importResult.value = res
    step.value = 2

  } catch (e) {
    configError.value = e.message
  } finally {
    importing.value = false
  }
}

function reset() {
  step.value         = 0
  previewData.value  = {}
  importResult.value = null
  fieldMapping.value = {}
  keyField.value     = null
  geoProp.value      = null
  selectedFile.value = null
  selectedZip.value  = null
  selectedIndex.value = null
  setupError.value   = null
  configError.value  = null
  if (fileInputRef.value)  fileInputRef.value.value  = ''
  if (zipInputRef.value)   zipInputRef.value.value   = ''
  if (indexInputRef.value) indexInputRef.value.value = ''
}
</script>

<style scoped>
.import-view {
  max-width: 860px;
  margin: 0 auto;
  padding: 1.5rem 1rem;
}

.import-header h2 {
  margin: 0 0 1.5rem;
  font-size: 1.3rem;
}

/* ── Step indicator ─── */
.import-steps {
  display: flex;
  gap: 0;
  margin-bottom: 2rem;
  border-radius: 8px;
  overflow: hidden;
  border: 1px solid var(--p-content-border-color, #e0e0e0);
}

.import-step {
  flex: 1;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.6rem 1rem;
  font-size: 0.85rem;
  color: var(--p-text-muted-color, #999);
  background: var(--p-content-background, #fff);
  border-right: 1px solid var(--p-content-border-color, #e0e0e0);
}
.import-step:last-child { border-right: none; }
.import-step.active {
  background: var(--p-primary-color, #6366f1);
  color: #fff;
  font-weight: 600;
}
.import-step.done {
  color: var(--p-primary-color, #6366f1);
}

.step-num {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.4rem;
  height: 1.4rem;
  border-radius: 50%;
  border: 2px solid currentColor;
  font-size: 0.75rem;
  font-weight: 700;
  flex-shrink: 0;
}
.import-step.active .step-num { border-color: #fff; }

/* ── Panel ─── */
.import-panel {
  background: var(--p-content-background, #fff);
  border: 1px solid var(--p-content-border-color, #e0e0e0);
  border-radius: 8px;
  padding: 1.5rem;
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}

/* ── Fields ─── */
.field-row {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.field-row label {
  font-weight: 600;
  font-size: 0.85rem;
}

.field-hint {
  color: var(--p-text-muted-color, #888);
  font-size: 0.78rem;
}

.file-input {
  font-size: 0.85rem;
  padding: 0.3rem 0;
}

/* ── Mapping ─── */
.mapping-header {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.5rem;
  font-weight: 600;
  font-size: 0.8rem;
  color: var(--p-text-muted-color, #888);
  padding: 0 0.25rem;
}

.mapping-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  align-items: center;
  gap: 0.5rem;
}

.mapping-col-name {
  font-size: 0.85rem;
  font-family: monospace;
  background: var(--p-surface-100, #f5f5f5);
  padding: 0.2rem 0.5rem;
  border-radius: 4px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.mapping-select { width: 100%; }

.key-field-row {
  border-top: 1px solid var(--p-content-border-color, #e0e0e0);
  padding-top: 1rem;
  margin-top: 0.25rem;
}

/* ── Preview ─── */
.preview-summary {
  margin: 0;
  font-size: 0.9rem;
  color: var(--p-text-muted-color, #666);
  display: flex;
  align-items: center;
  gap: 0.4rem;
  flex-wrap: wrap;
}

.geo-type-tag { font-size: 0.75rem; }

.preview-table-wrap {
  overflow: auto;
  max-height: 260px;
  border: 1px solid var(--p-content-border-color, #e0e0e0);
  border-radius: 6px;
}

/* ── Result ─── */
.result-stats {
  display: flex;
  gap: 1.5rem;
  margin-top: 0.75rem;
  flex-wrap: wrap;
}

.stat {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.1rem;
}
.stat span { font-size: 0.78rem; color: var(--p-text-muted-color, #888); }
.stat strong { font-size: 1.6rem; font-weight: 700; }

/* ── Actions ─── */
.step-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.75rem;
  padding-top: 0.5rem;
  border-top: 1px solid var(--p-content-border-color, #e0e0e0);
}
</style>
