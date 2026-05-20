<template>
  <AppLayout>
    <div class="tmpl-shell">

      <!-- ── Left sidebar ─────────────────────────────────────────────── -->
      <div class="tmpl-sidebar">
        <div class="tmpl-sidebar-title">{{ t('design_templates') }}</div>

        <!-- Table list -->
        <div
          v-for="tbl in tables"
          :key="tbl.tb"
          class="tmpl-table-item"
          :class="{ active: selectedTb === tbl.tb }"
          @click="selectTable(tbl)"
        >
          <i class="pi pi-table" />
          <span class="tmpl-table-label">{{ tbl.label }}</span>
        </div>

        <!-- Template list for selected table -->
        <template v-if="selectedTb">
          <div class="tmpl-list-header">
            <span>{{ t('templates') }}</span>
            <Button
              icon="pi pi-plus"
              size="small"
              text
              :title="t('add_template')"
              @click="showNewDialog = true"
            />
          </div>

          <div v-if="!templateNames.length" class="tmpl-empty-list">
            {{ t('no_templates') }}
          </div>

          <div
            v-for="name in templateNames"
            :key="name"
            class="tmpl-name-item"
            :class="{ active: selectedName === name }"
            @click="openTemplate(name)"
          >
            <i class="pi pi-file" />
            {{ name }}
          </div>
        </template>
      </div>

      <!-- ── Right editor ──────────────────────────────────────────────── -->
      <div class="tmpl-editor">

        <!-- Empty state -->
        <div v-if="!selectedTb" class="tmpl-editor-empty">
          <i class="pi pi-file-edit tmpl-empty-icon" />
          <p>{{ t('select_table_for_template') }}</p>
        </div>
        <div v-else-if="!selectedName" class="tmpl-editor-empty">
          <i class="pi pi-file-edit tmpl-empty-icon" />
          <p>{{ t('select_or_create_template') }}</p>
        </div>

        <!-- Editor -->
        <template v-else-if="form">

          <!-- Editor header -->
          <div class="tmpl-editor-header">
            <div class="tmpl-editor-title">
              <span class="tmpl-tb-label">{{ selectedTbLabel }}</span>
              <i class="pi pi-chevron-right tmpl-sep" />
              <span v-if="!renaming" class="tmpl-name-text">{{ selectedName }}</span>
              <InputText
                v-else
                v-model="newName"
                size="small"
                class="tmpl-rename-input"
                @keyup.enter="confirmRename"
                @keyup.escape="renaming = false"
              />
            </div>
            <div class="tmpl-editor-actions">
              <Button
                v-if="!renaming"
                :label="t('rename')"
                icon="pi pi-pencil"
                size="small"
                severity="secondary"
                outlined
                @click="startRename"
              />
              <Button
                v-if="renaming"
                :label="t('confirm')"
                icon="pi pi-check"
                size="small"
                severity="primary"
                :loading="renameLoading"
                @click="confirmRename"
              />
              <Button
                v-if="renaming"
                :label="t('cancel')"
                size="small"
                severity="secondary"
                text
                @click="renaming = false"
              />
              <Button
                v-if="!renaming"
                :label="t('save')"
                icon="pi pi-save"
                size="small"
                :loading="saving"
                @click="saveTemplate"
              />
              <Button
                v-if="!renaming"
                icon="pi pi-trash"
                size="small"
                severity="danger"
                outlined
                :title="t('delete_template')"
                :loading="deleting"
                @click="confirmDelete"
              />
            </div>
          </div>

          <!-- Sections -->
          <div class="tmpl-sections">

            <div
              v-for="(section, si) in form.sections"
              :key="si"
              class="tmpl-section-card"
            >
              <!-- Section header row -->
              <div class="tmpl-section-header">
                <div class="tmpl-section-meta">

                  <!-- Label -->
                  <div class="tmpl-field-group">
                    <label>{{ t('section_label') }}</label>
                    <InputText v-model="section.label" size="small" style="width:180px" />
                  </div>

                  <!-- Plugin toggle -->
                  <div class="tmpl-field-group">
                    <label>{{ t('plugin') }}</label>
                    <Select
                      v-model="section.plugin"
                      :options="pluginOptions"
                      optionLabel="label"
                      optionValue="tb"
                      :placeholder="t('core_section')"
                      :showClear="true"
                      size="small"
                      style="width:160px"
                    />
                  </div>

                  <!-- Collapsible -->
                  <div class="tmpl-field-group tmpl-checkbox-group">
                    <Checkbox v-model="section.collapsible" :binary="true" inputId="'col-' + si" />
                    <label :for="'col-' + si">{{ t('collapsible') }}</label>
                  </div>

                  <!-- Collapsed by default (only when collapsible) -->
                  <div v-if="section.collapsible" class="tmpl-field-group tmpl-checkbox-group">
                    <Checkbox v-model="section.collapsed" :binary="true" :inputId="'coldef-' + si" />
                    <label :for="'coldef-' + si">{{ t('collapsed_by_default') }}</label>
                  </div>
                </div>

                <!-- Section move + remove -->
                <div class="tmpl-section-actions">
                  <Button icon="pi pi-arrow-up"   size="small" text :disabled="si === 0"                         @click="moveSection(si, -1)" />
                  <Button icon="pi pi-arrow-down" size="small" text :disabled="si === form.sections.length - 1"  @click="moveSection(si,  1)" />
                  <Button icon="pi pi-trash"       size="small" text severity="danger"                            @click="removeSection(si)"   />
                </div>
              </div>

              <!-- Content items (fields) -->
              <div class="tmpl-content-list">
                <div
                  v-for="(item, ii) in section.content"
                  :key="ii"
                  class="tmpl-content-row"
                >
                  <!-- Field selector -->
                  <Select
                    v-model="item.field"
                    :options="section.plugin ? pluginFieldOptions(section.plugin) : coreFieldOptions"
                    optionLabel="label"
                    optionValue="name"
                    :placeholder="t('select_field')"
                    size="small"
                    filter
                    style="flex:1; min-width:140px"
                  />

                  <!-- Width selector -->
                  <Select
                    v-model="item.width"
                    :options="widthOptions"
                    size="small"
                    style="width:90px"
                  />

                  <!-- Row move + remove -->
                  <Button icon="pi pi-arrow-up"   size="small" text :disabled="ii === 0"                          @click="moveField(si, ii, -1)" />
                  <Button icon="pi pi-arrow-down" size="small" text :disabled="ii === section.content.length - 1" @click="moveField(si, ii,  1)" />
                  <Button icon="pi pi-times"      size="small" text severity="danger"                              @click="section.content.splice(ii, 1)" />
                </div>

                <Button
                  :label="t('add_field')"
                  icon="pi pi-plus"
                  size="small"
                  text
                  class="tmpl-add-field-btn"
                  @click="addField(si)"
                />
              </div>
            </div>

            <!-- Add section -->
            <Button
              :label="t('add_section')"
              icon="pi pi-plus"
              size="small"
              outlined
              class="tmpl-add-section-btn"
              @click="addSection"
            />
          </div>
        </template>

      </div>
    </div>

    <!-- ── New template dialog ─────────────────────────────────────────── -->
    <Dialog v-model:visible="showNewDialog" modal :header="t('add_template')" style="width:360px">
      <div class="tmpl-new-body">
        <label>{{ t('template_name') }}</label>
        <InputText
          v-model="newTemplateName"
          size="small"
          :placeholder="t('template_name_hint')"
          autofocus
          @keyup.enter="createTemplate"
        />
        <small class="tmpl-name-hint">{{ t('template_name_chars') }}</small>
      </div>
      <template #footer>
        <Button :label="t('cancel')" severity="secondary" size="small" text @click="showNewDialog = false" />
        <Button :label="t('create')" icon="pi pi-plus" size="small" :loading="creating" @click="createTemplate" />
      </template>
    </Dialog>

  </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import AppLayout   from '@/components/AppLayout.vue'
