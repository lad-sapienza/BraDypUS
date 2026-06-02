<template>
  <div class="cfg-idx-section">
    <div class="cfg-idx-header">
      <span class="cfg-idx-title"><i class="pi pi-database" /> {{ t('db_indexes') }}</span>
      <Button
        icon="pi pi-plus"
        size="small"
        text
        :title="t('add_index')"
        :disabled="showForm"
        @click="openForm"
      />
    </div>

    <!-- ─── Add form ────────────────────────────────────────── -->
    <div v-if="showForm" class="cfg-idx-form">
      <div class="cfg-idx-form-row">
        <div class="cfg-field-group" style="flex:1">
          <label>{{ t('idx_name') }}</label>
          <InputText v-model="form.name" size="small" :placeholder="t('idx_name_hint')" />
        </div>
        <div class="cfg-field-group" style="flex:2">
          <label>{{ t('idx_columns') }}</label>
          <MultiSelect
            v-model="form.columns"
            :options="columnOptions"
            option-label="label"
            option-value="value"
            :placeholder="t('select_columns')"
            size="small"
            display="chip"
          />
        </div>
        <div class="cfg-field-group cfg-field-group--check">
          <label>{{ t('idx_unique') }}</label>
          <ToggleSwitch v-model="form.is_unique" size="small" />
        </div>
      </div>
      <div class="cfg-idx-form-actions">
        <Button :label="t('cancel')" severity="secondary" size="small" text @click="cancelForm" />
        <Button
          :label="t('save')"
          icon="pi pi-save"
          size="small"
          :loading="saving"
          :disabled="!form.name || !form.columns.length"
          @click="saveIndex"
        />
      </div>
    </div>

    <!-- ─── Loading / empty ─────────────────────────────────── -->
    <div v-if="loading" class="cfg-idx-loading">
      <i class="pi pi-spin pi-spinner" />
    </div>

    <div v-else-if="indexes.length === 0 && !showForm" class="cfg-idx-empty">
      {{ t('no_indexes') }}
    </div>

    <!-- ─── Index list ──────────────────────────────────────── -->
    <div
      v-for="idx in indexes"
      :key="idx.id"
      class="cfg-idx-row"
    >
      <span class="cfg-idx-name">{{ idx.name }}</span>
      <span class="cfg-idx-cols">{{ (idx.columns || []).join(', ') }}</span>
      <span v-if="idx.is_unique" class="cfg-idx-unique-badge">UNIQUE</span>
      <Button
        icon="pi pi-trash"
        severity="danger"
        size="small"
        text
        :loading="deletingId === idx.id"
        @click="deleteIndex(idx)"
      />
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import Button      from 'primevue/button'
import InputText   from 'primevue/inputtext'
import MultiSelect from 'primevue/multiselect'
import ToggleSwitch from 'primevue/toggleswitch'
import { useToast }   from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import { useI18n }    from '@/i18n'
import { api }        from '@/api'

const props = defineProps({ tb: { type: String, required: true } })

const { t }   = useI18n()
const toast   = useToast()
const confirm = useConfirm()

const loading    = ref(false)
const saving     = ref(false)
const deletingId = ref(null)
const indexes    = ref([])
const columns    = ref([])
const showForm   = ref(false)
const form       = ref(emptyForm())

function emptyForm() {
  return { name: '', columns: [], is_unique: false }
}

const columnOptions = computed(() =>
  columns.value.map(c => ({ value: c, label: c }))
)

// ── Load indexes ───────────────────────────────────────────
async function load() {
  loading.value = true
  try {
    const res = await api.get(`/api/config/table/${props.tb}/indexes`)
    indexes.value = res.data ?? []
  } catch { /* ignore */ } finally {
    loading.value = false
  }
}

// ── Load available columns ─────────────────────────────────
async function loadColumns() {
  try {
    const res = await api.get(`/api/config/table/${props.tb}/fields`)
    if (res.status === 'success' || res.code === 'ok') {
      columns.value = Object.keys(res.fields ?? {})
    }
  } catch { /* ignore */ }
}

// ── Form ───────────────────────────────────────────────────
function openForm() {
  form.value = emptyForm()
  showForm.value = true
}
function cancelForm() {
  showForm.value = false
  form.value = emptyForm()
}

async function saveIndex() {
  saving.value = true
  try {
    const res = await api.post(`/api/config/table/${props.tb}/indexes`, {
      name:      form.value.name,
      columns:   form.value.columns,
      is_unique: form.value.is_unique,
    })
    toast.add({
      severity: res.status === 'success' ? 'success' : 'error',
      summary:  t(res.status === 'success' ? 'index_saved' : 'error'),
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

// ── Delete ─────────────────────────────────────────────────
function deleteIndex(idx) {
  confirm.require({
    message:  idx.name,
    header:   t('delete'),
    icon:     'pi pi-exclamation-triangle',
    severity: 'danger',
    accept:   () => doDelete(idx),
  })
}

async function doDelete(idx) {
  deletingId.value = idx.id
  try {
    const res = await api.delete(`/api/config/table/${props.tb}/indexes/${idx.id}`)
    toast.add({
      severity: res.status === 'success' ? 'success' : 'error',
      summary:  t(res.status === 'success' ? 'index_deleted' : 'error'),
      detail:   api.responseMessage(res, t),
      life: 4000,
    })
    if (res.status === 'success') {
      indexes.value = indexes.value.filter(i => i.id !== idx.id)
    }
  } catch (e) {
    toast.add({ severity: 'error', summary: e.message, life: 4000 })
  } finally {
    deletingId.value = null
  }
}

watch(() => props.tb, () => { load(); loadColumns() })
onMounted(() => { load(); loadColumns() })
</script>

<style scoped>
.cfg-idx-section {
  border-top: 1px solid var(--p-content-border-color);
  padding-top: 0.875rem;
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}
.cfg-idx-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.cfg-idx-title {
  font-size: 0.78rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--p-text-muted-color);
  display: flex;
  align-items: center;
  gap: 0.35rem;
}
.cfg-idx-loading {
  text-align: center;
  color: var(--p-text-muted-color);
  padding: 0.5rem;
}
.cfg-idx-empty {
  font-size: 0.8rem;
  color: var(--p-text-muted-color);
  padding: 0.35rem 0;
}

/* Form */
.cfg-idx-form {
  border: 1px solid var(--p-primary-color);
  border-radius: 6px;
  padding: 0.75rem;
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  background: var(--bdus-bg);
}
.cfg-idx-form-row {
  display: flex;
  align-items: flex-end;
  gap: 0.6rem;
  flex-wrap: wrap;
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
.cfg-field-group--check {
  justify-content: flex-end;
  padding-bottom: 0.2rem;
  gap: 0.3rem;
}
.cfg-idx-form-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.4rem;
  border-top: 1px solid var(--p-content-border-color);
  padding-top: 0.4rem;
}

/* Index rows */
.cfg-idx-row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.3rem 0.4rem;
  border-radius: 4px;
  font-size: 0.82rem;
  transition: background 0.12s;
}
.cfg-idx-row:hover { background: var(--p-content-hover-background); }
.cfg-idx-name {
  font-family: monospace;
  font-weight: 600;
  flex-shrink: 0;
}
.cfg-idx-cols {
  font-size: 0.78rem;
  color: var(--p-text-muted-color);
  font-family: monospace;
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.cfg-idx-unique-badge {
  font-size: 0.65rem;
  background: color-mix(in srgb, var(--p-primary-color) 15%, transparent);
  color: var(--p-primary-color);
  padding: 0.1rem 0.4rem;
  border-radius: 3px;
  flex-shrink: 0;
}
</style>
