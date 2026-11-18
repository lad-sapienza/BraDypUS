<template>
  <div class="field-editor" v-if="!schema.hide">
    <label class="field-label">
      {{ schema.label }}
      <span v-if="schema.required" class="required-mark">*</span>
      <InfoCircleOutlined v-if="schema.help" :title="schema.help" class="field-help" />
    </label>

    <!-- readonly: show as display -->
    <div v-if="schema.readonly" class="field-value-readonly">
      {{ modelValue ?? '—' }}
    </div>

    <!-- md: Markdown textarea with formatting toolbar + live preview toggle -->
    <div v-else-if="schema.type === 'md'" class="md-editor-wrap">
      <div class="md-toolbar">
        <template v-if="!mdPreview">
          <button type="button" class="md-tool" :title="t('md_bold')" @click="mdBold">
            <BoldOutlined />
          </button>
          <button type="button" class="md-tool" :title="t('md_italic')" @click="mdItalic">
            <ItalicOutlined />
          </button>
          <button type="button" class="md-tool" :title="t('md_link')" @click="mdLink">
            <LinkOutlined />
          </button>
          <button type="button" class="md-tool" :title="t('md_bullet_list')" @click="mdBulletList">
            <UnorderedListOutlined />
          </button>
          <button type="button" class="md-tool" :title="t('md_numbered_list')" @click="mdNumberedList">
            <OrderedListOutlined />
          </button>
          <button type="button" class="md-tool" :title="t('md_code')" @click="mdCode">
            <CodeOutlined />
          </button>
          <span class="md-toolbar-spacer" />
        </template>
        <button type="button" class="md-toggle" @click="mdPreview = !mdPreview">
          <component :is="mdPreview ? EditOutlined : EyeOutlined" />
          {{ mdPreview ? t('edit') : t('preview') }}
        </button>
      </div>
      <ATextarea
        v-if="!mdPreview"
        ref="mdTextareaRef"
        :value="modelValue"
        @update:value="onInput"
        :dir="schema.direction || 'ltr'"
        :maxlength="schema.max_length || undefined"
        :autoSize="{ minRows: 5 }"
        :status="showError ? 'error' : undefined"
        class="field-input w-full"
      />
      <div v-else class="md-preview" v-html="marked.parse(String(modelValue ?? ''))" />
      <CharPalette v-if="showPalette && !mdPreview" :items="schema.char_palette" :get-el="mdTextareaEl" />
    </div>

    <!-- long_text -->
    <div v-else-if="schema.type === 'long_text'">
      <ATextarea
        ref="longTextRef"
        :value="modelValue"
        @update:value="onInput"
        :dir="schema.direction || 'ltr'"
        :maxlength="schema.max_length || undefined"
        :autoSize="{ minRows: 3 }"
        :status="showError ? 'error' : undefined"
        class="field-input w-full"
      />
      <CharPalette v-if="showPalette" :items="schema.char_palette" :get-el="longTextEl" />
    </div>

    <!-- boolean -->
    <div v-else-if="schema.type === 'boolean'" class="bool-switch-wrap">
      <ASwitch
        :checked="modelValue === '1' || modelValue === 1 || modelValue === true"
        @update:checked="v => onInput(v ? '1' : '0')"
      />
      <span class="bool-switch-label">{{ modelValue === '1' || modelValue === 1 || modelValue === true ? t('yes') : t('no') }}</span>
    </div>

    <!-- select / combo_select / multi_select with options_source -->
    <template v-else-if="['select','combo_select','multi_select'].includes(schema.type) && schema.options_source">

      <!-- static options (dic) — no async needed -->
      <ASelect
        v-if="schema.options_source.type === 'static' && schema.type === 'select'"
        :value="modelValue"
        @update:value="onInput"
        :options="staticOptions"
        :placeholder="t('select_placeholder')"
        :status="showError ? 'error' : undefined"
        class="field-input w-full"
      />
      <ASelect
        v-else-if="schema.options_source.type === 'static' && schema.type === 'multi_select'"
        mode="multiple"
        :value="multiSelectArray"
        @update:value="v => onInput(v.join(';'))"
        :options="staticOptions"
        :placeholder="t('select_placeholder')"
        :status="showError ? 'error' : undefined"
        class="field-input w-full"
      />

      <!-- async options (db / id_from_tb / vocabulary) -->
      <template v-else>
        <ASelect
          v-if="schema.type === 'select'"
          :value="asyncModelValue"
          @update:value="onInput"
          :options="asyncOptions"
          :loading="loadingOptions"
          :placeholder="t('select_placeholder')"
          :status="showError ? 'error' : undefined"
          show-search
          :filter-option="partialSource ? false : filterOption"
          class="field-input w-full"
          @search="onSearch"
          @dropdown-visible-change="onDropdownChange"
        >
          <template #dropdownRender="{ menuNode }">
            <VNodes :vnodes="menuNode" />
            <div v-if="listPartial" class="options-partial">{{ t('options_partial_hint') }}</div>
          </template>
        </ASelect>
        <!-- combo_select: PrimeVue's Select has a single `editable` flag for a
             free-text + suggestions hybrid. AntD splits this into a separate
             AutoComplete component — there is no "editable Select". -->
        <AAutoComplete
          v-else-if="schema.type === 'combo_select'"
          :value="asyncModelValue"
          @update:value="onInput"
          :options="asyncOptions"
          :placeholder="t('select_placeholder')"
          :status="showError ? 'error' : undefined"
          :filter-option="partialSource ? false : filterOption"
          class="field-input w-full"
          @search="onSearch"
          @dropdown-visible-change="onDropdownChange"
        >
          <template #dropdownRender="{ menuNode }">
            <VNodes :vnodes="menuNode" />
            <div v-if="listPartial" class="options-partial">{{ t('options_partial_hint') }}</div>
          </template>
        </AAutoComplete>
        <ASelect
          v-else-if="schema.type === 'multi_select'"
          mode="multiple"
          :value="multiSelectArray"
          @update:value="v => onInput(v.join(';'))"
          :options="asyncOptions"
          :loading="loadingOptions"
          :placeholder="t('select_placeholder')"
          :status="showError ? 'error' : undefined"
          show-search
          :filter-option="partialSource ? false : filterOption"
          class="field-input w-full"
          @search="onSearch"
          @dropdown-visible-change="onDropdownChange"
        >
          <template #dropdownRender="{ menuNode }">
            <VNodes :vnodes="menuNode" />
            <div v-if="listPartial" class="options-partial">{{ t('options_partial_hint') }}</div>
          </template>
        </ASelect>
      </template>
    </template>

    <!-- slider -->
    <div v-else-if="schema.type === 'slider'" class="slider-wrap">
      <ASlider
        :value="Number(modelValue) || 0"
        @update:value="v => onInput(String(v))"
        :min="schema.min != null ? Number(schema.min) : 0"
        :max="schema.max != null ? Number(schema.max) : 100"
        class="field-slider"
      />
      <span class="slider-value">{{ modelValue ?? 0 }}</span>
    </div>

    <!-- date -->
    <AInput
      v-else-if="schema.type === 'date'"
      type="date"
      :value="modelValue"
      @update:value="onInput"
      :min="schema.min || undefined"
      :max="schema.max || undefined"
      :status="showError ? 'error' : undefined"
      class="field-input w-full"
    />

    <!-- text (default) — also handles number via min/max -->
    <AInput
      v-else
      :type="(schema.min != null || schema.max != null) ? 'number' : 'text'"
      :value="modelValue"
      @update:value="onInput"
      :dir="schema.direction || 'ltr'"
      :min="schema.min || undefined"
      :max="schema.max || undefined"
      :maxlength="schema.max_length || undefined"
      :pattern="schema.pattern || undefined"
      :status="showError ? 'error' : undefined"
      class="field-input w-full"
    />

    <!-- Inline validation error (sync) -->
    <div v-if="showError" class="field-error">
      <ExclamationCircleOutlined />
      {{ validationError }}
    </div>

    <!-- Inline uniqueness error (async no_dupl) -->
    <div v-if="uniqueError" class="field-error">
      <ExclamationCircleOutlined />
      {{ uniqueError }}
    </div>

  </div>
