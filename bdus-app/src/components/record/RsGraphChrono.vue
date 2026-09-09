<template>
  <div class="rs-chrono-wrap">
    <!-- Year axis (SVG, left side) -->
    <svg
      v-if="ready && axisTicks.length"
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
      <InfoCircleOutlined />
      {{ t('rs_no_relations') }}
    </div>

    <div v-if="undatedCount" class="rs-chrono-undated-label">
      {{ t('chrono_undated_section', undatedCount) }}
    </div>
  </div>
</template>

<script setup>
import { InfoCircleOutlined } from '@ant-design/icons-vue'
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from '@/i18n'
import { format as chronoFormat } from '@/utils/chronoParser'
import { REL_KEYS, UNDIRECTED, SWAP_DIRECTION, REL_INVERSE } from '@/composables/useRsRelations'

const { t } = useI18n()

const props = defineProps({
  nodes:       { type: Array,  default: () => [] },
  relations:   { type: Array,  default: () => [] },
  highlightId: { type: String, default: null },
})

const emit = defineEmits(['node-click'])

// ── Constants ────────────────────────────────────────────────────────────────
const AXIS_W         = 72    // px — SVG left axis width (fits "3000 BCE")
const MIN_SLOPE_FRAC = 0.5   // floor on the stretch slope, as a fraction of the
                             // natural dagre spacing — keeps adjacent ranks
                             // apart even where dated anchors sit in a tight
                             // year cluster (they then drift slightly off their
                             // exact year rather than overlap)

// ── Refs ─────────────────────────────────────────────────────────────────────
const cyEl    = ref(null)
const canvasH = ref(400)      // updated after layout to drive SVG height
const ready   = ref(false)    // true once the first layout pass has run
const yBand   = ref(null)     // { top, bottom } — model-Y span the year scale maps onto
let   cy      = null
let   ro      = null          // ResizeObserver on the canvas → keeps canvasH live

// Cytoscape's live pan/zoom transform. The year axis is a plain SVG rendered
// next to the Cytoscape canvas (not inside it), so it does not automatically
// track user pan/zoom — we mirror the transform here and apply it to the
// axis ticks ourselves, using the same formula Cytoscape uses internally
// for model → screen coordinates: screenY = pan.y + modelY * zoom.
const panZoom = ref({ pan: { x: 0, y: 0 }, zoom: 1 })

function syncPanZoom() {
  if (!cy) return
  panZoom.value = { pan: { x: cy.pan().x, y: cy.pan().y }, zoom: cy.zoom() }
}

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

// Map a year → Cytoscape model Y. The dated year range [min,max] is mapped
// linearly onto the vertical span the dagre pass produced (yBand), so dated and
// undated nodes share one coordinate space. Newer year = smaller Y (top).
function yearToY(year) {
  const r = yearRange.value
  const b = yBand.value
  if (!r || !b) return 0
  const span = r.max - r.min
  if (span <= 0) return (b.top + b.bottom) / 2
  return b.top + ((r.max - year) / span) * (b.bottom - b.top)
}

// Map a year → SVG screen Y, applying Cytoscape's current pan/zoom so the
// axis tracks the canvas as the user pans/zooms it.
function yearToScreenY(year) {
  return panZoom.value.pan.y + yearToY(year) * panZoom.value.zoom
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
      cy:    yearToScreenY(y),
      label: y < 0 ? `${Math.abs(y)} BCE` : `${y} CE`,
    })
  }
  return ticks
})

