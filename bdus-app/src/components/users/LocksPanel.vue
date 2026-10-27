<template>
  <div class="lp">
    <div class="lp-head">
      <p class="lp-hint">{{ t('grp_locks_hint') }}</p>
      <AButton type="primary" size="small" @click="openForm(null)">
        <template #icon><PlusOutlined /></template>
        {{ t('grp_lock_new') }}
      </AButton>
    </div>

    <ATable
      :columns="columns"
      :data-source="locks"
      :loading="loading"
      :pagination="false"
      size="small"
      row-key="id"
      :locale="{ emptyText: t('grp_no_locks') }"
    >
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'table'">
          <strong>{{ tableLabel(record.table) }}</strong>
        </template>
        <template v-else-if="column.key === 'filter'">
          <code class="lp-filter">{{ describe(record) }}</code>
        </template>
        <template v-else-if="column.key === 'frozen'">
          <ATag v-if="counts[record.id] != null" color="blue">{{ t('grp_lock_records', counts[record.id]) }}</ATag>
        </template>
        <template v-else-if="column.key === 'actions'">
          <AButton type="text" shape="circle" size="small" :title="t('edit')" @click="openForm(record)">
            <template #icon><EditOutlined /></template>
          </AButton>
          <AButton type="text" shape="circle" size="small" danger :title="t('delete')" @click="confirmDelete(record)">
            <template #icon><DeleteOutlined /></template>
          </AButton>
        </template>
      </template>
    </ATable>

    <AModal
      v-model:open="formOpen"
      :title="editingId ? t('grp_lock_edit') : t('grp_lock_new')"
      :width="720"
      :confirm-loading="saving"
      :ok-text="t('save')"
      :cancel-text="t('cancel')"
      destroy-on-close
      @ok="save"
    >
      <AAlert v-if="error" type="error" :message="error" show-icon :closable="false" class="lp-error" />
      <div class="lp-form">
        <label>{{ t('grp_table') }}
          <ASelect v-model:value="form.table" :options="tableOptions" :disabled="!!editingId" class="lp-full" />
        </label>
        <label>{{ t('grp_lock_note') }}
          <AInput v-model:value="form.note" :placeholder="t('grp_lock_note_placeholder')" />
        </label>
        <div>
          <div class="lp-label">{{ t('grp_lock_filter') }}</div>
          <FilterEditor v-if="form.table" ref="editor" :table="form.table" :filter="form.filter" />
        </div>
      </div>
    </AModal>
  </div>
</template>

<script setup>
// Table locks: rows matching a filter that nobody but admins can update or delete.
import { computed, onMounted, reactive, ref } from 'vue'
import { DeleteOutlined, EditOutlined, PlusOutlined } from '@ant-design/icons-vue'
import {
  Alert as AAlert, Button as AButton, Input as AInput, Modal as AModal,
  Select as ASelect, Table as ATable, Tag as ATag,
} from 'ant-design-vue'
import { api } from '@/api'
import { useI18n } from '@/i18n'
import { formula, filterToTree } from '@/utils/filterTree'
import { useConfirm, useToast } from '@/composables/useNotify'
import { useGroups } from '@/composables/useGroups'
import { useTables } from '@/composables/useTables'
import FilterEditor from './FilterEditor.vue'

const { t }   = useI18n()
const toast   = useToast()
const confirm = useConfirm()
const groupsApi = useGroups()
const { tables, loadTables } = useTables()

const locks   = ref([])
const counts  = reactive({})        // lock id → records frozen right now
const loading = ref(false)
const saving  = ref(false)
const formOpen  = ref(false)
const editingId = ref(null)
const error   = ref('')
const editor  = ref(null)
const form    = reactive({ table: '', note: '', filter: null })

const columns = computed(() => [
  { title: t('grp_table'),       key: 'table',   width: 180 },
  { title: t('grp_lock_filter'), key: 'filter' },
  { title: t('grp_lock_note'),   dataIndex: 'note', key: 'note', width: 220 },
  { title: '',                   key: 'frozen',  width: 150 },
  { title: '',                   key: 'actions', width: 90 },
])
const tableOptions = computed(() => tables.value.map(tb => ({ value: tb.name, label: tb.label || tb.name })))
const tableLabel   = name => tables.value.find(tb => tb.name === name)?.label || name

