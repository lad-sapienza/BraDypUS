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
        <ASpin size="large" />
      </div>

      <!-- Error -->
      <AAlert v-else-if="error" type="error" :message="error" show-icon />

      <!-- Content -->
      <template v-else>
        <!-- Summary badge -->
        <div class="mv-summary">
          <ATag v-if="applied === total" color="success">
            <CheckCircleOutlined style="margin-right: 0.35rem" />{{ t('migrations_all_applied') }}
          </ATag>
          <ATag v-else color="warning">
            <WarningOutlined style="margin-right: 0.35rem" />{{ tf('migrations_status_ok', applied, total) }}
          </ATag>
        </div>

        <!-- Table -->
        <ATable
          :columns="columns"
          :dataSource="migrations"
          :pagination="false"
          size="small"
          rowKey="name"
          class="mv-table"
        >
          <template #bodyCell="{ column, record, index }">
            <template v-if="column.key === 'idx'">
              <span class="mv-idx">{{ index + 1 }}</span>
            </template>
            <template v-else-if="column.key === 'name'">
              <code class="mv-name">{{ record.name }}</code>
            </template>
            <template v-else-if="column.key === 'status'">
              <ATag :color="record.applied ? 'success' : 'warning'">
                <component :is="record.applied ? CheckOutlined : ClockCircleOutlined" style="margin-right: 0.35rem" />{{ record.applied ? t('migration_applied') : t('migration_pending') }}
              </ATag>
            </template>
            <template v-else-if="column.key === 'applied_at'">
              <span v-if="record.applied_at" class="mv-date">{{ record.applied_at }}</span>
              <span v-else class="mv-date mv-date-empty">—</span>
            </template>
          </template>
        </ATable>
      </template>

    </div>
  </AppLayout>
</template>

<script setup>
import { CheckCircleOutlined, CheckOutlined, ClockCircleOutlined, WarningOutlined } from '@ant-design/icons-vue'
import { ref, computed, onMounted } from 'vue'
import AppLayout           from '@/components/AppLayout.vue'
import { Table as ATable, Spin as ASpin, Alert as AAlert, Tag as ATag } from 'ant-design-vue'
import { api }             from '@/api'
import { useI18n }         from '@/i18n'

const { t } = useI18n()

const columns = computed(() => [
  { title: '#',                            key: 'idx',        width: 56 },
  { title: t('migrations_col_name'),       dataIndex: 'name', key: 'name' },
  { title: t('migrations_col_status'),     key: 'status',     width: 144 },
  { title: t('migrations_col_applied_at'), key: 'applied_at', width: 192 },
])

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
