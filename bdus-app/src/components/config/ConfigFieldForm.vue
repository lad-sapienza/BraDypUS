<!--
  ConfigFieldForm — renders a field-editor form auto-generated from fld_structure.json.

  Props:
    tb        {string}  table name (required)
    field     {object}  current field data (empty object for "add new")
    structure {object}  fld_structure meta-schema returned by getFldConfig / getFldStructure

  Emits:
    saved(fieldName)  — on successful save
    cancelled         — user hit cancel

  S5 changes:
  - Labels translated via t('fld_' + key)                        (#15)
  - Boolean fields (readonly, hide, …) rendered as ToggleSwitch   (#14)
  - vocabulary_set / get_values_from_tb / id_from_tb shown only
    when type is select / combo_select / multi_select              (#13)
  - get_values_from_tb replaced by cascading table → field selects (#17)
  - def_value help text already in fld_structure.json             (#12)
  - type help text fixed in fld_structure.json                    (#16)
-->
<template>
  <form class="cfg-fld-form" @submit.prevent="submit">

    <div v-if="error" class="cfg-fld-error">{{ error }}</div>

    <div class="cfg-fld-grid">
      <template v-for="(meta, key) in structure" :key="key">
        <template v-if="shouldShow(key)">

          <!-- ── get_values_from_tb: cascading two-level select ── -->
          <div v-if="key === 'get_values_from_tb'" class="cfg-fld-field cfg-fld-span2">
            <label>{{ t('fld_get_values_from_tb') }}</label>
            <div class="cfg-gvft-row">
              <Select
                v-model="gvftTable"
                :options="gvftTableOptions"
                :show-clear="true"
                size="small"
                :placeholder="t('select_table')"
                @change="onGvftTableChange"
              />
              <Select
                v-model="gvftField"
                :options="gvftFieldOptions"
                option-label="label"
                option-value="value"
                :show-clear="true"
                size="small"
                :disabled="!gvftTable || gvftLoading"
                :placeholder="gvftLoading ? t('loading') : t('select_field')"
                @change="onGvftFieldChange"
              />
            </div>
            <small class="cfg-fld-help">{{ meta.help }}</small>
          </div>

          <!-- ── boolean (ToggleSwitch) ─────────────────────────── -->
          <div v-else-if="meta.type === 'boolean'" class="cfg-fld-field cfg-fld-bool">
            <label>{{ t('fld_' + key) }}</label>
            <ToggleSwitch v-model="form[key]" />
            <small v-if="meta.help" class="cfg-fld-help">{{ meta.help }}</small>
          </div>

          <!-- ── text input ─────────────────────────────────────── -->
          <div v-else-if="meta.type === 'input'" class="cfg-fld-field" :class="{ required: meta.required }">
            <label>
              {{ t('fld_' + key) }}
              <span v-if="meta.required" class="cfg-req">*</span>
            </label>
            <InputText
              v-model="form[key]"
              size="small"
              :disabled="meta.readonly && !!origName"
              :placeholder="meta.pattern ?? ''"
              :class="{ 'p-invalid': errors[key] }"
              @blur="validate(key, meta)"
            />
            <small v-if="meta.help" class="cfg-fld-help">{{ meta.help }}</small>
            <small v-if="errors[key]" class="cfg-fld-err-msg">{{ errors[key] }}</small>
          </div>

          <!-- ── single select ──────────────────────────────────── -->
          <div v-else-if="meta.type === 'select'" class="cfg-fld-field" :class="{ required: meta.required }">
            <label>
              {{ t('fld_' + key) }}
              <span v-if="meta.required" class="cfg-req">*</span>
            </label>
            <Select
              v-model="form[key]"
              :options="selectOptions(meta)"
              :show-clear="!meta.required"
              size="small"
              :class="{ 'p-invalid': errors[key] }"
              @change="validate(key, meta)"
            />
            <small v-if="meta.help" class="cfg-fld-help">{{ meta.help }}</small>
            <small v-if="errors[key]" class="cfg-fld-err-msg">{{ errors[key] }}</small>
          </div>

          <!-- ── multi select ───────────────────────────────────── -->
          <div v-else-if="meta.type === 'multi_select'" class="cfg-fld-field" :class="{ required: meta.required }">
            <label>{{ t('fld_' + key) }}</label>
            <MultiSelect
              v-model="form[key]"
              :options="meta.values"
              size="small"
              display="chip"
            />
            <small v-if="meta.help" class="cfg-fld-help">{{ meta.help }}</small>
          </div>

        </template>
      </template>
    </div>

    <div class="cfg-fld-actions">
      <Button type="button" :label="t('cancel')" severity="secondary" size="small" text @click="$emit('cancelled')" />
      <Button type="submit" :label="t('save')" icon="pi pi-save" size="small" :loading="saving" />
    </div>

  </form>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import Button       from 'primevue/button'
import InputText    from 'primevue/inputtext'
import Select       from 'primevue/select'
import MultiSelect  from 'primevue/multiselect'
import ToggleSwitch from 'primevue/toggleswitch'
import { useToast } from 'primevue/usetoast'
import { useI18n }  from '@/i18n'
import { api }      from '@/api'

// Keys that are only relevant for select-family types
const SELECT_TYPES     = ['select', 'combo_select', 'multi_select']
const SELECT_ONLY_KEYS = ['vocabulary_set', 'get_values_from_tb', 'id_from_tb']

const props = defineProps({
  tb:        { type: String,  required: true },
  field:     { type: Object,  default: () => ({}) },
  structure: { type: Object,  default: () => ({}) },
})
const emit = defineEmits(['saved', 'cancelled'])

const { t }  = useI18n()
const toast  = useToast()

const origName = ref('')
const form     = ref({})
const errors   = ref({})
const saving   = ref(false)
const error    = ref(null)

// ── get_values_from_tb cascading state ────────────────────────────────────
const gvftTable   = ref('')
const gvftField   = ref('')
const gvftFields  = ref({})   // { fieldname: label }
const gvftLoading = ref(false)

// Tables list comes from id_from_tb.values in the structure (already populated by PHP)
const gvftTableOptions = computed(() => props.structure?.id_from_tb?.values ?? [])

const gvftFieldOptions = computed(() =>
  Object.entries(gvftFields.value).map(([k, v]) => ({ value: k, label: `${v} (${k})` }))
)

// ── Visibility guard ──────────────────────────────────────────────────────
function shouldShow(key) {
  if (SELECT_ONLY_KEYS.includes(key)) {
    return SELECT_TYPES.includes(form.value.type)
  }
  return true
}

// ── Form initialisation ───────────────────────────────────────────────────
function initForm(fieldData) {
  const f = { ...(fieldData ?? {}) }
  // Normalise boolean fields so ToggleSwitch gets true/false, never undefined
  for (const [key, meta] of Object.entries(props.structure)) {
    if (meta.type === 'boolean') {
      f[key] = !!f[key]
    }
  }
  form.value     = f
  origName.value = fieldData?.name ?? ''
  errors.value   = {}
  error.value    = null
  parseGvft(f.get_values_from_tb ?? '')
}

// ── get_values_from_tb helpers ────────────────────────────────────────────
function parseGvft(val) {
  if (!val) {
    gvftTable.value = ''
    gvftField.value = ''
    gvftFields.value = {}
    return
  }
  const [table = '', field = ''] = val.split(':')
  gvftTable.value = table
  gvftField.value = field
  if (table) loadGvftFields(table)
}

async function loadGvftFields(tb) {
  gvftLoading.value = true
  gvftFields.value  = {}
  try {
    const res = await api.get(`/api/config/table/${tb}`)
    gvftFields.value = res.field_labels ?? {}
  } catch {
    // ignore — field list stays empty
  } finally {
    gvftLoading.value = false
  }
}

function onGvftTableChange() {
  gvftField.value             = ''
  gvftFields.value            = {}
  form.value.get_values_from_tb = ''
  if (gvftTable.value) loadGvftFields(gvftTable.value)
}

function onGvftFieldChange() {
  form.value.get_values_from_tb =
    gvftTable.value && gvftField.value
      ? `${gvftTable.value}:${gvftField.value}`
      : ''
}

// ── Sync field prop ───────────────────────────────────────────────────────
watch(() => props.field, val => initForm(val), { deep: true, immediate: true })

// ── Helpers ───────────────────────────────────────────────────────────────
function selectOptions(meta) {
  return (meta.values ?? []).map(v => (v === true || v === false) ? String(v) : v)
}

function validate(key, meta) {
  if (meta.required && !form.value[key]) {
    errors.value[key] = `${t('fld_' + key)} ${t('is_required') || 'is required'}`
  } else if (meta.pattern && form.value[key] && !new RegExp(meta.pattern).test(form.value[key])) {
    errors.value[key] = `Must match pattern: ${meta.pattern}`
  } else {
    delete errors.value[key]
  }
}

function validateAll() {
  errors.value = {}
  for (const [key, meta] of Object.entries(props.structure)) {
    validate(key, meta)
  }
  return Object.keys(errors.value).length === 0
}

// ── Submit ────────────────────────────────────────────────────────────────
async function submit() {
  if (!validateAll()) {
    error.value = t('errors_in_form')
    return
  }
  error.value  = null
  saving.value = true

  try {
    const isNew   = !origName.value
    const payload = { ...form.value }

    let res
    if (isNew) {
      res = await api.post(`/api/config/table/${props.tb}/field`, payload)
    } else {
      res = await api.put(`/api/config/table/${props.tb}/field/${origName.value}`, payload)
    }
    toast.add({
      severity: res.status === 'success' ? 'success' : 'error',
      summary:  t('saved'),
      detail:   api.responseMessage(res, t),
      life: 4000
    })
    if (res.status === 'success') {
      emit('saved', form.value.name)
    }
  } catch (e) {
    toast.add({ severity: 'error', summary: e.message, life: 4000 })
  } finally {
    saving.value = false
  }
}
</script>

<style scoped>
.cfg-fld-form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.cfg-fld-error {
  padding: 0.5rem 0.75rem;
  background: color-mix(in srgb, var(--p-red-400) 12%, transparent);
  color: var(--p-red-500);
  border-radius: 6px;
  font-size: 0.85rem;
}

.cfg-fld-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 0.75rem;
}

/* Span two columns for the wide get_values_from_tb widget */
.cfg-fld-span2 {
  grid-column: span 2;
}

.cfg-fld-field {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

/* Boolean fields: label + switch side by side */
.cfg-fld-bool {
  flex-direction: row;
  flex-wrap: wrap;
  align-items: flex-start;
  gap: 0.4rem;
}
.cfg-fld-bool label {
  flex: 1;
}
.cfg-fld-bool .cfg-fld-help {
  flex: 0 0 100%;
}

.cfg-fld-field label {
  font-size: 0.78rem;
  font-weight: 600;
  color: var(--p-text-muted-color);
  display: flex;
  align-items: center;
  gap: 0.25rem;
}

/* Cascading table:field selector */
.cfg-gvft-row {
  display: flex;
  gap: 0.5rem;
}
.cfg-gvft-row .p-select {
  flex: 1;
  min-width: 0;
}

.cfg-req { color: var(--p-red-400); font-size: 0.7rem; }

.cfg-fld-help {
  font-size: 0.72rem;
  color: var(--p-text-muted-color);
  line-height: 1.3;
}

.cfg-fld-err-msg {
  font-size: 0.72rem;
  color: var(--p-red-500);
}

.cfg-fld-actions {
  display: flex;
  gap: 0.5rem;
  justify-content: flex-end;
  padding-top: 0.5rem;
  border-top: 1px solid var(--p-content-border-color);
}
</style>
