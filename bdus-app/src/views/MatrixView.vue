<template>
  <AppLayout>
  <div class="matrix-view">

    <!-- ── Toolbar ────────────────────────────────────────────── -->
    <div class="matrix-toolbar">
      <span class="matrix-title">
        {{ t('harris_matrix') }}
        <span v-if="tableLabel" class="matrix-tb-label">— {{ tableLabel }}</span>
      </span>

      <span class="matrix-stats" v-if="!loading && matrixData">
        <Tag :value="`${matrixData.nodes.length} ${t('matrix_nodes')}`" severity="secondary" rounded />
        <Tag :value="`${matrixData.relations.length} ${t('matrix_relations')}`" severity="secondary" rounded />
      </span>

      <span class="matrix-spacer" />

      <!-- Back to table -->
      <Button
        :label="tableLabel || t('back_to_table')"
        icon="pi pi-arrow-left"
        size="small"
        severity="secondary"
        outlined
        @click="backToTable"
      />

      <!-- Back to record -->
      <Button
        v-if="fromId"
        :label="t('back_to_record')"
        icon="pi pi-arrow-left"
        size="small"
        severity="secondary"
        outlined
        @click="backToRecord"
      />

      <!-- Edit mode toggle (only for users with write access) -->
      <div v-if="canWrite && matrixData?.nodes.length" class="matrix-edit-toggle">
        <ToggleButton
          v-model="editMode"
          :onLabel="t('rs_edit_mode')"
          :offLabel="t('rs_edit_mode')"
          onIcon="pi pi-pencil"
          offIcon="pi pi-pencil"
          size="small"
          :pt="{ root: { class: editMode ? 'toggle-active' : '' } }"
        />
      </div>

      <!-- Reload -->
      <Button
        :label="t('reload')"
        icon="pi pi-refresh"
        size="small"
        severity="secondary"
        outlined
        :loading="loading"
        @click="loadMatrix"
      />

      <!-- Export PNG -->
      <Button
        :label="t('export_png')"
        icon="pi pi-image"
        size="small"
        severity="secondary"
        outlined
        :disabled="!matrixData || !matrixData.nodes.length"
        @click="exportPng"
      />
    </div>

    <!-- ── Loading ─────────────────────────────────────────────── -->
    <div v-if="loading" class="matrix-loading">
      <ProgressSpinner />
    </div>

    <!-- ── Error ───────────────────────────────────────────────── -->
    <Message v-else-if="fetchError" severity="error" class="matrix-error">
      {{ fetchError }}
    </Message>

    <!-- ── Graph ───────────────────────────────────────────────── -->
    <div v-else-if="matrixData" class="matrix-canvas-wrap">
      <RsGraph
        ref="graphRef"
        :nodes="matrixData.nodes"
        :relations="matrixData.relations"
        :highlightId="highlightId"
        :allowEdit="editMode"
        @node-click="onNodeClick"
        @relation-add-requested="onAddRequested"
        @relation-delete-requested="onDeleteRequested"
      />
    </div>

  </div>

  <!-- ── Add relation dialog ────────────────────────────────────── -->
  <Dialog
    v-model:visible="addDialog"
    modal
    :header="t('rs_add_relation')"
    style="width: 400px"
    :draggable="false"
  >
    <div v-if="pendingEdge" class="add-dialog-body">
      <div class="add-dialog-nodes">
        <Tag :value="pendingEdge.from.label" severity="primary" rounded />
        <i class="pi pi-arrows-h add-dialog-arrow" />
        <Tag :value="pendingEdge.to.label" severity="primary" rounded />
      </div>
      <Select
        v-model="pendingRelation"
        :options="relationOptions"
        optionLabel="label"
        optionValue="value"
        :placeholder="t('rs_select_relation')"
        class="w-full"
        autofocus
      />
    </div>
    <template #footer>
      <Button :label="t('cancel')" severity="secondary" outlined @click="cancelAdd" />
      <Button
        :label="t('rs_add_relation')"
        icon="pi pi-plus"
        :disabled="!pendingRelation"
        :loading="mutating"
        @click="confirmAdd"
      />
    </template>
  </Dialog>

  <!-- ── Delete relation dialog ─────────────────────────────────── -->
  <Dialog
    v-model:visible="deleteDialog"
    modal
    :header="t('rs_delete_relation')"
    style="width: 380px"
    :draggable="false"
  >
    <div v-if="pendingDelete" class="delete-dialog-body">
      <i class="pi pi-exclamation-triangle delete-dialog-icon" />
      <span>{{ deleteConfirmText }}</span>
    </div>
    <template #footer>
      <Button :label="t('cancel')" severity="secondary" outlined @click="cancelDelete" />
      <Button
        :label="t('delete')"
        icon="pi pi-trash"
        severity="danger"
        :loading="mutating"
        @click="confirmDelete"
      />
    </template>
  </Dialog>

  </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter }  from 'vue-router'