// ── Build Cytoscape elements ──────────────────────────────────────────────────
function buildElements() {
  const elements = []
  // Index by db_id (integer → string) since first/second are now integer IDs
  const nodeIds  = new Set(props.nodes.map(n => String(n.db_id)))

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
        id:        String(n.db_id),       // Cytoscape node id = db primary key
        label:     sublabel ? `${label}\n${sublabel}` : label,
        db_id:     n.db_id,
        in_filter: n.in_filter ? 1 : 0,
        highlight: String(n.db_id) === String(props.highlightId) ? 1 : 0,
        undated:   isUndated ? 1 : 0,
        dated:     isUndated ? 0 : 1,
        year:      nodeYear(n),
      },
    })
  }

  const seenUndirected = new Set()
  for (const r of props.relations) {
    // first/second are now integer db_ids
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
        label:    (() => { const lr = needSwap ? REL_INVERSE[rel] : rel; return REL_KEYS[lr] ? t(REL_KEYS[lr]) : String(lr) })(),
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
    // Out-of-filter first, then the dated/undated accent, then highlight last
    // so a highlighted node always reads as orange.
    {
      selector: 'node[in_filter = 0]',
      style: { 'border-style': 'dashed', 'border-color': '#94a3b8', 'color': '#64748b', 'background-color': '#f8fafc' },
    },
    {
      selector: 'node[dated = 1]',
      style: { 'border-color': '#16a34a' },   // green — pinned to its year
    },
    {
      selector: 'node[undated = 1]',
      style: { 'border-color': '#d97706', 'background-color': '#fffbeb', 'color': '#92400e', 'font-style': 'italic' },  // amber — placed by topology
    },
    {
      selector: 'node[highlight = 1]',
      style: { 'background-color': '#f97316', 'border-color': '#ea580c', 'color': '#ffffff' },
    },
    {
      selector: 'edge',
      style: {
        'width':              1.5,
        'line-color':         '#94a3b8',
        'target-arrow-color': '#94a3b8',
        'target-arrow-shape': 'triangle',
        'curve-style':        'bezier',
      },
    },
    {
      selector: 'edge[directed = 0]',
      style: { 'target-arrow-shape': 'none', 'line-style': 'dashed' },
    },
  ]
}

// ── Cytoscape layout ─────────────────────────────────────────────────────────
// The Harris matrix keeps its dagre layout untouched; chronological mode only
// stretches/squashes it vertically. A monotone piecewise-linear map f(dagreY)
// → finalY is pinned at the dated nodes (f(dagreY) = yearToY(year)) and applied
// to *every* node, so rank order is preserved and no two ranks ever coincide.
// See applyLayout() + buildAnchors() / makeStretch().
async function initCy() {
  if (!cyEl.value) return

  const [{ default: Cytoscape }, { default: CytoscapeDagre }] = await Promise.all([
    import('cytoscape'),
    import('cytoscape-dagre'),
  ])
  Cytoscape.use(CytoscapeDagre)

  destroyCy()

  cy = Cytoscape({
    container: cyEl.value,
    elements:  buildElements(),
    style:     buildStyle(),
    layout:    { name: 'preset' },   // no auto-layout — applyLayout() runs it below
    minZoom:   0.02,
    maxZoom:   3,
    wheelSensitivity: 0.3,
  })

  cy.on('pan zoom', syncPanZoom)
  cy.on('tap', 'node', evt => {
    const d = evt.target.data()
    emit('node-click', { db_id: d.db_id, identifier: d.id })
  })

  applyLayout()

  // The SVG axis lives next to the canvas, not inside it: keep its height and
  // the mirrored pan/zoom in step with the real canvas size (initial + resize).
  ro = new ResizeObserver(() => { updateCanvasH(); syncPanZoom() })
  ro.observe(cyEl.value)
}

// Longest non-decreasing subsequence of `arr` by the `t` key (arr already
// sorted by `d`). Drops the dated anchors whose year runs backwards against
// the stratigraphy so the stretch map can stay monotone.
function longestNonDecreasing(arr) {
  const m = arr.length
  if (m <= 1) return arr.slice()
  const prev = new Array(m).fill(-1)
  const tails = []                    // indices into arr
  for (let i = 0; i < m; i++) {
    let lo = 0, hi = tails.length
    while (lo < hi) {
      const mid = (lo + hi) >> 1
      if (arr[tails[mid]].t <= arr[i].t) lo = mid + 1
      else hi = mid
    }
    prev[i] = lo > 0 ? tails[lo - 1] : -1
    if (lo === tails.length) tails.push(i)
    else tails[lo] = i
  }
  const out = []
  for (let i = tails[tails.length - 1]; i >= 0; i = prev[i]) out.push(arr[i])
  return out.reverse()
}

