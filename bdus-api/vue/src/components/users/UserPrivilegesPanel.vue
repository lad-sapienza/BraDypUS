<template>
  <div class="privs-panel">

    <!-- List of existing overrides -->
    <div v-if="loading" class="privs-loading">
      <ProgressSpinner style="width:24px;height:24px" />
    </div>

    <template v-else>
      <div v-if="!rows.length" class="privs-empty">
        <i class="pi pi-info-circle" />
        {{ t('no_table_overrides') }}
      </div>

      <DataTable
        v-else
        :value="rows"
        size="small"
        class="privs-table"
      >
        <Column :header="t('table')" style="min-width:10rem">
          <template #body="{ data }">
            <code class="privs-table-name">{{ data.table_name }}</code>
          </template>
        </Column>
        <Column :header="t('global_privilege')" style="min-width:8rem">
          <template #body="{ data }">
            <Tag :value="privilegeLabel(data.privilege)" :severity="privilegeSeverity(data.privilege)" rounded />
          </template>
        </Column>
        <Column :header="t('privilege_subset')" style="min-width:12rem">
          <template #body="{ data }">
            <code v-if="data.subset" class="privs-subset">{{ data.subset }}</code>
            <span v-else class="privs-empty-cell">—</span>
          </template>
        </Column>
        <Column style="width:5rem">
          <template #body="{ data }">
            <Button
              icon="pi pi-pencil"
              text rounded size="small"
              :title="t('edit')"
              @click="startEdit(data)"
            />
            <Button
              icon="pi pi-trash"
              text rounded size="small"
              severity="danger"
              :title="t('delete')"
              :loading="deleting === data.id"
              @click="deleteRow(data.id)"
            />
          </template>
        </Column>
      </DataTable>

      <!-- Add / edit form -->
      <div class="privs-form">
        <div class="privs-form-title">
          {{ editingId ? t('edit') : t('add_table_privilege') }}
        </div>
        <div class="privs-form-row">
          <Select
            v-model="form.table_name"
            :options="tableOptions"
            optionLabel="label"
            optionValue="value"
            :placeholder="t('table')"
            :disabled="!!editingId"
            class="privs-form-table"
            filter
          />
          <Select
            v-model="form.privilege"
            :options="privilegeOptions"
            optionLabel="label"
            optionValue="value"
            :placeholder="t('global_privilege')"
            class="privs-form-priv"
          />
        </div>
        <div class="privs-form-row">
          <InputText
            v-model="form.subset"
            :placeholder="t('privilege_subset_hint')"
            class="privs-form-subset"
          />
          <Button
            icon="pi pi-check"
            :label="t('save')"
            size="small"
            :disabled="!form.table_name || !form.privilege"
            :loading="saving"
            @click="saveRow"
          />
          <Button
            v-if="editingId"
            icon="pi pi-times"
            :label="t('cancel')"
            size="small"
            severity="secondary"
            outlined
            @click="resetForm"
          />
        </div>
      </div>
    </template>

  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useToast } from 'primevue/usetoast'
import DataTable     from 'primevue/datatable'
import Column        from 'primevue/column'
import Button        from 'primevue/button'
import Select        from 'primevue/select'
import InputText     from 'primevue/inputtext'
import Tag           from 'primevue/tag'
import ProgressSpinner from 'primevue/progressspinner'
import { api }       from '@/api'
import { useI18n }   from '@/i18n'
import { useTables } from '@/composables/useTables'

const { t }     = useI18n()
const toast     = useToast()
const { tables, loadTables } = useTables()

const props = defineProps({
  userId:          { type: Number, required: true },
  /** Current user's privilege level — overrides must be >= this value */
  callerPrivilege: { type: Number, default: 1 },
})

// ── Privilege levels (mirrors utils.inc $privilege_array) ─────────
const PRIVILEGE_LEVELS = [
  { value: 1,  label: 'Super admin',   severity: 'danger'  },
  { value: 10, label: 'Admin',         severity: 'warning' },
  { value: 20, label: 'Writer',        severity: 'info'    },
  { value: 25, label: 'Self writer',   severity: 'info'    },
  { value: 30, label: 'Reader',        severity: 'success' },
  { value: 40, label: 'Waiting',       severity: 'secondary' },
]

/** Only offer levels the caller is allowed to assign (>= their own level) */
const privilegeOptions = computed(() =>
  PRIVILEGE_LEVELS.filter(p => p.value >= props.callerPrivilege)
)

