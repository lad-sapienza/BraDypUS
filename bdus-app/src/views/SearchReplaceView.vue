<template>
  <AppLayout>
    <div class="sr-page">

      <div class="page-header">
        <h2><i class="pi pi-search-plus" /> {{ t('find_replace') }}</h2>
      </div>

      <div class="sr-card">

        <!-- Table -->
        <div class="sr-field">
          <label class="sr-label">{{ t('table') }}</label>
          <Select
            v-model="selectedTable"
            :options="tables"
            optionLabel="label"
            optionValue="name"
            :placeholder="t('select_table')"
            :loading="loadingTables"
            class="w-full"
            @change="onTableChange"
          />
        </div>

        <!-- Field -->
        <div class="sr-field">
          <label class="sr-label">{{ t('field') }}</label>
          <Select
            v-model="selectedField"
            :options="fields"
            optionLabel="label"
            optionValue="name"
            :placeholder="selectedTable ? t('select_field') : t('select_table_first')"
            :loading="loadingFields"
            :disabled="!selectedTable"
            class="w-full"
          />
        </div>

        <!-- Search string -->
        <div class="sr-field">
          <label class="sr-label">{{ t('search_string') }}</label>
          <InputText
            v-model="searchStr"
            :placeholder="t('search_string_placeholder')"
            class="w-full"
          />
        </div>

        <!-- Replace string -->
        <div class="sr-field">
          <label class="sr-label">{{ t('replace_string') }}</label>
          <InputText
            v-model="replaceStr"
            :placeholder="t('replace_string_placeholder')"
            class="w-full"
          />
        </div>

        <!-- Result message -->
        <Message v-if="result" :severity="result.severity" class="sr-result">
          {{ result.text }}
        </Message>

        <!-- Action -->
        <div class="sr-actions">
          <Button
            :label="t('find_replace')"
            icon="pi pi-search-plus"
            :disabled="!canSubmit"
            :loading="running"
            @click="confirmReplace"
          />
        </div>

      </div>
    </div>

    <!-- Confirmation dialog -->
    <ConfirmDialog />
  </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useConfirm } from 'primevue/useconfirm'
import { useToast }   from 'primevue/usetoast'
import AppLayout      from '@/components/AppLayout.vue'
import { useI18n }    from '@/i18n'
import { api }        from '@/api'
import Select         from 'primevue/select'
import InputText      from 'primevue/inputtext'
import Button         from 'primevue/button'
import Message        from 'primevue/message'
import ConfirmDialog  from 'primevue/confirmdialog'

const { t }   = useI18n()
const confirm = useConfirm()
const toast   = useToast()

// ── State ────────────────────────────────────────────────────────────────────
const tables        = ref([])
const fields        = ref([])
const selectedTable = ref(null)
const selectedField = ref(null)
const searchStr     = ref('')
const replaceStr    = ref('')
const loadingTables = ref(false)
const loadingFields = ref(false)
const running       = ref(false)
const result        = ref(null)   // { severity, text }

const canSubmit = computed(() =>
  selectedTable.value && selectedField.value && searchStr.value.trim()
)

// ── Load tables on mount ─────────────────────────────────────────────────────
onMounted(async () => {
  loadingTables.value = true
  try {
    const res = await api.get('search_replace_ctrl', 'getTableList')
    tables.value = res.tables ?? []
  } finally {
    loadingTables.value = false
  }
})

// ── Load fields when table changes ───────────────────────────────────────────
async function onTableChange() {
  selectedField.value = null
  fields.value        = []
  result.value        = null
  if (!selectedTable.value) return

  loadingFields.value = true
  try {
    const res = await api.get('search_replace_ctrl', 'getFieldList', { tb: selectedTable.value })
    fields.value = res.fields ?? []
  } finally {
    loadingFields.value = false
  }
}

// ── Confirm + execute ────────────────────────────────────────────────────────
function confirmReplace() {
  const tableName = tables.value.find(t => t.name === selectedTable.value)?.label ?? selectedTable.value
  const fieldName = fields.value.find(f => f.name === selectedField.value)?.label ?? selectedField.value

  confirm.require({
    header:  t('find_replace'),
    message: t('sr_confirm_message', tableName, fieldName, searchStr.value, replaceStr.value || '∅'),
    icon:    'pi pi-exclamation-triangle',
    acceptClass: 'p-button-danger',
    accept:  doReplace,
  })
}

async function doReplace() {
  running.value = true
  result.value  = null
  try {
    const res = await api.post('search_replace_ctrl', 'doReplace', {
      tb:      selectedTable.value,
      fld:     selectedField.value,
      search:  searchStr.value,
      replace: replaceStr.value,
    })
    if (res.status === 'success') {
      result.value = {
        severity: 'success',
        text: t('ok_search_replace', res.affected),
      }
    } else {
      result.value = {
        severity: 'error',
        text: t(res.code ?? 'error'),
      }
    }
  } catch (e) {
    toast.add({ severity: 'error', summary: t('error'), detail: String(e), life: 4000 })
  } finally {
    running.value = false
  }
}
</script>

<style scoped>
.sr-page {
  padding: 2rem;
  overflow-y: auto;
  height: 100%;
}

.page-header {
  margin-bottom: 1.5rem;
}

.page-header h2 {
  font-size: 1.25rem;
  font-weight: 600;
  margin: 0;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.sr-card {
  max-width: 560px;
  display: flex;
  flex-direction: column;
  gap: 1.1rem;
  background: var(--p-surface-card);
  border: 1px solid var(--p-surface-border);
  border-radius: var(--p-border-radius-lg);
  padding: 1.5rem;
}

.sr-field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.sr-label {
  font-size: 0.85rem;
  font-weight: 600;
  color: var(--p-text-muted-color);
}

.sr-result { margin: 0; }

.sr-actions {
  display: flex;
  justify-content: flex-end;
  padding-top: 0.5rem;
  border-top: 1px solid var(--p-surface-border);
}
</style>
