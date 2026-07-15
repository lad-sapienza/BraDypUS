<template>
  <AppLayout>
  <div class="record-view">

    <!-- ── Header ──────────────────────────────────────────────────── -->
    <div class="record-header">
      <div class="record-breadcrumb">
        <router-link :to="backTarget" class="back-link">
          <i class="pi pi-arrow-left" />
          {{ record?.metadata?.tb_label ?? t('data') }}
        </router-link>
        <span v-if="recordTitle" class="sep">/</span>
        <span v-if="recordTitle" class="record-title-text">{{ recordTitle }}</span>
        <span v-if="isNew" class="record-title-text new-badge">{{ t('new_record') }}</span>
      </div>

      <div class="header-actions">
        <!-- Template selector (read mode, when >1 template available) -->
        <Select
          v-if="mode === 'read' && availableTemplates.length > 0"
          v-model="selectedTemplate"
          :options="templateOptions"
          optionLabel="label"
          optionValue="value"
          size="small"
          class="template-select"
          :title="t('template')"
          @change="onTemplateChange"
        />

        <!-- Column count selector (read mode, default layout only) -->
        <Select
          v-if="mode === 'read' && record && !hasTemplate"
          :modelValue="selectedCols"
          :options="COL_OPTIONS"
          optionLabel="label"
          optionValue="value"
          size="small"
          class="cols-select"
          :title="t('fields_columns')"
          @change="e => onColsChange(e.value)"
        />

        <template v-if="mode === 'read' && record">
          <!-- Version history button (only for existing records) -->
          <Button
            v-if="!isNew"
            :label="t('version_history')"
            icon="pi pi-history"
            size="small"
            severity="secondary"
            outlined
            @click="versionsDrawerOpen = true"
          />
          <Button
            v-if="!isNew && record.metadata?.can_add"
            :label="t('duplicate')"
            icon="pi pi-copy"
            size="small"
            severity="secondary"
            outlined
            :loading="duplicating"
            @click="duplicateRecord"
          />
          <Button
            v-if="record.metadata?.can_edit"
            :label="t('edit')"
            icon="pi pi-pencil"
            size="small"
            @click="enterEditMode"
          />
          <Button
            v-if="record.metadata?.can_delete"
            :label="t('delete')"
            icon="pi pi-trash"
            size="small"
            severity="danger"
            outlined
            @click="confirmDelete"
          />
        </template>

        <template v-if="mode === 'edit'">
          <Button
            :label="t('save')"
            icon="pi pi-check"
            size="small"
            severity="primary"
            :loading="saving"
            @click="saveRecord"
          />
          <Button
            :label="t('cancel')"
            icon="pi pi-times"
            size="small"
            severity="secondary"
            text
            @click="cancelEdit"
          />
        </template>
      </div>
    </div>

    <!-- ── Loading ─────────────────────────────────────────────────── -->
    <div v-if="loading" class="record-loading">
      <ProgressSpinner />
    </div>

    <!-- ── Error ───────────────────────────────────────────────────── -->
    <Message v-else-if="fetchError" severity="error" class="record-error">
      {{ fetchError }}
    </Message>

    <!-- ── Content ─────────────────────────────────────────────────── -->
    <div v-else-if="record" class="record-content" :class="{ 'has-sidebar': hasRightColumn }">

      <!-- ── Left: main data ─────────────────────────────────────── -->
      <div class="record-main">

        <!-- Files (always first, before fields, for visibility) -->
        <fieldset
          v-if="record.files?.length || mode === 'edit'"
          class="record-section"
        >
          <legend>{{ t('files') }}</legend>
          <FileGallery
            :files="record.files ?? []"
            :editMode="mode === 'edit'"
            :recordTb="record.metadata.tb_id"
            :recordId="id"
            @file-uploaded="onFileUploaded"
            @file-deleted="onFileDeleted"
            @files-reordered="onFilesReordered"
          />
        </fieldset>

        <!-- Template-driven layout (when a template is active) -->
        <template v-if="hasTemplate">
          <TemplateSection
            v-for="(section, idx) in record.schema.template.sections"
            :key="idx"
            :section="section"
            :mode="mode"
            :tb="record.metadata.tb_id"
            :record="record"
            :schema="record.schema"
            :edit-data="editData"
          />
        </template>

        <!-- Default layout (no template) -->
        <template v-else>
          <!-- Core fields -->
          <fieldset class="record-section">
            <legend>{{ record.metadata.tb_label }}</legend>
            <div class="fields-grid" :style="fieldsGridStyle">
              <div
                v-for="fld in visibleCoreFields"
                :key="fld.name"
                class="field-cell"
              >
                <FieldDisplay
                  v-if="mode === 'read'"
                  :schema="fld"
                  :value="record.core[fld.name]"
                />
                <FieldEditor
                  v-else
                  :schema="fld"
                  :tb="record.metadata.tb_id"
                  :modelValue="editData.core[fld.name]"
                  @update:modelValue="v => editData.core[fld.name] = v"
                />
              </div>
            </div>
          </fieldset>

          <!-- Plugin sections -->
          <PluginSection
            v-for="(plg, plgTb) in record.plugins"
            :key="plgTb"
            :schema="record.schema?.plugins?.[plgTb]"
            :plugin="plg"
            :mode="mode"
            :edit-rows="editData.plugins[plgTb] ?? []"
            @update:edit-rows="v => editData.plugins[plgTb] = v"
          />
        </template>

        <!-- Fuzzy date / Chronology plugin -->
        <ChronoSection
          v-if="record.schema?.has_fuzzy_date"
          v-bind="chronoData"
          :editMode="mode === 'edit'"
          @update:chrono="v => Object.assign(editData.core, v)"
        />

        <!-- Osteology plugin -->
        <OsteologySection
          v-if="record.schema?.has_osteology"
          :modelValue="osteoData"
          :editMode="mode === 'edit'"
          @update:modelValue="v => editData.core.osteo_data = v"
        />

        <!-- Chronological distribution of related records -->
        <ChronoDensityPanel
          v-if="!isNew"
          :tb="record.metadata.tb_id"
          :id="id"
        />

        <!-- Bibliography (Zotero) -->
        <ZoteroSection
          v-if="record.schema?.has_zotero && (hasBibliography || mode === 'edit')"
          :bibliography="record.bibliography ?? {}"
          :editMode="mode === 'edit'"
          :recordTb="record.metadata.tb_id"
          :recordId="id"
          @bib-added="onBibAdded"
          @bib-deleted="onBibDeleted"
        />

        <!-- Stratigraphic Relations (RS) — only when RS plugin is enabled -->
        <RsSection
          v-if="record.schema?.rs && !isNew"
          :rs="record.rs ?? {}"
          :schema="record.schema"
          :core="record.core"
          :mode="mode"
          :tb="record.metadata.tb_id"
          :record_id="id"
          @rs-updated="fetchRecord"
        />

      </div>

      <!-- ── Right: service strip (links + geodata only) ──────────── -->
      <div v-if="hasRightColumn" class="record-sidebar">

        <!-- Links & Backlinks (count-based, from table config) -->
        <fieldset
          v-if="hasLinks"
          class="record-section"
        >
          <legend>{{ t('linked_records') }}</legend>
          <ul class="links-list">
            <li v-for="(link, linkTb) in record.links" :key="linkTb">
              <router-link :to="`/${route.params.app}/data?tb=${linkTb}&filter=${encodeURIComponent(JSON.stringify(link.filter))}`">
                {{ link.tb_label }}
                <Tag :value="String(link.tot)" severity="secondary" rounded />
              </router-link>
            </li>
            <li v-for="(bl, blTb) in record.backlinks" :key="'bl_' + blTb">
              <router-link :to="`/${route.params.app}/data?tb=${bl.tb_id}&filter=${encodeURIComponent(JSON.stringify(bl.filter))}`">
                ← {{ bl.tb_label }}
                <Tag :value="String(bl.tot)" severity="secondary" rounded />
              </router-link>
            </li>
          </ul>
        </fieldset>

        <!-- Manual links (individual record-to-record links via userlinks table) -->
        <ManualLinksSection
          v-if="hasManualLinks || mode === 'edit'"
          :links="record.manualLinks ?? {}"
          :editMode="mode === 'edit'"
          :recordTb="record.metadata.tb_id"
          :recordId="id"
          :recordLabel="recordTitle"
          @link-added="onLinkAdded"
          @link-deleted="onLinkDeleted"
        />

        <!-- Geodata -->
        <fieldset
          v-if="record.schema?.has_geodata && (hasGeodata || mode === 'edit')"
          class="record-section"
        >
          <legend>{{ t('geodata') }}</legend>
          <div class="geodata-info">
            <i class="pi pi-map-marker" />
            {{ t('geodata_count', geodataCount) }}
          </div>
        </fieldset>

      </div>

    </div>

  </div>

  <!-- ── Plugin-delete confirmation dialog ───────────────────────── -->
  <Dialog
    v-model:visible="pluginDeleteDialog.visible"
    :header="t('delete')"
    :modal="true"
    :closable="true"
    :style="{ width: '420px' }"
  >
    <div class="plugin-delete-body">
      <i class="pi pi-exclamation-triangle plugin-delete-icon" />
      <p>{{ t('confirm_delete_record_with_plugins') }}</p>
      <ul class="plugin-delete-list">
        <li v-for="plg in pluginDeleteDialog.plugins" :key="plg.tb">
          <strong>{{ plg.label }}</strong>
          <span class="plugin-delete-count">{{ plg.count }}</span>
        </li>
      </ul>
      <p class="plugin-delete-hint">{{ t('confirm_delete_plugins_hint') }}</p>
    </div>
    <template #footer>
      <Button :label="t('cancel')" severity="secondary" text @click="pluginDeleteDialog.visible = false" />
      <Button
        :label="t('delete_plugins_and_record')"
        icon="pi pi-trash"
        severity="danger"
        :loading="pluginDeleteDialog.deleting"
        @click="doDeleteWithPlugins"
      />
    </template>
  </Dialog>

  <!-- ── Version history drawer ──────────────────────────────────── -->
  <RecordVersionsDrawer
    v-if="record && !isNew"
    v-model="versionsDrawerOpen"
    :tb="record.metadata.tb_id"
    :recordId="id"
    :fieldSchemas="record.schema?.fields ?? []"
    @restored="fetchRecord"
  />

  </AppLayout>
