<template>
  <AppLayout>
    <div class="tmpl-shell">

      <!-- ── Left sidebar ─────────────────────────────────────────────── -->
      <div class="tmpl-sidebar">
        <div class="tmpl-sidebar-title">{{ t('design_templates') }}</div>

        <!-- Table list, each table followed by its own templates -->
        <div v-for="tbl in tables" :key="tbl.tb" class="tmpl-table-group">
          <div
            class="tmpl-table-item"
            :class="{ active: selectedTb === tbl.tb }"
            @click="selectTable(tbl)"
          >
            <TableOutlined />
            <span class="tmpl-table-label">{{ tbl.label }}</span>
          </div>

          <div v-if="selectedTb === tbl.tb" class="tmpl-table-templates">
            <div class="tmpl-list-header">
              <span>{{ t('templates') }}</span>
              <AButton
                type="text"
                size="small"
                :title="t('add_template')"
                @click="showNewDialog = true"
              >
                <template #icon><PlusOutlined /></template>
              </AButton>
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
              <FileOutlined />
              {{ name }}
            </div>
          </div>
        </div>
      </div>

      <!-- ── Right editor ──────────────────────────────────────────────── -->
      <div class="tmpl-editor">

        <!-- Empty state -->
        <div v-if="!selectedTb" class="tmpl-editor-empty">
          <FileTextOutlined class="tmpl-empty-icon" />
          <p>{{ t('select_table_for_template') }}</p>
        </div>
        <div v-else-if="!selectedName" class="tmpl-editor-empty">
          <FileTextOutlined class="tmpl-empty-icon" />
          <p>{{ t('select_or_create_template') }}</p>
        </div>

        <!-- Editor -->
        <template v-else-if="form">

          <!-- Editor header -->
          <div class="tmpl-editor-header">
            <div class="tmpl-editor-title">
              <span class="tmpl-tb-label">{{ selectedTbLabel }}</span>
              <RightOutlined class="tmpl-sep" />
              <span v-if="!renaming" class="tmpl-name-text">{{ selectedName }}</span>
              <AInput
                v-else
                v-model:value="newName"
                size="small"
                class="tmpl-rename-input"
                @keyup.enter="confirmRename"
                @keyup.escape="renaming = false"
              />
            </div>
            <div class="tmpl-editor-actions">
              <ARadioGroup
                v-if="!renaming"
                v-model:value="editMode"
                size="small"
                button-style="solid"
                @change="onModeChange"
              >
                <ARadioButton value="visual">{{ t('template_visual') }}</ARadioButton>
                <ARadioButton value="json">{{ t('template_json') }}</ARadioButton>
              </ARadioGroup>
              <AButton v-if="!renaming" size="small" @click="startRename">
                <template #icon><EditOutlined /></template>
                {{ t('rename') }}
              </AButton>
              <AButton v-if="renaming" type="primary" size="small" :loading="renameLoading" @click="confirmRename">
                <template #icon><CheckOutlined /></template>
                {{ t('confirm') }}
              </AButton>
              <AButton v-if="renaming" type="text" size="small" @click="renaming = false">
                {{ t('cancel') }}
              </AButton>
              <AButton v-if="!renaming" size="small" :loading="saving" @click="saveTemplate">
                <template #icon><SaveOutlined /></template>
                {{ t('save') }}
              </AButton>
              <AButton
                v-if="!renaming"
                danger
                size="small"
                :title="t('delete_template')"
                :loading="deleting"
                @click="confirmDelete"
              >
                <template #icon><DeleteOutlined /></template>
              </AButton>
            </div>
          </div>

          <!-- Server-side validation errors -->
          <div v-if="serverErrors.length" class="tmpl-errors">
            <div class="tmpl-errors-title">{{ t('template_validation_failed') }}</div>
            <ul>
              <li v-for="(err, i) in serverErrors" :key="i">{{ err }}</li>
            </ul>
          </div>

          <!-- JSON code editor -->
          <div v-if="editMode === 'json'" class="tmpl-json">
            <ATextarea
              v-model:value="jsonText"
              class="tmpl-json-area"
              spellcheck="false"
              :auto-size="false"
            />
            <div v-if="jsonError" class="tmpl-json-error">{{ jsonError }}</div>
          </div>

          <!-- Sections -->
          <div v-else class="tmpl-sections">

            <div
              v-for="(section, si) in form.sections"
              :key="si"
              class="tmpl-section-card"
              :class="'tmpl-section-card--' + sectionType(section)"
            >
              <!-- Section header row -->
              <div class="tmpl-section-header">
                <div class="tmpl-section-meta">

                  <!-- Kind badge: plugin sections hold multiple rows -->
                  <span class="tmpl-kind-badge" :class="'tmpl-kind-badge--' + sectionType(section)">
                    {{ t('kind_' + sectionType(section)) }}
                  </span>

                  <!-- Label -->
                  <div class="tmpl-field-group">
                    <label>{{ t('section_label') }}</label>
                    <AInput v-model:value="section.label" size="small" style="width:180px" />
                  </div>

                  <!-- Section type -->
                  <div class="tmpl-field-group">
                    <label>{{ t('section_type') }}</label>
                    <ASelect
                      :value="sectionType(section)"
                      :options="sectionTypeOptions"
                      size="small"
                      style="width:140px"
                      @change="value => onSectionTypeChange(si, value)"
                    />
                  </div>

                  <!-- Plugin selector (only when type = plugin) -->
                  <div v-if="sectionType(section) === 'plugin'" class="tmpl-field-group">
                    <label>{{ t('plugin') }}</label>
                    <ASelect
                      v-model:value="section.plugin"
                      :options="pluginOptions"
                      :placeholder="t('select_plugin')"
                      allow-clear
                      size="small"
                      style="width:160px"
                    />
                  </div>

                  <!-- Collapsible (not applicable to accordion) -->
                  <template v-if="sectionType(section) !== 'accordion'">
                    <div class="tmpl-field-group tmpl-checkbox-group">
                      <ACheckbox v-model:checked="section.collapsible" :id="'col-' + si" />
                      <label :for="'col-' + si">{{ t('collapsible') }}</label>
                    </div>
                    <div v-if="section.collapsible" class="tmpl-field-group tmpl-checkbox-group">
                      <ACheckbox v-model:checked="section.collapsed" :id="'coldef-' + si" />
                      <label :for="'coldef-' + si">{{ t('collapsed_by_default') }}</label>
                    </div>
                  </template>
                </div>

                <!-- Section move + remove -->
                <div class="tmpl-section-actions">
                  <AButton type="text" size="small" :disabled="si === 0"                        @click="moveSection(si, -1)"><template #icon><ArrowUpOutlined /></template></AButton>
                  <AButton type="text" size="small" :disabled="si === form.sections.length - 1" @click="moveSection(si,  1)"><template #icon><ArrowDownOutlined /></template></AButton>
                  <AButton type="text" danger size="small" @click="removeSection(si)"><template #icon><DeleteOutlined /></template></AButton>
                </div>
              </div>

              <!-- Content: core fields -->
              <div v-if="sectionType(section) === 'core'" class="tmpl-content-list">
                <div
                  v-for="(item, ii) in section.content"
                  :key="ii"
                  class="tmpl-content-row"
                >
                  <ASelect
                    :value="itemValue(item)"
                    :options="mixedFieldOptions"
                    :placeholder="t('select_field')"
                    size="small"
                    show-search
                    option-filter-prop="label"
                    :class="{ 'tmpl-select--plugin': item.plugin }"
                    style="flex:1; min-width:140px"
                    @change="v => setItemValue(item, v)"
                  />
                  <ASelect v-model:value="item.width" :options="widthSelectOptions" size="small" style="width:90px" :disabled="item.field === '*'" />
                  <AButton type="text" size="small" :disabled="ii === 0"                          @click="moveField(si, ii, -1)"><template #icon><ArrowUpOutlined /></template></AButton>
                  <AButton type="text" size="small" :disabled="ii === section.content.length - 1" @click="moveField(si, ii,  1)"><template #icon><ArrowDownOutlined /></template></AButton>
                  <AButton type="text" danger size="small" @click="section.content.splice(ii, 1)"><template #icon><CloseOutlined /></template></AButton>
                </div>
                <AButton type="text" size="small" class="tmpl-add-field-btn" @click="addField(si)">
                  <template #icon><PlusOutlined /></template>
                  {{ t('add_field') }}
                </AButton>
              </div>

              <!-- Content: plugin (layout columns for PluginSection) -->
              <div v-else-if="sectionType(section) === 'plugin'" class="tmpl-content-list">
                <div
                  v-for="(item, ii) in section.content"
                  :key="ii"
                  class="tmpl-content-row"
                >
                  <span class="tmpl-parent-tag" :title="section.plugin">{{ pluginLabel(section.plugin) }} ›</span>
                  <ASelect
                    v-model:value="item.field"
                    :options="section.plugin ? pluginFieldOptions(section.plugin) : []"
                    :placeholder="t('select_field')"
                    size="small"
                    show-search
                    style="flex:1; min-width:140px"
                  />
                  <ASelect v-model:value="item.width" :options="widthSelectOptions" size="small" style="width:90px" />
                  <AButton type="text" size="small" :disabled="ii === 0"                          @click="moveField(si, ii, -1)"><template #icon><ArrowUpOutlined /></template></AButton>
                  <AButton type="text" size="small" :disabled="ii === section.content.length - 1" @click="moveField(si, ii,  1)"><template #icon><ArrowDownOutlined /></template></AButton>
                  <AButton type="text" danger size="small" @click="section.content.splice(ii, 1)"><template #icon><CloseOutlined /></template></AButton>
                </div>
                <div v-if="!section.content.length" class="tmpl-hint">{{ t('plugin_all_fields_hint') }}</div>
                <AButton type="text" size="small" class="tmpl-add-field-btn" @click="addField(si)">
                  <template #icon><PlusOutlined /></template>
                  {{ t('add_field') }}
                </AButton>
              </div>

              <!-- Content: accordion panels -->
              <div v-else-if="sectionType(section) === 'accordion'" class="tmpl-accordion-editor">
                <div
                  v-for="(panel, pi) in section.content"
                  :key="pi"
                  class="tmpl-accordion-panel"
                >
                  <!-- Panel header row -->
                  <div class="tmpl-accordion-panel-header">
                    <div class="tmpl-field-group" style="flex:1">
                      <label>{{ t('panel_label') }}</label>
                      <AInput v-model:value="panel.label" size="small" style="width:180px" />
                    </div>
                    <div class="tmpl-field-group tmpl-checkbox-group">
                      <ACheckbox v-model:checked="panel.open" :id="'open-' + si + '-' + pi" />
                      <label :for="'open-' + si + '-' + pi">{{ t('open_by_default') }}</label>
                    </div>
                    <div class="tmpl-section-actions">
                      <AButton type="text" size="small" :disabled="pi === 0"                          @click="movePanel(si, pi, -1)"><template #icon><ArrowUpOutlined /></template></AButton>
                      <AButton type="text" size="small" :disabled="pi === section.content.length - 1" @click="movePanel(si, pi,  1)"><template #icon><ArrowDownOutlined /></template></AButton>
                      <AButton type="text" danger size="small" @click="section.content.splice(pi, 1)"><template #icon><DeleteOutlined /></template></AButton>
                    </div>
                  </div>
                  <!-- Panel field list -->
                  <div class="tmpl-content-list">
                    <div
                      v-for="(item, ii) in panel.fields"
                      :key="ii"
                      class="tmpl-content-row"
                    >
                      <ASelect
                        v-model:value="item.field"
                        :options="coreFieldOptions"
                        :placeholder="t('select_field')"
                        size="small"
                        show-search
                        style="flex:1; min-width:140px"
                      />
                      <ASelect v-model:value="item.width" :options="widthSelectOptions" size="small" style="width:90px" />
                      <AButton type="text" size="small" :disabled="ii === 0"                       @click="movePanelField(si, pi, ii, -1)"><template #icon><ArrowUpOutlined /></template></AButton>
                      <AButton type="text" size="small" :disabled="ii === panel.fields.length - 1" @click="movePanelField(si, pi, ii,  1)"><template #icon><ArrowDownOutlined /></template></AButton>
                      <AButton type="text" danger size="small" @click="panel.fields.splice(ii, 1)"><template #icon><CloseOutlined /></template></AButton>
                    </div>
                    <AButton type="text" size="small" class="tmpl-add-field-btn" @click="addPanelField(si, pi)">
                      <template #icon><PlusOutlined /></template>
                      {{ t('add_field') }}
                    </AButton>
                  </div>
                </div>
                <AButton type="text" size="small" class="tmpl-add-field-btn" @click="addPanel(si)">
                  <template #icon><PlusOutlined /></template>
                  {{ t('add_panel') }}
                </AButton>
              </div>
            </div>

            <!-- Add section -->
            <AButton size="small" class="tmpl-add-section-btn" @click="addSection">
              <template #icon><PlusOutlined /></template>
              {{ t('add_section') }}
            </AButton>
          </div>
        </template>

      </div>
    </div>

    <!-- ── New template dialog ─────────────────────────────────────────── -->
    <AModal v-model:open="showNewDialog" :title="t('add_template')" width="360px">
      <div class="tmpl-new-body">
        <label>{{ t('template_name') }}</label>
        <AInput
          v-model:value="newTemplateName"
          size="small"
          :placeholder="t('template_name_hint')"
          autofocus
          @keyup.enter="createTemplate"
        />
        <small class="tmpl-name-hint">{{ t('template_name_chars') }}</small>
      </div>
      <template #footer>
        <AButton type="text" size="small" @click="showNewDialog = false">{{ t('cancel') }}</AButton>
        <AButton type="primary" size="small" :loading="creating" @click="createTemplate">
          <template #icon><PlusOutlined /></template>
          {{ t('create') }}
        </AButton>
      </template>
    </AModal>

  </AppLayout>