// The filter of a lock as a sentence ("Verificata = 1") when the builder can show it,
// as raw JSON otherwise. Needs the table's field labels, fetched once per table.
const configs = reactive({})   // table → { fields, operators }
async function ensureConfig(tb) {
  if (configs[tb]) return
  try {
    const res = await api.get(`/api/search/${tb}/config`)
    if (res.status !== 'error') configs[tb] = { fields: res.fields ?? [], operators: res.operators ?? [] }
  } catch { /* the JSON fallback is shown */ }
}
function describe(lock) {
  const cfg = configs[lock.table]
  if (!cfg) return JSON.stringify(lock.filter)
  const ctx = {
    mainTb: lock.table,
    fieldMeta: fld => cfg.fields.find(f => f.value === fld),
    operators: cfg.operators.map(o => o.value),
  }
  const tree = filterToTree(lock.filter, ctx)
  if (!tree) return JSON.stringify(lock.filter)
  return formula(tree, {
    fieldLabel: fld => cfg.fields.find(f => f.value === fld)?.label ?? fld,
    operatorLabel: op => t(cfg.operators.find(o => o.value === op)?.key ?? op).toLowerCase(),
    and: t('qb_and'),
    or: t('qb_or'),
  })
}

function fail(e) {
  toast.add({ severity: 'error', summary: t('grp_locks'), detail: e.message, life: 5000 })
}

async function load() {
  loading.value = true
  try {
    locks.value = await groupsApi.listLocks()
    await Promise.all([...new Set(locks.value.map(l => l.table))].map(ensureConfig))
    for (const lock of locks.value) {
      groupsApi.countRecords(lock.table, lock.filter).then(n => { counts[lock.id] = n }).catch(() => {})
    }
  } catch (e) { fail(e) } finally { loading.value = false }
}

function openForm(lock) {
  editingId.value = lock?.id ?? null
  form.table  = lock?.table ?? (tables.value[0]?.name ?? '')
  form.note   = lock?.note ?? ''
  form.filter = lock?.filter ?? null
  error.value = ''
  formOpen.value = true
}

async function save() {
  const res = editor.value?.getFilter()
  if (!res || res.error) {
    error.value = res?.error ?? ''
    return
  }
  saving.value = true
  error.value = ''
  const body = { table: form.table, filter: res.filter, note: form.note.trim() }
  try {
    if (editingId.value) await groupsApi.updateLock(editingId.value, body)
    else await groupsApi.createLock(body)
    toast.add({ severity: 'success', summary: t('grp_locks'), detail: t(editingId.value ? 'lock_updated' : 'lock_created'), life: 2500 })
    formOpen.value = false
    await load()
  } catch (e) {
    error.value = e.message
  } finally { saving.value = false }
}

function confirmDelete(lock) {
  confirm.require({
    message: t('grp_lock_delete_confirm'),
    header:  t('confirm'),
    icon:    'pi pi-exclamation-triangle',
    rejectProps: { label: t('cancel'), severity: 'secondary', outlined: true },
    acceptProps: { label: t('delete'), severity: 'danger' },
    accept: async () => {
      try {
        await groupsApi.deleteLock(lock.id)
        toast.add({ severity: 'success', summary: t('grp_locks'), detail: t('lock_deleted'), life: 2500 })
        await load()
      } catch (e) { fail(e) }
    },
  })
}

onMounted(async () => {
  await loadTables()
  await load()
})
</script>

<style scoped>
.lp { display: flex; flex-direction: column; gap: 0.9rem; padding: 0 1.5rem 1.5rem; }
.lp-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; }
.lp-hint { margin: 0; color: var(--p-text-muted-color); font-size: 0.88rem; max-width: 62ch; }
.lp-filter { font-size: 0.78rem; overflow-wrap: anywhere; }
.lp-form { display: flex; flex-direction: column; gap: 0.9rem; }
.lp-form label { display: flex; flex-direction: column; gap: 0.25rem; font-size: 0.85rem; }
.lp-label { font-size: 0.85rem; margin-bottom: 0.25rem; }
.lp-full { width: 100%; }
.lp-error { margin-bottom: 0.75rem; }
</style>
