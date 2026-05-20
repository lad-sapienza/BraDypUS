<template>
  <div class="field-editor" v-if="!schema.hide">
    <label class="field-label">
      {{ schema.label }}
      <span v-if="schema.required" class="required-mark">*</span>
      <span v-if="schema.help" class="field-help pi pi-info-circle" :title="schema.help" />
    </label>

    <!-- readonly / disabled: show as display -->
    <div v-if="schema.readonly || schema.disabled" class="field-value-readonly">
      {{ modelValue ?? '—' }}
    </div>

    <!-- long_text -->
    <Textarea
      v-else-if="schema.type === 'long_text'"
      :value="modelValue"
      @input="onInput($event.target.value)"
      :dir="schema.direction || 'ltr'"
      :maxlength="schema.max_length || undefined"
      rows="4"
      autoResize
      :class="['field-input', 'w-full', { 'p-invalid': showError }]"
    />

    <!-- boolean -->
    <Select
      v-else-if="schema.type === 'boolean'"
      :modelValue="modelValue"
      @update:modelValue="v => onInput(v)"
      :options="boolOptions"
      optionLabel="label"
      optionValue="value"
      :class="['field-input', 'w-full', { 'p-invalid': showError }]"
    />

    <!-- select / combo_select / multi_select with options_source -->
    <template v-else-if="['select','combo_select','multi_select'].includes(schema.type) && schema.options_source">

      <!-- static options (dic) — no async needed -->
      <Select
        v-if="schema.options_source.type === 'static' && schema.type === 'select'"
        :modelValue="modelValue"
        @update:modelValue="v => onInput(v)"
        :options="staticOptions"
        optionLabel="label"
        optionValue="value"
        :placeholder="t('select_placeholder')"
        :class="['field-input', 'w-full', { 'p-invalid': showError }]"
      />
      <MultiSelect
        v-else-if="schema.options_source.type === 'static' && schema.type === 'multi_select'"
        :modelValue="multiSelectArray"
        @update:modelValue="v => onInput(v.join(';'))"
        :options="staticOptions"
        optionLabel="label"
        optionValue="value"
        :placeholder="t('select_placeholder')"
        :class="['field-input', 'w-full', { 'p-invalid': showError }]"
      />

      <!-- async options (db / id_from_tb / vocabulary) -->
      <template v-else>
        <Select
          v-if="schema.type === 'select'"
          :modelValue="modelValue"
          @update:modelValue="v => onInput(v)"
          :options="asyncOptions"
          optionLabel="label"
          optionValue="value"
          :loading="loadingOptions"
          :placeholder="t('select_placeholder')"
          filter
          :class="['field-input', 'w-full', { 'p-invalid': showError }]"
          @before-show="loadOptions"
        />
        <Select
          v-else-if="schema.type === 'combo_select'"
          :modelValue="modelValue"
          @update:modelValue="v => onInput(v)"
          :options="asyncOptions"
          optionLabel="label"
          optionValue="value"
          :loading="loadingOptions"
          :placeholder="t('select_placeholder')"
          editable
          filter
          :class="['field-input', 'w-full', { 'p-invalid': showError }]"
          @before-show="loadOptions"
        />
        <MultiSelect
          v-else-if="schema.type === 'multi_select'"
          :modelValue="multiSelectArray"
          @update:modelValue="v => onInput(v.join(';'))"
          :options="asyncOptions"
          optionLabel="label"
          optionValue="value"
          :loading="loadingOptions"
          :placeholder="t('select_placeholder')"
          filter
          :class="['field-input', 'w-full', { 'p-invalid': showError }]"
          @before-show="loadOptions"
        />
      </template>
    </template>

    <!-- slider -->
    <div v-else-if="schema.type === 'slider'" class="slider-wrap">
      <Slider
        :modelValue="Number(modelValue) || 0"
        @update:modelValue="v => onInput(String(v))"
        :min="schema.min != null ? Number(schema.min) : 0"
        :max="schema.max != null ? Number(schema.max) : 100"
        class="field-slider"
      />
      <span class="slider-value">{{ modelValue ?? 0 }}</span>
    </div>

    <!-- date -->
    <InputText
      v-else-if="schema.type === 'date'"
      type="date"
      :value="modelValue"
      @input="onInput($event.target.value)"
      :min="schema.min || undefined"
      :max="schema.max || undefined"
      :class="['field-input', 'w-full', { 'p-invalid': showError }]"
    />

    <!-- text (default) — also handles number via min/max -->
    <InputText
      v-else
      :type="(schema.min != null || schema.max != null) ? 'number' : 'text'"
      :value="modelValue"
      @input="onInput($event.target.value)"
      :dir="schema.direction || 'ltr'"
      :min="schema.min || undefined"
      :max="schema.max || undefined"
      :maxlength="schema.max_length || undefined"
      :pattern="schema.pattern || undefined"
      :class="['field-input', 'w-full', { 'p-invalid': showError }]"
    />

    <!-- Inline validation error -->
    <div v-if="showError" class="field-error">
      <i class="pi pi-exclamation-circle" />
      {{ validationError }}
    </div>

  </div>
</template>

<script setup>
import { ref, computed, inject } from 'vue'
import InputText  from 'primevue/inputtext'
import Textarea    from 'primevue/textarea'
import Select      from 'primevue/select'
import MultiSelect from 'primevue/multiselect'
import Slider      from 'primevue/slider'
import { useToast } from 'primevue/usetoast'
import { api }     from '@/api'
import { useI18n } from '@/i18n'
import { onMounted } from 'vue'