</template>

<script setup>
import { ArrowDownOutlined, ArrowUpOutlined, CheckOutlined, CloseOutlined, DeleteOutlined, EditOutlined, FileOutlined, FileTextOutlined, PlusOutlined, RightOutlined, SaveOutlined, TableOutlined } from '@ant-design/icons-vue'
import { ref, computed, onMounted } from 'vue'
import AppLayout   from '@/components/AppLayout.vue'
import {
  Button as AButton,
  Input as AInput,
  Select as ASelect,
  Checkbox as ACheckbox,
  Modal as AModal,
  Radio
} from 'ant-design-vue'
import { useToast, useConfirm } from '@/composables/useNotify'
import { useI18n }    from '@/i18n'
import { api }        from '@/api'

const ATextarea    = AInput.TextArea
const ARadioGroup  = Radio.Group
const ARadioButton = Radio.Button

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
const editMode  = ref('visual')   // 'visual' | 'json'
const jsonText  = ref('')
const jsonError = ref('')
const serverErrors = ref([])
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
const widthSelectOptions = widthOptions.map(w => ({ value: w, label: w }))

const coreFieldOptions = computed(() => coreFields.value.map(f => ({ value: f.name, label: f.label })))

const pluginOptions = computed(() => plugins.value.map(p => ({ value: p.tb, label: p.label })))

