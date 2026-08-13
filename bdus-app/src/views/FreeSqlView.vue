<template>
  <AppLayout>
    <div class="free-sql-view">

      <div class="free-sql-header">
        <h2>
          <i class="pi pi-code" style="margin-right:0.5rem" />
          {{ t('free_sql') }}
        </h2>
        <AAlert type="warning" :closable="false" show-icon :message="t('free_sql_warning')" class="free-sql-warning" />
      </div>

      <!-- ── Password gate ──────────────────────────────────────────── -->
      <div v-if="!unlocked" class="free-sql-panel gate-panel">
        <p class="gate-desc">{{ t('free_sql_gate_desc') }}</p>

        <div class="gate-form">
          <AInputPassword
            v-model:value="gatePassword"
            :placeholder="t('your_password')"
            @keyup.enter="unlock"
            class="gate-input"
          />
          <AButton type="primary" :loading="unlocking" :disabled="!gatePassword" @click="unlock">
            <template #icon><i class="pi pi-lock-open" /></template>
            {{ t('free_sql_confirm') }}
          </AButton>
        </div>

        <AAlert v-if="gateError" type="error" :closable="false" show-icon :message="t(gateError)" />
      </div>

      <!-- ── SQL editor ─────────────────────────────────────────────── -->
      <template v-else>
        <div class="free-sql-panel editor-panel">
          <ATextarea
            v-model:value="sql"
            :placeholder="t('free_sql_placeholder')"
            :rows="8"
            class="sql-textarea"
            @keydown.ctrl.enter.prevent="runQuery"
            @keydown.meta.enter.prevent="runQuery"
          />

          <div class="editor-actions">
            <span class="kbd-hint">Ctrl+Enter {{ t('free_sql_run_shortcut') }}</span>
            <div class="editor-btns">
              <AButton size="small" :disabled="!sql.trim()" @click="clearAll">
                <template #icon><i class="pi pi-trash" /></template>
                {{ t('free_sql_clear') }}
              </AButton>
              <AButton type="primary" :loading="running" :disabled="!sql.trim()" @click="runQuery">
                <template #icon><i class="pi pi-play" /></template>
                {{ t('free_sql_run') }}
              </AButton>
            </div>
          </div>
        </div>

        <!-- ── Results ─────────────────────────────────────────────── -->
        <div v-if="result" class="free-sql-panel result-panel">

          <!-- Error -->
          <AAlert v-if="result.status === 'error'" type="error" :closable="false" show-icon>
            <template #message>
              {{ t('error_free_sql_run') }}
              <span v-if="result.detail"> — <code>{{ result.detail }}</code></span>
            </template>
          </AAlert>

          <!-- SELECT results -->
          <template v-else-if="result.rows !== undefined">
            <div class="result-header">
              <span class="result-count">{{ t('free_sql_rows', result.total) }}</span>
              <AButton v-if="result.rows.length" size="small" @click="exportCsv">
                <template #icon><i class="pi pi-download" /></template>
                {{ t('export_csv') }}
              </AButton>
            </div>
            <AAlert v-if="!result.rows.length" type="info" :closable="false" show-icon :message="t('free_sql_empty')" />
            <div v-else class="result-table-wrap">
              <ATable
                :columns="resultColumns"
                :dataSource="resultRows"
                :pagination="{ pageSize: 50, position: ['bottomCenter'] }"
                :scroll="{ y: 400 }"
                size="small"
                rowKey="__key"
                class="result-table"
              />
            </div>
          </template>

          <!-- DML result -->
          <template v-else>
            <AAlert type="success" :closable="false" show-icon :message="t('ok_free_sql_run', result.affected ?? 0)" />
          </template>
        </div>
      </template>

    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import AppLayout  from '@/components/AppLayout.vue'
import { api }    from '@/api'
import { useI18n } from '@/i18n'
import { Table as ATable, Button as AButton, Alert as AAlert, Input } from 'ant-design-vue'

const AInputPassword = Input.Password
const ATextarea      = Input.TextArea

const { t } = useI18n()

