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
import { REL_KEYS, REL_INVERSE, UNDIRECTED, SWAP_DIRECTION } from '@/composables/useRsRelations'

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
/**
 * Returns { nodeEls, directedEdgeEls }.
 *
 * Nodes connected by undirected relations (9=same_as, 10=bound_to) are merged
 * into a single compound node labelled "A\n= B\n= C". All directed edges that
 * pointed to any constituent node are re-routed to the merged node; self-loops
 * and duplicate edges (arising from the merge) are dropped.
 * This avoids all dagre same-rank routing issues and matches the traditional
 * Harris Matrix convention of grouping contemporaneous units in one box.
 */
function buildElements() {
  const nodeEls         = []
  const directedEdgeEls = []

  const nodeIds   = new Set(props.nodes.map(n => String(n.db_id)))
  const labelById = {}
  for (const n of props.nodes) labelById[String(n.db_id)] = String(n.identifier)

  // ── Step 1: find contemporaneous groups via BFS on undirected relations ──
  const adj = new Map()
  for (const r of props.relations) {
    const rel = parseInt(r.relation, 10)
    if (!UNDIRECTED.has(rel)) continue
    const s = String(r.first)
    const t = String(r.second)
    if (!nodeIds.has(s) || !nodeIds.has(t)) continue
    if (!adj.has(s)) adj.set(s, new Set())
    if (!adj.has(t)) adj.set(t, new Set())
    adj.get(s).add(t)
    adj.get(t).add(s)
  }

  // mergeMap: original db_id string → merged-node Cytoscape id
  const mergeMap = new Map()

  const visited = new Set()
  for (const start of adj.keys()) {
    if (visited.has(start)) continue
    const group = []
    const queue = [start]
    while (queue.length) {
      const id = queue.shift()
      if (visited.has(id)) continue
      visited.add(id)
      group.push(id)
      for (const nb of adj.get(id)) {
        if (!visited.has(nb)) queue.push(nb)
      }
    }
    if (group.length < 2) continue   // singletons stay as regular nodes

    // Sort alphabetically on identifier label for a stable, readable label
    const sorted     = [...group].sort((a, b) =>
      (labelById[a] ?? a).localeCompare(labelById[b] ?? b)
    )
    const mergedId    = 'merged_' + sorted.join('_')
    const mergedLabel = sorted.map(id => labelById[id] ?? id).join('\n= ')

    const groupNodes  = props.nodes.filter(n => group.includes(String(n.db_id)))
    const inFilter    = groupNodes.some(n => n.in_filter) ? 1 : 0
    const highlight   = group.some(id => String(id) === String(props.highlightId)) ? 1 : 0

    for (const id of group) mergeMap.set(id, mergedId)

    nodeEls.push({
      group: 'nodes',
      data: {
        id:        mergedId,
        label:     mergedLabel,
        db_id:     sorted[0],   // primary id used for navigation on click
        in_filter: inFilter,
        highlight,
        merged:    1,
      },
    })
  }

  // ── Step 2: add individual (non-merged) nodes ──────────────────────────
  for (const n of props.nodes) {
    const nodeId = String(n.db_id)
    if (mergeMap.has(nodeId)) continue   // already represented by a merged node
    nodeEls.push({
      group: 'nodes',
      data: {
        id:        nodeId,
        label:     String(n.identifier),
        db_id:     n.db_id,
        in_filter: n.in_filter ? 1 : 0,
        highlight: nodeId === String(props.highlightId) ? 1 : 0,
        merged:    0,
      },
    })
  }

  // ── Step 3: build directed edges, remapping endpoints through mergeMap ──
  const validNodeIds = new Set(nodeEls.map(e => e.data.id))
  const seenEdges    = new Set()
  let   skipped      = 0

  for (const r of props.relations) {
    const rel = parseInt(r.relation, 10)
    if (UNDIRECTED.has(rel)) continue   // undirected = merged, no edge needed

    const first  = mergeMap.get(String(r.first))  ?? String(r.first)
    const second = mergeMap.get(String(r.second)) ?? String(r.second)

    if (!validNodeIds.has(first) || !validNodeIds.has(second)) { skipped++; continue }
    if (first === second) continue   // self-loop after merging

    const needsSwap = SWAP_DIRECTION.has(rel)
    const edgeSrc   = needsSwap ? second : first
    const edgeTgt   = needsSwap ? first  : second

    // Drop duplicate directed edges that arise from merging multiple nodes
    const edgeKey = `${edgeSrc}→${edgeTgt}:${rel}`
    if (seenEdges.has(edgeKey)) continue
    seenEdges.add(edgeKey)

    const lr = needsSwap ? REL_INVERSE[rel] : rel
    directedEdgeEls.push({
      group: 'edges',
      data: {
        id:           'e' + r.id,
        rs_id:        r.id,
        source:       edgeSrc,
        target:       edgeTgt,
        source_label: labelById[edgeSrc] ?? edgeSrc,
        target_label: labelById[edgeTgt] ?? edgeTgt,
        label:        REL_KEYS[lr] ? t(REL_KEYS[lr]) : String(lr),
        relation:     rel,
        directed:     1,
      },
    })
  }

  if (skipped > 0) {
    console.warn(`[RsGraph] Skipped ${skipped} orphan relation(s) — node not in dataset`)
  }

  return { nodeEls, directedEdgeEls }
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
      // Merged node (two or more contemporaneous units collapsed into one)
      selector: 'node[merged = 1]',
      style: {
        'background-color': '#dbeafe',
        'border-color':     '#2563eb',
        'border-width':     '3px',
        'text-wrap':        'wrap',
        'text-max-width':   '120px',
      },
    },
    {
      // Merged node that contains the highlighted record
      selector: 'node[merged = 1][highlight = 1]',
      style: {
        'background-color': '#fed7aa',
        'border-color':     '#ea580c',
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

  const [{ default: Cytoscape }, { default: CytoscapeDagre }] = await Promise.all([
    import('cytoscape'),
    import('cytoscape-dagre'),
  ])

  Cytoscape.use(CytoscapeDagre)

  if (cy) { cy.destroy(); cy = null }
  selectedNode.value = null

  const { nodeEls, directedEdgeEls } = buildElements()

  // Cytoscape is initialised WITHOUT a layout option — dagre is run explicitly
  // below so that post-layout steps run inline after the sync .run() call.
  cy = Cytoscape({
    container: cyEl.value,
    elements:  [...nodeEls, ...directedEdgeEls],
    style:     buildStyle(),
    minZoom:         0.05,
    maxZoom:         3,
    wheelSensitivity: 0.3,
  })

  // ── Node tap ──────────────────────────────────────────────────
  cy.on('tap', 'node', (evt) => {
    const data = evt.target.data()

    if (!props.allowEdit) {
      emit('node-click', { db_id: data.db_id, identifier: data.id })
      return
    }

    if (!selectedNode.value) {
      evt.target.addClass('rs-selected')
      selectedNode.value = { id: data.id, label: data.label, db_id: data.db_id }
    } else if (selectedNode.value.id === data.id) {
      clearSelection()
    } else {
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

  // ── Run dagre synchronously (animate:false guarantees sync completion) ──
  cy.layout({
    name:    'dagre',
    rankDir: 'TB',
    ranksep: 60,
    nodesep: 40,
    edgeSep: 20,
    animate: false,
    fit:     true,
  }).run()

  // ── Post-layout steps (inline — animate:false makes .run() synchronous) ──
  cy.fit(undefined, 20)
  // Mark counter-flow (cyclic) directed edges in red.
  cy.edges().forEach(edge => {
    const sy = edge.source().position('y')
    const ty = edge.target().position('y')
    if (sy > ty) edge.addClass('cyclic')
    else         edge.removeClass('cyclic')
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
