<template>
  <!-- ── Drawer shell ─────────────────────────────────────────────── -->
  <Drawer
    v-model:visible="visible"
    :header="t('version_history')"
    position="right"
    :style="{ width: '480px' }"
    @hide="reset"
  >
    <!-- ── Loading spinner ─────────────────────────────────────────── -->
    <div v-if="loading" class="vd-loading">
      <ProgressSpinner />
    </div>

    <!-- ── Error message ───────────────────────────────────────────── -->
    <Message v-else-if="error" severity="error">{{ error }}</Message>

    <!-- ── Version list ────────────────────────────────────────────── -->
    <template v-else-if="!selectedVersion">
      <p v-if="!versions.length" class="vd-empty">{{ t('version_no_versions') }}</p>

      <ul v-else class="vd-list">
        <li
          v-for="v in versions"
          :key="v.id"
          class="vd-list-item"
          @click="loadDiff(v)"
        >
          <span class="vd-op-badge" :class="`op-${v.operation}`">
            {{ t(`version_op_${v.operation}`) }}
          </span>
          <span class="vd-meta">
            <span class="vd-time">{{ v.time }}</span>
            <span class="vd-user">{{ v.userid }}</span>
          </span>
          <i class="pi pi-chevron-right vd-arrow" />
        </li>
      </ul>
    </template>

    <!-- ── Diff view ───────────────────────────────────────────────── -->
    <template v-else>
      <!-- Back nav -->
      <div class="vd-diff-nav">
        <Button
          :label="t('version_back_list')"
          icon="pi pi-arrow-left"
          text
          size="small"
          @click="selectedVersion = null; diff = null"
        />
      </div>

      <!-- Version metadata -->
      <div class="vd-diff-meta">
        <span class="vd-op-badge" :class="`op-${selectedVersion.operation}`">
          {{ t(`version_op_${selectedVersion.operation}`) }}
        </span>
        <span class="vd-time">{{ selectedVersion.time }}</span>
        <span class="vd-user">{{ selectedVersion.userid }}</span>
      </div>

      <!-- Diff loading -->
      <div v-if="diffLoading" class="vd-loading">
        <ProgressSpinner />
      </div>

      <template v-else-if="diff">
        <!-- Deleted record banner -->
        <Message v-if="!diff.current.core" severity="warn" class="vd-deleted-banner">
          {{ t('version_record_deleted') }}
        </Message>

        <!-- Core fields -->
        <div class="vd-section">
          <div class="vd-section-header">
            <Checkbox
              v-if="diff.current.core"
              v-model="allCoreSelected"
              :binary="true"
              :indeterminate="someCoreSelected && !allCoreSelected"
              :inputId="`all-core`"
            />
            <label :for="`all-core`" class="vd-section-label">
              {{ t('version_core_fields') }}
            </label>
          </div>

          <div
            v-for="field in coreFields.filter(f => f.changed)"
            :key="field.name"
            class="vd-field-row changed"
          >
            <Checkbox
              v-if="diff.current.core"
              v-model="selectedFields"
              :value="field.name"
              :inputId="`f-${field.name}`"
              :disabled="!field.changed"
            />
            <div class="vd-field-info">
              <label :for="`f-${field.name}`" class="vd-field-name">
                {{ field.label }}
              </label>
              <div class="vd-val vd-val-snap">
                <span class="vd-val-label">{{ t('version_snapshot') }}</span>
                <code>{{ formatVal(field.snapVal) }}</code>
              </div>
              <div class="vd-val vd-val-cur">
                <span class="vd-val-label">{{ t('version_current') }}</span>
                <code>{{ formatVal(field.curVal) }}</code>
              </div>
            </div>
          </div>
        </div>

        <!-- Plugin sections -->
        <template v-if="pluginNames.length">
          <div
            v-for="plgName in pluginNames"
            :key="plgName"
            class="vd-section"
          >
            <div class="vd-section-header">
              <Checkbox
                v-if="diff.current.core"
                v-model="selectedPlugins"
                :value="plgName"
                :inputId="`plg-${plgName}`"
              />
              <label :for="`plg-${plgName}`" class="vd-section-label">
                {{ plgName }}
                <span class="vd-plugin-counts">
                  ({{ t('version_plugin_snap_rows', snapPluginCount(plgName)) }}
                  → {{ t('version_plugin_cur_rows', curPluginCount(plgName)) }})
                </span>
              </label>
            </div>
          </div>
        </template>

        <!-- Restore button -->
        <div class="vd-restore-bar">
          <Button
            v-if="diff.current.core"
            :label="t('version_restore_selected')"
            icon="pi pi-history"
            severity="warning"
            :disabled="selectedFields.length === 0 && selectedPlugins.length === 0"
            @click="confirmRestore"
          />
          <Button
            v-else
            :label="t('version_restore_full')"
            icon="pi pi-history"
            severity="warning"
            @click="confirmRestore"
          />
        </div>
      </template>
    </template>
  </Drawer>

  <!-- ── Confirmation dialog ─────────────────────────────────────── -->
  <Dialog
    v-model:visible="confirmVisible"
    :header="t('version_restore_confirm_title')"
    modal
    :style="{ width: '400px' }"
  >
    <div class="vd-confirm-body">
      <i class="pi pi-exclamation-triangle vd-confirm-icon" />
      <p>{{ t('version_restore_confirm_msg') }}</p>
    </div>
    <template #footer>
      <Button
        :label="t('cancel')"
        severity="secondary"
        text
        @click="confirmVisible = false"
      />
      <Button
        :label="t('version_restore')"
        severity="warning"
        icon="pi pi-history"
        :loading="restoring"
        @click="doRestore"
      />
    </template>
  </Dialog>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useToast }   from 'primevue/usetoast'
