<template>
  <div class="cfg-panel">
    <div class="cfg-panel-header">
      <h2><i class="pi pi-list" /> {{ t('fields') }}: <em>{{ tb }}</em></h2>
      <Button :label="t('add_field')" icon="pi pi-plus" size="small" outlined @click="addNew" />
    </div>

    <div v-if="loading" class="cfg-loading-center">
      <i class="pi pi-spin pi-spinner" />
    </div>
    <Message v-if="loadError" severity="error" :closable="false">{{ loadError }}</Message>

    <div v-if="!loading" class="cfg-fld-shell">

      <!-- ── Left: field list ──────────────────────────────────────── -->
      <div class="cfg-fld-list">
        <div v-if="fields.length === 0" class="cfg-fld-empty">{{ t('no_fields') }}</div>
        <div
          v-for="fld in fields"
          :key="fld.name"
          class="cfg-fld-item"
          :class="{ active: selectedFld?.name === fld.name }"
          @click="selectField(fld)"
        >
          <div class="cfg-fld-item-main">
            <span class="cfg-fld-name">{{ fld.name }}</span>
            <span class="cfg-fld-label">{{ fld.label }}</span>
          </div>
          <span class="cfg-fld-type">{{ fld.type }}</span>
          <div class="cfg-fld-item-actions">
            <button
              class="cfg-icon-btn"
              :title="t('rename_column')"
              @click.stop="startRename(fld)"
            >
              <i class="pi pi-pencil" />
            </button>
            <button
              class="cfg-icon-btn cfg-icon-btn--danger"
              :title="t('delete_column')"
              @click.stop="deleteField(fld)"
            >
              <i class="pi pi-trash" />
            </button>
          </div>
        </div>
      </div>

      <!-- ── Right: field editor ───────────────────────────────────── -->
      <div class="cfg-fld-editor">
        <div v-if="!selectedFld && !addingNew" class="cfg-fld-editor-empty">
          <i class="pi pi-arrow-left" />
          <span>{{ t('select_field') }}</span>
        </div>

        <template v-else>
          <div class="cfg-fld-editor-title">
            {{ addingNew ? t('new_field') : selectedFld?.name }}
          </div>
          <div class="cfg-fld-editor-scroll">
            <ConfigFieldForm
              :tb="tb"
              :field="addingNew ? {} : selectedFld"
              :structure="structure"
              @saved="onSaved"
              @cancelled="onCancelled"
            />
          </div>
        </template>
      </div>

    </div>

    <!-- ── Rename dialog ───────────────────────────────────────────── -->
    <Dialog v-model:visible="renameVisible" modal :header="t('rename_column')" style="width:350px">
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
import { ref, onMounted, watch } from 'vue'
import Button      from 'primevue/button'
import Dialog      from 'primevue/dialog'
import InputText   from 'primevue/inputtext'
import Message     from 'primevue/message'
import { useToast }      from 'primevue/usetoast'
import { useConfirm }    from 'primevue/useconfirm'
import { useI18n }       from '@/i18n'
import { api }           from '@/api'
import ConfigFieldForm   from '@/components/config/ConfigFieldForm.vue'

const props = defineProps({
  tb: { type: String, required: true }
})

const { t }   = useI18n()
const toast   = useToast()
const confirm = useConfirm()

const loading     = ref(false)
const loadError   = ref(null)
const fields      = ref([])
const structure   = ref({})
const selectedFld = ref(null)
const addingNew   = ref(false)

// Rename state
const renameVisible = ref(false)
const renamingFld   = ref(null)
const newName       = ref('')
const renaming      = ref(false)

// ── Data loading ───────────────────────────────────────────────────────────

async function load() {
  loading.value   = true
  loadError.value = null
  try {
    // Fetch field list for the table + fld_structure in one call
    const [cfgRes, strRes] = await Promise.all([
      api.get(`/api/config/table/${props.tb}`),
      api.get('/api/config/field-structure'),
    ])
    if (cfgRes.status === 'error') throw new Error(t(cfgRes.code))
    if (strRes.status === 'error') throw new Error(t(strRes.code))

    // Build a flat array of field objects from the table config
    const rawFields = cfgRes.table?.fields ?? {}
    fields.value    = Object.values(rawFields)
    structure.value = strRes.structure ?? {}

    // Keep selection live after reload
    if (selectedFld.value) {
      const reloaded = fields.value.find(f => f.name === selectedFld.value.name)
      selectedFld.value = reloaded ?? null
    }
  } catch (e) {
    loadError.value = e.message
  } finally {
    loading.value = false
  }
}

// Reload when table changes
watch(() => props.tb, () => {
  selectedFld.value = null
  addingNew.value   = false
  load()
})

// ── Field selection ────────────────────────────────────────────────────────

