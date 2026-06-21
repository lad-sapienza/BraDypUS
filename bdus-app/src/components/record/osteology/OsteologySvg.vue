<template>
  <div class="osteo-svg-wrap" ref="wrapEl">
    <!-- Zoom controls -->
    <div class="osteo-svg-controls">
      <button class="osteo-ctrl-btn" @click="adjustZoom(1.2)" title="Zoom in">+</button>
      <button class="osteo-ctrl-btn" @click="adjustZoom(0.8)" title="Zoom out">−</button>
      <button class="osteo-ctrl-btn" @click="resetView" title="Reimposta vista">↺</button>
    </div>

    <!-- Orientation labels -->
    <div class="osteo-orient">
      <span class="osteo-orient-dx">← D</span>
      <span class="osteo-orient-sx">S →</span>
    </div>

    <!-- SVG container (pan + zoom via div transform) -->
    <div
      class="osteo-svg-container"
      ref="containerEl"
      @wheel.prevent="onWheel"
      @mousedown="onMouseDown"
      @mousemove="onMouseMove"
      @mouseup="onMouseUp"
      @mouseleave="onMouseUp"
      @touchstart.prevent="onTouchStart"
      @touchmove.prevent="onTouchMove"
      @touchend="onTouchEnd"
    >
      <svg
        ref="svgEl"
        viewBox="0 0 240 660"
        class="osteo-svg"
        :style="svgStyle"
        xmlns="http://www.w3.org/2000/svg"
      >
        <!-- ── Colonna vertebrale (sfondo) ──────────────────────────── -->
        <g :id="bid('vertebre_cervicali')"  v-bind="boneAttrs('vertebre_cervicali')">
          <rect x="116" y="92"  width="8"  height="28" rx="2"/>
        </g>
        <g :id="bid('vertebre_toraciche')" v-bind="boneAttrs('vertebre_toraciche')">
          <rect x="113" y="120" width="14" height="90" rx="2"/>
        </g>
        <g :id="bid('vertebre_lombari')"   v-bind="boneAttrs('vertebre_lombari')">
          <rect x="115" y="210" width="10" height="50" rx="2"/>
        </g>
        <g :id="bid('sacro')"  v-bind="boneAttrs('sacro')">
          <rect x="112" y="260" width="16" height="42" rx="3"/>
        </g>
        <g :id="bid('coccige')" v-bind="boneAttrs('coccige')">
          <rect x="115" y="302" width="10" height="14" rx="3"/>
        </g>

        <!-- ── Pelvi (ileo, ischio, pube) ─────────────────────────── -->
        <g :id="bid('ileo_dx')" v-bind="boneAttrs('ileo_dx')">
          <path d="M115,262 Q100,258 80,265 Q65,275 68,295 Q80,302 115,300 Z"/>
        </g>
        <g :id="bid('ileo_sx')" v-bind="boneAttrs('ileo_sx')">
          <path d="M125,262 Q140,258 160,265 Q175,275 172,295 Q160,302 125,300 Z"/>
        </g>
        <g :id="bid('ischio_dx')" v-bind="boneAttrs('ischio_dx')">
          <path d="M68,295 Q65,308 72,320 Q85,328 115,320 L115,300 Q80,302 68,295 Z"/>
        </g>
        <g :id="bid('ischio_sx')" v-bind="boneAttrs('ischio_sx')">
          <path d="M172,295 Q175,308 168,320 Q155,328 125,320 L125,300 Q160,302 172,295 Z"/>
        </g>
        <g :id="bid('pube_dx')" v-bind="boneAttrs('pube_dx')">
          <rect x="104" y="298" width="16" height="22" rx="3"/>
        </g>
        <g :id="bid('pube_sx')" v-bind="boneAttrs('pube_sx')">
          <rect x="120" y="298" width="16" height="22" rx="3"/>
        </g>

        <!-- ── Cranio ───────────────────────────────────────────────── -->
        <g :id="bid('cranio')" v-bind="boneAttrs('cranio')">
          <!-- Vault (everything above orbit line) -->
          <path d="M80,76 Q80,10 120,10 Q160,10 160,76 Z"/>
        </g>
        <g :id="bid('faccia')" v-bind="boneAttrs('faccia')">
          <polygon points="108,74 132,74 129,94 111,94"/>
        </g>
        <g :id="bid('mandibola')" v-bind="boneAttrs('mandibola')">
          <path d="M104,92 L104,104 Q120,114 136,104 L136,92 L132,92 Q132,108 120,112 Q108,108 108,92 Z"/>
        </g>

        <!-- ── Cingolo scapolare ────────────────────────────────────── -->
        <g :id="bid('scapola_dx')" v-bind="boneAttrs('scapola_dx')">
          <polygon points="50,120 66,116 70,178 50,182"/>
        </g>
        <g :id="bid('scapola_sx')" v-bind="boneAttrs('scapola_sx')">
          <polygon points="190,120 174,116 170,178 190,182"/>
        </g>
        <g :id="bid('clavicola_dx')" v-bind="boneAttrs('clavicola_dx')">
          <rect x="68" y="116" width="48" height="7" rx="3"/>
        </g>
        <g :id="bid('clavicola_sx')" v-bind="boneAttrs('clavicola_sx')">
          <rect x="124" y="116" width="48" height="7" rx="3"/>
        </g>

        <!-- ── Torace ───────────────────────────────────────────────── -->
        <g :id="bid('coste_dx')" v-bind="boneAttrs('coste_dx')">
          <path d="M115,124 C88,132 70,162 68,196 C68,218 82,234 115,234 Z"/>
        </g>
        <g :id="bid('coste_sx')" v-bind="boneAttrs('coste_sx')">
          <path d="M125,124 C152,132 170,162 172,196 C172,218 158,234 125,234 Z"/>
        </g>
        <!-- Sterno on top of thoracic vertebrae -->
        <g :id="bid('sterno')" v-bind="boneAttrs('sterno')">
          <rect x="115" y="122" width="10" height="84" rx="2"/>
        </g>

        <!-- ── Arto superiore destro ───────────────────────────────── -->
        <g :id="bid('omero_dx')" v-bind="boneAttrs('omero_dx')">
          <rect x="51" y="126" width="14" height="118" rx="6"/>
        </g>
        <g :id="bid('radio_dx')" v-bind="boneAttrs('radio_dx')">
          <rect x="44" y="248" width="10" height="94" rx="4"/>
        </g>
        <g :id="bid('ulna_dx')" v-bind="boneAttrs('ulna_dx')">
          <rect x="56" y="244" width="10" height="98" rx="4"/>
        </g>
        <g :id="bid('carpali_dx')" v-bind="boneAttrs('carpali_dx')">
          <rect x="38" y="344" width="32" height="18" rx="3"/>
        </g>
        <g :id="bid('metacarpali_dx')" v-bind="boneAttrs('metacarpali_dx')">
          <rect x="34" y="362" width="36" height="22" rx="3"/>
        </g>
        <g :id="bid('falangi_mano_dx')" v-bind="boneAttrs('falangi_mano_dx')">
          <rect x="30" y="384" width="40" height="38" rx="5"/>
        </g>

        <!-- ── Arto superiore sinistro ─────────────────────────────── -->
        <g :id="bid('omero_sx')" v-bind="boneAttrs('omero_sx')">
          <rect x="175" y="126" width="14" height="118" rx="6"/>
        </g>
        <g :id="bid('radio_sx')" v-bind="boneAttrs('radio_sx')">
          <rect x="186" y="248" width="10" height="94" rx="4"/>
        </g>
        <g :id="bid('ulna_sx')" v-bind="boneAttrs('ulna_sx')">
          <rect x="174" y="244" width="10" height="98" rx="4"/>
        </g>
        <g :id="bid('carpali_sx')" v-bind="boneAttrs('carpali_sx')">
          <rect x="170" y="344" width="32" height="18" rx="3"/>
        </g>
        <g :id="bid('metacarpali_sx')" v-bind="boneAttrs('metacarpali_sx')">
          <rect x="170" y="362" width="36" height="22" rx="3"/>
        </g>
        <g :id="bid('falangi_mano_sx')" v-bind="boneAttrs('falangi_mano_sx')">
          <rect x="170" y="384" width="40" height="38" rx="5"/>
        </g>

        <!-- ── Arto inferiore destro ──────────────────────────────── -->
        <g :id="bid('femore_dx')" v-bind="boneAttrs('femore_dx')">
          <rect x="82" y="330" width="20" height="140" rx="8"/>
        </g>
        <g :id="bid('patella_dx')" v-bind="boneAttrs('patella_dx')">
          <ellipse cx="92" cy="474" rx="10" ry="9"/>
        </g>
        <g :id="bid('fibula_dx')" v-bind="boneAttrs('fibula_dx')">
          <rect x="72" y="486" width="9"  height="110" rx="4"/>
        </g>
        <g :id="bid('tibia_dx')" v-bind="boneAttrs('tibia_dx')">
          <rect x="83" y="486" width="16" height="110" rx="5"/>
        </g>
        <g :id="bid('calcagno_dx')" v-bind="boneAttrs('calcagno_dx')">
          <rect x="66" y="608" width="28" height="20" rx="4"/>
        </g>
        <g :id="bid('astragalo_dx')" v-bind="boneAttrs('astragalo_dx')">
          <rect x="78" y="597" width="20" height="14" rx="3"/>
        </g>
        <g :id="bid('tarsali_dx')" v-bind="boneAttrs('tarsali_dx')">
          <rect x="66" y="600" width="36" height="16" rx="3"/>
        </g>
        <g :id="bid('metatarsali_dx')" v-bind="boneAttrs('metatarsali_dx')">
          <rect x="62" y="616" width="40" height="18" rx="4"/>
        </g>
        <g :id="bid('falangi_piede_dx')" v-bind="boneAttrs('falangi_piede_dx')">
          <rect x="58" y="634" width="42" height="18" rx="4"/>
        </g>

        <!-- ── Arto inferiore sinistro ─────────────────────────────── -->
        <g :id="bid('femore_sx')" v-bind="boneAttrs('femore_sx')">
          <rect x="138" y="330" width="20" height="140" rx="8"/>
        </g>
        <g :id="bid('patella_sx')" v-bind="boneAttrs('patella_sx')">
          <ellipse cx="148" cy="474" rx="10" ry="9"/>
        </g>
        <g :id="bid('fibula_sx')" v-bind="boneAttrs('fibula_sx')">
          <rect x="159" y="486" width="9"  height="110" rx="4"/>
        </g>
        <g :id="bid('tibia_sx')" v-bind="boneAttrs('tibia_sx')">
          <rect x="141" y="486" width="16" height="110" rx="5"/>
        </g>
        <g :id="bid('calcagno_sx')" v-bind="boneAttrs('calcagno_sx')">
          <rect x="146" y="608" width="28" height="20" rx="4"/>
        </g>
        <g :id="bid('astragalo_sx')" v-bind="boneAttrs('astragalo_sx')">
          <rect x="142" y="597" width="20" height="14" rx="3"/>
        </g>
        <g :id="bid('tarsali_sx')" v-bind="boneAttrs('tarsali_sx')">
          <rect x="138" y="600" width="36" height="16" rx="3"/>
        </g>
        <g :id="bid('metatarsali_sx')" v-bind="boneAttrs('metatarsali_sx')">
          <rect x="138" y="616" width="40" height="18" rx="4"/>
        </g>
        <g :id="bid('falangi_piede_sx')" v-bind="boneAttrs('falangi_piede_sx')">
          <rect x="140" y="634" width="42" height="18" rx="4"/>
        </g>
      </svg>
    </div>

    <!-- Tooltip -->
    <div v-if="tooltip.visible" class="osteo-tooltip" :style="tooltip.style">
      <strong>{{ tooltip.label }}</strong>
      <span v-if="tooltip.status">{{ tooltip.status }}</span>
    </div>

    <!-- Legend -->
    <div class="osteo-legend">
      <span class="osteo-legend-item legend-undocumented">Non doc.</span>
      <span class="osteo-legend-item legend-absent">Assente</span>
      <span class="osteo-legend-item legend-tracce">Tracce</span>
      <span class="osteo-legend-item legend-frammentario">Framment.</span>
      <span class="osteo-legend-item legend-lt50">&lt;50%</span>
      <span class="osteo-legend-item legend-gt50">&gt;50%</span>
      <span class="osteo-legend-item legend-completo">Completo</span>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed } from 'vue'