import Drawer          from 'primevue/drawer'
import Dialog          from 'primevue/dialog'
import Button          from 'primevue/button'
import Checkbox        from 'primevue/checkbox'
import Message         from 'primevue/message'
import ProgressSpinner from 'primevue/progressspinner'
import { api }         from '@/api'
import { useI18n }     from '@/i18n'

const { t } = useI18n()
const toast = useToast()

// ── Props / emits ──────────────────────────────────────────────
const props = defineProps({
  /** Whether the drawer is open */
  modelValue: { type: Boolean, default: false },
  /** Table name */
  tb: { type: String, required: true },
  /** Record id */
  recordId: { type: [Number, String], required: true },
  /** All core field schemas: [{ name, label, ... }] */
  fieldSchemas: { type: Array, default: () => [] },
})
const emit = defineEmits(['update:modelValue', 'restored'])

// ── Visible binding ───────────────────────────────────────────
const visible = computed({
  get: () => props.modelValue,
  set: v  => emit('update:modelValue', v),
})

// ── State ─────────────────────────────────────────────────────
const loading        = ref(false)
const error          = ref(null)
const versions       = ref([])
const selectedVersion = ref(null)
const diff           = ref(null)
const diffLoading    = ref(false)
const selectedFields  = ref([])
const selectedPlugins = ref([])
const confirmVisible  = ref(false)
const restoring       = ref(false)

// ── Load versions list when drawer opens ──────────────────────
watch(visible, async (open) => {
  if (!open) return
  await loadVersions()
})

async function loadVersions() {
  loading.value = true
  error.value   = null
  versions.value = []
  try {
    const res = await api.get(`/api/record/${props.tb}/${props.recordId}/versions`)
    if (res.status === 'error') {
      error.value = api.responseMessage(res, t)
      return
    }
    versions.value = res.versions ?? []
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

// ── Load diff for a selected version ──────────────────────────
async function loadDiff(version) {
  selectedVersion.value = version
  diff.value      = null
  selectedFields.value  = []
  selectedPlugins.value = []
  diffLoading.value = true
  try {
    const res = await api.get(`/api/version/${version.id}`)
    if (res.status === 'error') {
      error.value = api.responseMessage(res, t)
      selectedVersion.value = null
      return
    }
    diff.value = res
    // Pre-select all changed fields
    selectedFields.value = coreFields.value
      .filter(f => f.changed)
      .map(f => f.name)
  } catch (e) {
    error.value = e.message
    selectedVersion.value = null
  } finally {
    diffLoading.value = false
  }
}

// ── Core fields diff ──────────────────────────────────────────
/** Build schema label map: name → label */
const schemaMap = computed(() => {
  const m = {}
  for (const f of props.fieldSchemas) m[f.name] = f.label
  return m
})

const coreFields = computed(() => {
  if (!diff.value) return []
  const snap    = diff.value.version.content.core ?? {}
  const current = diff.value.current.core ?? {}
  const names   = new Set([...Object.keys(snap), ...Object.keys(current)])
  // Exclude system fields
  const skip = new Set(['id', 'creator'])
  return [...names]
    .filter(n => !skip.has(n))
    .map(name => {
      const snapVal = snap[name] ?? null
      const curVal  = current[name] ?? null
      return {
        name,
        label:   schemaMap.value[name] ?? name,
        snapVal,
        curVal,
        changed: String(snapVal) !== String(curVal),
      }
    })
    .sort((a, b) => a.label.localeCompare(b.label))
})

const pluginNames = computed(() => {
  if (!diff.value) return []
  const snap = diff.value.version.content.plugins ?? {}
  const cur  = diff.value.current.plugins ?? {}
  return [...new Set([...Object.keys(snap), ...Object.keys(cur)])]
})

// ── Helpers ───────────────────────────────────────────────────
function snapPluginCount(name) {
  return (diff.value?.version?.content?.plugins?.[name] ?? []).length
}
function curPluginCount(name) {
  return (diff.value?.current?.plugins?.[name] ?? []).length
}

function formatVal(val) {
  if (val === null || val === undefined) return '—'
  if (typeof val === 'object') return JSON.stringify(val)
  return String(val)
}

// ── Select-all core ───────────────────────────────────────────
const allCoreSelected = computed({
  get: () => {
    const changeable = coreFields.value.filter(f => f.changed).map(f => f.name)
    return changeable.length > 0 && changeable.every(n => selectedFields.value.includes(n))
  },
  set: (v) => {
    if (v) {
      selectedFields.value = coreFields.value.filter(f => f.changed).map(f => f.name)
    } else {
      selectedFields.value = []
    }
  },
})

const someCoreSelected = computed(() =>
  coreFields.value.some(f => f.changed && selectedFields.value.includes(f.name))
)

// ── Restore ───────────────────────────────────────────────────
function confirmRestore() {
  confirmVisible.value = true
}

async function doRestore() {
  restoring.value = true
  try {
    const body = diff.value?.current?.core
      ? { version_id: selectedVersion.value.id, fields: selectedFields.value, restore_plugins: selectedPlugins.value }
      : { version_id: selectedVersion.value.id }

    const res = await api.post(`/api/version/${selectedVersion.value.id}/restore`, body)
    if (res.status === 'error') {
      toast.add({ severity: 'error', summary: t('generic_error'), detail: api.responseMessage(res, t), life: 5000 })
      return
    }
    confirmVisible.value = false
    visible.value = false
    toast.add({ severity: 'success', summary: t('version_restore'), detail: t('version_restored'), life: 3000 })
    emit('restored', res)
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 5000 })
  } finally {
    restoring.value = false
  }
}

