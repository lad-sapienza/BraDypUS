<template>
  <div class="rs-graph-wrap">
    <div ref="cyEl" class="rs-graph-canvas" />
    <div v-if="!nodes.length" class="rs-graph-empty">
      <i class="pi pi-info-circle" />
      {{ t('rs_no_relations') }}
    </div>

    <!-- Edit mode hint -->
    <div v-if="allowEdit && nodes.length" class="rs-graph-hint">
      <template v-if="!selectedNode">
        <i class="pi pi-info-circle" />
        {{ t('rs_edit_hint_select') }}
      </template>
      <template v-else>
        <i class="pi pi-check-circle" />
        {{ t('rs_edit_hint_second', selectedNode.label) }}
        <button class="rs-hint-cancel" @click="clearSelection">
          <i class="pi pi-times" /> {{ t('cancel') }}
        </button>
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, watch, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from '@/i18n'
import { REL_KEYS, UNDIRECTED, SWAP_DIRECTION } from '@/composables/useRsRelations'

const { t } = useI18n()

const props = defineProps({
  /** [ { db_id, identifier, in_filter } ] */
  nodes:       { type: Array,   default: () => [] },
  /** [ { id, first, second, relation } ] */
  relations:   { type: Array,   default: () => [] },
  /** identifier string of the record to highlight (from RecordView link) */
  highlightId: { type: String,  default: null },
  /** When true: two-click adds relation, edge click deletes relation */
  allowEdit:   { type: Boolean, default: false },
})

const emit = defineEmits(['node-click', 'relation-add-requested', 'relation-delete-requested'])

const cyEl         = ref(null)
const selectedNode = ref(null)   // { id: identifier, label, db_id } — first click in edit mode
let cy = null

// ── Build Cytoscape elements ──────────────────────────────────────
function buildElements() {
  const elements = []

  // Index nodes by db_id (string) for O(1) edge validation
  const nodeIds = new Set(props.nodes.map(n => String(n.db_id)))
  // db_id → identifier label (for edge display)
  const labelById = {}
  for (const n of props.nodes) labelById[String(n.db_id)] = String(n.identifier)

  for (const n of props.nodes) {
    elements.push({
      group: 'nodes',
      data: {
        id:        String(n.db_id),       // unique Cytoscape node id = db primary key
        label:     String(n.identifier),  // id_field value for display
        db_id:     n.db_id,
        // Store as integer (0/1) — Cytoscape selectors don't handle JS booleans
        in_filter: n.in_filter ? 1 : 0,
        highlight: String(n.db_id) === String(props.highlightId) ? 1 : 0,
      },
    })
  }

  // Track undirected edges to avoid A↔B and B↔A duplicates
  const seenUndirected = new Set()
  let skipped = 0

  for (const r of props.relations) {
    // first/second are now integer db_ids
    const src = String(r.first)
    const tgt = String(r.second)

    // Skip orphan edges — referenced record no longer exists
    if (!nodeIds.has(src) || !nodeIds.has(tgt)) {
      skipped++
      continue
    }

    const rel     = parseInt(r.relation, 10)
    const isUndir = UNDIRECTED.has(rel)

    // Passive relations (1,2,3,4): first = older unit, second = newer.
    // Swap so arrows flow newer→older (top→bottom in dagre TB).
    const needsSwap = SWAP_DIRECTION.has(rel)
    const edgeSrc = needsSwap ? tgt : src
    const edgeTgt = needsSwap ? src : tgt

    const edgeKey = isUndir
      ? [edgeSrc, edgeTgt].sort().join('|') + '|' + rel
      : null

    if (isUndir) {
      if (seenUndirected.has(edgeKey)) continue
      seenUndirected.add(edgeKey)
    }

    elements.push({
      group: 'edges',
      data: {
        id:           'e' + r.id,
        rs_id:        r.id,
        source:       edgeSrc,
        target:       edgeTgt,
        source_label: labelById[edgeSrc] ?? edgeSrc,
        target_label: labelById[edgeTgt] ?? edgeTgt,
        // When direction is swapped, show the inverse relation label so the
        // displayed label matches the arrow direction (e.g. "covers" not "is_covered_by")
        label:        (() => { const lr = needsSwap ? REL_INVERSE[rel] : rel; return REL_KEYS[lr] ? t(REL_KEYS[lr]) : String(lr) })(),
        relation:     rel,
        directed:     isUndir ? 0 : 1,
      },
    })
  }

  if (skipped > 0) {
    console.warn(`[RsGraph] Skipped ${skipped} orphan relation(s) — source or target node not in dataset`)
  }

  return elements
}

