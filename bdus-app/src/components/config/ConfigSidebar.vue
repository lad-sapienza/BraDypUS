<template>
  <aside class="cfg-sidebar">

    <!-- Top actions -->
    <div class="cfg-sidebar-actions">
      <button
        class="cfg-action-btn"
        :class="{ active: active === 'app' }"
        @click="$emit('select', 'app')"
        :title="t('app_settings')"
      >
        <i class="pi pi-cog" />
        <span>{{ t('app_settings') }}</span>
      </button>
      <button
        class="cfg-action-btn"
        :class="{ active: active === 'validation' }"
        @click="$emit('select', 'validation')"
        :title="t('validate_app')"
      >
        <i class="pi pi-check-circle" />
        <span>{{ t('validate_app') }}</span>
      </button>
      <button
        class="cfg-action-btn"
        :class="{ active: active === 'geoface' }"
        @click="$emit('select', 'geoface')"
        :title="t('geoface')"
      >
        <i class="pi pi-map" />
        <span>{{ t('geoface') }}</span>
      </button>
      <button
        class="cfg-action-btn"
        :class="{ active: active === 'apikeys' }"
        @click="$emit('select', 'apikeys')"
        :title="t('api_keys')"
      >
        <i class="pi pi-key" />
        <span>{{ t('api_keys') }}</span>
      </button>
    </div>

    <div class="cfg-sidebar-divider" />

    <!-- Table list -->
    <div class="cfg-table-list-header">
      <span>{{ t('tables') }}</span>
      <button class="cfg-icon-btn" @click="$emit('add-table')" :title="t('add_table')">
        <i class="pi pi-plus" />
      </button>
    </div>

    <div v-if="store.loading" class="cfg-loading">
      <i class="pi pi-spin pi-spinner" />
    </div>

    <div v-else-if="store.error" class="cfg-sidebar-error">{{ store.error }}</div>

    <div v-else class="cfg-table-list" ref="listEl">
      <div
        v-for="tbl in store.tables"
        :key="tbl.name"
        class="cfg-table-item"
        :class="{ active: selectedTable === tbl.name }"
        draggable="true"
        @dragstart="onDragStart($event, tbl.name)"
        @dragover.prevent
        @drop="onDrop($event, tbl.name)"
      >
        <i class="pi pi-bars cfg-drag-handle" />
        <span class="cfg-table-label" :title="tbl.name">
          {{ tbl.label }}
          <small v-if="tbl.is_plugin === '1'" class="cfg-plugin-badge">plugin</small>
        </span>
        <div class="cfg-table-btns">
          <button
            class="cfg-icon-btn"
            :title="t('table_settings')"
            @click.stop="$emit('select-table', tbl.name)"
          >
            <i class="pi pi-sliders-h" />
          </button>
          <button
            class="cfg-icon-btn"
            :title="t('fields')"
            @click.stop="$emit('select-fields', tbl.name)"
          >
            <i class="pi pi-list" />
          </button>
        </div>
      </div>
    </div>

  </aside>
</template>

<script setup>
import { ref }            from 'vue'
import { useConfigStore } from '@/stores/config'
import { useI18n }        from '@/i18n'
import { api }            from '@/api'

const props = defineProps({
  active:        { type: String,  default: null },
  selectedTable: { type: String,  default: null },
})
const emit = defineEmits(['select', 'select-table', 'select-fields', 'add-table'])

const { t } = useI18n()
const store = useConfigStore()

// ── Drag-and-drop sort ─────────────────────────────────────────────────
const dragging = ref(null)

function onDragStart(e, name) {
  dragging.value = name
  e.dataTransfer.effectAllowed = 'move'
}

async function onDrop(e, targetName) {
  if (!dragging.value || dragging.value === targetName) return

  const names  = store.tables.map(t => t.name)
  const fromIdx = names.indexOf(dragging.value)
  const toIdx   = names.indexOf(targetName)

  // Reorder locally for instant feedback
  const reordered = [...store.tables]
  const [moved]   = reordered.splice(fromIdx, 1)
  reordered.splice(toIdx, 0, moved)
  store.tables = reordered

  dragging.value = null

  // Persist to backend
  try {
    await api.post('/api/config/tables/sort', {
      sort: reordered.map(t => t.name)
    })
  } catch {
    // revert on error
    await store.loadTables(true)
  }
}
</script>

<style scoped>
.cfg-sidebar {
  width: 220px;
  flex-shrink: 0;
  border-right: 1px solid var(--p-content-border-color);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  background: var(--bdus-surface);
}

/* ── Top action buttons ─────────────────────────────── */
.cfg-sidebar-actions {
  padding: 0.5rem 0;
  flex-shrink: 0;
}

.cfg-action-btn {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  width: 100%;
  padding: 0.5rem 1rem;
  border: none;
  background: transparent;
  font-size: 0.875rem;
  color: var(--p-text-color);
  cursor: pointer;
  text-align: left;
  transition: background 0.15s;
}
.cfg-action-btn:hover  { background: var(--p-content-hover-background); }
.cfg-action-btn.active {
  background: var(--p-highlight-background);
  color: var(--p-primary-color);
  font-weight: 600;
}
.cfg-action-btn .pi { font-size: 0.95rem; width: 1rem; text-align: center; }

.cfg-sidebar-divider {
  height: 1px;
  background: var(--p-content-border-color);
  margin: 0.25rem 0;
  flex-shrink: 0;
}

/* ── Table list header ──────────────────────────────── */
.cfg-table-list-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.5rem 0.75rem 0.25rem 1rem;
  font-size: 0.7rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--p-text-muted-color);
  flex-shrink: 0;
}

.cfg-icon-btn {
  border: none;
  background: transparent;
  cursor: pointer;
  color: var(--p-text-muted-color);
  padding: 0.2rem 0.3rem;
  border-radius: 4px;
  line-height: 1;
  font-size: 0.8rem;
  transition: background 0.15s, color 0.15s;
}
.cfg-icon-btn:hover {
  background: var(--p-content-hover-background);
  color: var(--p-text-color);
}

.cfg-loading {
  padding: 1rem;
  text-align: center;
  color: var(--p-text-muted-color);
}

.cfg-sidebar-error {
  padding: 0.75rem 1rem;
  font-size: 0.8rem;
  color: var(--p-red-400);
}

/* ── Table list items ───────────────────────────────── */
.cfg-table-list {
  flex: 1;
  overflow-y: auto;
  padding: 0.25rem 0;
}

.cfg-table-item {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.4rem 0.5rem 0.4rem 0.75rem;
  cursor: default;
  border-radius: 4px;
  margin: 0 0.25rem;
  transition: background 0.12s;
}
.cfg-table-item:hover  { background: var(--p-content-hover-background); }
.cfg-table-item.active { background: var(--p-highlight-background); }

.cfg-drag-handle {
  font-size: 0.75rem;
  color: var(--p-text-muted-color);
  cursor: grab;
  flex-shrink: 0;
}

.cfg-table-label {
  flex: 1;
  font-size: 0.82rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  display: flex;
  align-items: center;
  gap: 0.35rem;
}

.cfg-plugin-badge {
  font-size: 0.65rem;
  background: var(--p-content-hover-background);
  color: var(--p-text-muted-color);
  padding: 0.05rem 0.3rem;
  border-radius: 3px;
  flex-shrink: 0;
}

.cfg-table-btns {
  display: flex;
  gap: 0.1rem;
  opacity: 0;
  transition: opacity 0.12s;
}
.cfg-table-item:hover .cfg-table-btns { opacity: 1; }
.cfg-table-item.active .cfg-table-btns { opacity: 1; }
</style>