import { BONES, boneStateClass } from './bonesConfig.js'

const props = defineProps({
  bones:        { type: Object,  default: () => ({}) },
  editMode:     { type: Boolean, default: false },
  selectedBone: { type: String,  default: null },
})

const emit = defineEmits(['bone-click'])

// ── Pan / zoom ──────────────────────────────────────────────────────────────
const wrapEl      = ref(null)
const containerEl = ref(null)
const svgEl       = ref(null)
const pan   = reactive({ x: 0, y: 0 })
const scale = ref(1)
const dragging  = ref(false)
const dragStart = reactive({ x: 0, y: 0, px: 0, py: 0 })

// Touch pinch state
const pinch = reactive({ active: false, dist: 0, scale0: 1 })

const svgStyle = computed(() => ({
  transform:       `translate(${pan.x}px, ${pan.y}px) scale(${scale.value})`,
  transformOrigin: '120px 330px',
  cursor:          dragging.value ? 'grabbing' : (props.editMode ? 'default' : 'grab'),
}))

function adjustZoom(factor) {
  scale.value = Math.min(5, Math.max(0.4, scale.value * factor))
}
function resetView() {
  pan.x = 0; pan.y = 0; scale.value = 1
}
function onWheel(e) {
  adjustZoom(e.deltaY > 0 ? 0.9 : 1.1)
}
function onMouseDown(e) {
  if (e.target.closest('g[data-bone]')) return // let bone click handle it
  dragging.value = true
  dragStart.x  = e.clientX; dragStart.y  = e.clientY
  dragStart.px = pan.x;     dragStart.py = pan.y
}
function onMouseMove(e) {
  if (!dragging.value) return
  pan.x = dragStart.px + (e.clientX - dragStart.x)
  pan.y = dragStart.py + (e.clientY - dragStart.y)
}
function onMouseUp() { dragging.value = false }

