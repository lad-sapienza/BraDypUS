<template>
  <div class="cfg-panel">
    <div class="cfg-panel-header">
      <h2><i class="pi pi-sitemap" /> {{ t('cfg_relations') }}</h2>
      <Button
        :label="t('add_relation')"
        icon="pi pi-plus"
        size="small"
        :disabled="showAddForm"
        @click="openAddForm"
      />
    </div>

    <div v-if="loading" class="cfg-loading-center">
      <i class="pi pi-spin pi-spinner" />
    </div>

    <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>

    <div v-if="!loading" class="cfg-rel-body">

      <!-- Help text -->
      <p class="cfg-help-text">{{ t('cfg_relations_help') }}</p>

      <!-- ─── Add / inline-edit form ──────────────────────────────── -->
      <div v-if="showAddForm || editingId !== null" class="cfg-rel-form-card">
        <div class="cfg-rel-form-title">
          {{ editingId !== null ? t('edit') : t('add_relation') }}
        </div>

        <div class="cfg-rel-form-row">
          <!-- Table A -->
          <div class="cfg-field-group" style="flex:1">
            <label>{{ t('from_table') }}</label>
            <Select
              v-model="formData.from_tb"
              :options="tableOptions"
              option-label="label"
              option-value="value"
              :placeholder="t('select_table')"
              size="small"
              @change="onFromTbChange"
            />
          </div>

          <i class="pi pi-arrows-h cfg-rel-arrow" />

          <!-- Table B -->
          <div class="cfg-field-group" style="flex:1">
            <label>{{ t('to_table') }}</label>
            <Select
              v-model="formData.to_tb"
              :options="tableOptions"
              option-label="label"
              option-value="value"
              :placeholder="t('select_table')"
              size="small"
              @change="onToTbChange"
            />
          </div>
        </div>

        <!-- Field mapping pairs -->
        <div v-if="formData.from_tb && formData.to_tb" class="cfg-rel-fld-section">
          <div class="cfg-rel-fld-header">{{ t('field_mapping') }}</div>
          <div
            v-for="(pair, idx) in formData.fld"
            :key="idx"
            class="cfg-link-pair"
          >
            <Select
              v-model="pair.my"
              :options="fieldOptionsFor(formData.from_tb)"
              option-label="label"
              option-value="value"
              :placeholder="t('my_field')"
              :show-clear="true"
              size="small"
              style="flex:1"
            />
            <i class="pi pi-arrows-h cfg-link-arrow" />
            <Select
              v-model="pair.other"
              :options="fieldOptionsFor(formData.to_tb)"
              option-label="label"
              option-value="value"
              :placeholder="t('other_field')"
              :show-clear="true"
              size="small"
              style="flex:1"
            />
            <Button
              icon="pi pi-minus"
              severity="danger"
              size="small"
              text
              @click="formData.fld.splice(idx, 1)"
            />
          </div>
          <Button
            :label="t('add')"
            icon="pi pi-plus"
            size="small"
            text
            @click="formData.fld.push({ my: '', other: '' })"
          />
        </div>

        <!-- Form actions -->
        <div class="cfg-rel-form-actions">
          <Button
            :label="t('cancel')"
            severity="secondary"
            size="small"
            text
            @click="cancelForm"
          />
          <Button
            :label="t('save')"
            icon="pi pi-save"
            size="small"
            :loading="saving"
            :disabled="!formData.from_tb || !formData.to_tb"
            @click="saveRelation"
          />
        </div>
      </div>

      <!-- ─── Relations list ──────────────────────────────────────── -->
      <div v-if="relations.length === 0 && !showAddForm" class="cfg-empty-msg">
        {{ t('no_relations') }}
      </div>

      <div
        v-for="rel in relations"
        :key="rel.id"
        class="cfg-rel-card"
        :class="{ 'cfg-rel-card--editing': editingId === rel.id }"
      >
        <div class="cfg-rel-card-body">
          <!-- Table pair -->
          <div class="cfg-rel-pair">
            <span class="cfg-rel-tb">{{ rel.from_label || rel.from_tb }}</span>
            <i class="pi pi-arrows-h cfg-rel-arrow" />
            <span class="cfg-rel-tb">{{ rel.to_label || rel.to_tb }}</span>
          </div>

          <!-- Field mapping badges -->
          <div v-if="rel.fld && rel.fld.length" class="cfg-rel-fld-badges">
            <span
              v-for="(pair, idx) in rel.fld"
              :key="idx"
              class="cfg-rel-fld-badge"
            >
              {{ pair.my }} ↔ {{ pair.other }}
            </span>
          </div>
        </div>

        <!-- Row actions -->
        <div class="cfg-rel-card-actions">
          <Button
            icon="pi pi-pencil"
            severity="secondary"
            size="small"
            text
            :title="t('edit')"
            :disabled="showAddForm || (editingId !== null && editingId !== rel.id)"
            @click="startEdit(rel)"
          />
          <Button
            icon="pi pi-trash"
            severity="danger"
            size="small"
            text
            :title="t('delete')"
            :loading="deletingId === rel.id"
            :disabled="showAddForm || editingId !== null"
            @click="deleteRelation(rel)"
          />
        </div>
      </div>

    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import Button  from 'primevue/button'