// Dated nodes → clean anchor list for the stretch map. Sorted by dagre Y,
// equal ranks merged (mean target), contradictory ones dropped, then nudged so
// every segment has slope ≥ minSlopeFrac (adjacent ranks can't coincide; where
// the data demands more compression than that, later anchors drift slightly
// below their exact year).
function buildAnchors(dated, minSlopeFrac) {
  const sorted = dated.slice().sort((a, b) => a.d - b.d)

  const merged = []
  for (const a of sorted) {
    const last = merged[merged.length - 1]
    if (last && Math.abs(last.d - a.d) < 1e-6) {
      last.t = (last.t * last.n + a.t) / (last.n + 1)
      last.n++
    } else {
      merged.push({ d: a.d, t: a.t, n: 1 })
    }
  }
  if (merged.length <= 1) return merged.map(({ d, t }) => ({ d, t }))

  const keep = longestNonDecreasing(merged).map(({ d, t }) => ({ d, t }))
  for (let i = 1; i < keep.length; i++) {
    const floor = keep[i - 1].t + minSlopeFrac * (keep[i].d - keep[i - 1].d)
    if (keep[i].t < floor) keep[i].t = floor
  }
  return keep
}

// Monotone piecewise-linear map through `anchors` (≥1, strictly increasing in
// both d and t). Slope 1 (natural dagre spacing) beyond the outer anchors.
function makeStretch(anchors) {
  const last = anchors.length - 1
  return (yv) => {
    if (last === 0) return anchors[0].t + (yv - anchors[0].d)
    if (yv <= anchors[0].d)    return anchors[0].t + (yv - anchors[0].d)
    if (yv >= anchors[last].d) return anchors[last].t + (yv - anchors[last].d)
    let i = 0
    while (i < last && anchors[i + 1].d <= yv) i++
    const a = anchors[i], b = anchors[i + 1]
    return a.t + ((yv - a.d) * (b.t - a.t)) / (b.d - a.d)
  }
}

function applyLayout() {
  if (!cy) return

  // Phase 1 — the full dagre layout. We only stretch/squash it vertically;
  // ranks are never reordered or merged.
  cy.layout({
    name: 'dagre', rankDir: 'TB', ranksep: 60, nodesep: 40,
    animate: false, fit: false,
  }).run()

  const nodes = cy.nodes()
  const pos = {}
  nodes.forEach(n => { pos[n.id()] = { x: n.position('x'), y: n.position('y') } })

  const r = yearRange.value
  if (!r) {
    // No dated nodes — plain dagre, no year scale, no axis.
    yBand.value = null
    cy.fit(undefined, 20)
    updateCanvasH(); syncPanZoom(); ready.value = true
    return
  }

  // yearToY() maps [minYear, maxYear] onto dagre's own vertical extent, so an
  // unwarped node would sit at ~its dagre Y and the axis reads in those units.
  const ys = nodes.map(n => pos[n.id()].y)
  const top = Math.min(...ys)
  let   bottom = Math.max(...ys)
  if (bottom - top < 1) bottom = top + Math.max(1, nodes.length) * 80
  yBand.value = { top, bottom }

  // Dated nodes → anchors (dagreY, yearY); build the monotone stretch map.
  const dated = nodes
    .filter(n => n.data('dated') === 1)
    .map(n => ({ d: pos[n.id()].y, t: yearToY(n.data('year')) }))
  const anchors = buildAnchors(dated, MIN_SLOPE_FRAC)

  if (!anchors.length) {
    // Every dated node contradicted the stratigraphy — leave dagre as-is.
    yBand.value = null
    cy.fit(undefined, 20)
    updateCanvasH(); syncPanZoom(); ready.value = true
    return
  }

  const f = makeStretch(anchors)

  cy.layout({
    name: 'preset',
    positions: n => ({ x: pos[n.id()].x, y: f(pos[n.id()].y) }),
    fit: true,
    padding: 30,
  }).run()

  updateCanvasH(); syncPanZoom(); ready.value = true
}

function updateCanvasH() {
  const h = cyEl.value?.offsetHeight
  if (h && h !== canvasH.value) canvasH.value = h
}

function destroyCy() {
  if (ro) { ro.disconnect(); ro = null }
  if (cy) { cy.destroy(); cy = null }
  ready.value = false
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