</template>

<script setup>
import {
  BoldOutlined, CodeOutlined, EditOutlined, ExclamationCircleOutlined, EyeOutlined,
  InfoCircleOutlined, ItalicOutlined, LinkOutlined, OrderedListOutlined, UnorderedListOutlined,
} from '@ant-design/icons-vue'
import { ref, computed, inject, nextTick } from 'vue'
import { marked } from 'marked'
import { Input, Select as ASelect, Switch as ASwitch, Slider as ASlider, AutoComplete as AAutoComplete } from 'ant-design-vue'
import { useToast } from '@/composables/useNotify'
import { api }     from '@/api'
import { useI18n } from '@/i18n'
import { validateField } from '@/utils/fieldValidation'
import { normalizeOptions, selectedValues, withSelected } from '@/utils/fieldOptions'
import CharPalette from './CharPalette.vue'
import { onMounted, watch } from 'vue'

const AInput = Input
const ATextarea = Input.TextArea

const { t } = useI18n()
const toast = useToast()

const props = defineProps({
  schema:     { type: Object, required: true },
  modelValue: { type: [String, Number, null], default: null },
  /** Full table id (needed for getFieldOptions and check-unique calls) */
  tb:         { type: String, required: true },
  /** Current record id — null for new records; needed to exclude self from no_dupl check */
  recordId:   { type: [String, Number, null], default: null },
})
const emit = defineEmits(['update:modelValue'])

