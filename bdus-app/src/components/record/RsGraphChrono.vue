<template>
  <div class="rs-chrono-wrap">
    <!-- Year axis (SVG, left side) -->
    <svg
      v-if="axisTicks.length"
      class="rs-chrono-axis"
      :width="AXIS_W"
      :height="canvasH"
    >
      <line
        :x1="AXIS_W - 1" y1="0"
        :x2="AXIS_W - 1" :y2="canvasH"
        stroke="var(--p-surface-300)" stroke-width="1"
      />
      <g v-for="tick in axisTicks" :key="tick.year">
        <line
          :x1="AXIS_W - 6" :y1="tick.cy"
          :x2="AXIS_W"     :y2="tick.cy"
          stroke="var(--p-surface-400)" stroke-width="1"
        />
        <text
          :x="AXIS_W - 9"
          :y="tick.cy + 4"
          text-anchor="end"
          font-size="10"
          fill="var(--p-text-muted-color)"
        >{{ tick.label }}</text>
      </g>
    </svg>

    <!-- Cytoscape canvas -->
    <div ref="cyEl" class="rs-chrono-canvas" />

    <div v-if="!nodes.length" class="rs-chrono-empty">
      <i class="pi pi-info-circle" />
      {{ t('rs_no_relations') }}
    </div>

    <div v-if="undatedCount" class="rs-chrono-undated-label">
      {{ t('chrono_undated_section', undatedCount) }}
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from '@/i18n'
import { format as chronoFormat } from '@/utils/chronoParser'
import { REL_KEYS, UNDIRECTED, SWAP_DIRECTION } from '@/composables/useRsRelations'

const { t } = useI18n()

const props = defineProps({
  nodes:       { type: Array,  default: () => [] },
  relations:   { type: Array,  default: () => [] },
  highlightId: { type: String, default: null },
})

const emit = defineEmits(['node-click'])

// ── Constants ────────────────────────────────────────────────────────────────
const AXIS_W           = 64   // px — SVG left axis width
const PIXELS_PER_YEAR  = 1    // Cytoscape coordinate units per year
const UNDATED_OFFSET   = 120  // units below the dated zone for undated nodes

// ── Refs ─────────────────────────────────────────────────────────────────────
const cyEl    = ref(null)
const canvasH = ref(400)      // updated after layout to drive SVG height
let   cy      = null

// ── Separate dated / undated nodes ───────────────────────────────────────────
const datedNodes = computed(() =>
  props.nodes.filter(n => n.chrono_from != null || n.chrono_to != null)
)

const undatedNodes = computed(() =>
  props.nodes.filter(n => n.chrono_from == null && n.chrono_to == null)
)

const undatedCount = computed(() => undatedNodes.value.length)

// ── Year range from dated nodes ───────────────────────────────────────────────
const yearRange = computed(() => {
  const years = []
  for (const n of datedNodes.value) {
    if (n.chrono_from != null) years.push(n.chrono_from)
    if (n.chrono_to   != null) years.push(n.chrono_to)
  }
  if (!years.length) return null
  return { min: Math.min(...years), max: Math.max(...years) }
})

// Map a year → Cytoscape Y coordinate.
// Newer (higher year number) = smaller Y (top); older = larger Y (bottom).
function yearToY(year) {
  const r = yearRange.value
  if (!r) return 0
  return (r.max - year) * PIXELS_PER_YEAR
}

// Reference Y for a node (ante/post quem handled).
function nodeYear(n) {
  if (n.chrono_from != null && n.chrono_to != null) {
    return (n.chrono_from + n.chrono_to) / 2
  }
  if (n.chrono_from != null) return n.chrono_from  // post quem
  if (n.chrono_to   != null) return n.chrono_to    // ante quem
  return null
}

// ── Y-axis tick computation (shared with SVG) ─────────────────────────────────
const axisTicks = computed(() => {
  const r = yearRange.value
  if (!r) return []

  const span = r.max - r.min
  // Pick a nice step: aim for ~6-10 ticks
  const rawStep = span / 7
  const steps = [25, 50, 100, 200, 250, 500, 1000, 2000]
  const step = steps.find(s => s >= rawStep) ?? 2000

  const ticks = []
  const start = Math.ceil(r.min / step) * step
  for (let y = start; y <= r.max; y += step) {
    ticks.push({
      year:  y,
      cy:    yearToY(y),
      label: y < 0 ? `${Math.abs(y)} BCE` : `${y} CE`,
    })
  }
  return ticks
})

