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
        <p>{{ t('no_vocabularies') }}</p>
      </div>

      <div v-else class="voc-layout">

        <!-- Left: vocabulary list with filter -->
        <div class="voc-names">
          <div class="voc-filter">
            <InputText
              v-model="filterText"
              :placeholder="t('voc_filter_placeholder')"
              size="small"
              class="voc-filter-input"
            />
          </div>
          <div
            v-for="voc in filteredVocs"
            :key="voc.name"
            class="voc-name-item"
            :class="{ active: selected?.name === voc.name }"
            @click="selected = voc"
          >
            <span class="voc-name-text">{{ voc.name }}</span>
            <Badge :value="voc.items.length" severity="secondary" />
          </div>
          <div v-if="filteredVocs.length === 0" class="voc-filter-empty">—</div>
        </div>

        <!-- Right: items + usage info for selected vocabulary -->
        <div class="voc-items" v-if="selected">
          <div class="voc-items-header">
            <h3>{{ selected.name }}</h3>
            <Button :label="t('voc_add_item')" icon="pi pi-plus" size="small" severity="secondary"
                    @click="openAddItemDialog(selected.name)" />
          </div>

          <!-- Usage info -->
          <div class="voc-usages" v-if="!usagesLoading">
            <template v-if="selectedUsages.length">
              <span class="voc-usages-label">{{ t('voc_used_by') }}:</span>
              <span
                v-for="(u, i) in selectedUsages"
                :key="i"
                class="voc-usage-chip"
                :title="`${u.tb}.${u.field}`"
              >{{ u.tb_label }} / {{ u.field_label }}</span>
            </template>
            <span v-else class="voc-usages-empty">{{ t('voc_no_usages') }}</span>
          </div>

          <DataTable
            :value="selected.items"
            dataKey="id"
            class="voc-table"
            size="small"
            @rowReorder="onReorder"
          >
            <Column rowReorder style="width: 2.5rem" />
            <Column field="def" :header="t('voc_item_def')">
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
          <p>{{ t('voc_select_hint') }}</p>
        </div>

      </div>
    </div>

    <!-- New vocabulary dialog -->
    <Dialog v-model:visible="newVocDialog" :header="t('new_voc')" modal style="width: 360px">
      <div class="field">
        <label>{{ t('voc_name_label') }}</label>
        <InputText v-model="newVocName" fluid :placeholder="t('voc_name_placeholder')" @keyup.enter="createVocAndItem" />
      </div>
      <div class="field">
        <label>{{ t('voc_first_item') }}</label>
        <InputText v-model="newVocDef" fluid :placeholder="t('voc_first_def')" @keyup.enter="createVocAndItem" />
      </div>
      <template #footer>
        <Button :label="t('cancel')" text @click="newVocDialog = false" />
        <Button :label="t('create')" icon="pi pi-check" :disabled="!newVocName || !newVocDef"
                :loading="saving" @click="createVocAndItem" />
      </template>
    </Dialog>

    <!-- Add item dialog -->
    <Dialog v-model:visible="addItemDialog" :header="addItemHeader" modal style="width: 360px">
      <div class="field">
        <label>{{ t('voc_item_def') }}</label>
        <InputText v-model="addItemDef" fluid autofocus @keyup.enter="addItem" />
      </div>
      <template #footer>
        <Button :label="t('cancel')" text @click="addItemDialog = false" />
        <Button :label="t('voc_add_item')" icon="pi pi-check" :disabled="!addItemDef"
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

// Filter
const filterText = ref('')
const filteredVocs = computed(() => {
  const q = filterText.value.trim().toLowerCase()
  return q ? vocs.value.filter(v => v.name.toLowerCase().includes(q)) : vocs.value
})

// Field usages
const usages = ref({})
const usagesLoading = ref(false)
const selectedUsages = computed(() => {
  if (!selected.value) return []
  return usages.value[selected.value.name] ?? []
})

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
const addItemHeader = computed(() => `${t('voc_add_item_to')} '${addItemVoc.value}'`)

// ── Load ────────────────────────────────────────────────────
async function load() {
  loading.value = true
  try {
    const res = await api.get('/api/vocabularies')
    vocs.value = res.vocs ?? []
    if (selected.value) {
      selected.value = vocs.value.find(v => v.name === selected.value.name) ?? null
    }
  } catch {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: t('generic_error'), life: 3000 })
  } finally {
    loading.value = false
  }
}

