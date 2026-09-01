<template>
  <AppLayout>
    <div class="sr-page">

      <div class="page-header">
        <h2><SearchOutlined /> {{ t('find_replace') }}</h2>
      </div>

      <div class="sr-card">

        <!-- Table -->
        <div class="sr-field">
          <label class="sr-label">{{ t('table') }}</label>
          <ASelect
            v-model:value="selectedTable"
            :options="tableOptions"
            :placeholder="t('select_table')"
            :loading="loadingTables"
            class="w-full"
            @change="onTableChange"
          />
        </div>

        <!-- Field -->
        <div class="sr-field">
          <label class="sr-label">{{ t('field') }}</label>
          <ASelect
            v-model:value="selectedField"
            :options="fieldOptions"
            :placeholder="selectedTable ? t('select_field') : t('select_table_first')"
            :loading="loadingFields"
            :disabled="!selectedTable"
            class="w-full"
          />
        </div>

        <!-- Search string -->
        <div class="sr-field">
          <label class="sr-label">{{ t('search_string') }}</label>
          <AInput
            v-model:value="searchStr"
            :placeholder="t('search_string_placeholder')"
            class="w-full"
          />
        </div>

        <!-- Replace string -->
        <div class="sr-field">
          <label class="sr-label">{{ t('replace_string') }}</label>
          <AInput
            v-model:value="replaceStr"
            :placeholder="t('replace_string_placeholder')"
            class="w-full"
          />
        </div>

        <!-- Action -->
        <div class="sr-actions">
          <AButton type="primary" :disabled="!canSubmit" :loading="running" @click="confirmReplace">
            <template #icon><SearchOutlined /></template>
            {{ t('find_replace') }}
          </AButton>
        </div>

      </div>
    </div>

    <!-- Result dialog -->
    <AModal
      v-model:open="showResult"
      :title="t('find_replace')"
      :style="{ width: '26rem' }"
    >
      <div class="sr-result-body">
        <component
          :is="result?.severity === 'success' ? CheckCircleOutlined : CloseCircleOutlined"
          :class="result?.severity === 'success' ? 'sr-result-icon sr-result-success' : 'sr-result-icon sr-result-error'"
        />
        <span>{{ result?.text }}</span>
      </div>
      <template #footer>
        <AButton type="primary" @click="showResult = false">{{ t('close') }}</AButton>
      </template>
    </AModal>
  </AppLayout>
</template>

<script setup>
import { CheckCircleOutlined, CloseCircleOutlined, SearchOutlined } from '@ant-design/icons-vue'
import { ref, computed, onMounted } from 'vue'
import { useToast, useConfirm } from '@/composables/useNotify'
import AppLayout      from '@/components/AppLayout.vue'
import { useI18n }    from '@/i18n'
import { api }        from '@/api'
import { Select as ASelect, Input as AInput, Button as AButton, Modal as AModal } from 'ant-design-vue'

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
const showResult    = ref(false)

const canSubmit = computed(() =>
  selectedTable.value && selectedField.value && searchStr.value.trim()
)

const tableOptions = computed(() => tables.value.map(t => ({ value: t.name, label: t.label })))
const fieldOptions  = computed(() => fields.value.map(f => ({ value: f.name, label: f.label })))

// ── Load tables on mount ─────────────────────────────────────────────────────
onMounted(async () => {
  loadingTables.value = true
  try {
    const res = await api.get('/api/search-replace/tables')
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
    const res = await api.get(`/api/search-replace/${selectedTable.value}/fields`)
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
    const res = await api.post('/api/search-replace', {
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
    showResult.value = true
  } catch (e) {
    result.value = { severity: 'error', text: String(e) }
    showResult.value = true
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

.sr-result-body {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.25rem 0;
}

.sr-result-icon {
  font-size: 1.5rem;
  flex-shrink: 0;
}

.sr-result-success { color: var(--p-green-500); }
.sr-result-error   { color: var(--p-red-500);   }

.sr-actions {
  display: flex;
  justify-content: flex-end;
  padding-top: 0.5rem;
  border-top: 1px solid var(--p-surface-border);
}
</style>
