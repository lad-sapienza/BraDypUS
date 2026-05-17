<template>
  <div class="saved-queries-panel">

    <!-- ── Save current search ─────────────────────────────────── -->
    <div class="save-section">
      <div class="save-section-label">{{ t('save_current_search') }}</div>
      <div v-if="currentSearch" class="save-row">
        <InputText
          v-model="newQueryName"
          :placeholder="t('name_for_query_to_save')"
          size="small"
          class="save-name-input"
          @keyup.enter="doSave"
        />
        <Button
          :label="t('save')"
          icon="pi pi-save"
          size="small"
          :loading="saving"
          :disabled="!newQueryName.trim()"
          @click="doSave"
        />
      </div>
      <div v-else class="save-hint">
        <i class="pi pi-info-circle" />
        {{ t('save_query_hint') }}
      </div>
    </div>

    <Divider />

    <!-- ── Query list ───────────────────────────────────────────── -->
    <div v-if="loading" class="panel-loading">
      <ProgressSpinner style="width:24px;height:24px" />
    </div>

    <div v-else-if="filteredQueries.length === 0" class="panel-empty">
      {{ t('no_saved_queries') }}
    </div>

    <ul v-else class="query-list">
      <li
        v-for="q in filteredQueries"
        :key="q.id"
        class="query-item"
        :class="{ 'query-item--foreign': q.tb !== currentTb }"
      >
        <div class="query-main">
          <span class="query-name" :title="q.name">{{ q.name }}</span>
          <span v-if="q.tb !== currentTb" class="query-tb-label">{{ q.tb_label }}</span>
          <span v-if="q.is_global" class="query-shared-badge">
            <i class="pi pi-share-alt" :title="t('share')" />
          </span>
        </div>
        <div class="query-actions">
          <!-- Execute -->
          <Button
            icon="pi pi-play"
            size="small"
            text
            :title="t('execute_query')"
            :disabled="!q.query"
            @click="emitLoad(q)"
          />
          <!-- Share / Unshare (only owner) -->
          <Button
            v-if="q.owned_by_me"
            :icon="q.is_global ? 'pi pi-star-fill' : 'pi pi-star'"
            size="small"
            text
            :title="q.is_global ? t('unshare') : t('share')"
            :loading="pendingId === q.id && pendingAction === 'share'"
            @click="toggleShare(q)"
          />
          <!-- Delete (only owner, with inline confirm) -->
          <Button
            v-if="q.owned_by_me"
            icon="pi pi-trash"
            size="small"
            text
            severity="danger"
            :title="t('delete')"
            :loading="pendingId === q.id && pendingAction === 'delete'"
            @click="confirmDelete(q)"
          />
        </div>

        <!-- Inline confirm row (shown when user clicks trash) -->
        <div v-if="confirmingId === q.id" class="query-confirm">
          <span class="query-confirm-text">{{ t('confirm_delete') }}</span>
          <Button
            :label="t('yes')"
            size="small"
            severity="danger"
            @click="doDelete(q)"
          />
          <Button
            :label="t('no')"
            size="small"
            severity="secondary"
            text
            @click="confirmingId = null"
          />
        </div>
      </li>
    </ul>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useToast } from 'primevue/usetoast'
import { api } from '@/api'
import { useI18n } from '@/i18n'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Divider from 'primevue/divider'
import ProgressSpinner from 'primevue/progressspinner'

// ── Props & emits ─────────────────────────────────────────────
const props = defineProps({
  /** The current active search payload to save, or null if no search is active */
  currentSearch: { type: Object, default: null },
  /** Current table name (prefixed, e.g. 'myapp__sites') */
  currentTb:     { type: String, default: '' },
})

const emit = defineEmits(['load-query'])

// ── State ─────────────────────────────────────────────────────
const { t }        = useI18n()
const toast        = useToast()
const { responseMessage } = api

const queries      = ref([])
const loading      = ref(false)
const saving       = ref(false)
const newQueryName = ref('')
const pendingId    = ref(null)
const pendingAction = ref(null)
const confirmingId = ref(null)

// ── Computed ──────────────────────────────────────────────────
/**
 * Show queries for current table first, then others.
 * Both groups sorted by name.
 */
const filteredQueries = computed(() => {
  const own   = queries.value.filter(q => q.tb === props.currentTb)
  const other = queries.value.filter(q => q.tb !== props.currentTb)
  const sort  = arr => [...arr].sort((a, b) => a.name.localeCompare(b.name))
  return [...sort(own), ...sort(other)]
})

