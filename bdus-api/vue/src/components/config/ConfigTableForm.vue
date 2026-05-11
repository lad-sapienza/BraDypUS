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
        <div class="cfg-form-row">

          <!-- Name -->
          <div class="cfg-form-field">
            <label>{{ t('name') }} <span class="cfg-req">*</span></label>
            <div class="cfg-input-action">
              <InputText v-model="form.name" size="small" :disabled="!!tb" style="flex:1" />
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
          </div>

          <!-- Label -->
          <div class="cfg-form-field">
            <label>{{ t('label') }} <span class="cfg-req">*</span></label>
            <InputText v-model="form.label" size="small" />
          </div>

          <!-- Is Plugin -->
          <div class="cfg-form-field">
            <label>{{ t('is_plugin') }}</label>
            <Select
              v-model="form.is_plugin"
              :options="[{ label: 'No', value: '' }, { label: 'Yes', value: '1' }]"
              option-label="label"
              option-value="value"
              size="small"
            />
          </div>
        </div>
      </section>

      <!-- ── Layout (only for non-plugin / existing tables) ──────── -->
      <section v-if="!isPlugin" class="cfg-section">
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
          </div>

          <div v-if="tb" class="cfg-form-field">
            <label>{{ t('rs_field') }}</label>
            <Select
              v-model="form.rs"
              :options="fieldOptions"
              option-label="label"
              option-value="value"
              :show-clear="true"
              size="small"
            />
          </div>
        </div>

        <!-- Preview fields (list of selects) -->
        <div class="cfg-form-field">
          <label>{{ t('preview_flds') }} <span class="cfg-req">*</span></label>
          <div class="cfg-list-selects">
            <div v-for="(val, idx) in form.preview" :key="idx" class="cfg-list-select-row">
              <Select
                v-model="form.preview[idx]"
                :options="fieldOptions"
                option-label="label"
                option-value="value"
                :show-clear="true"
                size="small"
                style="flex:1"
              />
              <Button
                icon="pi pi-minus"
                severity="danger"
                size="small"
                text
                @click="form.preview.splice(idx, 1)"
              />
            </div>
            <Button :label="t('add')" icon="pi pi-plus" size="small" outlined @click="form.preview.push('')" />
          </div>
        </div>
      </section>

      <!-- ── Plugins (existing non-plugin tables) ─────────────────── -->
      <section v-if="tb && !isPlugin" class="cfg-section">
        <div class="cfg-section-title">{{ t('plugins') }}</div>
        <div class="cfg-list-selects">
          <div v-for="(val, idx) in form.plugin" :key="idx" class="cfg-list-select-row">
            <Select
              v-model="form.plugin[idx]"
              :options="pluginOptions"
              option-label="label"
              option-value="value"
              :show-clear="true"
              size="small"
              style="flex:1"
            />
            <Button
              icon="pi pi-minus"
              severity="danger"
              size="small"
              text
              @click="form.plugin.splice(idx, 1)"
            />
          </div>
          <Button :label="t('add')" icon="pi pi-plus" size="small" outlined @click="form.plugin.push('')" />
        </div>
      </section>

      <!-- ── Backlinks (existing non-plugin tables) ────────────────── -->
      <section v-if="tb && !isPlugin" class="cfg-section">
        <div class="cfg-section-title">{{ t('backlinks') }}</div>
        <small class="cfg-help-text">{{ t('backlinks_help') }}</small>
        <div class="cfg-list-selects">
          <div v-for="(val, idx) in form.backlinks" :key="idx" class="cfg-list-select-row">
            <InputText v-model="form.backlinks[idx]" size="small" style="flex:1" placeholder="dest_table:plugin_table:plugin_field" />
            <Button icon="pi pi-minus" severity="danger" size="small" text @click="form.backlinks.splice(idx, 1)" />
          </div>
          <Button :label="t('add')" icon="pi pi-plus" size="small" outlined @click="form.backlinks.push('')" />
        </div>
      </section>

      <!-- ── Links (existing tables) ───────────────────────────────── -->
      <section v-if="tb" class="cfg-section">
        <div class="cfg-section-title">
          {{ t('links') }}
          <Button :label="t('add_link')" icon="pi pi-plus" size="small" outlined @click="addLink" />
        </div>

        <div v-for="(link, li) in form.link" :key="li" class="cfg-link-card">
          <div class="cfg-link-header">
            <Select
              v-model="link.other_tb"
              :options="tableOptions"
              option-label="label"
              option-value="value"
              :placeholder="t('select_table')"
              size="small"
              style="flex:1"
              @change="onOtherTbChange(link)"
            />
            <Button icon="pi pi-trash" severity="danger" size="small" text @click="form.link.splice(li, 1)" />
          </div>

          <!-- Field pairs -->
          <div class="cfg-link-fields">
            <div v-for="(pair, fi) in link.fld" :key="fi" class="cfg-link-pair">
              <Select
                v-model="pair.my"
                :options="fieldOptions"
                option-label="label"
                option-value="value"
                :placeholder="t('this_table_field')"
                :show-clear="true"
                size="small"
                style="flex:1"
              />
              <i class="pi pi-arrows-h cfg-link-arrow" />
              <Select
                v-model="pair.other"
                :options="otherFieldOptions(link)"
                option-label="label"
                option-value="value"
                :placeholder="t('other_table_field')"
                :show-clear="true"
                size="small"
                style="flex:1"
              />
              <Button icon="pi pi-minus" severity="danger" size="small" text @click="link.fld.splice(fi, 1)" />
            </div>
            <Button :label="t('add')" icon="pi pi-plus" size="small" text @click="link.fld.push({ my: '', other: '' })" />
          </div>
        </div>
      </section>

      <!-- ── Danger zone ────────────────────────────────────────────── -->
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

    <!-- ── Rename dialog ─────────────────────────────────────────────── -->
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
import Button    from 'primevue/button'
import Dialog    from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Select    from 'primevue/select'
import Message   from 'primevue/message'
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

