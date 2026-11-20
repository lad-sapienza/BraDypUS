<template>
  <div class="cfg-panel">
    <div class="cfg-panel-header">
      <h2><FontSizeOutlined /> {{ t('char_maps_title') }}</h2>
      <AButton type="primary" :loading="saving" :disabled="loading || !!error || !dirty || hasErrors" @click="save">
        {{ t('save') }}
      </AButton>
    </div>

    <div v-if="loading" class="cfg-loading-center"><LoadingOutlined spin /></div>
    <AAlert v-else-if="error" type="error" :message="error" show-icon class="cm-body" />

    <div v-else class="cm-body">
      <p class="hint">{{ t('char_maps_hint') }}</p>

      <!-- Built-in: read only, can be copied -->
      <section class="cfg-section">
        <div class="cfg-section-header"><span>{{ t('char_maps_builtin') }}</span></div>
        <div v-for="m in systemList" :key="m.name" class="cm-card">
          <div class="cm-card-head">
            <strong class="cm-name">{{ m.name }}</strong>
            <small class="hint">{{ m.items.length }} {{ t('char_maps_chars') }}</small>
            <AButton size="small" type="text" class="cm-right" @click="duplicate(m)">
              <template #icon><CopyOutlined /></template>
              {{ t('char_maps_duplicate') }}
            </AButton>
          </div>
          <CharPalette :items="m.items" preview />
        </div>
      </section>

      <!-- Custom: name + text, with a live preview -->
      <section class="cfg-section">
        <div class="cfg-section-header">
          <span>{{ t('char_maps_custom') }}</span>
          <AButton size="small" @click="addEmpty">
            <template #icon><PlusOutlined /></template>
            {{ t('char_maps_new') }}
          </AButton>
        </div>
        <small class="hint">{{ t('char_maps_custom_hint') }}</small>

        <p v-if="!rows.length" class="hint">{{ t('char_maps_none') }}</p>

        <div v-for="r in rows" :key="r.id" class="cm-card">
          <div class="cm-card-head">
            <AInput
              v-model:value="r.name"
              size="small"
              class="cm-name-input"
              :placeholder="t('char_maps_name')"
              :status="rowErrors[r.id]?.name ? 'error' : undefined"
            />
            <small class="hint">{{ charMapItems(r.parsed).length }} {{ t('char_maps_chars') }}</small>
            <APopconfirm :title="t('char_maps_delete_confirm')" :ok-text="t('delete')" :cancel-text="t('cancel')" @confirm="remove(r.id)">
              <AButton size="small" type="text" danger class="cm-right">
                <template #icon><DeleteOutlined /></template>
              </AButton>
            </APopconfirm>
          </div>
          <small v-if="rowErrors[r.id]?.name" class="cm-err">{{ rowErrors[r.id].name }}</small>

          <ATextArea
            v-model:value="r.text"
            :auto-size="{ minRows: 2, maxRows: 8 }"
            :placeholder="t('char_maps_text_placeholder')"
            :status="rowErrors[r.id]?.text ? 'error' : undefined"
            class="cm-text"
          />
          <small v-if="rowErrors[r.id]?.text" class="cm-err">{{ rowErrors[r.id].text }}</small>

          <CharPalette v-if="charMapItems(r.parsed).length" :items="charMapItems(r.parsed)" preview />
        </div>
      </section>
    </div>
  </div>
</template>

<script setup>
import { CopyOutlined, DeleteOutlined, FontSizeOutlined, LoadingOutlined, PlusOutlined } from '@ant-design/icons-vue'
import { ref, computed, watch, onMounted } from 'vue'
import { Button as AButton, Alert as AAlert, Input as AInput, Popconfirm as APopconfirm } from 'ant-design-vue'
import { useToast } from '@/composables/useNotify'
import { useI18n }  from '@/i18n'
import { api }      from '@/api'
import CharPalette  from '@/components/record/CharPalette.vue'
import { CHAR_MAP_NAME, charMapItems, paletteToText, textToPalette } from '@/utils/charPaletteConfig'

const ATextArea = AInput.TextArea

const { t }  = useI18n()
const toast  = useToast()

const loading = ref(true)
const saving  = ref(false)
const error   = ref(null)
const system  = ref({})           // { name: [{label, value}] }
const rows    = ref([])           // [{ id, name, text, parsed }]
const saved   = ref('[]')         // JSON of the stored rows, to know when something changed
let   nextId  = 1

const systemList = computed(() =>
  Object.entries(system.value).map(([name, items]) => ({ name, items }))
)