</template>

<script setup>
import { ref, computed, watch, reactive, onMounted, onUnmounted, provide } from 'vue'
import { useRoute, useRouter, onBeforeRouteLeave } from 'vue-router'
import { useToast }              from 'primevue/usetoast'
import { useConfirm }            from 'primevue/useconfirm'
import AppLayout      from '@/components/AppLayout.vue'
import Button         from 'primevue/button'
import Dialog         from 'primevue/dialog'
import Tag            from 'primevue/tag'
import Message        from 'primevue/message'
import ProgressSpinner from 'primevue/progressspinner'
import Select          from 'primevue/select'
import { api }        from '@/api'
import { useI18n }    from '@/i18n'
import FieldDisplay    from '@/components/record/FieldDisplay.vue'
import FieldEditor     from '@/components/record/FieldEditor.vue'
import PluginSection   from '@/components/record/PluginSection.vue'
import TemplateSection    from '@/components/record/TemplateSection.vue'
import FileGallery        from '@/components/record/FileGallery.vue'
import RsSection              from '@/components/record/RsSection.vue'
import ManualLinksSection     from '@/components/record/ManualLinksSection.vue'
import ZoteroSection          from '@/components/record/ZoteroSection.vue'
import ChronoSection          from '@/components/record/ChronoSection.vue'
import OsteologySection       from '@/components/record/osteology/OsteologySection.vue'
import ChronoDensityPanel     from '@/components/record/ChronoDensityPanel.vue'
import RecordVersionsDrawer   from '@/components/record/RecordVersionsDrawer.vue'

