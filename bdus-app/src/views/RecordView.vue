<template>
  <div class="record-view">

    <!-- ── Header ──────────────────────────────────────────────────── -->
    <div class="record-header">
      <div class="record-breadcrumb">
        <router-link to="/data" class="back-link">
          <i class="pi pi-arrow-left" />
          {{ record?.metadata?.tb_label ?? t('data') }}
        </router-link>
        <span v-if="recordTitle" class="sep">/</span>
        <span v-if="recordTitle" class="record-title-text">{{ recordTitle }}</span>
        <span v-if="isNew" class="record-title-text new-badge">{{ t('new_record') }}</span>
      </div>

      <div class="header-actions">
        <template v-if="mode === 'read' && record">
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
    <div v-else-if="record" class="record-content">

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
          <div class="fields-grid">
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

      <!-- Links & Backlinks (count-based, from table config) -->
      <fieldset
        v-if="hasLinks"
        class="record-section"
      >
        <legend>{{ t('linked_records') }}</legend>
        <ul class="links-list">
          <li v-for="(link, linkTb) in record.links" :key="linkTb">
            <router-link :to="`/data?tb=${linkTb}&where=${encodeURIComponent(link.where)}`">
              {{ link.tb_label }}
              <Tag :value="String(link.tot)" severity="secondary" rounded />
            </router-link>
          </li>
          <li v-for="(bl, blTb) in record.backlinks" :key="'bl_' + blTb">
            <router-link :to="`/data?tb=${bl.tb_id}&where=${encodeURIComponent(bl.where)}`">
              ← {{ bl.tb_label }}
              <Tag :value="String(bl.tot)" severity="secondary" rounded />
            </router-link>
          </li>
        </ul>
      </fieldset>

      <!-- Manual links (individual record-to-record links via userlinks table) -->
      <fieldset
        v-if="hasManualLinks"
        class="record-section"
      >
        <legend>{{ t('linked_records') }}</legend>
        <div
          v-for="(group, groupTb) in manualLinksByTable"
          :key="groupTb"
          class="manual-links-group"
        >
          <div class="manual-links-tb-label">{{ group.tb_label }}</div>
          <ul class="links-list">
            <li v-for="ml in group.items" :key="ml.key">
              <router-link :to="`/record/${ml.tb_id}/${ml.ref_id}`">
                {{ ml.ref_label }}
              </router-link>
            </li>
          </ul>
        </div>
      </fieldset>

      <!-- Files -->
      <fieldset
        v-if="record.files?.length"
        class="record-section"
      >
        <legend>{{ t('files') }}</legend>
        <FileGallery :files="record.files" />
      </fieldset>

      <!-- Geodata -->
      <fieldset
        v-if="hasGeodata"
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
</template>

<script setup>
import { ref, computed, watch, reactive, onMounted } from 'vue'
import { useRoute, useRouter }   from 'vue-router'
import { useToast }              from 'primevue/usetoast'
import { useConfirm }            from 'primevue/useconfirm'
import Button         from 'primevue/button'
import Tag            from 'primevue/tag'
import Message        from 'primevue/message'
import ProgressSpinner from 'primevue/progressspinner'
import { api }        from '@/api'
import { useI18n }    from '@/i18n'
import FieldDisplay    from '@/components/record/FieldDisplay.vue'
import FieldEditor     from '@/components/record/FieldEditor.vue'
import PluginSection   from '@/components/record/PluginSection.vue'
import TemplateSection from '@/components/record/TemplateSection.vue'
import FileGallery     from '@/components/record/FileGallery.vue'

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

// ── State ────────────────────────────────────────────────────────
const record     = ref(null)
const mode       = ref('read')
const loading    = ref(false)
const saving     = ref(false)
const fetchError = ref(null)

// Reactive edit data: flat values only (not the full {name,label,val} objects)
const editData = reactive({ core: {}, plugins: {} })

// ── Derived ─────────────────────────────────────────────────────
const visibleCoreFields = computed(() =>
  (record.value?.schema?.fields ?? []).filter(f => !f.hide)
)

const recordTitle = computed(() => {
  if (!record.value) return null
  const idFld = record.value.metadata?.id_field
  if (!idFld || !record.value.core?.[idFld]) return null
  const v = record.value.core[idFld]
  return (v?.val_label ?? v?.val) || null
})

const hasLinks = computed(() =>
  Object.keys(record.value?.links ?? {}).length > 0 ||
  Object.keys(record.value?.backlinks ?? {}).length > 0
)

