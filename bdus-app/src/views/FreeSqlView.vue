<template>
  <AppLayout>
    <div class="free-sql-view">

      <div class="free-sql-header">
        <h2>
          <i class="pi pi-code" style="margin-right:0.5rem" />
          {{ t('free_sql') }}
        </h2>
        <Message severity="warn" :closable="false" class="free-sql-warning">
          {{ t('free_sql_warning') }}
        </Message>
      </div>

      <!-- ── Password gate ──────────────────────────────────────────── -->
      <div v-if="!unlocked" class="free-sql-panel gate-panel">
        <p class="gate-desc">{{ t('free_sql_gate_desc') }}</p>

        <div class="gate-form">
          <Password
            v-model="gatePassword"
            :placeholder="t('your_password')"
            :feedback="false"
            toggleMask
            @keyup.enter="unlock"
            class="gate-input"
          />
          <Button
            :label="t('free_sql_confirm')"
            icon="pi pi-lock-open"
            :loading="unlocking"
            :disabled="!gatePassword"
            @click="unlock"
          />
        </div>

        <Message v-if="gateError" severity="error" :closable="false">
          {{ t(gateError) }}
        </Message>
      </div>

      <!-- ── SQL editor ─────────────────────────────────────────────── -->
      <template v-else>
        <div class="free-sql-panel editor-panel">
          <Textarea
            v-model="sql"
            :placeholder="t('free_sql_placeholder')"
            rows="8"
            class="sql-textarea"
            @keydown.ctrl.enter.prevent="runQuery"
            @keydown.meta.enter.prevent="runQuery"
          />

          <div class="editor-actions">
            <span class="kbd-hint">Ctrl+Enter {{ t('free_sql_run_shortcut') }}</span>
            <div class="editor-btns">
              <Button
                :label="t('free_sql_clear')"
                icon="pi pi-trash"
                severity="secondary"
                outlined
                size="small"
                :disabled="!sql.trim()"
                @click="clearAll"
              />
              <Button
                :label="t('free_sql_run')"
                icon="pi pi-play"
                :loading="running"
                :disabled="!sql.trim()"
                @click="runQuery"
              />
            </div>
          </div>
        </div>

        <!-- ── Results ─────────────────────────────────────────────── -->
        <div v-if="result" class="free-sql-panel result-panel">

          <!-- Error -->
          <Message v-if="result.status === 'error'" severity="error" :closable="false">
            {{ t('error_free_sql_run') }}
            <span v-if="result.detail"> — <code>{{ result.detail }}</code></span>
          </Message>

          <!-- SELECT results -->
          <template v-else-if="result.rows !== undefined">
            <div class="result-header">
              <span class="result-count">{{ t('free_sql_rows', result.total) }}</span>
              <Button
                v-if="result.rows.length"
                :label="t('export_csv')"
                icon="pi pi-download"
                size="small"
                severity="secondary"
                outlined
                @click="exportCsv"
              />
            </div>
            <Message v-if="!result.rows.length" severity="info" :closable="false">
              {{ t('free_sql_empty') }}
            </Message>
            <div v-else class="result-table-wrap">
              <DataTable
                :value="result.rows"
                size="small"
                striped-rows
                scrollable
                scroll-height="400px"
                paginator
                :rows="50"
                class="result-table"
              >
                <Column
                  v-for="col in result.columns"
                  :key="col"
                  :field="col"
                  :header="col"
                  sortable
                />
              </DataTable>
            </div>
          </template>

          <!-- DML result -->
          <template v-else>
            <Message severity="success" :closable="false">
              {{ t('ok_free_sql_run', result.affected ?? 0) }}
            </Message>
          </template>
        </div>
      </template>

    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import AppLayout  from '@/components/AppLayout.vue'
import { api }    from '@/api'
import { useI18n } from '@/i18n'
import Button    from 'primevue/button'
import Message   from 'primevue/message'
import Password  from 'primevue/password'
import Textarea  from 'primevue/textarea'
import DataTable from 'primevue/datatable'
import Column    from 'primevue/column'

const { t } = useI18n()

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