const { t }   = useI18n()
const route   = useRoute()
const router  = useRouter()
const toast   = useToast()
const confirm = useConfirm()
const { responseMessage } = api

// ── Route params ────────────────────────────────────────────────
const tb = computed(() => route.params.tb)
const id = computed(() => route.params.id === 'new' ? null : route.params.id)
const isNew = computed(() => !id.value)

/**
 * Where the back-link returns to.
 * If DataView passed ?back=<fullPath> (including filter params), honour it.
 * Otherwise fall back to the plain table view.
 */
const backTarget = computed(() => {
  const back = route.query.back
  if (back) return back
  return `/${route.params.app}/data?tb=${tb.value}`
})

// ── State ────────────────────────────────────────────────────────
const record       = ref(null)
const mode         = ref('read')
const loading      = ref(false)
const saving       = ref(false)
const fetchError   = ref(null)
const pendingEditMode = ref(false)
const versionsDrawerOpen = ref(false)
const duplicating        = ref(false)

/** Set to true on a failed save attempt so all FieldEditors show their errors */
const forceValidate = ref(false)
provide('forceValidate', forceValidate)

// Reactive edit data: flat values only (not the full {name,label,val} objects)
const editData = reactive({ core: {}, plugins: {} })

// Chrono values: in edit mode use flat editData; in read mode unwrap record.core
const chronoData = computed(() => {
  if (mode.value === 'edit') {
    return {
      from:      editData.core.chrono_from      ?? null,
      to:        editData.core.chrono_to        ?? null,
      label:     editData.core.chrono_label     ?? null,
      certainty: editData.core.chrono_certainty ?? null,
      period:    editData.core.chrono_period    ?? null,
    }
  }
  const c = record.value?.core ?? {}
  return {
    from:      c.chrono_from?.val      ?? null,
    to:        c.chrono_to?.val        ?? null,
    label:     c.chrono_label?.val     ?? null,
    certainty: c.chrono_certainty?.val ?? null,
    period:    c.chrono_period?.val    ?? null,
  }
})

