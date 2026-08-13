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
        <ATag>{{ matrixData.nodes.length }} {{ t('matrix_nodes') }}</ATag>
        <ATag>{{ matrixData.relations.length }} {{ t('matrix_relations') }}</ATag>
      </span>

      <span class="matrix-spacer" />

      <!-- Back to table -->
      <AButton size="small" @click="backToTable">
        <template #icon><i class="pi pi-arrow-left" /></template>
        {{ tableLabel || t('back_to_table') }}
      </AButton>

      <!-- Back to record -->
      <AButton v-if="fromId" size="small" @click="backToRecord">
        <template #icon><i class="pi pi-arrow-left" /></template>
        {{ t('back_to_record') }}
      </AButton>

      <!-- Edit mode toggle (only for users with write access) -->
      <div v-if="canWrite && matrixData?.nodes.length" class="matrix-edit-toggle">
        <AButton :type="editMode ? 'primary' : 'default'" size="small" @click="editMode = !editMode">
          <template #icon><i class="pi pi-pencil" /></template>
          {{ t('rs_edit_mode') }}
        </AButton>
      </div>

      <!-- Chronological layout toggle (only when fuzzy_date plugin is active) -->
      <div v-if="matrixData?.has_fuzzy_date" class="matrix-layout-toggle">
        <AButton :type="layoutMode === 'topological' ? 'primary' : 'default'" size="small" @click="layoutMode = 'topological'">
          <template #icon><i class="pi pi-sitemap" /></template>
          {{ t('matrix_layout_topological') }}
        </AButton>
        <AButton :type="layoutMode === 'chronological' ? 'primary' : 'default'" size="small" @click="layoutMode = 'chronological'">
          <template #icon><i class="pi pi-calendar" /></template>
          {{ t('matrix_layout_chronological') }}
        </AButton>
      </div>

      <!-- Reload -->
      <AButton size="small" :loading="loading" @click="loadMatrix">
        <template #icon><i class="pi pi-refresh" /></template>
        {{ t('reload') }}
      </AButton>

      <!-- Export PNG -->
      <AButton size="small" :disabled="!matrixData || !matrixData.nodes.length" @click="exportPng">
        <template #icon><i class="pi pi-image" /></template>
        {{ t('export_png') }}
      </AButton>
    </div>

    <!-- ── Loading ─────────────────────────────────────────────── -->
    <div v-if="loading" class="matrix-loading">
      <ASpin size="large" />
    </div>

    <!-- ── Error ───────────────────────────────────────────────── -->
    <AAlert v-else-if="fetchError" type="error" :message="fetchError" show-icon class="matrix-error" />

    <!-- ── Graph ───────────────────────────────────────────────── -->
    <div v-else-if="matrixData" class="matrix-canvas-wrap">
      <!-- Topological (standard) layout -->
      <RsGraph
        v-if="layoutMode === 'topological'"
        ref="graphRef"
        :nodes="matrixData.nodes"
        :relations="matrixData.relations"
        :highlightId="highlightId"
        :allowEdit="editMode"
        @node-click="onNodeClick"
        @relation-add-requested="onAddRequested"
        @relation-delete-requested="onDeleteRequested"
      />
      <!-- Chronological layout (absolute timeline) -->
      <RsGraphChrono
        v-else
        ref="graphRef"
        :nodes="matrixData.nodes"
        :relations="matrixData.relations"
        :highlightId="highlightId"
        @node-click="onNodeClick"
      />
    </div>

  </div>

  <!-- ── Add relation dialog ────────────────────────────────────── -->
  <AModal
    v-model:open="addDialog"
    :title="t('rs_add_relation')"
    width="400px"
    @cancel="cancelAdd"
  >
    <div v-if="pendingEdge" class="add-dialog-body">
      <div class="add-dialog-nodes">
        <ATag color="var(--p-primary-color)">{{ pendingEdge.from.label }}</ATag>
        <i class="pi pi-arrows-h add-dialog-arrow" />
        <ATag color="var(--p-primary-color)">{{ pendingEdge.to.label }}</ATag>
      </div>
      <ASelect
        v-model:value="pendingRelation"
        :options="relationOptions"
        :placeholder="t('rs_select_relation')"
        class="w-full"
        autofocus
      />
    </div>
    <template #footer>
      <AButton @click="cancelAdd">{{ t('cancel') }}</AButton>
      <AButton
        type="primary"
        :disabled="!pendingRelation"
        :loading="mutating"
        @click="confirmAdd"
      >
        <template #icon><i class="pi pi-plus" /></template>
        {{ t('rs_add_relation') }}
      </AButton>
    </template>
  </AModal>

  <!-- ── Delete relation dialog ─────────────────────────────────── -->
  <AModal
    v-model:open="deleteDialog"
    :title="t('rs_delete_relation')"
    width="380px"
    @cancel="cancelDelete"
  >
    <div v-if="pendingDelete" class="delete-dialog-body">
      <i class="pi pi-exclamation-triangle delete-dialog-icon" />
      <span>{{ deleteConfirmText }}</span>
    </div>
    <template #footer>
      <AButton @click="cancelDelete">{{ t('cancel') }}</AButton>
      <AButton danger :loading="mutating" @click="confirmDelete">
        <template #icon><i class="pi pi-trash" /></template>
        {{ t('delete') }}
      </AButton>
    </template>
  </AModal>

  </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter }  from 'vue-router'
import { useToast } from '@/composables/useNotify'
import { useAuthStore }         from '@/stores/auth'
import AppLayout       from '@/components/AppLayout.vue'
import {
  Button as AButton,
  Tag as ATag,
  Alert as AAlert,
  Spin as ASpin,
  Modal as AModal,
  Select as ASelect
} from 'ant-design-vue'
import RsGraph         from '@/components/record/RsGraph.vue'
import RsGraphChrono  from '@/components/record/RsGraphChrono.vue'
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
const matrixData  = ref(null)   // { has_fuzzy_date, nodes[], relations[] }
const loading     = ref(false)
const fetchError  = ref(null)
const graphRef    = ref(null)
const layoutMode  = ref('topological')  // 'topological' | 'chronological'
const editMode   = ref(false)
const mutating   = ref(false)  // shared loading flag for add/delete

// ── Relation options ──────────────────────────────────────────────
const relationOptions = computed(() => buildRelationOptions(t))

// ── Translate DataView URL params → getRsMatrix API params ──
// filter=JSON_STRING → parsed and passed as object (api.get serialises to bracket notation)
// qt=fast|expert + q=<value> → search_type + corresponding param
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
  router.push(`/${route.params.app}/record/${encodeURIComponent(tb.value)}/${db_id}`)
}

// ── Back navigation ───────────────────────────────────────────────
function backToRecord() {
  if (!fromId.value) return
  router.push(`/${route.params.app}/record/${encodeURIComponent(tb.value)}/${fromId.value}`)
}

function backToTable() {
  if (backUrl.value) {
    router.push(backUrl.value)
  } else {
    router.push(`/${route.params.app}/data`)
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

.matrix-layout-toggle {
  display: flex;
  gap: 0.25rem;
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