function touchDist(t) {
  const dx = t[0].clientX - t[1].clientX
  const dy = t[0].clientY - t[1].clientY
  return Math.hypot(dx, dy)
}
function onTouchStart(e) {
  if (e.touches.length === 2) {
    pinch.active = true; pinch.dist = touchDist(e.touches); pinch.scale0 = scale.value
  } else if (e.touches.length === 1) {
    dragging.value = true
    dragStart.x  = e.touches[0].clientX; dragStart.y  = e.touches[0].clientY
    dragStart.px = pan.x;                dragStart.py = pan.y
  }
}
function onTouchMove(e) {
  if (e.touches.length === 2 && pinch.active) {
    const d = touchDist(e.touches)
    scale.value = Math.min(5, Math.max(0.4, pinch.scale0 * d / pinch.dist))
  } else if (e.touches.length === 1 && dragging.value) {
    pan.x = dragStart.px + (e.touches[0].clientX - dragStart.x)
    pan.y = dragStart.py + (e.touches[0].clientY - dragStart.y)
  }
}
function onTouchEnd() { dragging.value = false; pinch.active = false }

// ── Bone rendering ──────────────────────────────────────────────────────────
function bid(id) { return 'bone-' + id }

function boneAttrs(id) {
  const data   = props.bones[id] ?? {}
  const cls    = ['bone', boneStateClass(data)]
  if (props.selectedBone === id)  cls.push('bone-selected')
  return {
    'data-bone':    id,
    class:          cls,
    role:           props.editMode ? 'button' : undefined,
    tabindex:       props.editMode ? '0'      : undefined,
    onClick:        () => props.editMode && emit('bone-click', id),
    onKeydown:      (e) => { if (props.editMode && (e.key === 'Enter' || e.key === ' ')) emit('bone-click', id) },
    onMouseenter:   (e) => showTooltip(id, e),
    onMouseleave:   hideTooltip,
  }
}