// Osteology data: in edit mode use flat editData; in read mode unwrap record.core
const osteoData = computed(() =>
  mode.value === 'edit'
    ? (editData.core.osteo_data ?? null)
    : (record.value?.core?.osteo_data?.val ?? null)
)

// ── Derived ─────────────────────────────────────────────────────
const CHRONO_FIELDS = new Set(['chrono_from', 'chrono_to', 'chrono_label', 'chrono_certainty', 'chrono_period'])
const OSTEO_FIELDS  = new Set(['osteo_data'])

const visibleCoreFields = computed(() =>
  (record.value?.schema?.fields ?? []).filter(f => !f.hide && !CHRONO_FIELDS.has(f.name) && !OSTEO_FIELDS.has(f.name))
)

const recordTitle = computed(() => {
  if (!record.value) return null
  const idFld = record.value.metadata?.id_field
  if (!idFld || !record.value.core?.[idFld]) return null
  const v = record.value.core[idFld]
  const raw = v?.val_label ?? v?.val
  return raw != null ? String(raw) : null
})

const hasLinks = computed(() =>
  Object.keys(record.value?.links ?? {}).length > 0 ||
  Object.keys(record.value?.backlinks ?? {}).length > 0
)

// Manual links
const hasManualLinks = computed(() =>
  Object.keys(record.value?.manualLinks ?? {}).length > 0
)

// Bibliography (Zotero)
const hasBibliography = computed(() =>
  Object.keys(record.value?.bibliography ?? {}).length > 0
)

const hasGeodata = computed(() => {
  const g = record.value?.geodata
  return g && (Array.isArray(g) ? g.length > 0 : Object.keys(g).length > 0)
})

// True only when the user has actually modified at least one value since entering
// edit mode — used to avoid false-positive "unsaved changes" warnings.
const hasActualChanges = computed(() => {
  if (!record.value || mode.value !== 'edit') return false
  // Core fields
  for (const [fld, val] of Object.entries(editData.core)) {
    if (val !== (record.value.core[fld]?.val ?? null)) return true
  }
  // Plugin rows: any new/deleted row is a definite change
  for (const [plgTb, rows] of Object.entries(editData.plugins)) {
    if (rows.some(r => r._isNew || r._delete)) return true
    const origData = record.value.plugins[plgTb]?.data ?? {}
    const origRows = Array.isArray(origData) ? origData : Object.values(origData)
    for (const row of rows) {
      const orig = origRows.find(r => String(r.id?.val) === String(row.id))
      if (!orig) return true
      for (const [k, v] of Object.entries(row.fields)) {
        if (v !== (orig[k]?.val ?? null)) return true
      }
    }
  }
  return false
})

const hasRightColumn = computed(() => {
  if (!record.value) return false
  const r = record.value
  const m = mode.value
  return hasLinks.value
    || hasManualLinks.value || m === 'edit'
    || (r.schema?.has_geodata && (hasGeodata.value || m === 'edit'))
})
const geodataCount = computed(() => {
  const g = record.value?.geodata
  if (!g) return 0
  return Array.isArray(g) ? g.length : Object.keys(g).length
})

/** True when the loaded record includes a valid resolved template. */
const hasTemplate = computed(() => !!record.value?.schema?.template)

// ── Column count preference ───────────────────────────────────────
const COL_OPTIONS = [
  { label: '2 col.',  value: 2    },
  { label: '3 col.',  value: 3    },
  { label: '4 col.',  value: 4    },
  { label: 'Auto',    value: null },
]

/**
 * When a fixed column count is selected, override grid-template-columns
 * directly with repeat(N, 1fr) — exact and gap-safe.
 * When Auto (null), leave blank so the CSS auto-fill rule applies.
 */
const fieldsGridStyle = computed(() =>
  selectedCols.value !== null
    ? { 'grid-template-columns': `repeat(${selectedCols.value}, 1fr)` }
    : {}
)

function colsStorageKey(tbName) { return `bradypus:cols:${tbName}` }

/** null = Auto (current browser-driven auto-fill behaviour) */
const selectedCols = ref(3)

