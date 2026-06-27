<template>
  <div class="osteo-table-wrap">
    <table class="osteo-table">
      <thead>
        <tr>
          <th class="col-bone">{{ t('osteo_bone_col') }}</th>
          <th class="col-presence">{{ t('osteo_presence') }}</th>
          <th class="col-conservation">{{ t('osteo_conservation') }}</th>
          <th class="col-certainty">{{ t('osteo_certainty') }}</th>
          <th class="col-laterality">{{ t('osteo_laterality') }}</th>
          <th class="col-notes">{{ t('osteo_notes') }}</th>
        </tr>
      </thead>
      <tbody>
        <template v-for="cat in BONE_CATEGORIES" :key="cat.id">
          <tr class="osteo-cat-row">
            <td colspan="6" class="osteo-cat-label">{{ t(cat.key) }}</td>
          </tr>
          <tr
            v-for="[boneId, def] in bonesInCategory(cat.id)"
            :key="boneId"
            class="osteo-bone-row"
            :class="rowClass(boneId)"
          >
            <!-- Bone name -->
            <td class="col-bone">
              {{ t(def.labelKey) }}
              <span v-if="def.side" class="osteo-side-tag">{{ t('osteo_side_' + def.side) }}</span>
            </td>

            <!-- Presence -->
            <td class="col-presence">
              <template v-if="editMode">
                <div class="osteo-radio-row">
                  <label v-for="opt in presenceOpts" :key="opt.value" class="osteo-radio-label">
                    <input
                      type="radio"
                      :name="'p-' + boneId"
                      :value="opt.value"
                      :checked="presenceValue(boneId) === opt.value"
                      @change="onPresenceChange(boneId, opt.value)"
                    />
                    {{ opt.label }}
                  </label>
                </div>
              </template>
              <template v-else>
                <span :class="presenceBadgeClass(boneId)">{{ presenceLabel(boneId) }}</span>
              </template>
            </td>

            <!-- Conservation -->
            <td class="col-conservation">
              <template v-if="editMode && boneData(boneId).present === true">
                <Select
                  :modelValue="boneData(boneId).conservation ?? null"
                  :options="conservationOpts"
                  optionValue="value"
                  optionLabel="label"
                  placeholder="—"
                  size="small"
                  class="osteo-cell-select"
                  @change="onFieldChange(boneId, 'conservation', $event.value)"
                />
              </template>
              <template v-else-if="!editMode && boneData(boneId).present === true">
                {{ conservationLabel(boneData(boneId).conservation) }}
              </template>
              <span v-else class="osteo-empty-cell">—</span>
            </td>

            <!-- Certainty -->
            <td class="col-certainty">
              <template v-if="editMode && boneData(boneId).present === true">
                <Select
                  :modelValue="boneData(boneId).certainty ?? null"
                  :options="certaintyOpts"
                  optionValue="value"
                  optionLabel="label"
                  placeholder="—"
                  size="small"
                  class="osteo-cell-select"
                  @change="onFieldChange(boneId, 'certainty', $event.value)"
                />
              </template>
              <template v-else-if="!editMode && boneData(boneId).present === true">
                {{ certaintyLabel(boneData(boneId).certainty) }}
              </template>
              <span v-else class="osteo-empty-cell">—</span>
            </td>

            <!-- Laterality certainty (bilateral only) -->
            <td class="col-laterality">
              <template v-if="def.bilateral && editMode && boneData(boneId).present === true">
                <Select
                  :modelValue="boneData(boneId).laterality_certainty ?? null"
                  :options="lateralityOpts"
                  optionValue="value"
                  optionLabel="label"
                  placeholder="—"
                  size="small"
                  class="osteo-cell-select"
                  @change="onFieldChange(boneId, 'laterality_certainty', $event.value)"
                />
              </template>
              <template v-else-if="def.bilateral && !editMode && boneData(boneId).present === true">
                {{ lateralityLabel(boneData(boneId).laterality_certainty) }}
              </template>
              <span v-else class="osteo-empty-cell">—</span>
            </td>

            <!-- Notes -->
            <td class="col-notes">
              <template v-if="editMode">
                <InputText
                  :modelValue="boneData(boneId).notes ?? ''"
                  size="small"
                  class="osteo-cell-input"
                  @blur="onFieldChange(boneId, 'notes', $event.target.value)"
                />
              </template>
              <template v-else>
                <span class="osteo-note-text">{{ boneData(boneId).notes ?? '' }}</span>
              </template>
            </td>
          </tr>
        </template>
      </tbody>
    </table>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from '@/i18n'
import Select    from 'primevue/select'
import InputText from 'primevue/inputtext'
import {
  BONES,
  BONE_CATEGORIES,
  CONSERVATION_OPTIONS,
  CERTAINTY_OPTIONS,
  LATERALITY_OPTIONS,
  boneStateClass,
} from './bonesConfig.js'

const props = defineProps({
  bones:    { type: Object,  default: () => ({}) },
  editMode: { type: Boolean, default: false },
})
const emit = defineEmits(['update'])

const { t } = useI18n()

// ── Translated option lists ──────────────────────────────────────────────────
const conservationOpts = computed(() =>
  CONSERVATION_OPTIONS.map(o => ({ value: o.value, label: t(o.labelKey) }))
)
const certaintyOpts = computed(() =>
  CERTAINTY_OPTIONS.map(o => ({ value: o.value, label: t(o.labelKey) }))
)
const lateralityOpts = computed(() =>
  LATERALITY_OPTIONS.map(o => ({ value: o.value, label: t(o.labelKey) }))
)
const presenceOpts = computed(() => [
  { value: 'undocumented', label: t('osteo_not_documented') },
  { value: 'absent',       label: t('osteo_absent') },
  { value: 'present',      label: t('osteo_present') },
])