// ── Reset on close ─────────────────────────────────────────────
function reset() {
  versions.value        = []
  selectedVersion.value = null
  diff.value            = null
  selectedFields.value  = []
  selectedPlugins.value = []
  error.value           = null
}
</script>

<style scoped>
/* ── Loading / empty ── */
.vd-loading {
  display: flex;
  justify-content: center;
  padding: 2rem 0;
}
.vd-empty {
  color: var(--p-text-muted-color);
  font-style: italic;
  padding: 1rem 0;
  text-align: center;
}

/* ── Version list ── */
.vd-list {
  list-style: none;
  margin: 0;
  padding: 0;
}
.vd-list-item {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.65rem 0.5rem;
  border-bottom: 1px solid var(--p-content-border-color);
  cursor: pointer;
  border-radius: 4px;
  transition: background 0.15s;
}
.vd-list-item:hover { background: var(--p-content-hover-background); }
.vd-meta {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
}
.vd-time { font-size: 0.85rem; font-weight: 500; }
.vd-user { font-size: 0.75rem; color: var(--p-text-muted-color); }
.vd-arrow { color: var(--p-text-muted-color); font-size: 0.75rem; }

/* ── Operation badge ── */
.vd-op-badge {
  font-size: 0.68rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  padding: 0.15rem 0.4rem;
  border-radius: 3px;
  white-space: nowrap;
}
.op-update  { background: var(--p-blue-100);   color: var(--p-blue-700); }
.op-delete  { background: var(--p-red-100);    color: var(--p-red-700); }
.op-restore { background: var(--p-orange-100); color: var(--p-orange-700); }

/* ── Diff nav ── */
.vd-diff-nav { margin-bottom: 0.5rem; }
.vd-diff-meta {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 1rem;
  padding-bottom: 0.75rem;
  border-bottom: 2px solid var(--p-content-border-color);
}

/* ── Sections ── */
.vd-section {
  margin-bottom: 1.25rem;
}
.vd-section-header {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 0.5rem;
}
.vd-section-label {
  font-size: 0.75rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--p-text-muted-color);
  cursor: pointer;
}
.vd-plugin-counts {
  font-weight: 400;
  font-size: 0.72rem;
}

/* ── Field rows ── */
.vd-field-row {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  padding: 0.4rem 0.25rem;
  border-radius: 4px;
}
.vd-field-row.changed { background: color-mix(in srgb, var(--p-warning-color) 6%, transparent); }
.vd-field-info {
  flex: 1;
  min-width: 0;
}
.vd-field-name {
  font-size: 0.78rem;
  font-weight: 600;
  display: block;
  margin-bottom: 0.2rem;
  cursor: pointer;
}
.vd-val {
  display: flex;
  align-items: baseline;
  gap: 0.35rem;
  font-size: 0.8rem;
  margin-bottom: 0.1rem;
}
.vd-val-label {
  font-size: 0.68rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--p-text-muted-color);
  min-width: 4.5rem;
}
.vd-val-snap code { color: var(--p-orange-600); background: var(--p-orange-50); padding: 0.05rem 0.25rem; border-radius: 3px; word-break: break-all; }
.vd-val-cur  code { color: var(--p-primary-color);                               padding: 0.05rem 0.25rem; border-radius: 3px; word-break: break-all; }
.vd-unchanged-note {
  font-size: 0.75rem;
  color: var(--p-text-muted-color);
  font-style: italic;
}

/* ── Restore bar ── */
.vd-restore-bar {
  margin-top: 1.5rem;
  padding-top: 1rem;
  border-top: 1px solid var(--p-content-border-color);
}

/* ── Confirm dialog ── */
.vd-confirm-body {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
}
.vd-confirm-icon {
  font-size: 1.5rem;
  color: var(--p-warning-color);
  flex-shrink: 0;
  margin-top: 0.1rem;
}
.vd-deleted-banner { margin-bottom: 1rem; }
</style>