import Button      from 'primevue/button'
import InputText   from 'primevue/inputtext'
import Select      from 'primevue/select'
import Checkbox    from 'primevue/checkbox'
import Dialog      from 'primevue/dialog'
import { useToast }   from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import { useI18n }    from '@/i18n'
import { api }        from '@/api'

const { t }   = useI18n()
const toast   = useToast()
const confirm = useConfirm()

// ── State ───────────────────────────────────────────────────────────────
const tables        = ref([])
const selectedTb    = ref(null)
const selectedTbLabel = ref('')
const selectedName  = ref(null)
const templateNames = ref([])
const coreFields    = ref([])   // [{ name, label }]
const plugins       = ref([])   // [{ tb, label }]
const pluginFieldsCache = ref({}) // tb → [{ name, label }]

const form    = ref(null)   // { sections: [...] }
const saving  = ref(false)
const deleting = ref(false)

const showNewDialog  = ref(false)
const newTemplateName = ref('')
const creating       = ref(false)

const renaming      = ref(false)
const newName       = ref('')
const renameLoading = ref(false)

// ── Computed ────────────────────────────────────────────────────────────
const widthOptions = ['1/1', '1/2', '1/3', '2/3', '1/4', '3/4']

const coreFieldOptions = computed(() => coreFields.value)