// ── Cytoscape style ───────────────────────────────────────────────
function buildStyle() {
  return [
    {
      selector: 'node',
      style: {
        'shape':            'roundrectangle',
        'label':            'data(label)',
        'text-valign':      'center',
        'text-halign':      'center',
        'font-size':        '11px',
        'font-weight':      '600',
        // 'label' keyword for width is deprecated in Cytoscape ≥3.23; use min-width instead
        'min-width':        'label',
        'min-height':       'label',
        'height':           '28px',
        'padding':          '8px',
        'background-color': '#ffffff',
        'border-width':     '2px',
        'border-color':     '#3b82f6',
        'color':            '#1e293b',
      },
    },
    {
      // Highlighted node (current record) — stored as integer 1
      selector: 'node[highlight = 1]',
      style: {
        'background-color': '#f97316',
        'border-color':     '#ea580c',
        'color':            '#ffffff',
      },
    },
    {
      // Nodes outside the filter — stored as integer 0
      selector: 'node[in_filter = 0]',
      style: {
        'border-style':     'dashed',
        'border-color':     '#94a3b8',
        'color':            '#64748b',
        'background-color': '#f8fafc',
      },
    },
    {
      // First-selected node in edit mode
      selector: 'node.rs-selected',
      style: {
        'background-color': '#ede9fe',
        'border-color':     '#7c3aed',
        'border-width':     '3px',
        'color':            '#5b21b6',
      },
    },
    {
      selector: 'edge',
      style: {
        'width':              1.5,
        'line-color':         '#94a3b8',
        'target-arrow-color': '#94a3b8',
        'target-arrow-shape': 'triangle',
        'curve-style':        'bezier',
        'label':              'data(label)',
        'font-size':          '9px',
        'color':              '#64748b',
        'text-background-color':   '#ffffff',
        'text-background-opacity': 0.8,
        'text-background-padding': '1px',
      },
    },
    {
      // Undirected edges (relations 9=same, 10=bound) — directed=0 means undirected
      selector: 'edge[directed = 0]',
      style: {
        'target-arrow-shape': 'none',
        'line-style':         'dashed',
      },
    },
    {
      // Counter-flow edges indicate stratigraphic cycles (data error)
      selector: 'edge.cyclic',
      style: {
        'line-color':         '#ef4444',
        'target-arrow-color': '#ef4444',
        'width':              2,
        'label':              'data(label)',
        'color':              '#ef4444',
      },
    },
    {
      // Edges in edit mode — show they are clickable
      selector: 'edge.edit-mode',
      style: {
        'width':      3,
        'line-color': '#fca5a5',
        'target-arrow-color': '#fca5a5',
      },
    },
    {
      selector: 'edge.edit-mode:hover',
      style: {
        'line-color': '#ef4444',
        'target-arrow-color': '#ef4444',
        'width':      3,
      },
    },
    {
      selector: 'node:selected',
      style: {
        'border-color':     '#7c3aed',
        'background-color': '#ede9fe',
      },
    },
  ]
}

// ── Selection management ──────────────────────────────────────────
function clearSelection() {
  if (cy) cy.$('.rs-selected').removeClass('rs-selected')
  selectedNode.value = null
}

function applyEditModeStyles() {
  if (!cy) return
  if (props.allowEdit) {
    cy.edges().addClass('edit-mode')
  } else {
    cy.edges().removeClass('edit-mode')
    clearSelection()
  }
}