// ── Tooltip ─────────────────────────────────────────────────────────────────
const tooltip = reactive({ visible: false, label: '', status: '', style: {} })

function conservationLabel(c) {
  const map = { completo: 'Completo', gt50: '>50%', lt50: '<50%', frammentario: 'Frammentario', tracce: 'Tracce' }
  return map[c] ?? ''
}

function showTooltip(id, e) {
  const data = props.bones[id] ?? {}
  const bone = BONES[id]
  tooltip.label  = bone?.label ?? id
  tooltip.status = data.present === true
    ? conservationLabel(data.conservation)
    : data.present === false ? 'Assente' : 'Non documentato'
  const rect = wrapEl.value?.getBoundingClientRect()
  if (rect) {
    tooltip.style = {
      left: (e.clientX - rect.left + 12) + 'px',
      top:  (e.clientY - rect.top  +  8) + 'px',
    }
  }
  tooltip.visible = true
}
function hideTooltip() { tooltip.visible = false }
</script>

<style scoped>
.osteo-svg-wrap {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 4px;
  user-select: none;
}
.osteo-svg-controls {
  display: flex;
  gap: 4px;
  padding: 2px 0;
}
.osteo-ctrl-btn {
  border: 1px solid var(--p-surface-300);
  background: var(--p-surface-0);
  border-radius: 4px;
  width: 26px;
  height: 26px;
  font-size: 1rem;
  line-height: 1;
  cursor: pointer;
  color: var(--p-text-color);
}
.osteo-ctrl-btn:hover { background: var(--p-surface-100); }

