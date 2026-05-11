<template>
  <AppLayout>
    <div class="voc-page">

      <div class="page-header">
        <h2>{{ t('available_vocs') }}</h2>
        <Button :label="t('new_voc')" icon="pi pi-plus" size="small" @click="openNewVocDialog" />
      </div>

      <div v-if="loading" class="loading">
        <ProgressSpinner />
      </div>

      <div v-else-if="vocs.length === 0" class="empty">
        <p>No vocabularies defined yet.</p>
      </div>

      <div v-else class="voc-layout">

        <!-- Left: vocabulary list -->
        <div class="voc-names">
          <div
            v-for="voc in vocs"
            :key="voc.name"
            class="voc-name-item"
            :class="{ active: selected?.name === voc.name }"
            @click="selected = voc"
          >
            <span>{{ voc.name }}</span>
            <Badge :value="voc.items.length" severity="secondary" />
          </div>
        </div>

        <!-- Right: items for selected vocabulary -->
        <div class="voc-items" v-if="selected">
          <div class="voc-items-header">
            <h3>{{ selected.name }}</h3>
            <Button label="Add item" icon="pi pi-plus" size="small" severity="secondary"
                    @click="openAddItemDialog(selected.name)" />
          </div>

          <DataTable
            :value="selected.items"
            dataKey="id"
            class="voc-table"
            size="small"
            @rowReorder="onReorder"
          >
            <Column rowReorder style="width: 2.5rem" />
            <Column field="def" header="Definition">
              <template #body="{ data }">
                <span
                  v-if="editingId !== data.id"
                  class="def-text"
                  @dblclick="startEdit(data)"
                >{{ data.def }}</span>
                <InputText
                  v-else
                  v-model="editingVal"
                  size="small"
                  fluid
                  autofocus
                  @keyup.enter="saveEdit(data)"
                  @keyup.escape="cancelEdit"
                  @blur="saveEdit(data)"
                />
              </template>
            </Column>
            <Column style="width: 5rem; text-align: right">
              <template #body="{ data }">
                <Button icon="pi pi-pencil" text rounded size="small"
                        @click="startEdit(data)" :disabled="editingId !== null" />
                <Button icon="pi pi-trash" text rounded size="small" severity="danger"
                        @click="confirmErase(data)" :disabled="editingId !== null" />
              </template>
            </Column>
          </DataTable>
        </div>

        <div class="voc-items voc-empty-hint" v-else>
          <p>Select a vocabulary on the left.</p>
        </div>

      </div>
    </div>

    <!-- New vocabulary dialog -->
    <Dialog v-model:visible="newVocDialog" header="New vocabulary" modal style="width: 360px">
      <div class="field">
        <label>Vocabulary name</label>
        <InputText v-model="newVocName" fluid placeholder="e.g. material" @keyup.enter="createVocAndItem" />
      </div>
      <div class="field">
        <label>First item</label>
        <InputText v-model="newVocDef" fluid placeholder="First definition" @keyup.enter="createVocAndItem" />
      </div>
      <template #footer>
        <Button label="Cancel" text @click="newVocDialog = false" />
        <Button label="Create" icon="pi pi-check" :disabled="!newVocName || !newVocDef"
                :loading="saving" @click="createVocAndItem" />
      </template>
    </Dialog>

    <!-- Add item dialog -->
    <Dialog v-model:visible="addItemDialog" :header="addItemHeader" modal style="width: 360px">
      <div class="field">
        <label>Definition</label>
        <InputText v-model="addItemDef" fluid autofocus @keyup.enter="addItem" />
      </div>
      <template #footer>
        <Button label="Cancel" text @click="addItemDialog = false" />
        <Button label="Add" icon="pi pi-check" :disabled="!addItemDef"
                :loading="saving" @click="addItem" />
      </template>
    </Dialog>

  </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from '@/i18n'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import { api } from '@/api'
import AppLayout from '@/components/AppLayout.vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Badge from 'primevue/badge'
import Dialog from 'primevue/dialog'
import ProgressSpinner from 'primevue/progressspinner'

const { t } = useI18n()
const toast = useToast()
const confirm = useConfirm()

const vocs = ref([])
const selected = ref(null)
const loading = ref(false)
const saving = ref(false)

// Inline edit state
const editingId = ref(null)
const editingVal = ref('')

// Dialogs
const newVocDialog = ref(false)
const newVocName = ref('')
const newVocDef = ref('')

const addItemDialog = ref(false)
const addItemVoc = ref('')
const addItemDef = ref('')
const addItemHeader = computed(() => `Add item to '${addItemVoc.value}'`)

// ── Load ────────────────────────────────────────────────────
async function load() {
  loading.value = true
  try {
    const res = await api.get('vocabularies_ctrl', 'list')
    vocs.value = res.vocs ?? []
    // keep selection in sync after reload
    if (selected.value) {
      selected.value = vocs.value.find(v => v.name === selected.value.name) ?? null
    }
  } catch {
    toast.add({ severity: 'error', summary: 'Error', detail: 'Could not load vocabularies', life: 3000 })
  } finally {
    loading.value = false
  }
}

onMounted(load)

// ── Inline edit ──────────────────────────────────────────────
function startEdit(item) {
  editingId.value = item.id
  editingVal.value = item.def
}

