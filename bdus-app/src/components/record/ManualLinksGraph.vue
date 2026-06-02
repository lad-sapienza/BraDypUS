<template>
  <div class="ml-graph-wrap">
    <div ref="cyEl" class="ml-graph-canvas" />
    <div v-if="isEmpty" class="ml-graph-empty">
      <i class="pi pi-info-circle" />
      {{ t('no_user_links') }}
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useI18n } from '@/i18n'

const { t }   = useI18n()
const router  = useRouter()
const route   = useRoute()

const props = defineProps({
  /**
   * links object — same shape as ManualLinksSection.links:
   *   { [linkKey]: { key, tb_id, tb_stripped, ref_id, ref_label, label, ... } }
   */
  links:        { type: Object, default: () => ({}) },
  /** Full table name of the current record (used for the self node) */
  recordTb:     { type: String, default: null },
  /** Numeric id of the current record */
  recordId:     { type: [String, Number], default: null },
  /** Human-readable label for the current record node */
  recordLabel:  { type: String, default: null },
})

const cyEl = ref(null)
let cy = null

const isEmpty = computed(() => Object.keys(props.links).length === 0)

// ── Build Cytoscape elements ──────────────────────────────────────
function buildElements() {
  const elements = []
  const selfId   = 'self'
  const selfLabel = props.recordLabel || String(props.recordId)

  elements.push({
    group: 'nodes',
    data: {
      id:       selfId,
      label:    selfLabel,
      isSelf:   1,
      tb:       props.recordTb,
      db_id:    props.recordId,
    },
  })

  for (const ml of Object.values(props.links)) {
    const nodeId = `n-${ml.tb_id}-${ml.ref_id}`

    // Deduplicate nodes (same target record linked multiple times)
    if (!elements.find(e => e.group === 'nodes' && e.data.id === nodeId)) {
      elements.push({
        group: 'nodes',
        data: {
          id:       nodeId,
          label:    String(ml.ref_label ?? ml.ref_id),
          isSelf:   0,
          tb:       ml.tb_id,
          tb_label: ml.tb_label,
          db_id:    ml.ref_id,
        },
      })
    }

    elements.push({
      group: 'edges',
      data: {
        id:     `e-${ml.key}`,
        source: selfId,
        target: nodeId,
        label:  ml.label ?? '',
      },
    })
  }

  return elements
}

// ── Style ─────────────────────────────────────────────────────────
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
        'font-weight':      '500',
        'min-width':        'label',
        'min-height':       'label',
        'height':           '28px',
        'padding':          '8px',
        'background-color': '#ffffff',
        'border-width':     '2px',
        'border-color':     '#3b82f6',
        'color':            '#1e293b',
        'cursor':           'pointer',
      },
    },
    {
      selector: 'node[isSelf = 1]',
      style: {
        'background-color': '#f97316',
        'border-color':     '#ea580c',
        'color':            '#ffffff',
        'font-weight':      '700',
        'cursor':           'default',
      },
    },
    {
      selector: 'node:hover',
      style: {
        'border-color': '#6366f1',
      },
    },
    {
      selector: 'edge',
      style: {
        'width':                       1.5,
        'line-color':                  '#94a3b8',
        'curve-style':                 'bezier',
        'target-arrow-color':          '#94a3b8',
        'target-arrow-shape':          'none',
        'label':                       'data(label)',
        'font-size':                   '9px',
        'color':                       '#64748b',
        'text-background-color':       '#ffffff',
        'text-background-opacity':     0.85,
        'text-background-padding':     '2px',
        'text-rotation':               'autorotate',
      },
    },
  ]
}

// ── Init / destroy ────────────────────────────────────────────────
async function initCy() {
  if (!cyEl.value || isEmpty.value) return

  const { default: Cytoscape } = await import('cytoscape')

  if (cy) { cy.destroy(); cy = null }

  cy = Cytoscape({
    container: cyEl.value,
    elements:  buildElements(),
    style:     buildStyle(),
    layout: {
      name:             'cose',
      animate:          false,
      fit:              true,
      padding:          24,
      nodeRepulsion:    8000,
      idealEdgeLength:  120,
      gravity:          0.3,
    },
    minZoom:          0.1,
    maxZoom:          3,
    wheelSensitivity: 0.3,
  })

  cy.on('layoutstop', () => cy.fit(undefined, 20))

  cy.on('tap', 'node', (evt) => {
    const data = evt.target.data()
    if (data.isSelf) return
    router.push(`/${route.params.app}/record/${data.tb}/${data.db_id}`)
  })
}

function destroyCy() {
  if (cy) { cy.destroy(); cy = null }
}

onMounted(initCy)
onBeforeUnmount(destroyCy)
watch(() => [props.links, props.recordLabel], initCy, { deep: true })
</script>

<style scoped>
.ml-graph-wrap {
  position: relative;
  width:    100%;
  height:   260px;
  border:   1px solid var(--p-content-border-color);
  border-radius: 6px;
  overflow: hidden;
  margin-top: 0.75rem;
}

.ml-graph-canvas {
  width:  100%;
  height: 100%;
}

.ml-graph-empty {
  position: absolute;
  inset:    0;
  display:  flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  color: var(--p-text-muted-color);
  font-size: 0.875rem;
  pointer-events: none;
}
</style>