const table            = ref(null)
const form             = ref(null)
const fieldLabels      = ref({})
const availablePlugins = ref({})
const availableTables  = ref({})

// Rename dialog
const renameVisible = ref(false)
const newName       = ref('')

// Cache for fetched other-table field lists
const otherTableFields = ref({})

// ── Computed helpers ───────────────────────────────────────────────────────
const isPlugin = computed(() => form.value?.is_plugin === '1')

const fieldOptions = computed(() =>
  Object.entries(fieldLabels.value).map(([k, v]) => ({ value: k, label: v }))
)

const pluginOptions = computed(() =>
  Object.entries(availablePlugins.value).map(([k, v]) => ({ value: k, label: v }))
)

const tableOptions = computed(() =>
  Object.entries(availableTables.value).map(([k, v]) => ({ value: k, label: v }))
)

function otherFieldOptions(link) {
  const tb = link.other_tb
  if (!tb) return []
  // Prefer freshly fetched data; fall back to initial other_fields
  const map = otherTableFields.value[tb] ?? link._other_fields_initial ?? {}
  return Object.entries(map).map(([k, v]) => ({ value: k, label: v }))
}

// ── Data loading ───────────────────────────────────────────────────────────
async function load() {
  loading.value   = true
  loadError.value = null
  try {
    const res = await api.get('config_ctrl', 'getTableConfig', props.tb ? { tb: props.tb } : {})
    if (res.status === 'error') throw new Error(t(res.code))

    table.value            = res.table
    fieldLabels.value      = res.field_labels      ?? {}
    availablePlugins.value = res.available_plugins ?? {}
    availableTables.value  = res.available_tables  ?? {}

    // Deep clone table data into form, normalising arrays
    const td = res.table ?? {}

    // Stash initial other_fields per link for the dropdowns
    const links = (td.link ?? []).map(l => ({
      other_tb: l.other_tb ?? '',
      fld: (l.fld ?? []).map(f => ({ my: f.my ?? '', other: f.other ?? '' })),
      _other_fields_initial: l.other_fields ?? {}
    }))
    if (links.length === 0) links.push(emptyLink())

    form.value = {
      name:        td.name        ?? '',
      label:       td.label       ?? '',
      is_plugin:   td.is_plugin   ?? '',
      order:       td.order       ?? '',
      id_field:    td.id_field    ?? '',
      rs:          td.rs          ?? '',
      preview:     Array.isArray(td.preview)   ? [...td.preview]   : [''],
      plugin:      Array.isArray(td.plugin)    ? [...td.plugin]    : [''],
      backlinks:   Array.isArray(td.backlinks) ? [...td.backlinks] : [],
      link:        links,
    }

    newName.value = props.tb ?? ''
  } catch (e) {
    loadError.value = e.message
  } finally {
    loading.value = false
  }
}

watch(() => props.tb, () => load())

// ── Links helpers ──────────────────────────────────────────────────────────
function emptyLink() {
  return { other_tb: '', fld: [{ my: '', other: '' }], _other_fields_initial: {} }
}

function addLink() {
  form.value.link.push(emptyLink())
}

async function onOtherTbChange(link) {
  const tb = link.other_tb
  if (!tb || otherTableFields.value[tb]) return
  try {
    const res = await api.get('config_ctrl', 'getFldList', { tb })
    if (res.status === 'success' || res.code === 'ok') {
      otherTableFields.value = { ...otherTableFields.value, [tb]: res.fields ?? {} }
    }
  } catch { /* silently ignore — user can still type */ }
}

// ── Save ───────────────────────────────────────────────────────────────────
async function save() {
  saving.value = true
  try {
    const endpoint = props.tb ? 'save_tb_data' : 'add_new_tb'
    const payload  = buildPayload()
    const res      = await api.post('config_ctrl', endpoint, payload,
                       props.tb ? { tb: props.tb } : {})
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
  // Remove the internal _other_fields_initial before sending
  const links = f.link
    .filter(l => l.other_tb)
    .map(({ _other_fields_initial, ...rest }) => rest)

  return {
    name:       f.name,
    label:      f.label,
    is_plugin:  f.is_plugin,
    order:      f.order,
    id_field:   f.id_field,
    rs:         f.rs,
    preview:    f.preview.filter(v => v),
    plugin:     f.plugin.filter(v => v),
    backlinks:  f.backlinks.filter(v => v),
    link:       links,
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
    const res = await api.get('config_ctrl', 'delete_tb', { tb: props.tb })
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
    const res = await api.get('config_ctrl', 'rename_tb', {
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

/* Dynamic list of selects */
.cfg-list-selects {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}
.cfg-list-select-row {
  display: flex;
  align-items: center;
  gap: 0.4rem;
}

/* Links */
.cfg-link-card {
  border: 1px solid var(--p-content-border-color);
  border-radius: 6px;
  padding: 0.75rem;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  background: var(--bdus-bg);
}
.cfg-link-header {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
.cfg-link-fields {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  padding-left: 0.5rem;
  border-left: 2px solid var(--p-content-border-color);
}
.cfg-link-pair {
  display: flex;
  align-items: center;
  gap: 0.4rem;
}
.cfg-link-arrow {
  font-size: 0.8rem;
  color: var(--p-text-muted-color);
  flex-shrink: 0;
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

/* Help text */
.cfg-help-text {
  font-size: 0.75rem;
  color: var(--p-text-muted-color);
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