import Select  from 'primevue/select'
import Message from 'primevue/message'
import { useToast }   from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import { useI18n }    from '@/i18n'
import { api }        from '@/api'
import { useConfigStore } from '@/stores/config'

const { t }   = useI18n()
const toast   = useToast()
const confirm = useConfirm()
const store   = useConfigStore()

// ── State ──────────────────────────────────────────────────────────────────
const loading    = ref(false)
const saving     = ref(false)
const deletingId = ref(null)
const error      = ref(null)

const relations  = ref([])

// Form state
const showAddForm = ref(false)
const editingId   = ref(null)       // id of the relation being edited, or null
const formData    = ref(emptyForm())

// Cache for per-table field lists { tbName: [{ value, label }, …] }
const tableFields = ref({})

// ── Derived ────────────────────────────────────────────────────────────────
const tableOptions = computed(() =>
  (store.tables ?? []).map(tb => ({ value: tb.name, label: tb.label || tb.name }))
)

function fieldOptionsFor(tbName) {
  return tableFields.value[tbName] ?? []
}

// ── Helpers ────────────────────────────────────────────────────────────────
function emptyForm() {
  return { from_tb: '', to_tb: '', fld: [{ my: '', other: '' }] }
}

async function fetchFields(tbName) {
  if (!tbName || tableFields.value[tbName]) return
  try {
    const res = await api.get(`/api/config/table/${tbName}/fields`)
    if (res.status === 'success' || res.code === 'ok') {
      const map = res.fields ?? {}
      tableFields.value = {
        ...tableFields.value,
        [tbName]: Object.entries(map).map(([k, v]) => ({ value: k, label: v }))
      }
    }
  } catch { /* silently ignore */ }
}

async function onFromTbChange() {
  await fetchFields(formData.value.from_tb)
}

async function onToTbChange() {
  await fetchFields(formData.value.to_tb)
}

