<template>
  <div class="cfg-panel">
    <div class="cfg-panel-header">
      <h2><i class="pi pi-sitemap" /> {{ t('cfg_relations') }}</h2>
      <div class="cfg-panel-header-actions">
        <Button
          :label="t('apply_all_constraints')"
          icon="pi pi-bolt"
          size="small"
          severity="secondary"
          :loading="applying"
          @click="applyAll"
        />
        <Button
          :label="t('add_relation')"
          icon="pi pi-plus"
          size="small"
          :disabled="showAddForm"
          @click="openAddForm"
        />
      </div>
    </div>

    <div v-if="loading" class="cfg-loading-center">
      <i class="pi pi-spin pi-spinner" />
    </div>

    <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>

    <div v-if="!loading" class="cfg-rel-body">
      <p class="cfg-help-text">{{ t('cfg_relations_help') }}</p>

      <!-- ─── Add / inline-edit form ──────────────────────────────────── -->
      <div v-if="showAddForm || editingId !== null" class="cfg-rel-form-card">
        <div class="cfg-rel-form-title">
          {{ editingId !== null ? t('edit') : t('add_relation') }}
        </div>

        <!-- Row 1: from_tb.from_col → to_tb.to_col -->
        <div class="cfg-rel-form-row">
          <!-- from_tb -->
          <div class="cfg-field-group" style="flex:1">
            <label>{{ t('fk_from_table') }}</label>
            <Select
              v-model="formData.from_tb"
              :options="tableOptions"
              option-label="label"
              option-value="value"
              :placeholder="t('select_table')"
              size="small"
              :disabled="editingId !== null"
              @change="onFromTbChange"
            />
          </div>

          <!-- from_col -->
          <div class="cfg-field-group" style="flex:1">
            <label>{{ t('fk_from_col') }}</label>
            <Select
              v-model="formData.from_col"
              :options="fieldOptionsFor(formData.from_tb)"
              option-label="label"
              option-value="value"
              :placeholder="t('select_field')"
              size="small"
              :disabled="editingId !== null || !formData.from_tb"
            />
          </div>

          <i class="pi pi-arrow-right cfg-rel-arrow" />

          <!-- to_tb -->
          <div class="cfg-field-group" style="flex:1">
            <label>{{ t('fk_to_table') }}</label>
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

          <!-- to_col -->
          <div class="cfg-field-group" style="flex:1">
            <label>{{ t('fk_to_col') }}</label>
            <Select
              v-model="formData.to_col"
              :options="fieldOptionsFor(formData.to_tb)"
              option-label="label"
              option-value="value"
              :placeholder="t('select_field')"
              size="small"
              :disabled="!formData.to_tb"
            />
          </div>
        </div>

        <!-- Row 2: on_delete / on_update — disabled when self-referential -->
        <div class="cfg-rel-form-row">
          <div class="cfg-field-group" style="flex:1">
            <label>{{ t('on_delete') }}</label>
            <Select
              v-model="formData.on_delete"
              :options="fkPolicies"
              option-label="label"
              option-value="value"
              size="small"
              :disabled="isSelfRef"
            />
          </div>
          <div class="cfg-field-group" style="flex:1">
            <label>{{ t('on_update') }}</label>
            <Select
              v-model="formData.on_update"
              :options="fkPolicies"
              option-label="label"
              option-value="value"
              size="small"
              :disabled="isSelfRef"
            />
          </div>
          <div v-if="isSelfRef" class="cfg-self-ref-note">
            <i class="pi pi-info-circle" /> {{ t('self_ref_policy_fixed') }}
          </div>
        </div>

        <!-- Form actions -->
        <div class="cfg-rel-form-actions">
          <Button :label="t('cancel')" severity="secondary" size="small" text @click="cancelForm" />
          <Button
            :label="t('save')"
            icon="pi pi-save"
            size="small"
            :loading="saving"
            :disabled="!formData.from_tb || !formData.from_col || !formData.to_tb || !formData.to_col"
            @click="saveRelation"
          />
        </div>
      </div>

      <!-- ─── Empty state ──────────────────────────────────────────────── -->
      <div v-if="relations.length === 0 && !showAddForm" class="cfg-empty-msg">
        {{ t('no_relations') }}
      </div>

      <!-- ─── Relations list ────────────────────────────────────────────── -->
      <div
        v-for="rel in relations"
        :key="rel.id"
        class="cfg-rel-card"
        :class="{ 'cfg-rel-card--editing': editingId === rel.id }"
      >
        <div class="cfg-rel-card-body">
          <!-- from_tb.from_col → to_tb.to_col -->
          <div class="cfg-rel-pair">
            <span class="cfg-rel-tb">{{ rel.from_label || rel.from_tb }}</span>
            <span class="cfg-rel-col">.{{ rel.from_col }}</span>
            <i class="pi pi-arrow-right cfg-rel-arrow-sm" />
            <span class="cfg-rel-tb">{{ rel.to_label || rel.to_tb }}</span>
            <span class="cfg-rel-col">.{{ rel.to_col }}</span>
          </div>

          <!-- Policy badges -->
          <div class="cfg-rel-policies">
            <span class="cfg-rel-policy-badge" :class="policyClass(rel.on_delete)">
              DEL {{ rel.on_delete }}
            </span>
            <span class="cfg-rel-policy-badge" :class="policyClass(rel.on_update)">
              UPD {{ rel.on_update }}
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
const applying   = ref(false)
const deletingId = ref(null)
const error      = ref(null)
const relations  = ref([])