function loadColsPreference() {
  if (!tb.value) return
  const saved = localStorage.getItem(colsStorageKey(tb.value))
  if (saved === null) {
    selectedCols.value = 3   // sensible default
    return
  }
  selectedCols.value = saved === 'null' ? null : Number(saved)
}

function onColsChange(val) {
  selectedCols.value = val
  if (val === null) {
    localStorage.removeItem(colsStorageKey(tb.value))
  } else {
    localStorage.setItem(colsStorageKey(tb.value), String(val))
  }
}

// ── Template selection ────────────────────────────────────────────
const availableTemplates = ref([])   // string[] — names returned by getTemplates

function tplStorageKey(tbName) { return `bradypus:template:${tbName}` }

const selectedTemplate = ref(null)   // currently chosen template name or null

const templateOptions = computed(() => [
  { label: t('default_layout'), value: null },
  ...availableTemplates.value.map(name => ({ label: name, value: name })),
])

async function loadAvailableTemplates() {
  if (!tb.value) return
  try {
    const res = await api.get(`/api/record/${tb.value}/templates`)
    availableTemplates.value = res.templates ?? []
    // Restore saved preference for this table
    const saved = localStorage.getItem(tplStorageKey(tb.value))
    if (saved && availableTemplates.value.includes(saved)) {
      selectedTemplate.value = saved
    } else {
      selectedTemplate.value = null
    }
  } catch { /* non-fatal */ }
}

function onTemplateChange(e) {
  const val = e.value
  if (val) {
    localStorage.setItem(tplStorageKey(tb.value), val)
  } else {
    localStorage.removeItem(tplStorageKey(tb.value))
  }
  fetchRecord()
}

// ── Load record ─────────────────────────────────────────────────
async function fetchRecord() {
  if (!tb.value) return
  loading.value = true
  fetchError.value = null
  try {
    // Priority: selectedTemplate state > URL param (for direct links)
    const tplParam = selectedTemplate.value ?? route.query.template ?? null
    const queryParams = tplParam ? { template: tplParam } : {}

    const recordPath = id.value
      ? `/api/record/${tb.value}/${id.value}`
      : `/api/record/${tb.value}/new`
    const res = await api.get(recordPath, queryParams)

    if (res.status === 'error') {
      fetchError.value = responseMessage(res, t)
      return
    }
    record.value = res

    // Warn if a template was requested but could not be loaded/validated
    if (res.schema?.template_errors) {
      toast.add({
        severity: 'warn',
        summary:  t('template') ?? 'Template',
        detail:   res.schema.template_errors[0],
        life:     5000,
      })
    }

    // If add_new, enter edit mode immediately; or re-enter after CMD+S on a new record
    if (isNew.value) enterEditMode()
    else if (pendingEditMode.value) { pendingEditMode.value = false; enterEditMode() }

  } catch (e) {
    fetchError.value = e.message
  } finally {
    loading.value = false
  }
}

// ── Edit mode ────────────────────────────────────────────────────
function enterEditMode() {
  if (!record.value) return

  // Populate editData.core with current values
  Object.keys(record.value.core).forEach(fld => {
    editData.core[fld] = record.value.core[fld]?.val ?? null
  })

  // Populate editData.plugins
  Object.keys(record.value.plugins).forEach(plgTb => {
    const plgData = record.value.plugins[plgTb]?.data ?? {}
    const rows = (Array.isArray(plgData) ? plgData : Object.values(plgData)).map(row => {
      const fields = {}
      Object.keys(row).forEach(k => {
        if (!['id','table_link','id_link','sort'].includes(k)) {
          fields[k] = row[k]?.val ?? null
        }
      })
      return {
        _uid:    row.id?.val ?? Math.random(),
        _delete: false,
        _isNew:  false,
        id:      row.id?.val ?? null,
        fields,
      }
    })
    editData.plugins[plgTb] = rows
  })

  mode.value = 'edit'
}

function cancelEdit() {
  forceValidate.value = false
  mode.value = 'read'
}

// ── Validation ────────────────────────────────────────────────────

/**
 * Returns true if the given value fails the field's client-side rules.
 * Mirrors the logic in FieldEditor.vue — keep in sync.
 */