// What the text of a row means: characters, or JSON (list / map). `parsed` feeds the preview.
function parseText(text) {
  const res = textToPalette(text)
  return res.error ? { error: res.error } : { value: res.value }
}

function makeRow(name, raw) {
  const text = paletteToText(raw)
  return { id: nextId++, name, text, parsed: parseText(text).value ?? null }
}

watch(rows, list => {
  for (const r of list) r.parsed = parseText(r.text).value ?? null
}, { deep: true })

// ── Validation (the server checks again) ─────────────────────────────────
const rowErrors = computed(() => {
  const out = {}
  const seen = new Map()
  for (const r of rows.value) {
    const e = {}
    const name = r.name.trim()
    if (!CHAR_MAP_NAME.test(name)) e.name = t('char_maps_err_name')
    else if (name in system.value) e.name = t('char_maps_err_builtin')
    else if (seen.has(name)) e.name = t('char_maps_err_taken')
    seen.set(name, true)

    const p = parseText(r.text)
    if (p.error) e.text = t('char_palette_invalid_json')
    else if (!charMapItems(p.value).length) e.text = t('char_maps_err_empty')
    else if (typeof p.value === 'string' && (CHAR_MAP_NAME.test(p.value) || p.value in system.value)) e.text = t('char_maps_err_reference')
    if (e.name || e.text) out[r.id] = e
  }
  return out
})
const hasErrors = computed(() => Object.keys(rowErrors.value).length > 0)

// The value to store for the current rows, in the order shown.
function toStore() {
  const out = {}
  for (const r of rows.value) out[r.name.trim()] = parseText(r.text).value
  return out
}
const dirty = computed(() => JSON.stringify(rows.value.map(r => [r.name.trim(), parseText(r.text).value ?? null])) !== saved.value)

function snapshot() {
  saved.value = JSON.stringify(rows.value.map(r => [r.name.trim(), parseText(r.text).value ?? null]))
}

// ── Actions ──────────────────────────────────────────────────────────────
function addEmpty() {
  rows.value.push({ id: nextId++, name: '', text: '', parsed: null })
}

function duplicate(m) {
  let name = `${m.name}-copy`
  for (let i = 2; rows.value.some(r => r.name === name); i++) name = `${m.name}-copy-${i}`
  // label = text for every built-in character: the plain string is the simplest form
  rows.value.push(makeRow(name, m.items.map(i => i.value).join(' ')))
}

function remove(id) {
  rows.value = rows.value.filter(r => r.id !== id)
}

async function load() {
  loading.value = true
  error.value   = null
  try {
    const res = await api.get('/api/config/char-maps')
    if (res.status === 'error') throw new Error(t(res.code) || res.code)
    system.value = res.system ?? {}
    rows.value   = Object.entries(res.custom ?? {}).map(([name, raw]) => makeRow(name, raw))
    snapshot()
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  try {
    const res = await api.put('/api/config/char-maps', { custom: toStore() })
    const ok  = res.status === 'success'
    toast.add({
      severity: ok ? 'success' : 'error',
      summary:  t(ok ? 'saved' : (res.code || 'error')),
      detail:   ok ? undefined : res.detail,
      life: ok ? 4000 : 7000,
    })
    if (ok) snapshot()
  } catch (e) {
    toast.add({ severity: 'error', summary: e.message, life: 5000 })
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<style scoped>
.cfg-panel {
  flex: 1;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.cfg-panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 1rem 1.25rem 0.75rem;
  border-bottom: 1px solid var(--p-content-border-color);
  flex-shrink: 0;
}
.cfg-panel-header h2 {
  margin: 0;
  font-size: 1rem;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
.cm-body {
  flex: 1;
  overflow-y: auto;
  padding: 1rem 1.25rem;
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}
.hint {
  display: block;
  margin: 0;
  font-size: 0.875rem;
  color: var(--p-text-muted-color);
}
.cfg-section {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}
.cfg-section-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-weight: 600;
  font-size: 0.85rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--p-text-muted-color);
  border-bottom: 1px solid var(--p-content-border-color);
  padding-bottom: 0.4rem;
}
.cm-card {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  padding: 0.75rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: 6px;
}
.cm-card-head {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}
.cm-name { font-family: monospace; }
.cm-name-input { max-width: 16rem; font-family: monospace; }
.cm-right { margin-left: auto; }
.cm-text { font-family: monospace; }
.cm-err {
  color: var(--p-red-500, #ff4d4f);
  font-size: 0.8rem;
}
.cfg-loading-center {
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 2rem;
  color: var(--p-text-muted-color);
  padding: 2rem;
}
</style>