// Core sections may also hold plugin fields: encoded as "tb::field" in the select
const mixedFieldOptions = computed(() => [
  { label: t('kind_core'), options: coreFieldOptions.value },
  ...plugins.value.map(p => ({
    label: `${t('kind_plugin')}: ${p.label}`,
    options: [
      { value: `${p.tb}::*`, label: `${p.label} › * (${t('all_fields')})` },
      ...pluginFieldOptions(p.tb).map(o => ({ ...o, value: `${p.tb}::${o.value}` })),
    ],
  })),
])

function itemValue(item) {
  return item.plugin ? `${item.plugin}::${item.field}` : item.field
}

function setItemValue(item, v) {
  const i = typeof v === 'string' ? v.indexOf('::') : -1
  if (i === -1) {
    delete item.plugin
    item.field = v
  } else {
    item.plugin = v.slice(0, i)
    item.field  = v.slice(i + 2)
    if (item.field === '*') item.width = '1/1'
  }
}

function pluginLabel(plgTb) {
  return plugins.value.find(p => p.tb === plgTb)?.label ?? plgTb ?? ''
}

function pluginFieldOptions(plgTb) {
  const parent = pluginLabel(plgTb)
  return (pluginFieldsCache.value[plgTb] ?? []).map(f => ({ value: f.name, label: `${parent} › ${f.label}` }))
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
    form.value = normalizeForm(JSON.parse(JSON.stringify(res.template)))
    jsonError.value = ''
    serverErrors.value = []
    if (editMode.value === 'json') jsonText.value = serializeForm()
  } catch (e) {
    toast.add({ severity: 'error', summary: e.message, life: 4000 })
  }
}