// ── Lifecycle ─────────────────────────────────────────────────
onMounted(fetchQueries)

// ── Methods ───────────────────────────────────────────────────
async function fetchQueries() {
  loading.value = true
  try {
    const res = await api.get('saved_queries_ctrl', 'listQueries')
    if (res.status === 'error') throw new Error(responseMessage(res, t))
    queries.value = res.queries ?? []
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 4000 })
  } finally {
    loading.value = false
  }
}

async function doSave() {
  const name = newQueryName.value.trim()
  if (!name || !props.currentTb || !props.currentSearch) return

  saving.value = true
  try {
    const res = await api.post('saved_queries_ctrl', 'saveQuery', {
      name,
      tb:    props.currentTb,
      query: props.currentSearch,
    })
    if (res.status === 'error') throw new Error(responseMessage(res, t))
    queries.value.unshift(res.query)
    newQueryName.value = ''
    toast.add({ severity: 'success', summary: t('ok_saved_query'), life: 2500 })
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 4000 })
  } finally {
    saving.value = false
  }
}

async function toggleShare(q) {
  pendingId.value     = q.id
  pendingAction.value = 'share'
  const method = q.is_global ? 'unshareQuery' : 'shareQuery'
  try {
    const res = await api.post('saved_queries_ctrl', method, { id: q.id })
    if (res.status === 'error') throw new Error(responseMessage(res, t))
    q.is_global = !q.is_global
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 4000 })
  } finally {
    pendingId.value     = null
    pendingAction.value = null
  }
}

function confirmDelete(q) {
  confirmingId.value = confirmingId.value === q.id ? null : q.id
}

async function doDelete(q) {
  pendingId.value     = q.id
  pendingAction.value = 'delete'
  confirmingId.value  = null
  try {
    const res = await api.post('saved_queries_ctrl', 'deleteQuery', { id: q.id })
    if (res.status === 'error') throw new Error(responseMessage(res, t))
    queries.value = queries.value.filter(r => r.id !== q.id)
    toast.add({ severity: 'success', summary: t('ok_erasing_query'), life: 2500 })
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 4000 })
  } finally {
    pendingId.value     = null
    pendingAction.value = null
  }
}

function emitLoad(q) {
  if (q.query) {
    emit('load-query', q.query)
  }
}
</script>

<style scoped>
.saved-queries-panel {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  padding: 0.75rem;
  min-width: 280px;
}

/* ── Save section ─────────────────────────────────────── */
.save-section-label {
  font-size: 0.78rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--p-text-muted-color);
  margin-bottom: 0.4rem;
}

.save-row {
  display: flex;
  gap: 0.4rem;
  align-items: center;
}

.save-name-input { flex: 1; min-width: 0; }

/* ── Loading / empty ──────────────────────────────────── */
.panel-loading {
  display: flex;
  justify-content: center;
  padding: 1.5rem 0;
}

.panel-empty {
  font-size: 0.85rem;
  color: var(--p-text-muted-color);
  text-align: center;
  padding: 1rem 0;
}

/* ── Save hint (no active search) ────────────────────── */
.save-hint {
  font-size: 0.82rem;
  color: var(--p-text-muted-color);
  display: flex;
  align-items: center;
  gap: 0.4rem;
}

/* ── Query list ───────────────────────────────────────── */
.query-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  max-height: 55vh;
  overflow-y: auto;
}

.query-item {
  border: 1px solid var(--p-content-border-color);
  border-radius: 6px;
  padding: 0.4rem 0.6rem;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  background: var(--bdus-surface);
}

.query-item--foreign {
  opacity: 0.85;
}

.query-main {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  flex-wrap: wrap;
  min-width: 0;
}

.query-name {
  font-size: 0.88rem;
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  flex: 1;
  min-width: 0;
}

.query-tb-label {
  font-size: 0.75rem;
  color: var(--p-text-muted-color);
  background: var(--p-content-hover-background);
  border-radius: 4px;
  padding: 0.1rem 0.35rem;
  flex-shrink: 0;
}

.query-shared-badge {
  color: var(--p-primary-color);
  font-size: 0.8rem;
  flex-shrink: 0;
}

.query-actions {
  display: flex;
  gap: 0.1rem;
  align-items: center;
}

/* ── Inline confirm ───────────────────────────────────── */
.query-confirm {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  padding-top: 0.2rem;
  border-top: 1px solid var(--p-content-border-color);
}

.query-confirm-text {
  font-size: 0.8rem;
  flex: 1;
}
</style>
