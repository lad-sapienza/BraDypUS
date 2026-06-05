<template>
  <fieldset class="record-section">
    <legend>{{ t('fuzzy_date_label') }}</legend>

    <!-- ── Read mode ─────────────────────────────────────────── -->
    <template v-if="!editMode">
      <div v-if="displayLabel" class="chrono-display">
        <span class="chrono-label">{{ displayLabel }}</span>
        <Tag
          v-if="certainty"
          :value="t(certainty)"
          :severity="certaintySeverity"
          class="chrono-tag"
        />
        <span v-if="period" class="chrono-period">· {{ period }}</span>
      </div>
      <div v-else class="chrono-empty">—</div>
    </template>

    <!-- ── Edit mode ──────────────────────────────────────────── -->
    <template v-else>
      <!-- Chronology input -->
      <div class="chrono-input-row">
        <div class="chrono-input-wrap">
          <InputText
            v-model="inputStr"
            :placeholder="t('fuzzy_date_input_placeholder')"
            :class="['chrono-input', { 'p-invalid': parseResult && !parseResult.valid }]"
            @input="onInput"
          />
          <!-- Live preview -->
          <small v-if="parseResult?.valid && parseResult.label" class="chrono-preview">
            {{ parseResult.label }}
            <span v-if="parseResult.from != null || parseResult.to != null" class="chrono-preview-nums">
              ({{ numericRange }})
            </span>
          </small>
          <small v-else-if="parseResult && !parseResult.valid" class="chrono-error p-error">
            {{ parseResult.error }}
          </small>
        </div>
      </div>

      <!-- Certainty + Period row -->
      <div class="chrono-meta-row">
        <div class="chrono-meta-field">
          <label class="field-label">{{ t('fuzzy_date_certainty') }}</label>
          <Select
            v-model="localCertainty"
            :options="certaintyOptions"
            optionLabel="label"
            optionValue="value"
            showClear
            class="w-full"
            @update:modelValue="onMetaChange"
          />
        </div>
        <div class="chrono-meta-field">
          <label class="field-label">{{ t('fuzzy_date_period') }}</label>
          <InputText
            v-model="localPeriod"
            class="w-full"
            @input="onMetaChange"
          />
        </div>
      </div>
    </template>
  </fieldset>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import InputText from 'primevue/inputtext'
import Select    from 'primevue/select'
import Tag       from 'primevue/tag'
import { useI18n } from '@/i18n'
import { parse, format } from '@/utils/chronoParser'

const { t } = useI18n()

const props = defineProps({
  /** Current chrono values from core (read mode source). */
  from:      { type: Number, default: null },
  to:        { type: Number, default: null },
  label:     { type: String, default: null },
  certainty: { type: String, default: null },
  period:    { type: String, default: null },
  editMode:  { type: Boolean, default: false },
})

const emit = defineEmits(['update:chrono'])

// ── Helpers ───────────────────────────────────────────────────────────────────

function numbersToInput(from, to) {
  if (from == null && to == null) return '?'
  const tok = n => String(n)   // negative ints match YEAR_NEG, positive match YEAR_POS
  if (from == null) return `?/${tok(to)}`
  if (to   == null) return `${tok(from)}/?`
  return `${tok(from)}/${tok(to)}`
}

// ── Local state ───────────────────────────────────────────────────────────────

const inputStr      = ref('')
const parseResult   = ref(null)
const localCertainty = ref(props.certainty ?? null)
const localPeriod    = ref(props.period    ?? '')

// ── Initialise input string from stored values ─────────────────────────────

watch(() => [props.from, props.to, props.label], ([f, t_, l]) => {
  if (inputStr.value !== '') return   // don't overwrite mid-edit
  if (f != null || t_ != null) {
    // Build a parseable input string from the stored numbers.
    // The stored label is the display output of the parser, not re-parseable input.
    inputStr.value = numbersToInput(f, t_)
  } else if (l) {
    inputStr.value = l
  }
}, { immediate: true })

watch(() => props.certainty, v => { localCertainty.value = v ?? null })
watch(() => props.period,    v => { localPeriod.value    = v ?? '' })

// ── Computed ──────────────────────────────────────────────────────────────────

const displayLabel = computed(() => {
  if (props.label) return props.label
  if (props.from != null || props.to != null) return format(props.from, props.to)
  return null
})

const numericRange = computed(() => {
  if (!parseResult.value?.valid) return ''
  const { from, to } = parseResult.value
  if (from === null && to === null) return ''
  if (from === null) return `≤ ${to}`
  if (to === null)   return `≥ ${from}`
  if (from === to)   return `${from}`
  return `${from} – ${to}`
})

const certaintyOptions = computed(() => [
  { value: 'certain',  label: t('certain')  },
  { value: 'probable', label: t('probable') },
  { value: 'possible', label: t('possible') },
])

const certaintySeverity = computed(() => ({
  certain:  'success',
  probable: 'warn',
  possible: 'danger',
}[props.certainty] ?? 'secondary'))

// ── Handlers ──────────────────────────────────────────────────────────────────

function onInput() {
  const str = inputStr.value.trim()
  if (!str) {
    parseResult.value = null
    emitChrono(null, null, null)
    return
  }
  parseResult.value = parse(str)
  if (parseResult.value.valid) {
    emitChrono(
      parseResult.value.from,
      parseResult.value.to,
      parseResult.value.label,
    )
  }
}

function onMetaChange() {
  // Re-emit with the current from/to/label (unchanged) plus updated meta
  const pr = parseResult.value
  emitChrono(
    pr?.from  ?? props.from  ?? null,
    pr?.to    ?? props.to    ?? null,
    pr?.label ?? props.label ?? null,
  )
}

function emitChrono(from, to, label) {
  emit('update:chrono', {
    chrono_from:      from,
    chrono_to:        to,
    chrono_label:     label,
    chrono_certainty: localCertainty.value || null,
    chrono_period:    localPeriod.value    || null,
  })
}
</script>

<style scoped>
.chrono-input-row   { display: flex; gap: .5rem; margin-bottom: .75rem; }
.chrono-input-wrap  { flex: 1; display: flex; flex-direction: column; gap: .25rem; }
.chrono-input       { width: 100%; }
.chrono-preview     { color: var(--p-text-muted-color); font-size: .8rem; }
.chrono-preview-nums { opacity: .7; }
.chrono-error       { font-size: .8rem; }
.chrono-meta-row    { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
.chrono-meta-field  { display: flex; flex-direction: column; gap: .25rem; }
.chrono-display     { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
.chrono-label       { font-weight: 500; }
.chrono-period      { color: var(--p-text-muted-color); font-size: .9rem; }
.chrono-tag         { font-size: .75rem; }
.chrono-empty       { color: var(--p-text-muted-color); }
</style>