// ── Init / destroy ────────────────────────────────────────────────
async function initCy() {
  if (!cyEl.value) return

  // Lazy-load to keep main bundle lean
  const [{ default: Cytoscape }, { default: CytoscapeDagre }] = await Promise.all([
    import('cytoscape'),
    import('cytoscape-dagre'),
  ])

  Cytoscape.use(CytoscapeDagre)

  if (cy) { cy.destroy(); cy = null }
  selectedNode.value = null

  cy = Cytoscape({
    container: cyEl.value,
    elements:  buildElements(),
    style:     buildStyle(),
    layout: {
      name:    'dagre',
      rankDir: 'TB',
      ranksep: 60,
      nodesep: 40,
      edgeSep: 20,
      animate: false,
      fit:     true,
    },
    minZoom:         0.05,
    maxZoom:         3,
    wheelSensitivity: 0.3,
  })

  // After layout: fit viewport and mark counter-flow (cyclic) edges in red.
  // A directed edge is counter-flow when its source node sits below its target
  // node in the TB layout — this indicates a stratigraphic cycle in the data.
  cy.on('layoutstop', () => {
    cy.fit(undefined, 20)
    cy.edges('[directed = 1]').forEach(edge => {
      const sy = edge.source().position('y')
      const ty = edge.target().position('y')
      if (sy > ty) {
        edge.addClass('cyclic')
      } else {
        edge.removeClass('cyclic')
      }
    })
  })

  // ── Node tap ──────────────────────────────────────────────────
  cy.on('tap', 'node', (evt) => {
    const data = evt.target.data()

    if (!props.allowEdit) {
      // Normal mode: navigate to record
      emit('node-click', { db_id: data.db_id, identifier: data.id })
      return
    }

    if (!selectedNode.value) {
      // First click: select node
      evt.target.addClass('rs-selected')
      selectedNode.value = { id: data.id, label: data.label, db_id: data.db_id }
    } else if (selectedNode.value.id === data.id) {
      // Click same node again: deselect
      clearSelection()
    } else {
      // Second click on different node: request add relation
      // id = String(db_id), so MatrixView receives integer-string as first/second
      const from = { ...selectedNode.value }
      clearSelection()
      emit('relation-add-requested', { from, to: { id: data.id, label: data.label, db_id: data.db_id } })
    }
  })

  // ── Edge tap (edit mode only: request delete) ─────────────────
  cy.on('tap', 'edge', (evt) => {
    if (!props.allowEdit) return
    const data = evt.target.data()
    emit('relation-delete-requested', {
      rs_id:    data.rs_id,
      source:   data.source_label ?? data.source,
      target:   data.target_label ?? data.target,
      relation: data.relation,
      label:    data.label,
    })
  })

  // ── Background tap: deselect ──────────────────────────────────
  cy.on('tap', (evt) => {
    if (evt.target === cy) clearSelection()
  })

  applyEditModeStyles()
}

function destroyCy() {
  if (cy) { cy.destroy(); cy = null }
}

/**
 * Export graph as PNG blob and trigger browser download.
 * Called externally via template ref from MatrixView.
 */
function exportPng() {
  if (!cy) return
  const blob = cy.png({ output: 'blob', bg: 'white', full: true, scale: 2 })
  const url  = URL.createObjectURL(blob)
  const a    = document.createElement('a')
  a.href     = url
  a.download = 'harris-matrix.png'
  a.click()
  URL.revokeObjectURL(url)
}

defineExpose({ exportPng })

onMounted(initCy)
onBeforeUnmount(destroyCy)

// Re-build graph when data changes
watch(() => [props.nodes, props.relations, props.highlightId], initCy, { deep: true })

// Apply/remove edit-mode styles when toggle changes (without full rebuild)
watch(() => props.allowEdit, () => {
  clearSelection()
  applyEditModeStyles()
})
</script>

<style scoped>
.rs-graph-wrap {
  position: relative;
  width: 100%;
  height: 100%;
}

.rs-graph-canvas {
  width:  100%;
  height: 100%;
}

.rs-graph-empty {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  color: var(--p-text-muted-color);
  font-size: 0.9rem;
  pointer-events: none;
}

/* Edit mode hint bar */
.rs-graph-hint {
  position: absolute;
  bottom: 1rem;
  left: 50%;
  transform: translateX(-50%);
  background: var(--p-surface-card);
  border: 1px solid var(--p-surface-border);
  border-radius: 999px;
  padding: 0.35rem 0.9rem;
  font-size: 0.8rem;
  color: var(--p-text-muted-color);
  display: flex;
  align-items: center;
  gap: 0.5rem;
  box-shadow: 0 2px 8px rgba(0,0,0,0.08);
  pointer-events: auto;
  white-space: nowrap;
}

.rs-hint-cancel {
  background: none;
  border: none;
  cursor: pointer;
  color: var(--p-red-400);
  font-size: 0.78rem;
  padding: 0 0.2rem;
  display: flex;
  align-items: center;
  gap: 0.25rem;
}
.rs-hint-cancel:hover { color: var(--p-red-600); }
</style>