function selectField(fld) {
  selectedFld.value = fld
  addingNew.value   = false
}

function addNew() {
  selectedFld.value = null
  addingNew.value   = true
}

function onCancelled() {
  addingNew.value = false
}

async function onSaved(fieldName) {
  addingNew.value = false
  await load()
  // Select the just-saved field
  const saved = fields.value.find(f => f.name === fieldName)
  if (saved) selectedFld.value = saved
}

// ── Delete field ───────────────────────────────────────────────────────────

function deleteField(fld) {
  confirm.require({
    message:  `${t('confirm_delete')}\n"${fld.name}"`,
    header:   t('delete_column'),
    icon:     'pi pi-exclamation-triangle',
    severity: 'danger',
    accept:   () => doDelete(fld),
  })
}

async function doDelete(fld) {
  try {
    const res = await api.delete(`/api/config/table/${props.tb}/field/${fld.name}`)
    toast.add({
      severity: res.status === 'success' ? 'success' : 'error',
      summary:  t('saved'),
      detail:   api.responseMessage(res, t),
      life: 4000
    })
    if (res.status === 'success') {
      if (selectedFld.value?.name === fld.name) selectedFld.value = null
      await load()
    }
  } catch (e) {
    toast.add({ severity: 'error', summary: e.message, life: 4000 })
  }
}

// ── Rename field ───────────────────────────────────────────────────────────

function startRename(fld) {
  renamingFld.value   = fld
  newName.value       = fld.name
  renameVisible.value = true
}

async function confirmRename() {
  if (!newName.value || newName.value === renamingFld.value.name) {
    renameVisible.value = false
    return
  }
  renaming.value = true
  try {
    const res = await api.patch(`/api/config/table/${props.tb}/field/${renamingFld.value.name}`, {
      old_name: renamingFld.value.name,
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
      await load()
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
  gap: 0.4rem;
}
.cfg-panel-header h2 em { font-style: normal; color: var(--p-primary-color); }
.cfg-loading-center {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 2rem;
  color: var(--p-text-muted-color);
}

/* ── Split layout ───────────── */
.cfg-fld-shell {
  flex: 1;
  display: flex;
  min-height: 0;
  overflow: hidden;
}

/* ── Left pane ──────────────── */
.cfg-fld-list {
  width: 230px;
  flex-shrink: 0;
  border-right: 1px solid var(--p-content-border-color);
  overflow-y: auto;
  padding: 0.25rem 0;
}
.cfg-fld-empty {
  padding: 1rem;
  font-size: 0.85rem;
  color: var(--p-text-muted-color);
}
.cfg-fld-item {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.45rem 0.75rem;
  cursor: pointer;
  border-radius: 4px;
  margin: 0 0.25rem;
  transition: background 0.12s;
}
.cfg-fld-item:hover  { background: var(--p-content-hover-background); }
.cfg-fld-item.active { background: var(--p-highlight-background); }

.cfg-fld-item-main {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  overflow: hidden;
}
.cfg-fld-name {
  font-size: 0.82rem;
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.cfg-fld-label {
  font-size: 0.72rem;
  color: var(--p-text-muted-color);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.cfg-fld-type {
  font-size: 0.65rem;
  color: var(--p-text-muted-color);
  background: var(--p-content-hover-background);
  padding: 0.05rem 0.3rem;
  border-radius: 3px;
  flex-shrink: 0;
}
.cfg-fld-item-actions {
  display: flex;
  gap: 0.1rem;
  opacity: 0;
  transition: opacity 0.12s;
}
.cfg-fld-item:hover .cfg-fld-item-actions,
.cfg-fld-item.active .cfg-fld-item-actions { opacity: 1; }

.cfg-icon-btn {
  border: none;
  background: transparent;
  cursor: pointer;
  color: var(--p-text-muted-color);
  padding: 0.2rem 0.3rem;
  border-radius: 4px;
  font-size: 0.75rem;
  transition: background 0.15s, color 0.15s;
}
.cfg-icon-btn:hover { background: var(--p-content-hover-background); color: var(--p-text-color); }
.cfg-icon-btn--danger:hover { color: var(--p-red-400); }

/* ── Right pane ─────────────── */
.cfg-fld-editor {
  flex: 1;
  min-width: 0;
  overflow-y: auto;
  padding: 1rem 1.25rem;
  display: flex;
  flex-direction: column;
}
.cfg-fld-editor-empty {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  color: var(--p-text-muted-color);
  font-size: 0.9rem;
}
.cfg-fld-editor-title {
  font-weight: 700;
  font-size: 0.9rem;
  margin-bottom: 0.75rem;
  color: var(--p-primary-color);
}
.cfg-fld-editor-scroll {
  flex: 1;
}

/* ── Rename dialog ───────────── */
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
