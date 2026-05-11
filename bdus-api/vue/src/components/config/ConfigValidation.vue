<template>
  <div class="cfg-panel">
    <div class="cfg-panel-header">
      <h2><i class="pi pi-check-circle" /> {{ t('validate_app') }}</h2>
      <Button
        :label="t('validate_app')"
        icon="pi pi-refresh"
        size="small"
        :loading="loading"
        @click="load"
      />
    </div>

    <div v-if="loading" class="cfg-loading-center">
      <i class="pi pi-spin pi-spinner" />
    </div>

    <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>

    <div v-if="!loading && report.length" class="cfg-validation-report">

      <div class="cfg-val-toolbar">
        <label class="cfg-val-toggle">
          <input type="checkbox" v-model="onlyErrors" />
          {{ t('show_only_errors') }}
        </label>
      </div>

      <template v-for="(item, idx) in visibleReport" :key="idx">
        <!-- Section heading -->
        <div v-if="item.status === 'head'" class="cfg-val-head">{{ item.text }}</div>

        <!-- Status row -->
        <div
          v-else
          class="cfg-val-item"
          :class="`cfg-val-${item.status}`"
        >
          <i :class="iconFor(item.status)" />
          <span class="cfg-val-text">{{ item.text }}</span>
          <Button
            v-if="item.fix"
            :label="item.suggest"
            size="small"
            severity="danger"
            outlined
            class="cfg-val-fix"
            :loading="fixing === idx"
            @click="runFix(item, idx)"
          />
        </div>
      </template>

    </div>

    <div v-else-if="!loading && !error" class="cfg-empty-msg">
      {{ t('click_to_validate') }}
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import Button  from 'primevue/button'
import Message from 'primevue/message'
import { useI18n } from '@/i18n'
import { api }     from '@/api'
import { useToast } from 'primevue/usetoast'

const { t } = useI18n()
const toast = useToast()

const loading    = ref(false)
const error      = ref(null)
const report     = ref([])
const onlyErrors = ref(false)
const fixing     = ref(null)

const visibleReport = computed(() =>
  onlyErrors.value
    ? report.value.filter(i => i.status === 'head' || i.status === 'danger' || i.status === 'error')
    : report.value
)

function iconFor(status) {
  if (status === 'success') return 'pi pi-check-circle'
  if (status === 'warning') return 'pi pi-exclamation-triangle'
  if (status === 'danger')  return 'pi pi-times-circle'
  return 'pi pi-info-circle'
}

async function load() {
  loading.value = true
  error.value   = null
  try {
    const res = await api.get('config_ctrl', 'getValidationReport')
    if (res.status === 'error') throw new Error(t(res.code))
    report.value = res.report ?? []
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

async function runFix(item, idx) {
  // item.fix = [action, tb, col] – same convention as v4 config.fix(this, ...)
  const [action, tb, col] = item.fix
  fixing.value = idx
  try {
    const res = await api.get('config_ctrl', 'fix', { action, tb, col: col ?? '' })
    toast.add({ severity: res.status === 'success' ? 'success' : 'error', summary: t('saved'), detail: api.responseMessage(res, t), life: 4000 })
    await load()   // refresh the report after a fix
  } catch (e) {
    toast.add({ severity: 'error', summary: e.message, life: 4000 })
  } finally {
    fixing.value = null
  }
}

onMounted(load)
</script>

<style scoped>
.cfg-panel {
  flex: 1;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.cfg-panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 1rem 1.25rem 0.75rem;
  border-bottom: 1px solid var(--p-content-border-color);
  flex-shrink: 0;
}
.cfg-panel-header h2 {
  margin: 0;
  font-size: 1rem;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.cfg-loading-center {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 2rem;
  color: var(--p-text-muted-color);
}

.cfg-validation-report {
  flex: 1;
  overflow-y: auto;
  padding: 1rem 1.25rem;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.cfg-val-toolbar {
  display: flex;
  justify-content: flex-end;
  margin-bottom: 0.5rem;
}
.cfg-val-toggle {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.85rem;
  cursor: pointer;
}

.cfg-val-head {
  font-weight: 700;
  font-size: 0.9rem;
  margin-top: 1rem;
  margin-bottom: 0.25rem;
  padding-bottom: 0.25rem;
  border-bottom: 1px solid var(--p-content-border-color);
  color: var(--p-text-muted-color);
}

.cfg-val-item {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.4rem 0.6rem;
  border-radius: 6px;
  font-size: 0.875rem;
}
.cfg-val-success {
  background: color-mix(in srgb, var(--p-green-400) 12%, transparent);
  color: var(--p-green-500);
}
.cfg-val-warning {
  background: color-mix(in srgb, var(--p-yellow-400) 12%, transparent);
  color: var(--p-yellow-500);
}
.cfg-val-danger {
  background: color-mix(in srgb, var(--p-red-400) 12%, transparent);
  color: var(--p-red-500);
}
.cfg-val-info {
  background: color-mix(in srgb, var(--p-blue-400) 12%, transparent);
  color: var(--p-blue-500);
}

.cfg-val-text { flex: 1; }

.cfg-val-fix { flex-shrink: 0; }

.cfg-empty-msg {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--p-text-muted-color);
  font-size: 0.9rem;
}
</style>