const pluginOptions = computed(() => plugins.value)

function pluginFieldOptions(plgTb) {
  return pluginFieldsCache.value[plgTb] ?? []
}

// ── Boot ────────────────────────────────────────────────────────────────
onMounted(async () => {
  try {
    const res = await api.get('/api/templates')
    if (res.status === 'error') throw new Error(t(res.code))
    tables.value = res.tables ?? []
  } catch (e) {
    toast.add({ severity: 'error', summary: e.message, life: 4000 })
  }
})

// ── Table selection ─────────────────────────────────────────────────────
async function selectTable(tbl) {
  selectedTb.value    = tbl.tb
  selectedTbLabel.value = tbl.label
  selectedName.value  = null
  form.value          = null
  templateNames.value = []
  coreFields.value    = []
  plugins.value       = []

  try {
    const res = await api.get(`/api/templates/${tbl.tb}`)
    if (res.status === 'error') throw new Error(t(res.code))
    templateNames.value = res.templates ?? []
    coreFields.value    = res.fields    ?? []
    plugins.value       = res.plugins   ?? []

    // Pre-load plugin fields for the editor selectors
    for (const plg of plugins.value) {
      await loadPluginFields(plg.tb)
    }
  } catch (e) {
    toast.add({ severity: 'error', summary: e.message, life: 4000 })
  }
}

async function loadPluginFields(plgTb) {
  if (pluginFieldsCache.value[plgTb]) return
  try {
    const res = await api.get(`/api/config/table/${plgTb}/fields`)
    const raw = res.fields ?? {}
    // getFldList returns { name: label } map; convert to [{ name, label }]
    pluginFieldsCache.value[plgTb] = Object.entries(raw).map(([name, label]) => ({ name, label }))
  } catch { /* silently ignore — editor still works, just no field labels */ }
}

// ── Template open ────────────────────────────────────────────────────────
async function openTemplate(name) {
  selectedName.value = name
  form.value         = null
  renaming.value     = false

  try {
    const res = await api.get(`/api/template/${selectedTb.value}/${name}`)
    if (res.status === 'error') throw new Error(t(res.code))
    // Deep-clone so edits don't alias the fetched object
    form.value = JSON.parse(JSON.stringify(res.template))
    // Ensure every section has the keys we depend on
    for (const s of form.value.sections) {
      s.collapsible = s.collapsible ?? false
      s.collapsed   = s.collapsed   ?? false
      s.plugin      = s.plugin      ?? null
      s.content     = s.content     ?? []
    }
  } catch (e) {
    toast.add({ severity: 'error', summary: e.message, life: 4000 })
  }
}

// ── Sections / fields mutation ──────────────────────────────────────────
function addSection() {
  form.value.sections.push({
    label: t('new_section'),
    collapsible: false,
    collapsed:   false,
    plugin:      null,
    content:     [],
  })
}

function removeSection(si) {
  form.value.sections.splice(si, 1)
}

function moveSection(si, dir) {
  const arr = form.value.sections
  const tmp = arr[si]
  arr[si]       = arr[si + dir]
  arr[si + dir] = tmp
}

function addField(si) {
  form.value.sections[si].content.push({ field: '', width: '1/2' })
}

function moveField(si, ii, dir) {
  const arr = form.value.sections[si].content
  const tmp  = arr[ii]
  arr[ii]       = arr[ii + dir]
  arr[ii + dir] = tmp
}