/** Ensure every section has the keys the visual editor depends on. */
function normalizeForm(tpl) {
  tpl.sections = tpl.sections ?? []
  for (const s of tpl.sections) {
    s.collapsible = s.collapsible ?? false
    s.collapsed   = s.collapsed   ?? false
    s.plugin      = s.plugin      ?? null
    s.content     = s.content     ?? []
    if (s.type === 'accordion') {
      for (const panel of s.content) {
        panel.open   = panel.open   !== false
        panel.fields = panel.fields ?? []
      }
    }
  }
  return tpl
}

/** Strip editor-only keys, as sent to the API. */
function cleanPayload(tpl) {
  const payload = JSON.parse(JSON.stringify(tpl))
  for (const s of payload.sections) {
    if (!s.plugin) delete s.plugin
    if (!s.collapsible) delete s.collapsed
    if (s.type !== 'accordion') delete s.type
  }
  return payload
}

function serializeForm() {
  return JSON.stringify(cleanPayload(form.value), null, 2)
}

/** Parse the JSON textarea. Returns the template object, or null (and sets jsonError). */
function parseJson() {
  try {
    const obj = JSON.parse(jsonText.value)
    if (!obj || typeof obj !== 'object' || Array.isArray(obj) || !Array.isArray(obj.sections)) {
      jsonError.value = t('template_json_structure')
      return null
    }
    jsonError.value = ''
    return obj
  } catch (e) {
    jsonError.value = t('template_json_invalid', { msg: e.message })
    return null
  }
}

