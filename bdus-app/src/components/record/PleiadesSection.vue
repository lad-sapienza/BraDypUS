<template>
  <fieldset class="record-section">
    <legend>{{ t('pleiades_label') }}</legend>

    <!-- ── Read mode ─────────────────────────────────────────── -->
    <template v-if="!editMode">
      <div v-if="pleiadesId" class="pleiades-read">
        <span class="pleiades-title-row">
          <a
            :href="`https://pleiades.stoa.org/places/${pleiadesId}`"
            target="_blank"
            rel="noopener"
            class="pleiades-title-link"
          >{{ label || pleiadesId }}</a>
          <span class="pleiades-id">#{{ pleiadesId }}</span>
        </span>
        <span v-if="altLabel" class="pleiades-alt">{{ altLabel }}</span>
      </div>
      <div v-else class="pleiades-empty">—</div>
    </template>

    <!-- ── Edit mode ──────────────────────────────────────────── -->
    <template v-else>
      <div class="pleiades-field">
        <AAutoComplete
          v-model:value="query"
          :options="options"
          :placeholder="t('pleiades_search_placeholder')"
          class="w-full"
          @search="onSearch"
          @select="onSelect"
        >
          <template #option="opt">
            <div class="pleiades-option">
              <span class="pleiades-option-title">
                <strong>{{ opt.label }}</strong>
                <span class="pleiades-id">#{{ opt.id }}</span>
              </span>
              <div v-if="opt.snippet" class="pleiades-option-snippet">{{ opt.snippet }}</div>
            </div>
          </template>
        </AAutoComplete>

        <div v-if="pleiadesId" class="pleiades-selected">
          <a
            :href="`https://pleiades.stoa.org/places/${pleiadesId}`"
            target="_blank"
            rel="noopener"
          >{{ label }}</a>
          <span class="pleiades-id">#{{ pleiadesId }}</span>
          <AButton type="text" danger size="small" @click="onRemove">
            {{ t('pleiades_remove') }}
          </AButton>
        </div>
      </div>

      <div class="pleiades-field">
        <label class="field-label">{{ t('pleiades_alt_label') }}</label>
        <AInput
          v-model:value="localAltLabel"
          class="w-full"
          @change="onAltLabelChange"
        />
      </div>
    </template>
  </fieldset>
</template>

<script setup>
import { ref, watch } from 'vue'
import { AutoComplete as AAutoComplete, Input as AInput, Button as AButton } from 'ant-design-vue'
import { useI18n } from '@/i18n'
import { api } from '@/api'
import { useToast } from '@/composables/useNotify'

const { t } = useI18n()
const toast = useToast()

const props = defineProps({
  pleiadesId: { type: [Number, String], default: null },
  label:      { type: String, default: null },
  altLabel:   { type: String, default: null },
  editMode:   { type: Boolean, default: false },
})

const emit = defineEmits(['update:pleiades'])

// ── Local state ───────────────────────────────────────────────────────────────

const query         = ref('')
const options       = ref([])
const localAltLabel = ref(props.altLabel ?? '')

watch(() => props.altLabel, v => { localAltLabel.value = v ?? '' })

// ── Search (debounced — unlike the local-DB AutoCompletes elsewhere in this
// app, this one hits the external Pleiades API, so it needs its own pacing) ──

let searchDebounce = null

function onSearch(text) {
  clearTimeout(searchDebounce)
  if (!text || text.trim().length < 3) {
    options.value = []
    return
  }
  searchDebounce = setTimeout(async () => {
    try {
      const res = await api.get('/api/pleiades/search', { q: text.trim() })
      // AntD's AutoComplete works on a flat text value: carry `id`/`snippet`
      // as extra keys on each option and recover `id` in onSelect (same
      // idiom as ManualLinksSection's record-candidate AutoComplete).
      if (res.status === 'success') {
        options.value = res.results.map(r => ({ value: r.title, label: r.title, id: r.id, snippet: r.snippet }))
      } else {
        options.value = []
        toast.add({ severity: 'error', summary: t('generic_error'), detail: t(res.code ?? 'pleiades_api_error'), life: 5000 })
      }
    } catch (e) {
      options.value = []
      toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 5000 })
    }
  }, 400)
}

async function onSelect(value, option) {
  try {
    const res = await api.get(`/api/pleiades/place/${option.id}`)
    if (res.status !== 'success') {
      toast.add({ severity: 'error', summary: t('generic_error'), detail: t(res.code ?? 'pleiades_api_error'), life: 5000 })
      return
    }
    query.value = res.place.title
    emitPleiades(res.place.id, res.place.title, localAltLabel.value, res.place.reprPoint, false)
  } catch (e) {
    // Selection failed — leave the previously saved fields untouched.
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 5000 })
  }
}

function onRemove() {
  query.value   = ''
  options.value = []
  emitPleiades(null, null, localAltLabel.value, null, true)
}

function onAltLabelChange() {
  emitPleiades(props.pleiadesId, props.label, localAltLabel.value, null, false)
}

// `_reprPoint`/`_removed` are transient — never persisted as record columns.
// The parent (RecordView) strips them out before merging the rest into
// editData.core, and uses them only to drive the post-save Geoface call.
function emitPleiades(pleiadesId, label, altLabel, reprPoint, removed) {
  emit('update:pleiades', {
    pleiades_id:        pleiadesId,
    pleiades_label:      label,
    pleiades_alt_label: altLabel,
    _reprPoint:         reprPoint,
    _removed:           removed,
  })
}
</script>

<style scoped>
.pleiades-field    { display: flex; flex-direction: column; gap: .25rem; margin-bottom: .75rem; }
.pleiades-field:last-child { margin-bottom: 0; }
.pleiades-option-title { display: flex; align-items: baseline; gap: .4rem; }
.pleiades-option-snippet { font-size: .78rem; color: var(--p-text-muted-color); white-space: normal; }
.pleiades-selected  { display: flex; align-items: center; gap: .5rem; margin-top: .35rem; }
.pleiades-read      { display: flex; flex-direction: column; gap: .25rem; }
.pleiades-title-row { display: flex; align-items: baseline; gap: .4rem; }
.pleiades-title-link { font-weight: 500; }
.pleiades-id        { font-size: .78rem; color: var(--p-text-muted-color); font-variant-numeric: tabular-nums; }
.pleiades-alt       { font-size: .85rem; color: var(--p-text-muted-color); }
.pleiades-empty     { color: var(--p-text-muted-color); }
</style>