import { useToast }             from 'primevue/usetoast'
import { useAuthStore }         from '@/stores/auth'
import AppLayout       from '@/components/AppLayout.vue'
import Button          from 'primevue/button'
import ToggleButton    from 'primevue/togglebutton'
import Tag             from 'primevue/tag'
import Message         from 'primevue/message'
import ProgressSpinner from 'primevue/progressspinner'
import Dialog          from 'primevue/dialog'
import Select          from 'primevue/select'
import RsGraph         from '@/components/record/RsGraph.vue'
import { api }         from '@/api'
import { useI18n }     from '@/i18n'
import { useTables }   from '@/composables/useTables'
import { buildRelationOptions, REL_KEYS } from '@/composables/useRsRelations'

const { t }     = useI18n()
const route     = useRoute()
const router    = useRouter()
const toast     = useToast()
const auth      = useAuthStore()
const { tables, loadTables } = useTables()

// ── Permissions ───────────────────────────────────────────────────
const canWrite = computed(() => auth.user?.can_write === true)

// ── Route params ─────────────────────────────────────────────────
const tb          = computed(() => route.params.tb)
const highlightId = computed(() => route.query.highlight ?? null)
const fromId      = computed(() => route.query.from_id   ?? null)
const backUrl     = computed(() => route.query.back       ?? null)

/** Label of the current table (from shared useTables cache). */
const tableLabel = computed(() =>
  tables.value.find(t => t.name === tb.value)?.label ?? tb.value
)

// ── State ────────────────────────────────────────────────────────
const matrixData = ref(null)   // { rs_field, nodes[], relations[] }
const loading    = ref(false)
const fetchError = ref(null)
const graphRef   = ref(null)
const editMode   = ref(false)
const mutating   = ref(false)  // shared loading flag for add/delete

// ── Relation options ──────────────────────────────────────────────
const relationOptions = computed(() => buildRelationOptions(t))

// ── Translate DataView URL params (qt/q/where) → getRsMatrix API params ──
// DataView stores search state as qt=<type>&q=<payload> in the URL (URL-friendly),
// but getRsMatrix expects search_type=<type> + type-specific params (adv, search, etc.)
function buildMatrixApiParams() {
  const p    = { tb: tb.value }
  const qt         = route.query.qt     ?? null
  const q          = route.query.q      ?? null
  const filterJson = route.query.filter ?? null

  if (filterJson) {
    try { p.filter = JSON.parse(filterJson) } catch { /* ignore malformed filter */ }
  } else if (qt === 'fast' && q) {
    p.search_type = 'fast'
    p.search      = q
  } else if (qt === 'filter' && q) {
    p.filter = q               // base64-encoded JSON filter — backend decodes it
  } else if (qt === 'expert' && q) {
    p.search_type = 'sqlExpert'
    p.querytext   = q
  } else {
    p.search_type = 'all'
  }

  return p
}

// ── Load matrix data ──────────────────────────────────────────────
async function loadMatrix() {
  if (!tb.value) return
  loading.value    = true
  fetchError.value = null

  const params = buildMatrixApiParams()

  try {
    const res = await api.get('/api/rs/matrix', params)
    if (res.status === 'error') {
      fetchError.value = t(res.code ?? 'error')
    } else {
      matrixData.value = res
    }
  } catch (e) {
    fetchError.value = t('error')
    toast.add({ severity: 'error', summary: t('harris_matrix'), detail: String(e), life: 4000 })
  } finally {
    loading.value = false
  }
}

// ── Node click → navigate to record (read mode only) ─────────────
function onNodeClick({ db_id }) {
  if (!db_id) return
  router.push(`/record/${encodeURIComponent(tb.value)}/${db_id}`)
}

// ── Back navigation ───────────────────────────────────────────────
function backToRecord() {
  if (!fromId.value) return
  router.push(`/record/${encodeURIComponent(tb.value)}/${fromId.value}`)
}

