<template>
  <AppLayout>
    <div class="migrations-view">

      <!-- Header -->
      <div class="mv-header">
        <span class="mv-title">{{ t('migrations') }}</span>
        <p class="mv-hint">{{ t('migrations_hint') }}</p>
      </div>

      <!-- Loading -->
      <div v-if="loading" class="mv-loading">
        <ProgressSpinner style="width:32px;height:32px" />
      </div>

      <!-- Error -->
      <Message v-else-if="error" severity="error">{{ error }}</Message>

      <!-- Content -->
      <template v-else>
        <!-- Summary badge -->
        <div class="mv-summary">
          <Tag
            v-if="applied === total"
            :value="t('migrations_all_applied')"
            severity="success"
            icon="pi pi-check-circle"
          />
          <Tag
            v-else
            :value="tf('migrations_status_ok', applied, total)"
            severity="warn"
            icon="pi pi-exclamation-triangle"
          />
        </div>

        <!-- Table -->
        <DataTable
          :value="migrations"
          size="small"
          stripedRows
          class="mv-table"
        >
          <!-- # -->
          <Column header="#" style="width:3.5rem; text-align:center">
            <template #body="{ index }">
              <span class="mv-idx">{{ index + 1 }}</span>
            </template>
          </Column>

          <!-- Name -->
          <Column :header="t('migrations_col_name')" field="name">
            <template #body="{ data }">
              <code class="mv-name">{{ data.name }}</code>
            </template>
          </Column>

          <!-- Status -->
          <Column :header="t('migrations_col_status')" style="width:9rem">
            <template #body="{ data }">
              <Tag
                :value="data.applied ? t('migration_applied') : t('migration_pending')"
                :severity="data.applied ? 'success' : 'warn'"
                :icon="data.applied ? 'pi pi-check' : 'pi pi-clock'"
              />
            </template>
          </Column>

          <!-- Applied at -->
          <Column :header="t('migrations_col_applied_at')" style="width:12rem">
            <template #body="{ data }">
              <span v-if="data.applied_at" class="mv-date">{{ data.applied_at }}</span>
              <span v-else class="mv-date mv-date-empty">—</span>
            </template>
          </Column>
        </DataTable>
      </template>

    </div>
  </AppLayout>
</template>

<script setup>
import { ref, onMounted }  from 'vue'
import AppLayout           from '@/components/AppLayout.vue'
import ProgressSpinner     from 'primevue/progressspinner'
import Message             from 'primevue/message'
import DataTable           from 'primevue/datatable'
import Column              from 'primevue/column'
import Tag                 from 'primevue/tag'
import { api }             from '@/api'
import { useI18n }         from '@/i18n'

const { t } = useI18n()

/** Simple sprintf-style helper for locale strings with positional %s */
function tf(key, ...args) {
  let s = t(key)
  for (const arg of args) s = s.replace('%s', arg)
  return s
}

const loading    = ref(true)
const error      = ref(null)
const migrations = ref([])
const total      = ref(0)
const applied    = ref(0)

onMounted(async () => {
  try {
    const res = await api.get('/api/migrations')
    if (res.status === 'error') throw new Error(res.code ?? 'generic_error')
    migrations.value = res.migrations ?? []
    total.value      = res.total      ?? 0
    applied.value    = res.applied    ?? 0
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
})
</script>

<style scoped>
.migrations-view {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-height: 0;
  padding: 1.5rem;
  gap: 1.25rem;
  overflow-y: auto;
  max-width: 860px;
}

.mv-loading {
  display: flex;
  justify-content: center;
  padding: 2rem;
}

/* ── Header ── */
.mv-header {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  flex-shrink: 0;
}

.mv-title {
  font-size: 1.4rem;
  font-weight: 700;
  color: var(--p-primary-color);
}

.mv-hint {
  font-size: 0.85rem;
  color: var(--p-text-muted-color);
  margin: 0;
}

/* ── Summary ── */
.mv-summary {
  flex-shrink: 0;
}

/* ── Table ── */
.mv-table {
  flex: 1;
  min-height: 0;
}

.mv-idx {
  font-size: 0.75rem;
  color: var(--p-text-muted-color);
  display: block;
  text-align: center;
}

.mv-name {
  font-family: monospace;
  font-size: 0.82rem;
  background: var(--p-content-hover-background);
  padding: 0.1em 0.4em;
  border-radius: 3px;
}

.mv-date {
  font-size: 0.82rem;
  font-family: monospace;
}

.mv-date-empty {
  color: var(--p-text-muted-color);
}
</style>