// Manual links: individual record-to-record links stored in userlinks table.
// Backend returns an object keyed by link id; we group by target table for display.
const hasManualLinks = computed(() =>
  Object.keys(record.value?.manualLinks ?? {}).length > 0
)

const manualLinksByTable = computed(() => {
  const groups = {}
  for (const ml of Object.values(record.value?.manualLinks ?? {})) {
    if (!groups[ml.tb_id]) {
      groups[ml.tb_id] = { tb_label: ml.tb_label, items: [] }
    }
    groups[ml.tb_id].items.push(ml)
  }
  // Within each group preserve backend sort order (already sorted by sort, id)
  return groups
})

const hasGeodata = computed(() => {
  const g = record.value?.geodata
  return g && (Array.isArray(g) ? g.length > 0 : Object.keys(g).length > 0)
})
const geodataCount = computed(() => {
  const g = record.value?.geodata
  if (!g) return 0
  return Array.isArray(g) ? g.length : Object.keys(g).length
})

/** True when the loaded record includes a valid resolved template. */
const hasTemplate = computed(() => !!record.value?.schema?.template)

// ── Load record ─────────────────────────────────────────────────
async function fetchRecord() {
  if (!tb.value) return
  loading.value = true
  fetchError.value = null
  try {
    const params = { tb: tb.value }
    if (id.value) params.id = id.value
    if (route.query.template) params.template = route.query.template

    const res = await api.get('record_ctrl', 'getRecord', params)

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

    // If add_new, enter edit mode immediately
    if (isNew.value) enterEditMode()

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
  mode.value = 'read'
}

// ── Save ─────────────────────────────────────────────────────────
async function saveRecord() {
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
      tb:      tb.value,
      id:      id.value ?? null,
      core:    { ...editData.core },
      plugins: pluginsPayload,
    }

    const res = await api.post('record_ctrl', 'saveRecord', payload)

    if (res.status === 'error') {
      toast.add({ severity: 'error', summary: t('generic_error'), detail: responseMessage(res, t), life: 5000 })
      return
    }

    toast.add({ severity: 'success', summary: t('save'), detail: t(res.code), life: 3000 })
    mode.value = 'read'

    // If this was a new record, navigate to the saved record
    if (!id.value && res.id) {
      router.replace(`/record/${tb.value}/${res.id}`)
    } else {
      // Reload to reflect server-side computed values
      await fetchRecord()
    }
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 5000 })
  } finally {
    saving.value = false
  }
}

// ── Delete ────────────────────────────────────────────────────────
function confirmDelete() {
  confirm.require({
    message:  t('confirm_delete_record'),
    header:   t('delete'),
    icon:     'pi pi-exclamation-triangle',
    severity: 'danger',
    accept: doDelete,
  })
}

async function doDelete() {
  try {
    const res = await api.post('record_ctrl', 'erase', {}, {
      tb: tb.value,
      id: id.value,
    })
    if (res.status === 'error') {
      toast.add({ severity: 'error', summary: t('generic_error'), detail: responseMessage(res, t), life: 5000 })
      return
    }
    toast.add({ severity: 'success', summary: t('delete'), detail: t('all_record_deleted'), life: 3000 })
    router.push('/data')
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 5000 })
  }
}

// ── Init ──────────────────────────────────────────────────────────
onMounted(fetchRecord)

// Reload when route params change (navigating record→record)
watch(() => [route.params.tb, route.params.id], fetchRecord)
</script>

<style scoped>
.record-view {
  display: flex;
  flex-direction: column;
  height: 100%;
  overflow: hidden;
}

/* ── Header ── */
.record-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.6rem 1.25rem;
  border-bottom: 1px solid var(--p-surface-border);
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
  background: var(--p-primary-50);
  color: var(--p-primary-700);
  border-radius: 4px;
  padding: 0.1rem 0.4rem;
  font-size: 0.78rem;
}

.header-actions { display: flex; gap: 0.4rem; }

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
  padding: 1rem 1.25rem;
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

/* ── Sections ── */
.record-section {
  border: 1px solid var(--p-surface-border);
  border-radius: 6px;
  padding: 0.75rem 1rem;
  margin: 0;
}
.record-section legend {
  font-size: 0.8rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--p-text-muted-color);
  padding: 0 0.4rem;
}

/* ── Fields grid ── */
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
</style>