// ── Markdown preview toggle ────────────────────────────────────
const mdPreview = ref(false)

// ── Markdown formatting toolbar ─────────────────────────────────
const mdTextareaRef = ref(null)
const longTextRef  = ref(null)

/** The strip of special characters under a long_text / md field (`char_palette` in the field's configuration). */
const showPalette = computed(() => !props.schema.readonly && !!props.schema.char_palette?.length)
function longTextEl() {
  return longTextRef.value?.resizableTextArea?.textArea ?? null
}

function mdTextareaEl() {
  return mdTextareaRef.value?.resizableTextArea?.textArea ?? null
}

/** Commit the edited value, then restore focus and selection so typing can continue right away. */
function mdApplyEdit(newValue, selStart, selEnd) {
  onInput(newValue)
  nextTick(() => {
    const el = mdTextareaEl()
    if (!el) return
    el.focus()
    el.setSelectionRange(selStart, selEnd)
  })
}

/** Wrap the current selection in `before`/`after` markers, or insert a placeholder if nothing is selected. */
function mdWrapSelection(before, after, placeholder) {
  const el = mdTextareaEl()
  const value = String(props.modelValue ?? '')
  const start = el ? el.selectionStart : value.length
  const end   = el ? el.selectionEnd   : value.length
  const selected = value.slice(start, end) || placeholder
  const newValue = value.slice(0, start) + before + selected + after + value.slice(end)
  const selStart = start + before.length
  mdApplyEdit(newValue, selStart, selStart + selected.length)
}

function mdBold()   { mdWrapSelection('**', '**', t('md_bold_placeholder')) }
function mdItalic()  { mdWrapSelection('*', '*', t('md_italic_placeholder')) }
function mdCode()   { mdWrapSelection('`', '`', t('md_code_placeholder')) }

function mdLink() {
  const el = mdTextareaEl()
  const value = String(props.modelValue ?? '')
  const start = el ? el.selectionStart : value.length
  const end   = el ? el.selectionEnd   : value.length
  const label = value.slice(start, end) || t('md_link_placeholder')
  const insert = `[${label}](url)`
  const newValue = value.slice(0, start) + insert + value.slice(end)
  // Select the "url" placeholder so the user can type the real address right away.
  const urlStart = start + label.length + 3
  mdApplyEdit(newValue, urlStart, urlStart + 3)
}

