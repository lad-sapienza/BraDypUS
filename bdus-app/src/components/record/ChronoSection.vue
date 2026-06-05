<template>
  <fieldset class="record-section">
    <legend>{{ t('fuzzy_date_label') }}</legend>

    <!-- ── Read mode ─────────────────────────────────────────── -->
    <template v-if="!editMode">
      <div v-if="displayLabel || from != null || to != null" class="chrono-read">
        <span v-if="displayLabel" class="chrono-label">{{ displayLabel }}</span>
        <div class="chrono-nums">
          <span class="chrono-num-item">{{ t('chrono_from') }}: <strong>{{ from ?? '—' }}</strong></span>
          <span class="chrono-num-item">{{ t('chrono_to') }}: <strong>{{ to ?? '—' }}</strong></span>
        </div>
        <div class="chrono-meta-read">
          <Tag
            v-if="normCertainty(certainty)"
            :value="t('chrono_certainty_' + normCertainty(certainty))"
            :severity="certaintySeverity"
            class="chrono-tag"
          />
          <span v-if="period" class="chrono-period">{{ period }}</span>
        </div>
      </div>
      <div v-else class="chrono-empty">—</div>
    </template>

    <!-- ── Edit mode ──────────────────────────────────────────── -->
    <template v-else>
      <!-- Chronology input -->
      <div class="chrono-field">
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

      <!-- Certainty -->
      <div class="chrono-field">
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

      <!-- Period -->
      <div class="chrono-field">
        <label class="field-label">{{ t('fuzzy_date_period') }}</label>
        <InputText
          v-model="localPeriod"
          class="w-full"
          @input="onMetaChange"
        />
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
  from:      { type: Number, default: null },
  to:        { type: Number, default: null },
  label:     { type: String, default: null },
  certainty: { type: Number, default: null },
  period:    { type: String, default: null },
  editMode:  { type: Boolean, default: false },
})

const emit = defineEmits(['update:chrono'])

// ── Helpers ───────────────────────────────────────────────────────────────────

// Map legacy English/Italian string values to the new numeric codes (1/2/3).
const LEGACY_CERTAINTY = { certain: 1, certa: 1, probable: 2, probabile: 2, possible: 3, incerta: 3, uncertain: 3 }

function normCertainty(v) {
  if (v == null) return null
  if (typeof v === 'number') return v
  return LEGACY_CERTAINTY[String(v).toLowerCase()] ?? null
}

function numbersToInput(from, to) {
  if (from == null && to == null) return '?'
  const tok = n => String(n)
  if (from == null) return `?/${tok(to)}`
  if (to   == null) return `${tok(from)}/?`
  return `${tok(from)}/${tok(to)}`
}

// ── Local state ───────────────────────────────────────────────────────────────

const inputStr       = ref('')
const parseResult    = ref(null)
const localCertainty = ref(normCertainty(props.certainty))
const localPeriod    = ref(props.period    ?? '')

// ── Initialise input string from stored values ─────────────────────────────

watch(() => [props.from, props.to, props.label], ([f, t_, l]) => {
  if (inputStr.value !== '') return   // don't overwrite mid-edit
  if (l && parse(l).valid) {
    inputStr.value = l
  } else if (f != null || t_ != null) {
    inputStr.value = numbersToInput(f, t_)
  }
}, { immediate: true })

watch(() => props.certainty, v => { localCertainty.value = normCertainty(v) })
watch(() => props.period,    v => { localPeriod.value    = v ?? '' })

// ── Computed ──────────────────────────────────────────────────────────────────

const displayLabel = computed(() => {
  if (props.label) {
    const parsed = parse(props.label)
    if (parsed.valid && parsed.label) return parsed.label
  }
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
  { value: 1, label: t('chrono_certainty_1') },
  { value: 2, label: t('chrono_certainty_2') },
  { value: 3, label: t('chrono_certainty_3') },
])

const certaintySeverity = computed(() => ({
  1: 'success',
  2: 'warn',
  3: 'danger',
}[normCertainty(props.certainty)] ?? 'secondary'))

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
      str,   // save the raw input, not the display label
    )
  }
}

function onMetaChange() {
  const pr = parseResult.value
  emitChrono(
    pr?.from  ?? props.from  ?? null,
    pr?.to    ?? props.to    ?? null,
    inputStr.value.trim() || props.label || null,
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
.chrono-field       { display: flex; flex-direction: column; gap: .25rem; margin-bottom: .75rem; }
.chrono-field:last-child { margin-bottom: 0; }
.chrono-input       { width: 100%; }
.chrono-preview     { color: var(--p-text-muted-color); font-size: .8rem; }
.chrono-preview-nums { opacity: .7; }
.chrono-error       { font-size: .8rem; }

.chrono-read        { display: flex; flex-direction: column; gap: .35rem; }
.chrono-label       { font-weight: 500; }
.chrono-nums        { display: flex; gap: .75rem; font-size: .82rem; color: var(--p-text-muted-color); }
.chrono-meta-read   { display: flex; align-items: center; gap: .4rem; flex-wrap: wrap; }
.chrono-period      { font-size: .9rem; color: var(--p-text-muted-color); }
.chrono-tag         { font-size: .75rem; }
.chrono-empty       { color: var(--p-text-muted-color); }
</style>