// ── Build Cytoscape elements ──────────────────────────────────────────────────
function buildElements() {
  const elements = []
  const nodeIds  = new Set(props.nodes.map(n => String(n.identifier)))

  for (const n of props.nodes) {
    const label      = String(n.identifier)
    const sublabel   = n.chrono_label ?? (
      n.chrono_from != null || n.chrono_to != null
        ? chronoFormat(n.chrono_from ?? null, n.chrono_to ?? null)
        : null
    )
    const isUndated = (n.chrono_from == null && n.chrono_to == null)

    elements.push({
      group: 'nodes',
      data: {
        id:        label,
        label:     sublabel ? `${label}\n${sublabel}` : label,
        db_id:     n.db_id,
        in_filter: n.in_filter ? 1 : 0,
        highlight: label === props.highlightId ? 1 : 0,
        undated:   isUndated ? 1 : 0,
        year:      nodeYear(n),
      },
    })
  }

  const seenUndirected = new Set()
  for (const r of props.relations) {
    const src = String(r.first)
    const tgt = String(r.second)
    if (!nodeIds.has(src) || !nodeIds.has(tgt)) continue

    const rel      = parseInt(r.relation, 10)
    const isUndir  = UNDIRECTED.has(rel)
    const needSwap = SWAP_DIRECTION.has(rel)
    const edgeSrc  = needSwap ? tgt : src
    const edgeTgt  = needSwap ? src : tgt
    const edgeKey  = isUndir
      ? [edgeSrc, edgeTgt].sort().join('|') + '|' + rel
      : null

    if (isUndir) {
      if (seenUndirected.has(edgeKey)) continue
      seenUndirected.add(edgeKey)
    }

    elements.push({
      group: 'edges',
      data: {
        id:       'e' + r.id,
        source:   edgeSrc,
        target:   edgeTgt,
        label:    REL_KEYS[rel] ? t(REL_KEYS[rel]) : String(rel),
        directed: isUndir ? 0 : 1,
      },
    })
  }

  return elements
}

// ── Cytoscape style ───────────────────────────────────────────────────────────
function buildStyle() {
  return [
    {
      selector: 'node',
      style: {
        'shape':            'roundrectangle',
        'label':            'data(label)',
        'text-valign':      'center',
        'text-halign':      'center',
        'text-wrap':        'wrap',
        'font-size':        '10px',
        'font-weight':      '600',
        'min-width':        'label',
        'min-height':       'label',
        'height':           '36px',
        'padding':          '8px',
        'background-color': '#ffffff',
        'border-width':     '2px',
        'border-color':     '#3b82f6',
        'color':            '#1e293b',
      },
    },
    {
      selector: 'node[highlight = 1]',
      style: { 'background-color': '#f97316', 'border-color': '#ea580c', 'color': '#ffffff' },
    },
    {
      selector: 'node[in_filter = 0]',
      style: { 'border-style': 'dashed', 'border-color': '#94a3b8', 'color': '#64748b', 'background-color': '#f8fafc' },
    },
    {
      selector: 'node[undated = 1]',
      style: { 'border-color': '#d1d5db', 'background-color': '#f9fafb', 'color': '#9ca3af', 'font-style': 'italic' },
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
      selector: 'edge[directed = 0]',
      style: { 'target-arrow-shape': 'none', 'line-style': 'dashed' },
    },
  ]
}

// ── Two-phase Cytoscape layout ────────────────────────────────────────────────
async function initCy() {
  if (!cyEl.value) return

  const [{ default: Cytoscape }, { default: CytoscapeDagre }] = await Promise.all([
    import('cytoscape'),
    import('cytoscape-dagre'),
  ])
  Cytoscape.use(CytoscapeDagre)

  if (cy) { cy.destroy(); cy = null }

  cy = Cytoscape({
    container: cyEl.value,
    elements:  buildElements(),
    style:     buildStyle(),
    layout:    { name: 'dagre', rankDir: 'TB', ranksep: 60, nodesep: 40, animate: false },
    minZoom:   0.02,
    maxZoom:   3,
    wheelSensitivity: 0.3,
  })

  // Phase 1 complete → override Y with chrono, keep dagre X
  cy.one('layoutstop', () => {
    const r = yearRange.value

    if (!r) {
      // No dated nodes: just fit the dagre result
      cy.fit(undefined, 20)
      return
    }

    // Bottom Y of the dated zone
    const datedBottomY = yearToY(r.min) + 60

    const positions = {}
    cy.nodes().forEach(node => {
      const year = node.data('year')
      positions[node.id()] = {
        x: node.position('x'),
        y: year != null
          ? yearToY(year)
          : datedBottomY + UNDATED_OFFSET,
      }
    })

    cy.layout({
      name:      'preset',
      positions: n => positions[n.id()],
      fit:       true,
      padding:   30,
    }).run()

    // After preset: sync SVG axis height
    cy.one('layoutstop', () => {
      canvasH.value = cyEl.value?.offsetHeight ?? 400
    })
  })

  cy.on('tap', 'node', evt => {
    const d = evt.target.data()
    emit('node-click', { db_id: d.db_id, identifier: d.id })
  })
}

function destroyCy() {
  if (cy) { cy.destroy(); cy = null }
}

function exportPng() {
  if (!cy) return
  const blob = cy.png({ output: 'blob', bg: 'white', full: true, scale: 2 })
  const url  = URL.createObjectURL(blob)
  const a    = document.createElement('a')
  a.href = url; a.download = 'harris-matrix-chrono.png'; a.click()
  URL.revokeObjectURL(url)
}

defineExpose({ exportPng })

onMounted(initCy)
onBeforeUnmount(destroyCy)
watch(() => [props.nodes, props.relations, props.highlightId], initCy, { deep: true })
</script>

<style scoped>
.rs-chrono-wrap {
  position: relative;
  display: flex;
  width: 100%;
  height: 100%;
}

.rs-chrono-axis {
  flex-shrink: 0;
  overflow: visible;
}

.rs-chrono-canvas {
  flex: 1;
  min-width: 0;
  height: 100%;
}

.rs-chrono-empty {
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

.rs-chrono-undated-label {
  position: absolute;
  bottom: 0.75rem;
  right: 1rem;
  font-size: 0.75rem;
  color: var(--p-text-muted-color);
  font-style: italic;
  pointer-events: none;
}
</style>
