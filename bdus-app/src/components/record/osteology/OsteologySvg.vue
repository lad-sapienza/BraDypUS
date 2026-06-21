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
        <!-- ── Spine (background) ──────────────────────────────────────── -->
        <g :id="bid('cervical_vertebrae')"  v-bind="boneAttrs('cervical_vertebrae')">
          <rect x="116" y="92"  width="8"  height="28" rx="2"/>
        </g>
        <g :id="bid('thoracic_vertebrae')" v-bind="boneAttrs('thoracic_vertebrae')">
          <rect x="113" y="120" width="14" height="90" rx="2"/>
        </g>
        <g :id="bid('lumbar_vertebrae')"   v-bind="boneAttrs('lumbar_vertebrae')">
          <rect x="115" y="210" width="10" height="50" rx="2"/>
        </g>
        <g :id="bid('sacrum')"  v-bind="boneAttrs('sacrum')">
          <rect x="112" y="260" width="16" height="42" rx="3"/>
        </g>
        <g :id="bid('coccyx')" v-bind="boneAttrs('coccyx')">
          <rect x="115" y="302" width="10" height="14" rx="3"/>
        </g>

        <!-- ── Pelvis (ilium, ischium, pubis) ────────────────────────── -->
        <g :id="bid('ilium_right')" v-bind="boneAttrs('ilium_right')">
          <path d="M115,262 Q100,258 80,265 Q65,275 68,295 Q80,302 115,300 Z"/>
        </g>
        <g :id="bid('ilium_left')" v-bind="boneAttrs('ilium_left')">
          <path d="M125,262 Q140,258 160,265 Q175,275 172,295 Q160,302 125,300 Z"/>
        </g>
        <g :id="bid('ischium_right')" v-bind="boneAttrs('ischium_right')">
          <path d="M68,295 Q65,308 72,320 Q85,328 115,320 L115,300 Q80,302 68,295 Z"/>
        </g>
        <g :id="bid('ischium_left')" v-bind="boneAttrs('ischium_left')">
          <path d="M172,295 Q175,308 168,320 Q155,328 125,320 L125,300 Q160,302 172,295 Z"/>
        </g>
        <g :id="bid('pubis_right')" v-bind="boneAttrs('pubis_right')">
          <rect x="104" y="298" width="16" height="22" rx="3"/>
        </g>
        <g :id="bid('pubis_left')" v-bind="boneAttrs('pubis_left')">
          <rect x="120" y="298" width="16" height="22" rx="3"/>
        </g>

        <!-- ── Head ───────────────────────────────────────────────────── -->
        <g :id="bid('cranium')" v-bind="boneAttrs('cranium')">
          <!-- Vault (everything above orbit line) -->
          <path d="M80,76 Q80,10 120,10 Q160,10 160,76 Z"/>
        </g>
        <g :id="bid('face')" v-bind="boneAttrs('face')">
          <polygon points="108,74 132,74 129,94 111,94"/>
        </g>
        <g :id="bid('mandible')" v-bind="boneAttrs('mandible')">
          <path d="M104,92 L104,104 Q120,114 136,104 L136,92 L132,92 Q132,108 120,112 Q108,108 108,92 Z"/>
        </g>

        <!-- ── Shoulder girdle ────────────────────────────────────────── -->
        <g :id="bid('scapula_right')" v-bind="boneAttrs('scapula_right')">
          <polygon points="50,120 66,116 70,178 50,182"/>
        </g>
        <g :id="bid('scapula_left')" v-bind="boneAttrs('scapula_left')">
          <polygon points="190,120 174,116 170,178 190,182"/>
        </g>
        <g :id="bid('clavicle_right')" v-bind="boneAttrs('clavicle_right')">
          <rect x="68" y="116" width="48" height="7" rx="3"/>
        </g>
        <g :id="bid('clavicle_left')" v-bind="boneAttrs('clavicle_left')">
          <rect x="124" y="116" width="48" height="7" rx="3"/>
        </g>

        <!-- ── Thorax ──────────────────────────────────────────────────── -->
        <g :id="bid('ribs_right')" v-bind="boneAttrs('ribs_right')">
          <path d="M115,124 C88,132 70,162 68,196 C68,218 82,234 115,234 Z"/>
        </g>
        <g :id="bid('ribs_left')" v-bind="boneAttrs('ribs_left')">
          <path d="M125,124 C152,132 170,162 172,196 C172,218 158,234 125,234 Z"/>
        </g>
        <!-- Sternum on top of thoracic vertebrae -->
        <g :id="bid('sternum')" v-bind="boneAttrs('sternum')">
          <rect x="115" y="122" width="10" height="84" rx="2"/>
        </g>

        <!-- ── Upper limb right ───────────────────────────────────────── -->
        <g :id="bid('humerus_right')" v-bind="boneAttrs('humerus_right')">
          <rect x="51" y="126" width="14" height="118" rx="6"/>
        </g>
        <g :id="bid('radius_right')" v-bind="boneAttrs('radius_right')">
          <rect x="44" y="248" width="10" height="94" rx="4"/>
        </g>
        <g :id="bid('ulna_right')" v-bind="boneAttrs('ulna_right')">
          <rect x="56" y="244" width="10" height="98" rx="4"/>
        </g>
        <g :id="bid('carpals_right')" v-bind="boneAttrs('carpals_right')">
          <rect x="38" y="344" width="32" height="18" rx="3"/>
        </g>
        <g :id="bid('metacarpals_right')" v-bind="boneAttrs('metacarpals_right')">
          <rect x="34" y="362" width="36" height="22" rx="3"/>
        </g>
        <g :id="bid('hand_phalanges_right')" v-bind="boneAttrs('hand_phalanges_right')">
          <rect x="30" y="384" width="40" height="38" rx="5"/>
        </g>

        <!-- ── Upper limb left ────────────────────────────────────────── -->
        <g :id="bid('humerus_left')" v-bind="boneAttrs('humerus_left')">
          <rect x="175" y="126" width="14" height="118" rx="6"/>
        </g>
        <g :id="bid('radius_left')" v-bind="boneAttrs('radius_left')">
          <rect x="186" y="248" width="10" height="94" rx="4"/>
        </g>
        <g :id="bid('ulna_left')" v-bind="boneAttrs('ulna_left')">
          <rect x="174" y="244" width="10" height="98" rx="4"/>
        </g>
        <g :id="bid('carpals_left')" v-bind="boneAttrs('carpals_left')">
          <rect x="170" y="344" width="32" height="18" rx="3"/>
        </g>
        <g :id="bid('metacarpals_left')" v-bind="boneAttrs('metacarpals_left')">
          <rect x="170" y="362" width="36" height="22" rx="3"/>
        </g>
        <g :id="bid('hand_phalanges_left')" v-bind="boneAttrs('hand_phalanges_left')">
          <rect x="170" y="384" width="40" height="38" rx="5"/>
        </g>

        <!-- ── Lower limb right ──────────────────────────────────────── -->
        <g :id="bid('femur_right')" v-bind="boneAttrs('femur_right')">
          <rect x="82" y="330" width="20" height="140" rx="8"/>
        </g>
        <g :id="bid('patella_right')" v-bind="boneAttrs('patella_right')">
          <ellipse cx="92" cy="474" rx="10" ry="9"/>
        </g>
        <g :id="bid('fibula_right')" v-bind="boneAttrs('fibula_right')">
          <rect x="72" y="486" width="9"  height="110" rx="4"/>
        </g>
        <g :id="bid('tibia_right')" v-bind="boneAttrs('tibia_right')">
          <rect x="83" y="486" width="16" height="110" rx="5"/>
        </g>
        <g :id="bid('calcaneus_right')" v-bind="boneAttrs('calcaneus_right')">
          <rect x="66" y="608" width="28" height="20" rx="4"/>
        </g>
        <g :id="bid('talus_right')" v-bind="boneAttrs('talus_right')">
          <rect x="78" y="597" width="20" height="14" rx="3"/>
        </g>
        <g :id="bid('tarsals_right')" v-bind="boneAttrs('tarsals_right')">
          <rect x="66" y="600" width="36" height="16" rx="3"/>
        </g>
        <g :id="bid('metatarsals_right')" v-bind="boneAttrs('metatarsals_right')">
          <rect x="62" y="616" width="40" height="18" rx="4"/>
        </g>
        <g :id="bid('foot_phalanges_right')" v-bind="boneAttrs('foot_phalanges_right')">
          <rect x="58" y="634" width="42" height="18" rx="4"/>
        </g>

        <!-- ── Lower limb left ────────────────────────────────────────── -->
        <g :id="bid('femur_left')" v-bind="boneAttrs('femur_left')">
          <rect x="138" y="330" width="20" height="140" rx="8"/>
        </g>
        <g :id="bid('patella_left')" v-bind="boneAttrs('patella_left')">
          <ellipse cx="148" cy="474" rx="10" ry="9"/>
        </g>
        <g :id="bid('fibula_left')" v-bind="boneAttrs('fibula_left')">
          <rect x="159" y="486" width="9"  height="110" rx="4"/>
        </g>
        <g :id="bid('tibia_left')" v-bind="boneAttrs('tibia_left')">
          <rect x="141" y="486" width="16" height="110" rx="5"/>
        </g>
        <g :id="bid('calcaneus_left')" v-bind="boneAttrs('calcaneus_left')">
          <rect x="146" y="608" width="28" height="20" rx="4"/>
        </g>
        <g :id="bid('talus_left')" v-bind="boneAttrs('talus_left')">
          <rect x="142" y="597" width="20" height="14" rx="3"/>
        </g>
        <g :id="bid('tarsals_left')" v-bind="boneAttrs('tarsals_left')">
          <rect x="138" y="600" width="36" height="16" rx="3"/>
        </g>
        <g :id="bid('metatarsals_left')" v-bind="boneAttrs('metatarsals_left')">
          <rect x="138" y="616" width="40" height="18" rx="4"/>
        </g>
        <g :id="bid('foot_phalanges_left')" v-bind="boneAttrs('foot_phalanges_left')">
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
      <span class="osteo-legend-item legend-traces">Tracce</span>
      <span class="osteo-legend-item legend-fragmentary">Framment.</span>
      <span class="osteo-legend-item legend-lt50">&lt;50%</span>
      <span class="osteo-legend-item legend-gt50">&gt;50%</span>
      <span class="osteo-legend-item legend-complete">Completo</span>
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
  const map = { complete: 'Completo', gt50: '>50%', lt50: '<50%', fragmentary: 'Frammentario', traces: 'Tracce' }
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
:deep(.bone-traces)       { fill: #ef4444; stroke: #b91c1c; }
:deep(.bone-fragmentary)  { fill: #f97316; stroke: #c2410c; }
:deep(.bone-lt50)         { fill: #eab308; stroke: #a16207; }
:deep(.bone-gt50)         { fill: #84cc16; stroke: #4d7c0f; }
:deep(.bone-complete)     { fill: #22c55e; stroke: #15803d; }

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
.legend-traces       { background: #ef4444; color: #fff; }
.legend-fragmentary  { background: #f97316; color: #fff; }
.legend-lt50         { background: #eab308; color: #fff; }
.legend-gt50         { background: #84cc16; color: #fff; }
.legend-complete     { background: #22c55e; color: #fff; }
</style>
