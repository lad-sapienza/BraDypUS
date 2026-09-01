<template>
  <AppLayout>
    <div class="voc-page">

      <div class="page-header">
        <h2>{{ t('available_vocs') }}</h2>
        <AButton type="primary" size="small" @click="openNewVocDialog">
          <template #icon><PlusOutlined /></template>
          {{ t('new_voc') }}
        </AButton>
      </div>

      <div v-if="loading" class="loading">
        <ASpin size="large" />
      </div>

      <div v-else-if="vocs.length === 0" class="empty">
        <p>{{ t('no_vocabularies') }}</p>
      </div>

      <div v-else class="voc-layout">

        <!-- Left: vocabulary list with filter -->
        <div class="voc-names">
          <div class="voc-filter">
            <AInput
              v-model:value="filterText"
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
            <ABadge :count="voc.items.length" :number-style="{ backgroundColor: 'var(--p-surface-400, #94a3b8)' }" />
          </div>
          <div v-if="filteredVocs.length === 0" class="voc-filter-empty">—</div>
        </div>

        <!-- Right: items + usage info for selected vocabulary -->
        <div class="voc-items" v-if="selected">
          <div class="voc-items-header">
            <h3>{{ selected.name }}</h3>
            <AButton size="small" @click="openAddItemDialog(selected.name)">
              <template #icon><PlusOutlined /></template>
              {{ t('voc_add_item') }}
            </AButton>
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

          <!--
            AntD's core Table has no built-in drag-to-reorder-rows (PrimeVue's
            Column `rowReorder` was a single prop + a `@rowReorder` event) —
            same gap as the dropped column-reorder in DataView.vue. Replaced
            with up/down buttons
            rather than pulling in a drag library for one table.
          -->
          <ATable
            :columns="vocColumns"
            :dataSource="selected.items"
            :pagination="false"
            class="voc-table"
            size="small"
            rowKey="id"
          >
            <template #bodyCell="{ column, record, index }">
              <template v-if="column.key === 'reorder'">
                <div class="reorder-btns">
                  <button
                    class="reorder-btn"
                    :disabled="index === 0"
                    :title="t('move_up')"
                    @click="moveItem(index, -1)"
                  ><UpOutlined /></button>
                  <button
                    class="reorder-btn"
                    :disabled="index === selected.items.length - 1"
                    :title="t('move_down')"
                    @click="moveItem(index, 1)"
                  ><DownOutlined /></button>
                </div>
              </template>
              <template v-else-if="column.key === 'def'">
                <span
                  v-if="editingId !== record.id"
                  class="def-text"
                  @dblclick="startEdit(record)"
                >{{ record.def }}</span>
                <AInput
                  v-else
                  v-model:value="editingVal"
                  size="small"
                  autofocus
                  style="width: 100%"
                  @keyup.enter="saveEdit(record)"
                  @keyup.escape="cancelEdit"
                  @blur="saveEdit(record)"
                />
              </template>
              <template v-else-if="column.key === 'actions'">
                <AButton type="text" shape="circle" size="small"
                        @click="startEdit(record)" :disabled="editingId !== null"
                ><template #icon><EditOutlined /></template></AButton>
                <AButton type="text" shape="circle" danger size="small"
                        @click="confirmErase(record)" :disabled="editingId !== null"
                ><template #icon><DeleteOutlined /></template></AButton>
              </template>
            </template>
          </ATable>
        </div>

        <div class="voc-items voc-empty-hint" v-else>
          <p>{{ t('voc_select_hint') }}</p>
        </div>

      </div>
    </div>

    <!-- New vocabulary dialog -->
    <AModal v-model:open="newVocDialog" :title="t('new_voc')" width="360px">
      <div class="field">
        <label>{{ t('voc_name_label') }}</label>
        <AInput v-model:value="newVocName" style="width: 100%" :placeholder="t('voc_name_placeholder')" @keyup.enter="createVocAndItem" />
      </div>
      <div class="field">
        <label>{{ t('voc_first_item') }}</label>
        <AInput v-model:value="newVocDef" style="width: 100%" :placeholder="t('voc_first_def')" @keyup.enter="createVocAndItem" />
      </div>
      <template #footer>
        <AButton type="text" @click="newVocDialog = false">{{ t('cancel') }}</AButton>
        <AButton type="primary" :disabled="!newVocName || !newVocDef"
                :loading="saving" @click="createVocAndItem">
          <template #icon><CheckOutlined /></template>
          {{ t('create') }}
        </AButton>
      </template>
    </AModal>

    <!-- Add item dialog -->
    <AModal v-model:open="addItemDialog" :title="addItemHeader" width="360px">
      <div class="field">
        <label>{{ t('voc_item_def') }}</label>
        <AInput v-model:value="addItemDef" style="width: 100%" autofocus @keyup.enter="addItem" />
      </div>
      <template #footer>
        <AButton type="text" @click="addItemDialog = false">{{ t('cancel') }}</AButton>
        <AButton type="primary" :disabled="!addItemDef"
                :loading="saving" @click="addItem">
          <template #icon><CheckOutlined /></template>
          {{ t('voc_add_item') }}
        </AButton>
      </template>
    </AModal>

  </AppLayout>
</template>

<script setup>
import { CheckOutlined, DeleteOutlined, DownOutlined, EditOutlined, PlusOutlined, UpOutlined } from '@ant-design/icons-vue'
import { ref, computed, onMounted } from 'vue'
import { useI18n } from '@/i18n'
import { useToast, useConfirm } from '@/composables/useNotify'
import { api } from '@/api'
import AppLayout from '@/components/AppLayout.vue'
import { Table as ATable, Button as AButton, Input as AInput, Badge as ABadge, Modal as AModal, Spin as ASpin } from 'ant-design-vue'

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

const vocColumns = computed(() => [
  { title: '',                key: 'reorder', width: 40 },
  { title: t('voc_item_def'), key: 'def' },
  { title: '',                key: 'actions', width: 80 },
])

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

function moveItem(index, dir) {
  const items = selected.value.items
  const newIndex = index + dir
  if (newIndex < 0 || newIndex >= items.length) return
  const reordered = [...items]
  const [moved] = reordered.splice(index, 1)
  reordered.splice(newIndex, 0, moved)
  onReorder({ value: reordered })
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

.reorder-btns {
  display: flex;
  flex-direction: column;
}

.reorder-btn {
  border: none;
  background: transparent;
  color: var(--p-text-muted-color);
  cursor: pointer;
  padding: 0.05rem 0.2rem;
  line-height: 1;
  font-size: 0.7rem;
}

.reorder-btn:hover:not(:disabled) {
  color: var(--p-primary-color);
}

.reorder-btn:disabled {
  opacity: 0.3;
  cursor: default;
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