async function loadUsages() {
  usagesLoading.value = true
  try {
    const res = await api.get('/api/vocabularies/usages')
    if (res.status === 'success') usages.value = res.usages ?? {}
  } catch {
    // non-critical: silently ignore
  } finally {
    usagesLoading.value = false
  }
}

onMounted(async () => {
  await Promise.all([load(), loadUsages()])
})

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
    const res = await api.patch(`/api/vocabulary/${item.id}`, { val: editingVal.value })
    if (res.status === 'success') {
      item.def = editingVal.value
      toast.add({ severity: 'success', summary: t('saved'), life: 2000 })
    } else {
      toast.add({ severity: 'error', summary: t('generic_error'), detail: api.responseMessage(res, t), life: 3000 })
    }
  } catch {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: t('generic_error'), life: 3000 })
  } finally {
    cancelEdit()
  }
}

// ── Delete ───────────────────────────────────────────────────
function confirmErase(item) {
  confirm.require({
    message: `${t('voc_confirm_delete')} "${item.def}"?`,
    header: t('confirm'),
    icon: 'pi pi-exclamation-triangle',
    rejectLabel: t('cancel'),
    acceptLabel: t('delete'),
    acceptClass: 'p-button-danger',
    accept: () => eraseItem(item),
  })
}

async function eraseItem(item) {
  try {
    const res = await api.delete(`/api/vocabulary/${item.id}`)
    if (res.status === 'success') {
      toast.add({ severity: 'success', summary: t('deleted'), life: 2000 })
      await load()
    } else {
      toast.add({ severity: 'error', summary: t('generic_error'), detail: api.responseMessage(res, t), life: 3000 })
    }
  } catch {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: t('generic_error'), life: 3000 })
  }
}

// ── Sort ─────────────────────────────────────────────────────
async function onReorder(event) {
  selected.value.items = event.value
  const ids = event.value.map(i => i.id)
  try {
    await api.post('/api/vocabularies/sort', { ids })
  } catch {
    toast.add({ severity: 'warn', summary: t('voc_sort_not_saved'), life: 3000 })
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
    const res = await api.post('/api/vocabularies', { voc: newVocName.value, def: newVocDef.value })
    if (res.status === 'success') {
      toast.add({ severity: 'success', summary: t('saved'), life: 2000 })
      newVocDialog.value = false
      await load()
      selected.value = vocs.value.find(v => v.name === newVocName.value) ?? null
    } else {
      toast.add({ severity: 'error', summary: t('generic_error'), detail: api.responseMessage(res, t), life: 3000 })
    }
  } catch {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: t('generic_error'), life: 3000 })
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
    const res = await api.post('/api/vocabularies', { voc: addItemVoc.value, def: addItemDef.value })
    if (res.status === 'success') {
      toast.add({ severity: 'success', summary: t('saved'), life: 2000 })
      addItemDialog.value = false
      await load()
    } else {
      toast.add({ severity: 'error', summary: t('generic_error'), detail: api.responseMessage(res, t), life: 3000 })
    }
  } catch {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: t('generic_error'), life: 3000 })
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
  width: 220px;
  flex-shrink: 0;
  border: 1px solid var(--p-content-border-color);
  border-radius: 6px;
  overflow: hidden;
}

.voc-filter {
  padding: 0.5rem 0.6rem;
  border-bottom: 1px solid var(--p-content-border-color);
}

.voc-filter-input { width: 100%; }

.voc-filter-empty {
  padding: 0.6rem 0.9rem;
  color: var(--p-text-muted-color);
  font-style: italic;
  font-size: 0.85rem;
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
  gap: 0.5rem;
}

.voc-name-item:last-child { border-bottom: none; }
.voc-name-item:hover { background: var(--p-content-hover-background); }
.voc-name-item.active {
  background: var(--p-highlight-background);
  color: var(--p-primary-color);
  font-weight: 600;
}

.voc-name-text {
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  min-width: 0;
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
  margin-bottom: 0.5rem;
}

.voc-items-header h3 {
  font-size: 1rem;
  font-weight: 600;
}

/* Usage info */
.voc-usages {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.35rem;
  margin-bottom: 0.75rem;
  font-size: 0.78rem;
}

.voc-usages-label {
  color: var(--p-text-muted-color);
  font-weight: 500;
}

.voc-usage-chip {
  background: var(--p-content-hover-background);
  border: 1px solid var(--p-content-border-color);
  border-radius: 12px;
  padding: 0.1rem 0.5rem;
  color: var(--p-text-color);
  font-size: 0.75rem;
}

.voc-usages-empty {
  color: var(--p-text-muted-color);
  font-style: italic;
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