/** Prefix every line touched by the current selection (or the current line, if empty). */
function mdPrefixLines(makePrefix) {
  const el = mdTextareaEl()
  const value = String(props.modelValue ?? '')
  const start = el ? el.selectionStart : value.length
  const end   = el ? el.selectionEnd   : value.length

  const lineStart = value.lastIndexOf('\n', start - 1) + 1
  let lineEnd = value.indexOf('\n', end)
  if (lineEnd === -1) lineEnd = value.length

  const prefixed = value.slice(lineStart, lineEnd).split('\n').map(makePrefix).join('\n')
  const newValue = value.slice(0, lineStart) + prefixed + value.slice(lineEnd)
  mdApplyEdit(newValue, lineStart, lineStart + prefixed.length)
}

function mdBulletList()   { mdPrefixLines(line => `- ${line}`) }
function mdNumberedList() { let n = 1; mdPrefixLines(line => `${n++}. ${line}`) }

// ── Dirty tracking (set to true on first user change) ─────────
const dirty = ref(false)

/** Provided by RecordView to force-show validation errors on save attempt */
const forceValidate = inject('forceValidate', ref(false))

// ── Validation ──────────────────────────────────────────────────
const validationError = computed(() => {
  const err = validateField(props.schema, props.modelValue)
  return err ? t(...err) : null
})

// no_dupl: async — handled separately via uniqueError ref below

// ── Async uniqueness check (no_dupl) ────────────────────────────
const uniqueError = ref(null)
let _uniqueTimer = null

watch(() => props.modelValue, (val) => {
  if (!(props.schema.check ?? []).includes('no_dupl')) return
  uniqueError.value = null
  clearTimeout(_uniqueTimer)
  if (val === null || val === undefined || val === '') return
  _uniqueTimer = setTimeout(async () => {
    try {
      const params = { field: props.schema.name, value: val }
      if (props.recordId) params.id = props.recordId
      const res = await api.get(`/api/record/${props.tb}/check-unique`, params)
      if (res.status === 'success' && !res.unique) {
        uniqueError.value = t('value_not_unique')
      }
    } catch { /* network error — silent, server will catch on save */ }
  }, 600)
})

/** Show the sync error when the user has interacted OR parent forces it */
const showError = computed(() =>
  (dirty.value || forceValidate.value) && !!validationError.value
)

/** True when either sync or async validation fails (used by RecordView for pre-save gate) */
const hasError = computed(() => !!validationError.value || !!uniqueError.value)

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

/** AntD Select/AutoComplete: unlike PrimeVue's built-in optionLabel filter,
 * filterOption must be supplied explicitly — it's given the raw option, not
 * a pre-resolved label. */
function filterOption(input, option) {
  return String(option?.label ?? '').toLowerCase().includes(String(input).toLowerCase())
}

// ── Async options (db / id_from_tb / vocabulary) ──────────────
// The server sends at most a page of the values of a column or of the records of
// a lookup table (`truncated` says the list is partial). When it is, the search
// box queries the server (all the values), and a note under the list says so.
const asyncOptions   = ref([])
const loadingOptions = ref(false)
const partialSource  = ref(false) // the unfiltered list was cut: search runs on the server
const listPartial    = ref(false) // the list on screen is a partial one
let   optionsLoaded  = false
let   baseOptions    = []         // the unfiltered list, to go back to after a search
let   baseTruncated  = false
let   searched       = false
let   searchTimer    = null
let   requestSeq     = 0
const knownOptions   = new Map()  // every option seen, by value (labels of what is selected)

// Keeps `VNodes` out of the template's way: renders the node AntD hands to dropdownRender.
const VNodes = (props) => props.vnodes
VNodes.props = ['vnodes']

function currentSelected() {
  return selectedValues(props.modelValue, props.schema.type)
}

async function fetchOptions(q = '') {
  const params = { fld: props.schema.name }
  if (q) params.q = q
  const sel = currentSelected()
  if (sel.length) params.include = sel.join(';')
  const res = await api.get(`/api/record/${props.tb}/field-options`, params)
  const list = normalizeOptions(res?.options)
  list.forEach(o => knownOptions.set(o.value, o))
  return { list, truncated: !!res?.truncated }
}