const { t } = useI18n()
const toast = useToast()

const props = defineProps({
  schema:     { type: Object, required: true },
  modelValue: { type: [String, Number, null], default: null },
  /** Full table id (needed for getFieldOptions call) */
  tb:         { type: String, required: true },
})
const emit = defineEmits(['update:modelValue'])

// ── Dirty tracking (set to true on first user change) ─────────
const dirty = ref(false)

/** Provided by RecordView to force-show validation errors on save attempt */
const forceValidate = inject('forceValidate', ref(false))

// ── Validation ──────────────────────────────────────────────────
const validationError = computed(() => {
  const v = props.modelValue
  const s = props.schema
  const check = s.check ?? []

  const isEmpty = v === null || v === undefined || v === ''

  // required / not_empty (server normalises both to s.required = true)
  if (s.required && isEmpty) {
    return t('field_required')
  }

  // No further checks on empty optional fields
  if (isEmpty) return null

  // int — whole numbers only
  if (check.includes('int') && !/^-?\d+$/.test(String(v))) {
    return t('value_must_be_integer')
  }

  // email
  if (check.includes('email') && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(v))) {
    return t('invalid_email')
  }

  // min / max — numeric fields
  if (s.type !== 'date' && s.min != null) {
    if (Number(v) < Number(s.min)) return t('value_too_small', s.min)
  }
  if (s.type !== 'date' && s.max != null) {
    if (Number(v) > Number(s.max)) return t('value_too_large', s.max)
  }

  // min / max — date fields (ISO string comparison: 'YYYY-MM-DD' sorts lexicographically)
  if (s.type === 'date' && s.min && String(v) < String(s.min)) {
    return t('value_too_small', s.min)
  }
  if (s.type === 'date' && s.max && String(v) > String(s.max)) {
    return t('value_too_large', s.max)
  }

  // max_length
  if (s.max_length && String(v).length > Number(s.max_length)) {
    return t('value_too_long', s.max_length)
  }

  // pattern / regex
  if (s.pattern) {
    try {
      if (!new RegExp(s.pattern).test(String(v))) return t('invalid_format')
    } catch { /* invalid regex in config — ignore */ }
  }

  // no_dupl and valid_wkt are backend-only checks (require DB or geometry lib)

  return null
})

/** Show the error when the user has interacted OR parent forces it */
const showError = computed(() =>
  (dirty.value || forceValidate.value) && !!validationError.value
)

/** True when this field currently has a validation problem */
const hasError = computed(() => !!validationError.value)

// Let RecordView query hasError on each field editor via template ref
defineExpose({ hasError })

function onInput(value) {
  dirty.value = true
  emit('update:modelValue', value)
}

// ── Static options (dic) ───────────────────────────────────────
const staticOptions = computed(() =>
  (props.schema.options_source?.items ?? []).map(v => ({ value: v, label: v }))
)

// ── Async options (db / id_from_tb / vocabulary) ──────────────
const asyncOptions   = ref([])
const loadingOptions = ref(false)
let   optionsLoaded  = false

async function loadOptions() {
  if (optionsLoaded || props.schema.options_source?.type === 'static') return
  loadingOptions.value = true
  try {
    const res = await api.get(`/api/record/${props.tb}/field-options`, {
      fld: props.schema.name,
    })
    asyncOptions.value = Array.isArray(res) ? res : []
    optionsLoaded = true
  } catch (e) {
    toast.add({
      severity: 'warn',
      summary:  props.schema.label,
      detail:   t('error_loading_options'),
      life:     4000,
    })
  } finally {
    loadingOptions.value = false
  }
}

// ── Boolean options ────────────────────────────────────────────
const boolOptions = computed(() => [
  { value: '1',  label: t('yes') },
  { value: '0',  label: t('no') },
])

// ── multi_select: stored as "a;b;c", bound as array ───────────
const multiSelectArray = computed(() => {
  if (!props.modelValue) return []
  return String(props.modelValue).split(';').map(v => v.trim()).filter(Boolean)
})

// Pre-load async options on mount for all select-family fields so the closed-state
// display can resolve the current value immediately (without waiting for the user to
// open the dropdown). The @before-show guard (optionsLoaded flag) prevents a second
// fetch when the user actually opens the panel.
onMounted(() => {
  if (['select', 'combo_select', 'multi_select'].includes(props.schema.type)) {
    loadOptions()
  }
})
</script>

<style scoped>
.field-editor {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
  padding: 0.35rem 0;
}

.field-label {
  font-size: 0.72rem;
  font-weight: 600;
  text-transform: uppercase;
  color: var(--p-text-muted-color);
  letter-spacing: 0.04em;
}

.required-mark { color: var(--p-red-500); margin-left: 0.15rem; }
.field-help    { margin-left: 0.25rem; cursor: help; opacity: 0.6; }

.field-input { font-size: 0.875rem; }
.w-full      { width: 100%; }

.field-value-readonly {
  font-size: 0.875rem;
  color: var(--p-text-muted-color);
  padding: 0.35rem 0;
}

.slider-wrap {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}
.field-slider { flex: 1; }
.slider-value { min-width: 2.5rem; text-align: right; font-size: 0.875rem; }

.field-error {
  display: flex;
  align-items: center;
  gap: 0.3rem;
  font-size: 0.72rem;
  color: var(--p-red-500);
  margin-top: 0.1rem;
}
</style>