const showAddForm = ref(false)
const editingId   = ref(null)
const formData    = ref(emptyForm())
const tableFields = ref({})

// ── Constants ──────────────────────────────────────────────────────────────
const fkPolicies = [
  { value: 'RESTRICT',  label: 'RESTRICT'  },
  { value: 'CASCADE',   label: 'CASCADE'   },
  { value: 'SET NULL',  label: 'SET NULL'  },
  { value: 'NO ACTION', label: 'NO ACTION' },
]

// ── Derived ────────────────────────────────────────────────────────────────
const tableOptions = computed(() =>
  (store.tables ?? []).map(tb => ({ value: tb.name, label: tb.label || tb.name }))
)

const isSelfRef = computed(() =>
  !!formData.value.from_tb && formData.value.from_tb === formData.value.to_tb
)

function fieldOptionsFor(tbName) {
  return tableFields.value[tbName] ?? []
}

function policyClass(policy) {
  if (policy === 'CASCADE')   return 'cfg-rel-policy--cascade'
  if (policy === 'RESTRICT')  return 'cfg-rel-policy--restrict'
  if (policy === 'SET NULL')  return 'cfg-rel-policy--setnull'
  return ''
}

// ── Helpers ────────────────────────────────────────────────────────────────
function emptyForm() {
  return { from_tb: '', from_col: '', to_tb: '', to_col: '', on_delete: 'RESTRICT', on_update: 'CASCADE' }
}

async function fetchFields(tbName) {
  if (!tbName || tableFields.value[tbName]) return
  try {
    const res = await api.get(`/api/config/table/${tbName}/fields`)
    if (res.status === 'success' || res.code === 'ok') {
      const map = res.fields ?? {}
      tableFields.value = {
        ...tableFields.value,
        [tbName]: Object.entries(map).map(([k, v]) => ({ value: k, label: `${k} — ${v}` }))
      }
    }
  } catch { /* silently ignore */ }
}

async function onFromTbChange() {
  formData.value.from_col = ''
  await fetchFields(formData.value.from_tb)
}

async function onToTbChange() {
  formData.value.to_col = ''
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
  editingId.value   = null
  formData.value    = emptyForm()
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
    from_tb:   rel.from_tb,
    from_col:  rel.from_col,
    to_tb:     rel.to_tb,
    to_col:    rel.to_col,
    on_delete: rel.on_delete ?? 'RESTRICT',
    on_update: rel.on_update ?? 'CASCADE',
  }
  await Promise.all([fetchFields(rel.from_tb), fetchFields(rel.to_tb)])
}