// ── Load ───────────────────────────────────────────────────────────────────
async function load() {
  loading.value = true
  error.value   = null
  try {
    const res = await api.get('/api/config/relations')
    if (res.status === 'error') throw new Error(t(res.code))
    relations.value = res.data ?? []
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

// ── Add form ───────────────────────────────────────────────────────────────
function openAddForm() {
  editingId.value  = null
  formData.value   = emptyForm()
  showAddForm.value = true
}

function cancelForm() {
  showAddForm.value = false
  editingId.value   = null
  formData.value    = emptyForm()
}

// ── Edit ───────────────────────────────────────────────────────────────────
async function startEdit(rel) {
  showAddForm.value = false
  editingId.value   = rel.id
  formData.value = {
    from_tb: rel.from_tb,
    to_tb:   rel.to_tb,
    fld:     rel.fld?.length
      ? rel.fld.map(p => ({ my: p.my ?? '', other: p.other ?? '' }))
      : [{ my: '', other: '' }],
  }
  // Pre-fetch field lists so dropdowns are ready
  await Promise.all([fetchFields(rel.from_tb), fetchFields(rel.to_tb)])
}

// ── Save ───────────────────────────────────────────────────────────────────
async function saveRelation() {
  saving.value = true
  try {
    const fld = formData.value.fld.filter(p => p.my || p.other)
    const body = {
      from_tb: formData.value.from_tb,
      to_tb:   formData.value.to_tb,
      fld,
    }

    const res = editingId.value !== null
      ? await api.put(`/api/config/relations/${editingId.value}`, body)
      : await api.post('/api/config/relations', body)

    toast.add({
      severity: res.status === 'success' ? 'success' : 'error',
      summary:  t(res.status === 'success' ? 'relation_saved' : 'error'),
      detail:   api.responseMessage(res, t),
      life: 4000
    })

    if (res.status === 'success') {
      cancelForm()
      await load()
    }
  } catch (e) {
    toast.add({ severity: 'error', summary: e.message, life: 4000 })
  } finally {
    saving.value = false
  }
}

// ── Delete ─────────────────────────────────────────────────────────────────
function deleteRelation(rel) {
  confirm.require({
    message:  `${rel.from_label || rel.from_tb} ↔ ${rel.to_label || rel.to_tb}`,
    header:   t('delete'),
    icon:     'pi pi-exclamation-triangle',
    severity: 'danger',
    accept:   () => doDelete(rel.id),
  })
}

async function doDelete(id) {
  deletingId.value = id
  try {
    const res = await api.delete(`/api/config/relations/${id}`)
    toast.add({
      severity: res.status === 'success' ? 'success' : 'error',
      summary:  t(res.status === 'success' ? 'relation_deleted' : 'error'),
      detail:   api.responseMessage(res, t),
      life: 4000
    })
    if (res.status === 'success') {
      relations.value = relations.value.filter(r => r.id !== id)
    }
  } catch (e) {
    toast.add({ severity: 'error', summary: e.message, life: 4000 })
  } finally {
    deletingId.value = null
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
.cfg-loading-center {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 2rem;
  color: var(--p-text-muted-color);
}
.cfg-rel-body {
  flex: 1;
  overflow-y: auto;
  padding: 1rem 1.25rem;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}
.cfg-help-text {
  font-size: 0.8rem;
  color: var(--p-text-muted-color);
  margin: 0;
}

/* ── Add / edit form card ──────────────────────────────── */
.cfg-rel-form-card {
  border: 1px solid var(--p-primary-color);
  border-radius: 6px;
  padding: 0.875rem;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  background: var(--bdus-bg);
}
.cfg-rel-form-title {
  font-size: 0.78rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--p-primary-color);
}
.cfg-rel-form-row {
  display: flex;
  align-items: flex-end;
  gap: 0.75rem;
}
.cfg-rel-arrow {
  font-size: 0.9rem;
  color: var(--p-text-muted-color);
  flex-shrink: 0;
  padding-bottom: 0.35rem;  /* align with input baseline */
}
.cfg-field-group {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}
.cfg-field-group label {
  font-size: 0.78rem;
  font-weight: 500;
  color: var(--p-text-muted-color);
}
.cfg-rel-fld-section {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  padding-left: 0.5rem;
  border-left: 2px solid var(--p-content-border-color);
}
.cfg-rel-fld-header {
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--p-text-muted-color);
  text-transform: uppercase;
  letter-spacing: 0.05em;
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
.cfg-rel-form-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.4rem;
  padding-top: 0.25rem;
  border-top: 1px solid var(--p-content-border-color);
}

/* ── Relation cards ────────────────────────────────────── */
.cfg-rel-card {
  border: 1px solid var(--p-content-border-color);
  border-radius: 6px;
  padding: 0.6rem 0.75rem;
  display: flex;
  align-items: center;
  gap: 0.75rem;
  background: var(--bdus-bg);
  transition: border-color 0.15s;
}
.cfg-rel-card:hover { border-color: var(--p-primary-color); }
.cfg-rel-card--editing { border-color: var(--p-primary-color); }

.cfg-rel-card-body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
.cfg-rel-pair {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.88rem;
  font-weight: 600;
}
.cfg-rel-tb {
  max-width: 180px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.cfg-rel-fld-badges {
  display: flex;
  flex-wrap: wrap;
  gap: 0.3rem;
}
.cfg-rel-fld-badge {
  font-size: 0.72rem;
  color: var(--p-text-muted-color);
  background: var(--p-content-hover-background);
  border-radius: 3px;
  padding: 0.1rem 0.4rem;
  font-family: monospace;
}

.cfg-rel-card-actions {
  display: flex;
  gap: 0.1rem;
  flex-shrink: 0;
  opacity: 0;
  transition: opacity 0.12s;
}
.cfg-rel-card:hover .cfg-rel-card-actions,
.cfg-rel-card--editing .cfg-rel-card-actions { opacity: 1; }

.cfg-empty-msg {
  text-align: center;
  padding: 2rem 1rem;
  color: var(--p-text-muted-color);
  font-size: 0.9rem;
}
</style>
