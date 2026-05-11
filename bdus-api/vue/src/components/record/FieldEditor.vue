<template>
  <div class="field-editor" :class="{ hidden: schema.hide }" v-if="!schema.hide">
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
      @input="emit('update:modelValue', $event.target.value)"
      :dir="schema.direction || 'ltr'"
      :maxlength="schema.max_length || undefined"
      rows="4"
      autoResize
      class="field-input w-full"
    />

    <!-- boolean -->
    <Select
      v-else-if="schema.type === 'boolean'"
      :modelValue="modelValue"
      @update:modelValue="emit('update:modelValue', $event)"
      :options="boolOptions"
      optionLabel="label"
      optionValue="value"
      class="field-input w-full"
    />

    <!-- select / combo_select / multi_select with options_source -->
    <template v-else-if="['select','combo_select','multi_select'].includes(schema.type) && schema.options_source">

      <!-- static options (dic) — no async needed -->
      <Select
        v-if="schema.options_source.type === 'static' && schema.type === 'select'"
        :modelValue="modelValue"
        @update:modelValue="emit('update:modelValue', $event)"
        :options="staticOptions"
        optionLabel="label"
        optionValue="value"
        :placeholder="t('select_placeholder')"
        class="field-input w-full"
      />
      <MultiSelect
        v-else-if="schema.options_source.type === 'static' && schema.type === 'multi_select'"
        :modelValue="multiSelectArray"
        @update:modelValue="v => emit('update:modelValue', v.join(';'))"
        :options="staticOptions"
        optionLabel="label"
        optionValue="value"
        :placeholder="t('select_placeholder')"
        class="field-input w-full"
      />

      <!-- async options (db / id_from_tb / vocabulary) -->
      <template v-else>
        <Select
          v-if="schema.type === 'select'"
          :modelValue="modelValue"
          @update:modelValue="emit('update:modelValue', $event)"
          :options="asyncOptions"
          optionLabel="label"
          optionValue="value"
          :loading="loadingOptions"
          :placeholder="t('select_placeholder')"
          filter
          class="field-input w-full"
          @before-show="loadOptions"
        />
        <Select
          v-else-if="schema.type === 'combo_select'"
          :modelValue="modelValue"
          @update:modelValue="emit('update:modelValue', $event)"
          :options="asyncOptions"
          optionLabel="label"
          optionValue="value"
          :loading="loadingOptions"
          :placeholder="t('select_placeholder')"
          editable
          filter
          class="field-input w-full"
          @before-show="loadOptions"
        />
        <MultiSelect
          v-else-if="schema.type === 'multi_select'"
          :modelValue="multiSelectArray"
          @update:modelValue="v => emit('update:modelValue', v.join(';'))"
          :options="asyncOptions"
          optionLabel="label"
          optionValue="value"
          :loading="loadingOptions"
          :placeholder="t('select_placeholder')"
          filter
          class="field-input w-full"
          @before-show="loadOptions"
        />
      </template>
    </template>

    <!-- slider -->
    <div v-else-if="schema.type === 'slider'" class="slider-wrap">
      <Slider
        :modelValue="Number(modelValue) || 0"
        @update:modelValue="emit('update:modelValue', String($event))"
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
      @input="emit('update:modelValue', $event.target.value)"
      :min="schema.min || undefined"
      :max="schema.max || undefined"
      class="field-input w-full"
    />

    <!-- text (default) — also handles number via min/max -->
    <InputText
      v-else
      :type="(schema.min != null || schema.max != null) ? 'number' : 'text'"
      :value="modelValue"
      @input="emit('update:modelValue', $event.target.value)"
      :dir="schema.direction || 'ltr'"
      :min="schema.min || undefined"
      :max="schema.max || undefined"
      :maxlength="schema.max_length || undefined"
      :pattern="schema.pattern || undefined"
      class="field-input w-full"
    />

  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import InputText  from 'primevue/inputtext'
import Textarea    from 'primevue/textarea'
import Select      from 'primevue/select'
import MultiSelect from 'primevue/multiselect'
import Slider      from 'primevue/slider'
import { api }     from '@/api'
import { useI18n } from '@/i18n'
import { onMounted } from 'vue'

const { t } = useI18n()

const props = defineProps({
  schema:     { type: Object, required: true },
  modelValue: { type: [String, Number, null], default: null },
  /** Full table id (needed for getFieldOptions call) */
  tb:         { type: String, required: true },
})
const emit = defineEmits(['update:modelValue'])

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
    const res = await api.get('record_ctrl', 'getFieldOptions', {
      tb:  props.tb,
      fld: props.schema.name,
    })
    asyncOptions.value = Array.isArray(res) ? res : []
    optionsLoaded = true
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

// Pre-load async options for multi_select on mount so the closed-state display
// can resolve labels immediately (without waiting for the user to open the dropdown).
// Static (dic) options are already computed synchronously and need no fetch.
onMounted(() => {
  if (props.schema.type === 'multi_select') {
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
</style>