function privilegeLabel(value) {
  return PRIVILEGE_LEVELS.find(p => p.value === Number(value))?.label ?? String(value)
}
function privilegeSeverity(value) {
  return PRIVILEGE_LEVELS.find(p => p.value === Number(value))?.severity ?? 'secondary'
}

// ── Table options from shared cache ──────────────────────────────
const tableOptions = computed(() =>
  tables.value.map(tb => ({ value: tb.name, label: tb.label ?? tb.name }))
)

// ── State ─────────────────────────────────────────────────────────
const rows      = ref([])
const loading   = ref(false)
const saving    = ref(false)
const deleting  = ref(null)
const editingId = ref(null)

const emptyForm = () => ({ table_name: null, privilege: null, subset: '' })
const form      = ref(emptyForm())

// ── Load overrides ────────────────────────────────────────────────
async function load() {
  loading.value = true
  try {
    const res = await api.get('user_ctrl', 'getTablePrivileges', { user_id: props.userId })
    rows.value = Array.isArray(res) ? res : []
  } catch (e) {
    toast.add({ severity: 'error', summary: t('table_privileges'), detail: String(e), life: 4000 })
  } finally {
    loading.value = false
  }
}

// ── Save (add or update) ──────────────────────────────────────────
async function saveRow() {
  if (!form.value.table_name || !form.value.privilege) return
  saving.value = true
  try {
    const res = await api.post('user_ctrl', 'saveTablePrivilege', {
      user_id:    props.userId,
      table_name: form.value.table_name,
      privilege:  form.value.privilege,
      subset:     form.value.subset || '',
    })
    if (res.status === 'success') {
      toast.add({ severity: 'success', summary: t('table_privileges'), detail: t('privilege_saved'), life: 2500 })
      resetForm()
      await load()
    } else {
      toast.add({ severity: 'warn', summary: t('table_privileges'), detail: t(res.code ?? 'error'), life: 4000 })
    }
  } catch (e) {
    toast.add({ severity: 'error', summary: t('table_privileges'), detail: String(e), life: 4000 })
  } finally {
    saving.value = false
  }
}

// ── Edit existing row ────────────────────────────────────────────
function startEdit(row) {
  editingId.value    = row.id
  form.value = {
    table_name: row.table_name,
    privilege:  Number(row.privilege),
    subset:     row.subset ?? '',
  }
}

function resetForm() {
  editingId.value = null
  form.value = emptyForm()
}

// ── Delete ───────────────────────────────────────────────────────
async function deleteRow(id) {
  deleting.value = id
  try {
    const res = await api.get('user_ctrl', 'deleteTablePrivilege', { id })
    if (res.status === 'success') {
      toast.add({ severity: 'success', summary: t('table_privileges'), detail: t('privilege_deleted'), life: 2500 })
      await load()
    } else {
      toast.add({ severity: 'warn', summary: t('table_privileges'), detail: t(res.code ?? 'error'), life: 4000 })
    }
  } catch (e) {
    toast.add({ severity: 'error', summary: t('table_privileges'), detail: String(e), life: 4000 })
  } finally {
    deleting.value = null
  }
}

onMounted(async () => {
  await loadTables()
  await load()
})
</script>

<style scoped>
.privs-panel {
  padding: 0.75rem 1rem 1rem;
  background: var(--p-surface-ground);
  border-top: 1px solid var(--p-surface-border);
}

.privs-loading {
  display: flex;
  justify-content: center;
  padding: 1rem;
}

.privs-empty {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  color: var(--p-text-muted-color);
  font-size: 0.85rem;
  padding: 0.5rem 0;
}

.privs-table {
  margin-bottom: 1rem;
}

.privs-table-name {
  font-size: 0.8rem;
  background: var(--p-surface-card);
  border: 1px solid var(--p-surface-border);
  border-radius: 4px;
  padding: 0.1rem 0.4rem;
}

.privs-subset {
  font-size: 0.78rem;
  color: var(--p-text-muted-color);
}

.privs-empty-cell {
  color: var(--p-text-muted-color);
  font-size: 0.85rem;
}

/* Add/edit form */
.privs-form {
  border-top: 1px solid var(--p-surface-border);
  padding-top: 0.75rem;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.privs-form-title {
  font-size: 0.78rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--p-text-muted-color);
}

.privs-form-row {
  display: flex;
  gap: 0.5rem;
  align-items: center;
  flex-wrap: wrap;
}

.privs-form-table { flex: 2; min-width: 160px; }
.privs-form-priv  { flex: 1; min-width: 130px; }
.privs-form-subset { flex: 3; min-width: 200px; }
</style>