function onModeChange() {
  if (editMode.value === 'json') {
    jsonText.value = serializeForm()
    jsonError.value = ''
    return
  }
  // json → visual: only switch if the code parses
  const obj = parseJson()
  if (!obj) {
    editMode.value = 'json'
    return
  }
  form.value = normalizeForm(obj)
}

// ── Section type helpers ────────────────────────────────────────────────
const sectionTypeOptions = computed(() => [
  { label: t('core_section'), value: 'core' },
  { label: t('plugin'),       value: 'plugin' },
  { label: 'Accordion',       value: 'accordion' },
])

function sectionType(section) {
  if (section.plugin) return 'plugin'
  if (section.type === 'accordion') return 'accordion'
  return 'core'
}

function onSectionTypeChange(si, newType) {
  const s = form.value.sections[si]
  if (newType === 'accordion') {
    delete s.plugin
    s.type    = 'accordion'
    s.content = []
  } else if (newType === 'plugin') {
    delete s.type
    s.plugin  = null
    s.content = []
  } else {
    delete s.plugin
    delete s.type
    s.content = []
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

// ── Accordion panel mutation ─────────────────────────────────────────────
function addPanel(si) {
  form.value.sections[si].content.push({
    label:  t('new_section'),
    open:   true,
    fields: [],
  })
}

function movePanel(si, pi, dir) {
  const arr = form.value.sections[si].content
  const tmp = arr[pi]
  arr[pi]       = arr[pi + dir]
  arr[pi + dir] = tmp
}

function addPanelField(si, pi) {
  form.value.sections[si].content[pi].fields.push({ field: '', width: '1/2' })
}

function movePanelField(si, pi, ii, dir) {
  const arr = form.value.sections[si].content[pi].fields
  const tmp = arr[ii]
  arr[ii]       = arr[ii + dir]
  arr[ii + dir] = tmp
}

// ── Save ─────────────────────────────────────────────────────────────────
async function saveTemplate() {
  saving.value = true
  try {
    let payload
    if (editMode.value === 'json') {
      const obj = parseJson()
      if (!obj) return
      form.value = normalizeForm(JSON.parse(JSON.stringify(obj)))
      payload = obj
    } else {
      // Strip null plugin key before sending (Loader.validate expects absence, not null)
      payload = cleanPayload(form.value)
    }

    const res = await api.post(`/api/template/${selectedTb.value}/${selectedName.value}`, payload)
    toast.add({
      severity: res.status === 'success' ? 'success' : 'error',
      summary:  t('saved'),
      detail:   api.responseMessage(res, t),
      life: 4000,
    })
    serverErrors.value = res.status === 'error' && Array.isArray(res.errors) ? res.errors : []
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
.tmpl-json { flex: 1; min-height: 0; display: flex; flex-direction: column; padding: 0.75rem 1rem; gap: 0.5rem; }
.tmpl-json-area { flex: 1; min-height: 320px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.8rem; resize: none; }
.tmpl-json-error { color: var(--p-red-500, #d33); font-size: 0.8rem; }
.tmpl-errors { margin: 0.75rem 1rem 0; padding: 0.5rem 0.75rem; border: 1px solid var(--p-red-500, #d33); border-radius: 6px; background: color-mix(in srgb, var(--p-red-500, #d33) 8%, transparent); font-size: 0.8rem; }
.tmpl-errors-title { font-weight: 700; margin-bottom: 0.25rem; }
.tmpl-errors ul { margin: 0; padding-left: 1.2rem; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
.tmpl-hint { font-size: 0.75rem; color: var(--p-text-muted-color); padding: 0.25rem 0; }

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

.tmpl-table-templates {
  margin: 0 0 0.4rem 1.1rem;
  border-left: 1px solid var(--p-content-border-color);
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

.tmpl-table-templates .tmpl-list-header {
  border-top: none;
  margin-top: 0;
  padding-left: 0.75rem;
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

.tmpl-section-card--plugin {
  border-left: 4px solid var(--p-orange-500, #f59e0b);
}
.tmpl-section-card--plugin .tmpl-section-header {
  background: color-mix(in srgb, var(--p-orange-500, #f59e0b) 8%, transparent);
}
.tmpl-select--plugin :deep(.ant-select-selector) {
  border-color: var(--p-orange-500, #f59e0b) !important;
}
.tmpl-parent-tag {
  flex-shrink: 0;
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--p-orange-600, #d97706);
  white-space: nowrap;
}
.tmpl-kind-badge {
  align-self: flex-end;
  font-size: 0.7rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  padding: 0.15rem 0.5rem;
  border-radius: 10px;
  background: var(--p-content-hover-background);
  color: var(--p-text-muted-color);
}
.tmpl-kind-badge--plugin {
  background: color-mix(in srgb, var(--p-orange-500, #f59e0b) 20%, transparent);
  color: var(--p-orange-600, #d97706);
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

/* Accordion panel editor */
.tmpl-accordion-editor {
  padding: 0.5rem 0.75rem;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.tmpl-accordion-panel {
  border: 1px solid var(--p-content-border-color);
  border-radius: 5px;
  overflow: hidden;
}

.tmpl-accordion-panel-header {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.45rem 0.6rem;
  background: color-mix(in srgb, var(--p-primary-color) 4%, transparent);
  border-bottom: 1px solid var(--p-content-border-color);
  flex-wrap: wrap;
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
