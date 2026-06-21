<template>
  <div class="cfg-panel">
    <div class="cfg-panel-header">
      <h2>
        <i class="pi pi-table" />
        {{ tb ? table?.label || tb : t('add_table') }}
      </h2>
      <div class="cfg-panel-header-actions">
        <Button
          v-if="tb"
          :label="t('fields')"
          icon="pi pi-list"
          size="small"
          outlined
          @click="$emit('open-fields', tb)"
        />
        <Button :label="t('save')" icon="pi pi-save" size="small" :loading="saving" @click="save" />
      </div>
    </div>

    <div v-if="loading" class="cfg-loading-center">
      <i class="pi pi-spin pi-spinner" />
    </div>
    <Message v-if="loadError" severity="error" :closable="false">{{ loadError }}</Message>

    <div v-if="!loading && form" class="cfg-form-body">

      <!-- ── General ──────────────────────────────────────────────── -->
      <section class="cfg-section">
        <div class="cfg-section-title">{{ t('general') }}</div>

        <!-- Name: full-width row because of the inline rename button (#19 fix) -->
        <div class="cfg-form-field">
          <label>{{ t('name') }} <span class="cfg-req">*</span></label>
          <div class="cfg-input-action">
            <InputText v-model="form.name" size="small" :disabled="!!tb" style="flex:1; min-width:0" />
            <Button
              v-if="tb"
              icon="pi pi-pencil"
              severity="secondary"
              size="small"
              outlined
              :title="t('rename_table')"
              @click="renameVisible = true"
            />
          </div>
          <small class="cfg-hint">{{ t('help_table_name') }}</small>
        </div>

        <div class="cfg-form-row">
          <!-- Label -->
          <div class="cfg-form-field">
            <label>{{ t('label') }} <span class="cfg-req">*</span></label>
            <InputText v-model="form.label" size="small" />
            <small class="cfg-hint">{{ t('help_table_label') }}</small>
          </div>

          <!-- Is Plugin (#20: ToggleSwitch) -->
          <div class="cfg-form-field">
            <label>{{ t('is_plugin') }}</label>
            <ToggleSwitch v-model="isPluginBool" />
            <small class="cfg-hint">{{ t('help_table_is_plugin') }}</small>
          </div>
        </div>
      </section>

      <!-- ── Layout (only for existing non-plugin tables) ──────────
           Hidden when creating a new table: order/id_field/preview
           default to 'id' on the backend and are configured later. -->
      <section v-if="tb && !isPlugin" class="cfg-section">
        <div class="cfg-section-title">{{ t('layout') }}</div>
        <div class="cfg-form-row">

          <div class="cfg-form-field">
            <label>{{ t('order_field') }} <span class="cfg-req">*</span></label>
            <Select
              v-model="form.order"
              :options="fieldOptions"
              option-label="label"
              option-value="value"
              :show-clear="true"
              size="small"
            />
            <small class="cfg-hint">{{ t('help_table_order_field') }}</small>
          </div>

          <div class="cfg-form-field">
            <label>{{ t('id_field') }} <span class="cfg-req">*</span></label>
            <Select
              v-model="form.id_field"
              :options="fieldOptions"
              option-label="label"
              option-value="value"
              :show-clear="true"
              size="small"
            />
            <small class="cfg-hint">{{ t('help_table_id_field') }}</small>
          </div>

        </div>

        <!-- Preview fields (#21: MultiSelect) -->
        <div class="cfg-form-field">
          <label>{{ t('preview_flds') }} <span class="cfg-req">*</span></label>
          <MultiSelect
            v-model="form.preview"
            :options="fieldOptions"
            option-label="label"
            option-value="value"
            display="chip"
            size="small"
            :placeholder="t('select_fields')"
          />
          <small class="cfg-hint">{{ t('help_table_preview_flds') }}</small>
        </div>
      </section>

      <!-- ── Plugins (#22: list with switches) ───────────────────── -->
      <section v-if="tb && !isPlugin" class="cfg-section">
        <div class="cfg-section-title">{{ t('plugins') }}</div>
        <small class="cfg-hint cfg-hint-section">{{ t('help_table_plugins') }}</small>
        <div v-if="Object.keys(availablePlugins).length" class="cfg-form-row">
          <div v-for="(label, name) in availablePlugins" :key="name" class="cfg-form-field">
            <label>{{ label }}</label>
            <ToggleSwitch
              :modelValue="form.plugin.includes(name)"
              @update:modelValue="togglePlugin(name, $event)"
            />
          </div>
        </div>
        <p v-else class="cfg-hint">{{ t('no_plugins_available') }}</p>
      </section>

      <!-- ── System plugins ──────────────────────────────────────── -->
      <section v-if="tb && !isPlugin" class="cfg-section">
        <div class="cfg-section-title">{{ t('system_plugins') }}</div>
        <small class="cfg-hint cfg-hint-section">{{ t('help_table_system_plugins') }}</small>
        <div class="cfg-form-row">
          <div class="cfg-form-field">
            <label>{{ t('rs_plugin') }}</label>
            <ToggleSwitch v-model="form.rs" />
          </div>
          <div class="cfg-form-field">
            <label>{{ t('geodata_plugin') }}</label>
            <ToggleSwitch v-model="form.geodata" />
          </div>
          <div class="cfg-form-field">
            <label>{{ t('zotero_plugin') }}</label>
            <ToggleSwitch v-model="form.zotero" />
          </div>
          <div class="cfg-form-field">
            <label>{{ t('fuzzy_date_plugin') }}</label>
            <div class="cfg-input-action">
              <ToggleSwitch
                :modelValue="fuzzyDateActive"
                :disabled="fuzzyDateBusy"
                @update:modelValue="toggleFuzzyDate"
              />
              <i v-if="fuzzyDateBusy" class="pi pi-spin pi-spinner" style="font-size:.9rem" />
            </div>
            <small v-if="fuzzyDateActive" class="cfg-hint">
              {{ t('fuzzy_date_activated') }} — chrono_from, chrono_to, chrono_label, chrono_certainty, chrono_period
            </small>
          </div>
          <div class="cfg-form-field">
            <label>{{ t('osteo_plugin') }}</label>
            <div class="cfg-input-action">
              <ToggleSwitch
                :modelValue="osteoActive"
                :disabled="osteoBusy"
                @update:modelValue="toggleOsteology"
              />
              <i v-if="osteoBusy" class="pi pi-spin pi-spinner" style="font-size:.9rem" />
            </div>
            <small v-if="osteoActive" class="cfg-hint">
              {{ t('osteo_activated') }} — osteo_data
            </small>
          </div>
        </div>
      </section>

      <!-- ── Indexes ──────────────────────────────────────────────── -->
      <section v-if="tb" class="cfg-section">
        <ConfigIndexes :tb="tb" />
      </section>

      <!-- ── Danger zone ──────────────────────────────────────────── -->
      <section v-if="tb" class="cfg-section cfg-danger-section">
        <div class="cfg-section-title cfg-danger-title">{{ t('danger_zone') }}</div>
        <p class="cfg-danger-warn">{{ t('warning_delete_table') }}</p>
        <Button
          :label="t('delete_table')"
          icon="pi pi-trash"
          severity="danger"
          outlined
          size="small"
          :loading="deleting"
          @click="deleteTable"
        />
      </section>

    </div>

    <!-- ── Rename dialog ──────────────────────────────────────────── -->
    <Dialog v-model:visible="renameVisible" modal :header="t('rename_table')" style="width:350px">
      <div class="cfg-rename-body">
        <label>{{ t('new_name') }}</label>
        <InputText v-model="newName" size="small" autofocus @keyup.enter="confirmRename" />
      </div>
      <template #footer>
        <Button :label="t('cancel')" severity="secondary" size="small" text @click="renameVisible = false" />
        <Button :label="t('rename')" icon="pi pi-check" size="small" :loading="renaming" @click="confirmRename" />
      </template>
    </Dialog>

  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import Button       from 'primevue/button'
import Dialog       from 'primevue/dialog'
import InputText    from 'primevue/inputtext'
import Select       from 'primevue/select'
import MultiSelect  from 'primevue/multiselect'
import Message      from 'primevue/message'
import ToggleSwitch from 'primevue/toggleswitch'
import ConfigIndexes from '@/components/config/ConfigIndexes.vue'
import { useToast }   from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import { useI18n }    from '@/i18n'
import { api }        from '@/api'

const props = defineProps({
  tb: { type: String, default: null }   // null → "add new" mode
})
const emit = defineEmits(['saved', 'deleted', 'renamed', 'open-fields'])

const { t }   = useI18n()
const toast   = useToast()
const confirm = useConfirm()

// ── State ─────────────────────────────────────────────────────────────────
const loading   = ref(false)
const saving    = ref(false)
const deleting  = ref(false)
const renaming  = ref(false)
const loadError = ref(null)

const fuzzyDateActive = ref(false)
const fuzzyDateBusy   = ref(false)
const osteoActive     = ref(false)
const osteoBusy       = ref(false)

const table            = ref(null)
const form             = ref(null)
const fieldLabels      = ref({})
const availablePlugins = ref({})

// Rename dialog
const renameVisible = ref(false)
const newName       = ref('')

// ── Computed helpers ───────────────────────────────────────────────────────
const isPlugin = computed(() => form.value?.is_plugin === '1')

// is_plugin as boolean for ToggleSwitch (#20)
const isPluginBool = computed({
  get: () => form.value?.is_plugin === '1',
  set: (v) => { if (form.value) form.value.is_plugin = v ? '1' : '' }
})

const fieldOptions = computed(() =>
  Object.entries(fieldLabels.value).map(([k, v]) => ({ value: k, label: v }))
)

const pluginOptions = computed(() =>
  Object.entries(availablePlugins.value).map(([k, v]) => ({ value: k, label: v }))
)

// ── Plugin toggle helpers (#22) ────────────────────────────────────────────
function togglePlugin(name, active) {
  if (!form.value) return
  if (active) {
    if (!form.value.plugin.includes(name)) form.value.plugin.push(name)
  } else {
    form.value.plugin = form.value.plugin.filter(p => p !== name)
  }
}

// ── Data loading ───────────────────────────────────────────────────────────
async function load() {
  loading.value   = true
  loadError.value = null
  try {
    const res = await api.get(props.tb ? `/api/config/table/${props.tb}` : '/api/config/tables')
    if (res.status === 'error') throw new Error(t(res.code))

    table.value            = res.table
    fieldLabels.value      = res.field_labels      ?? {}
    availablePlugins.value = res.available_plugins ?? {}

    const td = res.table ?? {}

    form.value = {
      name:        td.name        ?? '',
      label:       td.label       ?? '',
      is_plugin:   td.is_plugin   ?? '',
      order:       td.order       ?? '',
      id_field:    td.id_field    ?? '',
      rs:          !!td.rs,
      geodata:     !!td.geodata,
      zotero:      !!td.zotero,
      preview:     Array.isArray(td.preview)   ? [...td.preview]   : [],
      plugin:      Array.isArray(td.plugin)    ? [...td.plugin]    : [],
      backlinks:   Array.isArray(td.backlinks) ? [...td.backlinks] : [],
    }

    fuzzyDateActive.value = !!td.fuzzy_date
    osteoActive.value     = !!td.osteology
    newName.value = props.tb ?? ''
  } catch (e) {
    loadError.value = e.message
  } finally {
    loading.value = false
  }
}

watch(() => props.tb, () => load())

// ── Save ───────────────────────────────────────────────────────────────────
async function save() {
  saving.value = true
  try {
    const payload = buildPayload()
    const res = props.tb
      ? await api.put(`/api/config/table/${props.tb}`, payload)
      : await api.post('/api/config/tables', payload)
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

function buildPayload() {
  const f = form.value
  return {
    name:       f.name,
    label:      f.label,
    is_plugin:  f.is_plugin,
    order:      f.order,
    id_field:   f.id_field,
    rs:         f.rs ? 1 : 0,
    geodata:    f.geodata ? 1 : 0,
    zotero:     f.zotero  ? 1 : 0,
    preview:    f.preview.filter(v => v),
    plugin:     f.plugin.filter(v => v),
    backlinks:  f.backlinks.filter(v => v),
  }
}

// ── Fuzzy date toggle ─────────────────────────────────────────────────────
async function toggleFuzzyDate(newVal) {
  if (!props.tb || fuzzyDateBusy.value) return
  fuzzyDateBusy.value = true
  try {
    const res = newVal
      ? await api.post(`/api/config/table/${props.tb}/fuzzy-date`, {})
      : await api.delete(`/api/config/table/${props.tb}/fuzzy-date`, {})
    if (res.status === 'success') {
      fuzzyDateActive.value = newVal
      toast.add({ severity: 'success', summary: t(res.code), life: 3000 })
    } else {
      toast.add({ severity: 'error', summary: api.responseMessage(res, t), life: 5000 })
    }
  } catch (e) {
    toast.add({ severity: 'error', summary: e.message, life: 4000 })
  } finally {
    fuzzyDateBusy.value = false
  }
}

// ── Osteology toggle ──────────────────────────────────────────────────────
async function toggleOsteology(newVal) {
  if (!props.tb || osteoBusy.value) return
  osteoBusy.value = true
  try {
    const res = newVal
      ? await api.post(`/api/config/table/${props.tb}/osteology`, {})
      : await api.delete(`/api/config/table/${props.tb}/osteology`, {})
    if (res.status === 'success') {
      osteoActive.value = newVal
      toast.add({ severity: 'success', summary: t(res.code), life: 3000 })
    } else {
      toast.add({ severity: 'error', summary: api.responseMessage(res, t), life: 5000 })
    }
  } catch (e) {
    toast.add({ severity: 'error', summary: e.message, life: 4000 })
  } finally {
    osteoBusy.value = false
  }
}

// ── Delete ─────────────────────────────────────────────────────────────────
function deleteTable() {
  confirm.require({
    message:  t('warning_delete_table'),
    header:   t('delete_table'),
    icon:     'pi pi-exclamation-triangle',
    severity: 'danger',
    accept:   doDelete,
  })
}

async function doDelete() {
  deleting.value = true
  try {
    const res = await api.delete(`/api/config/table/${props.tb}`)
    toast.add({
      severity: res.status === 'success' ? 'success' : 'error',
      summary:  t('saved'),
      detail:   api.responseMessage(res, t),
      life: 4000
    })
    if (res.status === 'success') {
      emit('deleted')
    }
  } catch (e) {
    toast.add({ severity: 'error', summary: e.message, life: 4000 })
  } finally {
    deleting.value = false
  }
}

// ── Rename ─────────────────────────────────────────────────────────────────
async function confirmRename() {
  if (!newName.value || newName.value === props.tb) {
    renameVisible.value = false
    return
  }
  renaming.value = true
  try {
    const res = await api.patch(`/api/config/table/${props.tb}`, {
      old_name: props.tb,
      new_name: newName.value
    })
    toast.add({
      severity: res.status === 'success' ? 'success' : 'error',
      summary:  t('saved'),
      detail:   api.responseMessage(res, t),
      life: 4000
    })
    if (res.status === 'success') {
      renameVisible.value = false
      emit('renamed', newName.value)
    }
  } catch (e) {
    toast.add({ severity: 'error', summary: e.message, life: 4000 })
  } finally {
    renaming.value = false
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
.cfg-panel-header-actions {
  display: flex;
  gap: 0.5rem;
}
.cfg-loading-center {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 2rem;
  color: var(--p-text-muted-color);
}
.cfg-form-body {
  flex: 1;
  overflow-y: auto;
  padding: 1rem 1.25rem;
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}
.cfg-section {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}
.cfg-section-title {
  font-weight: 700;
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.07em;
  color: var(--p-text-muted-color);
  border-bottom: 1px solid var(--p-content-border-color);
  padding-bottom: 0.35rem;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.cfg-form-row {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 0.75rem;
}
.cfg-form-field {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
  min-width: 0;
}
.cfg-form-field label {
  font-size: 0.8rem;
  font-weight: 500;
  color: var(--p-text-muted-color);
  display: flex;
  align-items: center;
  gap: 0.25rem;
}
.cfg-req { color: var(--p-red-400); font-size: 0.7rem; }

.cfg-input-action {
  display: flex;
  gap: 0.4rem;
  align-items: center;
}

/* Help texts */
.cfg-hint {
  font-size: 0.72rem;
  color: var(--p-text-muted-color);
  line-height: 1.3;
}
.cfg-hint-section {
  margin-top: -0.25rem;
}

/* Danger zone */
.cfg-danger-section {
  margin-top: 1rem;
  padding-top: 1rem;
  border-top: 2px solid var(--p-red-200);
}
.cfg-danger-title { color: var(--p-red-400) !important; }
.cfg-danger-warn {
  font-size: 0.85rem;
  color: var(--p-red-600);
  margin: 0;
}

/* Rename dialog */
.cfg-rename-body {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}
.cfg-rename-body label {
  font-size: 0.82rem;
  color: var(--p-text-muted-color);
}
</style>