function backToTable() {
  if (backUrl.value) {
    router.push(backUrl.value)
  } else {
    router.push('/data')
  }
}

// ── PNG export ────────────────────────────────────────────────────
function exportPng() {
  graphRef.value?.exportPng()
}

// ── Add relation ──────────────────────────────────────────────────
const addDialog      = ref(false)
const pendingEdge    = ref(null)   // { from: {id, label}, to: {id, label} }
const pendingRelation = ref(null)

function onAddRequested({ from, to }) {
  pendingEdge.value     = { from, to }
  pendingRelation.value = null
  addDialog.value       = true
}

function cancelAdd() {
  addDialog.value       = false
  pendingEdge.value     = null
  pendingRelation.value = null
}

async function confirmAdd() {
  if (!pendingRelation.value || !pendingEdge.value) return
  mutating.value = true
  try {
    const res = await api.post(`/api/record/${tb.value}/rs`, {
      first:    pendingEdge.value.from.id,
      relation: pendingRelation.value,
      second:   pendingEdge.value.to.id,
    })
    if (res.status === 'success') {
      cancelAdd()
      await loadMatrix()
    } else {
      toast.add({ severity: 'warn', summary: t('rs'), detail: t(res.code ?? 'error'), life: 4000 })
    }
  } catch {
    toast.add({ severity: 'error', summary: t('rs'), detail: t('error'), life: 4000 })
  } finally {
    mutating.value = false
  }
}

// ── Delete relation ───────────────────────────────────────────────
const deleteDialog = ref(false)
const pendingDelete = ref(null)  // { rs_id, source, target, relation, label }

const deleteConfirmText = computed(() => {
  if (!pendingDelete.value) return ''
  const relLabel = t(REL_KEYS[pendingDelete.value.relation] ?? String(pendingDelete.value.relation))
  return t('rs_delete_confirm', relLabel, pendingDelete.value.source, pendingDelete.value.target)
})

function onDeleteRequested(edge) {
  pendingDelete.value = edge
  deleteDialog.value  = true
}

function cancelDelete() {
  deleteDialog.value  = false
  pendingDelete.value = null
}

async function confirmDelete() {
  if (!pendingDelete.value) return
  mutating.value = true
  try {
    const res = await api.delete(`/api/rs/${pendingDelete.value.rs_id}`)
    if (res.status === 'success') {
      cancelDelete()
      await loadMatrix()
    } else {
      toast.add({ severity: 'warn', summary: t('rs'), detail: t(res.code ?? 'error'), life: 4000 })
    }
  } catch {
    toast.add({ severity: 'error', summary: t('rs'), detail: t('error'), life: 4000 })
  } finally {
    mutating.value = false
  }
}

// ── Init ──────────────────────────────────────────────────────────
onMounted(async () => {
  await loadTables()
  await loadMatrix()
})
</script>

<style scoped>
.matrix-view {
  display: flex;
  flex-direction: column;
  height: 100%;
  gap: 0;
}

.matrix-toolbar {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.6rem 1rem;
  border-bottom: 1px solid var(--p-surface-border);
  background: var(--p-surface-card);
  flex-shrink: 0;
  flex-wrap: wrap;
}

.matrix-title {
  font-weight: 700;
  font-size: 0.95rem;
}

.matrix-tb-label {
  font-weight: 400;
  color: var(--p-text-muted-color);
}

.matrix-stats {
  display: flex;
  gap: 0.4rem;
}

.matrix-spacer { flex: 1; }

.matrix-edit-toggle :deep(.toggle-active) {
  background: var(--p-primary-color);
  border-color: var(--p-primary-color);
  color: #fff;
}

.matrix-loading,
.matrix-error {
  display: flex;
  align-items: center;
  justify-content: center;
  flex: 1;
  padding: 2rem;
}

.matrix-canvas-wrap {
  flex: 1;
  min-height: 0;
  position: relative;
}

/* Add dialog */
.add-dialog-body {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding: 0.5rem 0;
}

.add-dialog-nodes {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.75rem;
  padding: 0.5rem;
  background: var(--p-surface-ground);
  border-radius: 6px;
}

.add-dialog-arrow {
  font-size: 1.2rem;
  color: var(--p-text-muted-color);
}

/* Delete dialog */
.delete-dialog-body {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
  padding: 0.5rem 0;
}

.delete-dialog-icon {
  font-size: 1.4rem;
  color: var(--p-yellow-500);
  flex-shrink: 0;
  margin-top: 0.1rem;
}
</style>