// ── Save ─────────────────────────────────────────────────────────────────
async function saveTemplate() {
  saving.value = true
  try {
    // Strip null plugin key before sending (Loader.validate expects absence, not null)
    const payload = JSON.parse(JSON.stringify(form.value))
    for (const s of payload.sections) {
      if (!s.plugin) delete s.plugin
      if (!s.collapsible) { delete s.collapsed }
    }

    const res = await api.post(`/api/template/${selectedTb.value}/${selectedName.value}`, payload)
    toast.add({
      severity: res.status === 'success' ? 'success' : 'error',
      summary:  t('saved'),
      detail:   api.responseMessage(res, t),
      life: 4000,
    })
    if (res.status === 'error' && res.errors) {
      console.warn('Template validation errors:', res.errors)
    }
  } catch (e) {
    toast.add({ severity: 'error', summary: e.message, life: 4000 })
  } finally {
    saving.value = false
  }
}

// ── Delete ────────────────────────────────────────────────────────────────
function confirmDelete() {
  confirm.require({
    message:  t('confirm_delete_template'),
    header:   t('delete_template'),
    icon:     'pi pi-exclamation-triangle',
    severity: 'danger',
    accept:   doDelete,
  })
}

async function doDelete() {
  deleting.value = true
  try {
    const res = await api.delete(`/api/template/${selectedTb.value}/${selectedName.value}`)
    toast.add({
      severity: res.status === 'success' ? 'success' : 'error',
      summary:  t('saved'),
      detail:   api.responseMessage(res, t),
      life: 4000,
    })
    if (res.status === 'success') {
      templateNames.value = templateNames.value.filter(n => n !== selectedName.value)
      selectedName.value  = null
      form.value          = null
    }
  } catch (e) {
    toast.add({ severity: 'error', summary: e.message, life: 4000 })
  } finally {
    deleting.value = false
  }
}

// ── Rename ────────────────────────────────────────────────────────────────
function startRename() {
  newName.value  = selectedName.value
  renaming.value = true
}

async function confirmRename() {
  if (!newName.value || newName.value === selectedName.value) {
    renaming.value = false
    return
  }
  renameLoading.value = true
  try {
    const res = await api.post(`/api/template/${selectedTb.value}/${selectedName.value}/rename`, {
      old: selectedName.value, new: newName.value,
    })
    toast.add({
      severity: res.status === 'success' ? 'success' : 'error',
      summary:  t('saved'),
      detail:   api.responseMessage(res, t),
      life: 4000,
    })
    if (res.status === 'success') {
      const idx = templateNames.value.indexOf(selectedName.value)
      if (idx !== -1) templateNames.value[idx] = newName.value
      selectedName.value = newName.value
      renaming.value     = false
    }
  } catch (e) {
    toast.add({ severity: 'error', summary: e.message, life: 4000 })
  } finally {
    renameLoading.value = false
  }
}

// ── Create ────────────────────────────────────────────────────────────────
async function createTemplate() {
  const name = newTemplateName.value.trim()
  if (!name) return

  creating.value = true
  try {
    // Create an empty but valid template and save it immediately
    const payload = { sections: [] }
    const res = await api.post(`/api/template/${selectedTb.value}/${name}`, payload)
    toast.add({
      severity: res.status === 'success' ? 'success' : 'error',
      summary:  t('saved'),
      detail:   api.responseMessage(res, t),
      life: 4000,
    })
    if (res.status === 'success') {
      if (!templateNames.value.includes(name)) {
        templateNames.value = [...templateNames.value, name].sort()
      }
      showNewDialog.value   = false
      newTemplateName.value = ''
      await openTemplate(name)
    }
  } catch (e) {
    toast.add({ severity: 'error', summary: e.message, life: 4000 })
  } finally {
    creating.value = false
  }
}
</script>

<style scoped>
/* ── Shell ─────────────────────────────────────────────────────────── */
.tmpl-shell {
  display: flex;
  flex: 1;
  min-height: 0;
  overflow: hidden;
}

/* ── Sidebar ────────────────────────────────────────────────────────── */
.tmpl-sidebar {
  width: 220px;
  flex-shrink: 0;
  border-right: 1px solid var(--p-content-border-color);
  overflow-y: auto;
  display: flex;
  flex-direction: column;
}