function fieldHasClientError(fld, value) {
  const check   = fld.check ?? []
  const isEmpty = value === null || value === undefined || value === ''

  if (fld.required && isEmpty)   return true
  if (isEmpty)                   return false

  if (check.includes('int')   && !/^-?\d+$/.test(String(value)))            return true
  if (check.includes('email') && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value))) return true

  if (fld.type !== 'date' && fld.min != null && Number(value) < Number(fld.min)) return true
  if (fld.type !== 'date' && fld.max != null && Number(value) > Number(fld.max)) return true
  if (fld.type === 'date'  && fld.min        && String(value) < String(fld.min))  return true
  if (fld.type === 'date'  && fld.max        && String(value) > String(fld.max))  return true

  if (fld.max_length && String(value).length > Number(fld.max_length)) return true

  if (fld.pattern) {
    try { if (!new RegExp(fld.pattern).test(String(value))) return true } catch {}
  }

  return false
}

/**
 * Collects labels of all fields that fail client-side validation.
 * Returns an empty array if everything is valid.
 */
function collectValidationErrors() {
  const bad = []

  // Core fields
  for (const fld of visibleCoreFields.value) {
    if (fieldHasClientError(fld, editData.core[fld.name])) {
      bad.push(fld.label)
    }
  }

  // Plugin fields
  for (const [plgTb, rows] of Object.entries(editData.plugins)) {
    const plgFields = record.value?.schema?.plugins?.[plgTb]?.fields ?? []
    const plgLabel  = record.value?.schema?.plugins?.[plgTb]?.label ?? plgTb
    for (const row of rows.filter(r => !r._delete)) {
      for (const fld of plgFields) {
        if (fieldHasClientError(fld, row.fields[fld.name])) {
          bad.push(`${plgLabel} → ${fld.label}`)
        }
      }
    }
  }

  return bad
}

// ── Save ─────────────────────────────────────────────────────────
async function saveRecord(keepEditMode = false) {
  // Pre-save validation
  const errors = collectValidationErrors()
  if (errors.length) {
    forceValidate.value = true
    toast.add({
      severity: 'warn',
      summary:  t('validation_error'),
      detail:   `${t('required_fields_missing')}: ${errors.join(', ')}`,
      life:     6000,
    })
    return
  }

  forceValidate.value = false
  saving.value = true
  try {
    // Build plugins payload: only rows that have changed, been deleted, or are new
    const pluginsPayload = {}
    for (const [plgTb, rows] of Object.entries(editData.plugins)) {
      const plgRows = rows.map(row => ({
        id:      row.id ?? null,
        _delete: row._delete ?? false,
        _isNew:  row._isNew  ?? false,
        fields:  row.fields  ?? {},
      }))
      if (plgRows.length) pluginsPayload[plgTb] = plgRows
    }

    const payload = {
      id:      id.value ?? null,
      core:    { ...editData.core },
      plugins: pluginsPayload,
    }

    const res = await api.post(`/api/record/${tb.value}`, payload)

    if (res.status === 'error') {
      if (res.code === 'validation_failed' && res.errors?.length) {
        // Server-side validation errors (no_dupl, valid_wkt, …) — show field list
        forceValidate.value = true
        const labels = res.errors.map(e => e.label).join(', ')
        toast.add({
          severity: 'warn',
          summary:  t('validation_error'),
          detail:   `${t('validation_server_errors')}: ${labels}`,
          life:     8000,
        })
      } else {
        toast.add({ severity: 'error', summary: t('generic_error'), detail: responseMessage(res, t), life: 5000 })
      }
      return
    }

    toast.add({ severity: 'success', summary: t('saved'), detail: t(res.code), life: 3000 })
    forceValidate.value = false

    if (!id.value && res.id) {
      // New record: navigate to the saved record; re-enter edit mode if requested
      if (keepEditMode) pendingEditMode.value = true
      router.replace(`/${route.params.app}/record/${tb.value}/${res.id}`)
    } else {
      // Existing record: reload to reflect server-side computed values
      if (!keepEditMode) mode.value = 'read'
      await fetchRecord()
      if (keepEditMode) enterEditMode()
    }
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 5000 })
  } finally {
    saving.value = false
  }
}

// ── Duplicate ─────────────────────────────────────────────────────
async function duplicateRecord() {
  duplicating.value = true
  try {
    const res = await api.post(`/api/record/${tb.value}/${id.value}/duplicate`)
    if (res.status === 'error') {
      toast.add({ severity: 'error', summary: t('generic_error'), detail: responseMessage(res, t), life: 5000 })
      return
    }
    toast.add({ severity: 'success', summary: t('duplicate'), detail: t('success_duplicated'), life: 3000 })
    router.push(`/${route.params.app}/record/${tb.value}/${res.id}`)
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 5000 })
  } finally {
    duplicating.value = false
  }
}