// ── Save ───────────────────────────────────────────────────────────────────
async function saveRelation() {
  saving.value = true
  try {
    const body = {
      from_tb:   formData.value.from_tb,
      from_col:  formData.value.from_col,
      to_tb:     formData.value.to_tb,
      to_col:    formData.value.to_col,
      on_delete: formData.value.on_delete,
      on_update: formData.value.on_update,
    }

    const res = editingId.value !== null
      ? await api.put(`/api/config/relations/${editingId.value}`, body)
      : await api.post('/api/config/relations', body)

    if (res.status === 'warning') {
      // Orphans found: config saved but FK not applied to DB
      toast.add({
        severity: 'warn',
        summary:  t('relation_saved'),
        detail:   t('relation_orphans_warning', { count: res.orphans }),
        life: 6000,
      })
      cancelForm()
      await load()
      return
    }

    toast.add({
      severity: res.status === 'success' ? 'success' : 'error',
      summary:  t(res.status === 'success' ? 'relation_saved' : 'error'),
      detail:   api.responseMessage(res, t),
      life: 4000,
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

// ── Apply all constraints ──────────────────────────────────────────────────
async function applyAll() {
  applying.value = true
  try {
    const res = await api.post('/api/config/apply-constraints')
    const sev = res.skipped > 0 ? 'warn' : 'success'
    toast.add({
      severity: sev,
      summary:  t('constraints_applied'),
      detail:   t('constraints_applied_detail', { applied: res.applied, skipped: res.skipped }),
      life: 5000,
    })
  } catch (e) {
    toast.add({ severity: 'error', summary: e.message, life: 4000 })
  } finally {
    applying.value = false
  }
}

// ── Delete ─────────────────────────────────────────────────────────────────
function deleteRelation(rel) {
  confirm.require({
    message:  `${rel.from_label || rel.from_tb}.${rel.from_col} → ${rel.to_label || rel.to_tb}.${rel.to_col}`,
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
      life: 4000,
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
.cfg-panel-header-actions {
  display: flex;
  gap: 0.5rem;
  align-items: center;
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

/* ── Form ───────────────────────────────────────────────── */
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
  gap: 0.6rem;
  flex-wrap: wrap;
}
.cfg-rel-arrow {
  font-size: 0.85rem;
  color: var(--p-text-muted-color);
  flex-shrink: 0;
  padding-bottom: 0.35rem;
}
.cfg-field-group {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  min-width: 120px;
}
.cfg-field-group label {
  font-size: 0.78rem;
  font-weight: 500;
  color: var(--p-text-muted-color);
}
.cfg-self-ref-note {
  font-size: 0.78rem;
  color: var(--p-amber-600, #b45309);
  display: flex;
  align-items: center;
  gap: 0.3rem;
  padding-bottom: 0.25rem;
  flex: 1;
}
.cfg-rel-form-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.4rem;
  padding-top: 0.25rem;
  border-top: 1px solid var(--p-content-border-color);
}

/* ── Cards ──────────────────────────────────────────────── */
.cfg-rel-card {
  border: 1px solid var(--p-content-border-color);
  border-radius: 6px;
  padding: 0.55rem 0.75rem;
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
  gap: 0.3rem;
}
.cfg-rel-pair {
  display: flex;
  align-items: center;
  gap: 0.25rem;
  font-size: 0.86rem;
  font-weight: 600;
  flex-wrap: wrap;
}
.cfg-rel-tb { max-width: 160px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cfg-rel-col { font-family: monospace; font-size: 0.82rem; color: var(--p-primary-color); font-weight: 400; }
.cfg-rel-arrow-sm { font-size: 0.75rem; color: var(--p-text-muted-color); flex-shrink: 0; }

.cfg-rel-policies {
  display: flex;
  gap: 0.35rem;
}
.cfg-rel-policy-badge {
  font-size: 0.68rem;
  font-family: monospace;
  padding: 0.1rem 0.4rem;
  border-radius: 3px;
  background: var(--p-content-hover-background);
  color: var(--p-text-muted-color);
}
.cfg-rel-policy--cascade  { background: color-mix(in srgb, var(--p-green-500) 15%, transparent); color: var(--p-green-700, #15803d); }
.cfg-rel-policy--restrict { background: color-mix(in srgb, var(--p-orange-500) 15%, transparent); color: var(--p-orange-700, #c2410c); }
.cfg-rel-policy--setnull  { background: color-mix(in srgb, var(--p-blue-500) 15%, transparent); color: var(--p-blue-700, #1d4ed8); }

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