function cancelEdit() {
  editingId.value = null
  editingVal.value = ''
}

async function saveEdit(item) {
  if (editingVal.value === item.def) { cancelEdit(); return }
  try {
    const res = await api.get('vocabularies_ctrl', 'edit', { id: item.id, val: editingVal.value })
    if (res.status === 'success') {
      item.def = editingVal.value
      toast.add({ severity: 'success', summary: 'Saved', life: 2000 })
    } else {
      toast.add({ severity: 'error', summary: 'Error', detail: res.text, life: 3000 })
    }
  } catch {
    toast.add({ severity: 'error', summary: 'Error', detail: 'Save failed', life: 3000 })
  } finally {
    cancelEdit()
  }
}

// ── Delete ───────────────────────────────────────────────────
function confirmErase(item) {
  confirm.require({
    message: `Delete "${item.def}"?`,
    header: 'Confirm deletion',
    icon: 'pi pi-exclamation-triangle',
    rejectLabel: 'Cancel',
    acceptLabel: 'Delete',
    acceptClass: 'p-button-danger',
    accept: () => eraseItem(item),
  })
}

async function eraseItem(item) {
  try {
    const res = await api.get('vocabularies_ctrl', 'erase', { id: item.id })
    if (res.status === 'success') {
      toast.add({ severity: 'success', summary: 'Deleted', life: 2000 })
      await load()
    } else {
      toast.add({ severity: 'error', summary: 'Error', detail: res.text, life: 3000 })
    }
  } catch {
    toast.add({ severity: 'error', summary: 'Error', detail: 'Delete failed', life: 3000 })
  }
}

// ── Sort ─────────────────────────────────────────────────────
async function onReorder(event) {
  selected.value.items = event.value
  const ids = event.value.map(i => i.id)
  try {
    await api.post('vocabularies_ctrl', 'sort', { ids })
  } catch {
    toast.add({ severity: 'warn', summary: 'Sort not saved', life: 3000 })
  }
}

// ── New vocabulary ───────────────────────────────────────────
function openNewVocDialog() {
  newVocName.value = ''
  newVocDef.value = ''
  newVocDialog.value = true
}

async function createVocAndItem() {
  if (!newVocName.value || !newVocDef.value) return
  saving.value = true
  try {
    const res = await api.get('vocabularies_ctrl', 'add', { voc: newVocName.value, def: newVocDef.value })
    if (res.status === 'success') {
      toast.add({ severity: 'success', summary: 'Created', life: 2000 })
      newVocDialog.value = false
      await load()
      selected.value = vocs.value.find(v => v.name === newVocName.value) ?? null
    } else {
      toast.add({ severity: 'error', summary: 'Error', detail: res.text, life: 3000 })
    }
  } catch {
    toast.add({ severity: 'error', summary: 'Error', life: 3000 })
  } finally {
    saving.value = false
  }
}

// ── Add item ─────────────────────────────────────────────────
function openAddItemDialog(vocName) {
  addItemVoc.value = vocName
  addItemDef.value = ''
  addItemDialog.value = true
}

async function addItem() {
  if (!addItemDef.value) return
  saving.value = true
  try {
    const res = await api.get('vocabularies_ctrl', 'add', { voc: addItemVoc.value, def: addItemDef.value })
    if (res.status === 'success') {
      toast.add({ severity: 'success', summary: 'Added', life: 2000 })
      addItemDialog.value = false
      await load()
    } else {
      toast.add({ severity: 'error', summary: 'Error', detail: res.text, life: 3000 })
    }
  } catch {
    toast.add({ severity: 'error', summary: 'Error', life: 3000 })
  } finally {
    saving.value = false
  }
}
</script>

<style scoped>
.voc-page {
  padding: 1.5rem;
  max-width: 900px;
}

.page-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 1.5rem;
}

.page-header h2 {
  font-size: 1.4rem;
  font-weight: 700;
}

.loading, .empty {
  display: flex;
  justify-content: center;
  padding: 3rem;
  color: var(--p-text-muted-color);
}

.voc-layout {
  display: flex;
  gap: 1.5rem;
  align-items: flex-start;
}

/* Left panel */
.voc-names {
  width: 200px;
  flex-shrink: 0;
  border: 1px solid var(--p-content-border-color);
  border-radius: 6px;
  overflow: hidden;
}

.voc-name-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.6rem 0.9rem;
  cursor: pointer;
  border-bottom: 1px solid var(--p-content-border-color);
  font-size: 0.875rem;
  transition: background 0.15s;
}

.voc-name-item:last-child { border-bottom: none; }
.voc-name-item:hover { background: var(--p-content-hover-background); }
.voc-name-item.active {
  background: var(--p-highlight-background);
  color: var(--p-primary-color);
  font-weight: 600;
}

/* Right panel */
.voc-items {
  flex: 1;
  min-width: 0;
}

.voc-items-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 0.75rem;
}

.voc-items-header h3 {
  font-size: 1rem;
  font-weight: 600;
}

.voc-empty-hint {
  color: var(--p-text-muted-color);
  padding: 2rem;
}

.def-text {
  cursor: text;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  margin-bottom: 1rem;
}

.field label {
  font-size: 0.85rem;
  font-weight: 500;
  color: var(--p-text-muted-color);
}
</style>