// ── Helpers ──────────────────────────────────────────────────────────────────
function bonesInCategory(catId) {
  return Object.entries(BONES).filter(([, def]) => def.category === catId)
}

function boneData(boneId) {
  return props.bones[boneId] ?? {}
}

function presenceValue(boneId) {
  const d = boneData(boneId)
  if (d.present === true)  return 'present'
  if (d.present === false) return 'absent'
  return 'undocumented'
}

function presenceLabel(boneId) {
  const v = presenceValue(boneId)
  return presenceOpts.value.find(o => o.value === v)?.label ?? '—'
}

function presenceBadgeClass(boneId) {
  const v = presenceValue(boneId)
  return ['osteo-presence-badge', `badge-${v}`]
}

function conservationLabel(val) {
  return conservationOpts.value.find(o => o.value === val)?.label ?? '—'
}
function certaintyLabel(val) {
  return certaintyOpts.value.find(o => o.value === val)?.label ?? '—'
}
function lateralityLabel(val) {
  return lateralityOpts.value.find(o => o.value === val)?.label ?? '—'
}

function rowClass(boneId) {
  return boneStateClass(boneData(boneId))
}

// ── Mutations ────────────────────────────────────────────────────────────────
function onPresenceChange(boneId, val) {
  const current = { ...boneData(boneId) }
  if (val === 'undocumented') {
    emit('update', { boneId, data: {} })
  } else if (val === 'absent') {
    emit('update', { boneId, data: { present: false, notes: current.notes || undefined } })
  } else {
    emit('update', { boneId, data: { ...current, present: true } })
  }
}

function onFieldChange(boneId, field, value) {
  const current = { ...boneData(boneId) }
  if (value === null || value === '' || value === undefined) {
    delete current[field]
  } else {
    current[field] = value
  }
  emit('update', { boneId, data: current })
}
</script>

<style scoped>
.osteo-table-wrap {
  overflow-x: auto;
  max-height: 520px;
  overflow-y: auto;
  border: 1px solid var(--p-surface-200);
  border-radius: 6px;
}

.osteo-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.8rem;
}

.osteo-table thead th {
  position: sticky;
  top: 0;
  background: var(--p-surface-100);
  border-bottom: 2px solid var(--p-surface-300);
  padding: 6px 8px;
  text-align: left;
  font-weight: 600;
  white-space: nowrap;
  z-index: 1;
}

.osteo-cat-row td {
  background: var(--p-surface-50);
  padding: 4px 8px;
  font-weight: 700;
  font-size: 0.75rem;
  color: var(--p-text-muted-color);
  text-transform: uppercase;
  letter-spacing: 0.04em;
  border-top: 1px solid var(--p-surface-200);
}

.osteo-bone-row td {
  padding: 4px 8px;
  border-bottom: 1px solid var(--p-surface-100);
  vertical-align: middle;
}
.osteo-bone-row:hover td { background: var(--p-surface-50); }

/* State-based left border */
.osteo-bone-row.bone-undocumented td:first-child { border-left: 3px solid var(--p-surface-300); }
.osteo-bone-row.bone-absent        td:first-child { border-left: 3px solid #ef4444; }
.osteo-bone-row.bone-traces        td:first-child { border-left: 3px solid #f97316; }
.osteo-bone-row.bone-fragmentary   td:first-child { border-left: 3px solid #eab308; }
.osteo-bone-row.bone-lt50          td:first-child { border-left: 3px solid #84cc16; }
.osteo-bone-row.bone-gt50          td:first-child { border-left: 3px solid #22c55e; }
.osteo-bone-row.bone-complete      td:first-child { border-left: 3px solid #15803d; }

.col-bone         { min-width: 120px; }
.col-presence     { min-width: 200px; }
.col-conservation { min-width: 120px; }
.col-certainty    { min-width: 110px; }
.col-laterality   { min-width: 110px; }
.col-notes        { min-width: 160px; }

.osteo-side-tag {
  display: inline-block;
  margin-left: 4px;
  font-size: 0.7rem;
  color: var(--p-text-muted-color);
  background: var(--p-surface-100);
  border-radius: 3px;
  padding: 0 4px;
}

.osteo-radio-row {
  display: flex;
  gap: 8px;
  flex-wrap: nowrap;
}
.osteo-radio-label {
  display: flex;
  align-items: center;
  gap: 3px;
  cursor: pointer;
  white-space: nowrap;
}
.osteo-radio-label input { cursor: pointer; }

.osteo-presence-badge {
  display: inline-block;
  padding: 1px 6px;
  border-radius: 3px;
  font-size: 0.75rem;
}
.badge-undocumented { background: var(--p-surface-100); color: var(--p-text-muted-color); }
.badge-absent       { background: #fee2e2; color: #b91c1c; }
.badge-present      { background: #dcfce7; color: #15803d; }

.osteo-cell-select { width: 100%; }
.osteo-cell-input  { width: 100%; }
.osteo-empty-cell  { color: var(--p-text-muted-color); }
.osteo-note-text   { font-size: 0.78rem; color: var(--p-text-muted-color); }
</style>