// ── Delete (two-step: check plugins first) ────────────────────────
const pluginDeleteDialog = reactive({
  visible:  false,
  plugins:  [],
  deleting: false,
})

async function confirmDelete() {
  try {
    const res = await api.get(`/api/record/${tb.value}/check-plugins-before-delete`, { id: id.value })
    if (res.status !== 'success') throw new Error()

    if (!res.plugins || res.plugins.length === 0) {
      // No plugin data → simple confirm
      confirm.require({
        message:  t('confirm_delete_record'),
        header:   t('delete'),
        icon:     'pi pi-exclamation-triangle',
        severity: 'danger',
        accept:   doDelete,
      })
    } else {
      // Plugin rows exist → open the enhanced dialog
      pluginDeleteDialog.plugins = res.plugins
      pluginDeleteDialog.visible = true
    }
  } catch {
    // If the check fails, fall back to simple confirm
    confirm.require({
      message:  t('confirm_delete_record'),
      header:   t('delete'),
      icon:     'pi pi-exclamation-triangle',
      severity: 'danger',
      accept:   doDelete,
    })
  }
}

async function doDelete() {
  try {
    const res = await api.delete(`/api/record/${tb.value}/${id.value}`)
    if (res.status === 'error') {
      toast.add({ severity: 'error', summary: t('generic_error'), detail: responseMessage(res, t), life: 5000 })
      return
    }
    toast.add({ severity: 'success', summary: t('delete'), detail: t('all_record_deleted'), life: 3000 })
    router.push(backTarget.value)
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 5000 })
  }
}

async function doDeleteWithPlugins() {
  pluginDeleteDialog.deleting = true
  try {
    // 1. Delete all plugin rows
    await api.delete(`/api/record/${tb.value}/${id.value}/plugins`)
    // 2. Delete the main record
    const res = await api.delete(`/api/record/${tb.value}/${id.value}`)
    pluginDeleteDialog.visible = false
    if (res.status === 'error') {
      toast.add({ severity: 'error', summary: t('generic_error'), detail: responseMessage(res, t), life: 5000 })
      return
    }
    toast.add({ severity: 'success', summary: t('delete'), detail: t('all_record_deleted'), life: 3000 })
    router.push(backTarget.value)
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 5000 })
  } finally {
    pluginDeleteDialog.deleting = false
  }
}

// ── File events (from FileGallery in edit mode) ───────────────────
/**
 * A file was successfully uploaded: add it optimistically to the local files array
 * so the gallery refreshes without a full record reload.
 */
function onFileUploaded(newFile) {
  if (record.value) {
    record.value.files = [...(record.value.files ?? []), newFile]
  }
}

/**
 * A file was successfully deleted: remove it from the local files array.
 */
function onFileDeleted(fileId) {
  if (record.value) {
    record.value.files = (record.value.files ?? []).filter(f => f.id !== fileId)
  }
}

/**
 * Files were reordered via drag-and-drop: update the local array so view-mode
 * reflects the new order immediately without a full record reload.
 */
function onFilesReordered(newFiles) {
  if (record.value) {
    record.value.files = newFiles
  }
}

// ── Manual link events (from ManualLinksSection) ──────────────────
function onLinkAdded(link) {
  if (record.value) {
    record.value.manualLinks = {
      ...(record.value.manualLinks ?? {}),
      [link.key]: link,
    }
  }
}

function onLinkDeleted(linkKey) {
  if (record.value?.manualLinks) {
    const updated = { ...record.value.manualLinks }
    delete updated[linkKey]
    record.value.manualLinks = updated
  }
}

// ── Bibliography events (from ZoteroSection) ──────────────────────
function onBibAdded(newEntry) {
  if (record.value) {
    record.value.bibliography = {
      ...(record.value.bibliography ?? {}),
      [newEntry.id]: newEntry,
    }
  }
}

function onBibDeleted(entryId) {
  if (record.value?.bibliography) {
    const updated = { ...record.value.bibliography }
    delete updated[entryId]
    record.value.bibliography = updated
  }
}

// ── Unsaved changes guard ─────────────────────────────────────────
onBeforeRouteLeave((_to, _from) => {
  if (mode.value !== 'edit' || !hasActualChanges.value) {
    return true
  }
  return new Promise((resolve) => {
    confirm.require({
      message:  t('unsaved_changes_warning'),
      header:   t('unsaved_changes'),
      icon:     'pi pi-exclamation-triangle',
      severity: 'warn',
      accept:   () => resolve(true),
      reject:   () => resolve(false),
    })
  })
})