// ── SQL result table: dynamic columns, unknown ahead of time ────────────
function compareValues(a, b) {
  if (a == null && b == null) return 0
  if (a == null) return -1
  if (b == null) return 1
  if (typeof a === 'number' && typeof b === 'number') return a - b
  return String(a).localeCompare(String(b))
}

const resultColumns = computed(() =>
  (result.value?.columns ?? []).map(col => ({
    title: col,
    dataIndex: col,
    key: col,
    sorter: (a, b) => compareValues(a[col], b[col]),
    ellipsis: true,
  }))
)

const resultRows = computed(() =>
  (result.value?.rows ?? []).map((row, i) => ({ ...row, __key: i }))
)

// ── Password gate ──────────────────────────────────────────────────────
const unlocked     = ref(false)
const gatePassword = ref('')
const unlocking    = ref(false)
const gateError    = ref(null)

async function unlock() {
  if (!gatePassword.value) return
  gateError.value = null
  unlocking.value = true
  try {
    const res = await api.post('/api/free-sql/verify', {
      password: gatePassword.value,
    })
    if (res.status === 'success') {
      unlocked.value = true
      gatePassword.value = ''
    } else {
      gateError.value = res.code ?? 'generic_error'
    }
  } catch (e) {
    gateError.value = 'generic_error'
  } finally {
    unlocking.value = false
  }
}

// ── SQL editor ─────────────────────────────────────────────────────────
const sql     = ref('')
const running = ref(false)
const result  = ref(null)

async function runQuery() {
  if (!sql.value.trim()) return
  running.value = true
  result.value  = null
  try {
    const res = await api.post('/api/free-sql/run', { sql: sql.value })
    result.value = res
  } catch (e) {
    result.value = { status: 'error', detail: e.message }
  } finally {
    running.value = false
  }
}

function clearAll() {
  sql.value    = ''
  result.value = null
}

// ── CSV export ─────────────────────────────────────────────────────────
function exportCsv() {
  if (!result.value?.rows?.length) return
  const cols = result.value.columns
  const lines = [
    cols.join(','),
    ...result.value.rows.map(row =>
      cols.map(c => {
        const v = row[c] ?? ''
        const s = String(v)
        return s.includes(',') || s.includes('"') || s.includes('\n')
          ? `"${s.replace(/"/g, '""')}"`
          : s
      }).join(',')
    ),
  ]
  const blob = new Blob([lines.join('\n')], { type: 'text/csv' })
  const a    = document.createElement('a')
  a.href     = URL.createObjectURL(blob)
  a.download = 'query_result.csv'
  a.click()
  URL.revokeObjectURL(a.href)
}
</script>

<style scoped>
.free-sql-view {
  max-width: 1000px;
  margin: 0 auto;
  padding: 1.5rem 1rem;
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}

.free-sql-header h2 {
  margin: 0 0 0.75rem;
  font-size: 1.3rem;
  display: flex;
  align-items: center;
}

.free-sql-warning { margin: 0; }

.free-sql-panel {
  background: var(--p-content-background, #fff);
  border: 1px solid var(--p-content-border-color, #e0e0e0);
  border-radius: 8px;
  padding: 1.5rem;
}

/* ── Gate ── */
.gate-panel {
  max-width: 480px;
}

.gate-desc {
  margin: 0 0 1rem;
  color: var(--p-text-muted-color, #666);
  font-size: 0.9rem;
}

.gate-form {
  display: flex;
  gap: 0.75rem;
  align-items: flex-start;
}

.gate-input { flex: 1; }

/* ── Editor ── */
.editor-panel { display: flex; flex-direction: column; gap: 0.75rem; }

.sql-textarea {
  width: 100%;
  font-family: 'Courier New', Courier, monospace;
  font-size: 0.88rem;
  resize: vertical;
  min-height: 160px;
}

.editor-actions {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.kbd-hint {
  font-size: 0.78rem;
  color: var(--p-text-muted-color, #999);
}

.editor-btns { display: flex; gap: 0.5rem; }

/* ── Results ── */
.result-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 0.75rem;
}

.result-count {
  font-size: 0.85rem;
  color: var(--p-text-muted-color, #666);
}

.result-table-wrap {
  overflow: auto;
  border: 1px solid var(--p-content-border-color, #e0e0e0);
  border-radius: 6px;
}
</style>