async function loadOptions() {
  if (optionsLoaded || props.schema.options_source?.type === 'static') return
  loadingOptions.value = true
  try {
    const { list, truncated } = await fetchOptions()
    baseOptions   = list
    baseTruncated = truncated
    partialSource.value = truncated
    listPartial.value   = truncated
    // A partial list must not hide the value already stored in the record.
    asyncOptions.value  = truncated ? withSelected(list, knownOptions, currentSelected()) : list
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

/** Search box: with a partial list, ask the server (debounced); otherwise AntD filters locally. */
function onSearch(text) {
  if (!partialSource.value) return
  clearTimeout(searchTimer)
  const q = String(text ?? '').trim()
  if (!q) { resetSearch(); return }
  searchTimer = setTimeout(async () => {
    const seq = ++requestSeq
    loadingOptions.value = true
    try {
      const { list, truncated } = await fetchOptions(q)
      if (seq !== requestSeq) return // a newer search superseded this one
      searched = true
      listPartial.value  = truncated
      asyncOptions.value = withSelected(list, knownOptions, currentSelected())
    } catch {
      /* keep the list on screen; the next keystroke retries */
    } finally {
      if (seq === requestSeq) loadingOptions.value = false
    }
  }, 300)
}

/** Back to the unfiltered list. */
function resetSearch() {
  clearTimeout(searchTimer)
  requestSeq++
  loadingOptions.value = false
  if (!searched) return
  searched = false
  listPartial.value  = baseTruncated
  asyncOptions.value = withSelected(baseOptions, knownOptions, currentSelected())
}

function onDropdownChange(open) {
  if (open) loadOptions()
  else resetSearch()
}

/**
 * Coerce modelValue to string for async-select comparisons.
 * asyncOptions values are always strings (see loadOptions normalization above),
 * but the stored value could arrive as a number (e.g. MySQL integer FK).
 */
const asyncModelValue = computed(() => {
  if (props.modelValue === null || props.modelValue === undefined) return props.modelValue
  return String(props.modelValue)
})

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
.options-partial {
  padding: 6px 12px;
  font-size: 0.75rem;
  color: var(--ant-color-text-secondary, #888);
  border-top: 1px solid var(--ant-color-border-secondary, #eee);
}

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

.bool-switch-wrap {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.35rem 0;
}
.bool-switch-label {
  font-size: 0.875rem;
}
.slider-value { min-width: 2.5rem; text-align: right; font-size: 0.875rem; }

.field-error {
  display: flex;
  align-items: center;
  gap: 0.3rem;
  font-size: 0.72rem;
  color: var(--p-red-500);
  margin-top: 0.1rem;
}

.md-editor-wrap {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
}
.md-toolbar {
  display: flex;
  align-items: center;
  gap: 0.1rem;
}
.md-toolbar-spacer { flex: 1; }
.md-tool {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 0.85rem;
  color: var(--p-text-muted-color);
  background: none;
  border: none;
  cursor: pointer;
  width: 1.6rem;
  height: 1.6rem;
  border-radius: var(--p-border-radius);
}
.md-tool:hover { color: var(--p-primary-color); background: var(--p-content-hover-background); }
.md-toggle {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  font-size: 0.78rem;
  color: var(--p-primary-color);
  background: none;
  border: none;
  cursor: pointer;
  padding: 0.15rem 0.4rem;
  border-radius: var(--p-border-radius);
}
.md-toggle:hover { opacity: 0.75; }
.md-preview {
  font-size: 0.875rem;
  line-height: 1.6;
  color: var(--p-text-color);
  min-height: 6rem;
  padding: 0.5rem 0.75rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: var(--p-border-radius);
}
.md-preview :deep(p)        { margin: 0 0 0.5rem; }
.md-preview :deep(strong)   { font-weight: 600; }
.md-preview :deep(em)       { font-style: italic; }
.md-preview :deep(ul),
.md-preview :deep(ol)       { padding-left: 1.5rem; margin: 0 0 0.5rem; }
.md-preview :deep(a)        { color: var(--p-primary-color); }
</style>