// ── Keyboard shortcut: CMD+S / CTRL+S → save and stay in edit ────
function handleSaveShortcut(e) {
  if ((e.metaKey || e.ctrlKey) && e.key === 's' && mode.value === 'edit' && !saving.value) {
    e.preventDefault()
    saveRecord(true)
  }
}

// ── Init ──────────────────────────────────────────────────────────
onMounted(async () => {
  window.addEventListener('keydown', handleSaveShortcut)
  await loadAvailableTemplates()
  loadColsPreference()
  fetchRecord()
})

onUnmounted(() => {
  window.removeEventListener('keydown', handleSaveShortcut)
})

// Reload when route params change (navigating record→record)
// Re-load templates too if the table changes (tb is part of the route)
watch(() => route.params.tb, async () => {
  await loadAvailableTemplates()
  loadColsPreference()
  fetchRecord()
})
watch(() => route.params.id, fetchRecord)
</script>

<style scoped>
.record-view {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-height: 0;
  overflow: hidden;
}

/* ── Header ── */
.record-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.6rem 1.25rem;
  border-bottom: 1px solid var(--p-content-border-color);
  flex-shrink: 0;
  flex-wrap: wrap;
}

.record-breadcrumb {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.9rem;
}
.back-link { color: var(--p-primary-color); text-decoration: none; }
.back-link:hover { text-decoration: underline; }
.sep { color: var(--p-text-muted-color); }
.record-title-text { font-weight: 600; }
.new-badge {
  background: var(--p-highlight-background);
  color: var(--p-primary-700);
  border-radius: 4px;
  padding: 0.1rem 0.4rem;
  font-size: 0.78rem;
}

.header-actions {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  flex-wrap: wrap;
}

.template-select { width: 140px; }
.cols-select      { width: 90px; }

/* ── Loading / Error ── */
.record-loading {
  display: flex;
  justify-content: center;
  align-items: center;
  flex: 1;
}
.record-error { margin: 1rem; }

/* ── Content ── */
.record-content {
  flex: 1;
  overflow-y: auto;
  padding: 1rem 1.25rem 2rem;
  width: 100%;
  max-width: 1200px;
  margin-inline: auto;
  box-sizing: border-box;
  /* Single-column default */
  display: grid;
  grid-template-columns: 1fr;
  align-items: start;
  gap: 1rem;
}

.record-content.has-sidebar {
  grid-template-columns: 1fr 300px;
  column-gap: 1.5rem;
  row-gap: 0;
}

.record-main {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  min-width: 0;
}

.record-sidebar {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  min-width: 0;
  position: sticky;
  top: 1rem;
  max-height: calc(100dvh - 7rem);
  overflow-y: auto;
  padding-bottom: 1rem;
}

@media (max-width: 800px) {
  .record-content.has-sidebar {
    grid-template-columns: 1fr;
  }
  .record-sidebar {
    position: static;
    max-height: none;
    overflow-y: visible;
  }
}

/* ── Fields grid ── */
/*
 * Default: auto-fill at ≥280px per column.
 * A fixed column count is applied via inline style (repeat(N, 1fr))
 * which overrides this declaration — cleaner and gap-safe.
 */
.fields-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 0 1.5rem;
}

/* ── Links ── */
.links-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
.links-list a {
  color: var(--p-primary-color);
  text-decoration: none;
  display: flex;
  align-items: center;
  gap: 0.4rem;
}
.links-list a:hover { text-decoration: underline; }

/* ── Manual links ── */
.manual-links-group { margin-bottom: 0.6rem; }
.manual-links-group:last-child { margin-bottom: 0; }
.manual-links-tb-label {
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--p-text-muted-color);
  margin-bottom: 0.2rem;
}

/* ── Files: rendered by FileGallery.vue ── */

/* ── Geodata ── */
.geodata-info { display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; }

/* ── Plugin delete dialog ── */
.plugin-delete-body {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.75rem;
  text-align: center;
  padding: 0.5rem 0;
}
.plugin-delete-icon {
  font-size: 2.5rem;
  color: var(--p-orange-500);
}
.plugin-delete-list {
  list-style: none;
  padding: 0;
  margin: 0;
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
.plugin-delete-list li {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0.35rem 0.75rem;
  background: var(--p-content-hover-background);
  border-radius: 4px;
  font-size: 0.88rem;
}
.plugin-delete-count {
  font-weight: 700;
  color: var(--p-red-500);
}
.plugin-delete-hint {
  font-size: 0.8rem;
  color: var(--p-text-muted-color);
  margin: 0;
}
</style>