.tmpl-sidebar-title {
  font-size: 0.7rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.07em;
  color: var(--p-text-muted-color);
  padding: 0.75rem 1rem 0.4rem;
}

.tmpl-table-item {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.45rem 1rem;
  font-size: 0.875rem;
  cursor: pointer;
  border-left: 3px solid transparent;
  transition: background 0.12s;
}
.tmpl-table-item:hover  { background: var(--p-content-hover-background); }
.tmpl-table-item.active {
  border-left-color: var(--p-primary-color);
  background: var(--p-highlight-background);
  color: var(--p-primary-color);
  font-weight: 600;
}
.tmpl-table-label {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.tmpl-list-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.6rem 0.75rem 0.2rem 1rem;
  font-size: 0.7rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.07em;
  color: var(--p-text-muted-color);
  border-top: 1px solid var(--p-content-border-color);
  margin-top: 0.25rem;
}

.tmpl-empty-list {
  padding: 0.35rem 1rem;
  font-size: 0.8rem;
  color: var(--p-text-muted-color);
}

.tmpl-name-item {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.35rem 1rem 0.35rem 1.5rem;
  font-size: 0.82rem;
  cursor: pointer;
  color: var(--p-text-muted-color);
  transition: background 0.12s;
}
.tmpl-name-item:hover  { background: var(--p-content-hover-background); color: var(--p-text-color); }
.tmpl-name-item.active { color: var(--p-primary-color); font-weight: 600; }

/* ── Editor ─────────────────────────────────────────────────────────── */
.tmpl-editor {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.tmpl-editor-empty {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 1rem;
  color: var(--p-text-muted-color);
}
.tmpl-empty-icon {
  font-size: 3rem;
  opacity: 0.3;
}

/* Editor header */
.tmpl-editor-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.6rem 1.25rem;
  border-bottom: 1px solid var(--p-content-border-color);
  flex-shrink: 0;
  flex-wrap: wrap;
}
.tmpl-editor-title {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.95rem;
  font-weight: 600;
}
.tmpl-tb-label { color: var(--p-text-muted-color); }
.tmpl-sep      { font-size: 0.7rem; color: var(--p-text-muted-color); }
.tmpl-rename-input { width: 160px; }

.tmpl-editor-actions {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  flex-wrap: wrap;
}

/* Sections scroll area */
.tmpl-sections {
  flex: 1;
  overflow-y: auto;
  padding: 1rem 1.25rem;
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

/* Section card */
.tmpl-section-card {
  border: 1px solid var(--p-content-border-color);
  border-radius: 6px;
  overflow: hidden;
}

.tmpl-section-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.6rem 0.75rem;
  background: color-mix(in srgb, var(--p-primary-color) 5%, transparent);
  border-bottom: 1px solid var(--p-content-border-color);
  flex-wrap: wrap;
}

.tmpl-section-meta {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  flex-wrap: wrap;
}

.tmpl-field-group {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
}
.tmpl-field-group label {
  font-size: 0.7rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--p-text-muted-color);
}

.tmpl-checkbox-group {
  flex-direction: row;
  align-items: center;
  gap: 0.4rem;
}
.tmpl-checkbox-group label {
  text-transform: none;
  letter-spacing: 0;
  font-size: 0.82rem;
  font-weight: 500;
  cursor: pointer;
}

.tmpl-section-actions {
  display: flex;
  align-items: center;
  gap: 0.1rem;
  flex-shrink: 0;
}

/* Content rows */
.tmpl-content-list {
  padding: 0.5rem 0.75rem;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.tmpl-content-row {
  display: flex;
  align-items: center;
  gap: 0.4rem;
}

.tmpl-add-field-btn {
  align-self: flex-start;
  margin-top: 0.15rem;
}

/* Add section button */
.tmpl-add-section-btn {
  align-self: flex-start;
}

/* New template dialog */
.tmpl-new-body {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}
.tmpl-new-body label {
  font-size: 0.82rem;
  color: var(--p-text-muted-color);
}
.tmpl-name-hint {
  font-size: 0.72rem;
  color: var(--p-text-muted-color);
}
</style>