.osteo-orient {
  display: flex;
  justify-content: space-between;
  font-size: 0.7rem;
  color: var(--p-text-muted-color);
  padding: 0 6px;
}

.osteo-svg-container {
  position: relative;
  overflow: hidden;
  width: 240px;
  height: 440px;
  border: 1px solid var(--p-surface-200);
  border-radius: 6px;
  background: var(--p-surface-50);
}
.osteo-svg {
  width: 240px;
  height: 660px;
  display: block;
  transition: transform 0.05s linear;
  will-change: transform;
}

/* ── Bone states ──────────────────────────────────────────────────────────── */
:deep(.bone) {
  cursor: default;
  stroke-width: 1;
}
:deep(.bone-undocumented) { fill: var(--p-surface-100); stroke: var(--p-surface-300); }
:deep(.bone-absent)       { fill: var(--p-surface-0);   stroke: var(--p-surface-300); stroke-dasharray: 3 2; }
:deep(.bone-tracce)       { fill: #ef4444; stroke: #b91c1c; }
:deep(.bone-frammentario) { fill: #f97316; stroke: #c2410c; }
:deep(.bone-lt50)         { fill: #eab308; stroke: #a16207; }
:deep(.bone-gt50)         { fill: #84cc16; stroke: #4d7c0f; }
:deep(.bone-completo)     { fill: #22c55e; stroke: #15803d; }

:deep(.bone[role="button"]) { cursor: pointer; }
:deep(.bone[role="button"]:hover) { opacity: 0.75; }
:deep(.bone-selected) { stroke: var(--p-primary-color) !important; stroke-width: 2.5 !important; }

/* ── Tooltip ─────────────────────────────────────────────────────────────── */
.osteo-tooltip {
  position: absolute;
  background: var(--p-surface-800, #1e1e1e);
  color: var(--p-surface-0, #fff);
  border-radius: 4px;
  padding: 4px 8px;
  font-size: 0.75rem;
  pointer-events: none;
  z-index: 10;
  display: flex;
  flex-direction: column;
  gap: 2px;
  white-space: nowrap;
  box-shadow: 0 2px 8px rgba(0,0,0,.25);
}

/* ── Legend ──────────────────────────────────────────────────────────────── */
.osteo-legend {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  font-size: 0.67rem;
}
.osteo-legend-item {
  padding: 1px 6px;
  border-radius: 3px;
  border: 1px solid transparent;
}
.legend-undocumented { background: var(--p-surface-100); border-color: var(--p-surface-300); color: var(--p-text-muted-color); }
.legend-absent       { background: var(--p-surface-0);   border-color: var(--p-surface-300); border-style: dashed; color: var(--p-text-muted-color); }
.legend-tracce       { background: #ef4444; color: #fff; }
.legend-frammentario { background: #f97316; color: #fff; }
.legend-lt50         { background: #eab308; color: #fff; }
.legend-gt50         { background: #84cc16; color: #fff; }
.legend-completo     { background: #22c55e; color: #fff; }
</style>
