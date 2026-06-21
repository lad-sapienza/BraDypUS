<template>
  <div class="bone-panel">
    <div class="bone-panel-header">
      <span class="bone-panel-name">{{ boneDef?.label ?? boneId }}</span>
      <button class="bone-panel-close" @click="$emit('close')" title="Chiudi">×</button>
    </div>

    <div class="bone-panel-body">
      <!-- Presenza -->
      <div class="bone-field">
        <label class="bone-field-label">{{ t('osteo_presence') }}</label>
        <div class="bone-radio-group">
          <label class="bone-radio-label">
            <input type="radio" :name="'p-' + boneId" value="undocumented"
              :checked="presenceValue === 'undocumented'" @change="onPresenceChange('undocumented')" />
            {{ t('osteo_not_documented') }}
          </label>
          <label class="bone-radio-label">
            <input type="radio" :name="'p-' + boneId" value="absent"
              :checked="presenceValue === 'absent'" @change="onPresenceChange('absent')" />
            {{ t('osteo_absent') }}
          </label>
          <label class="bone-radio-label">
            <input type="radio" :name="'p-' + boneId" value="present"
              :checked="presenceValue === 'present'" @change="onPresenceChange('present')" />
            {{ t('osteo_present') }}
          </label>
        </div>
      </div>

      <!-- Conservazione (solo se presente) -->
      <template v-if="localData.present === true">
        <div class="bone-field">
          <label class="bone-field-label">{{ t('osteo_conservation') }}</label>
          <Select
            v-model="localData.conservation"
            :options="CONSERVATION_OPTIONS"
            optionValue="value"
            optionLabel="label"
            placeholder="—"
            size="small"
            class="bone-select"
            @change="pushUpdate"
          />
        </div>

        <div class="bone-field">
          <label class="bone-field-label">{{ t('osteo_certainty') }}</label>
          <Select
            v-model="localData.certainty"
            :options="CERTAINTY_OPTIONS"
            optionValue="value"
            optionLabel="label"
            placeholder="—"
            size="small"
            class="bone-select"
            @change="pushUpdate"
          />
        </div>

        <!-- Certezza lateralità: solo ossa bilaterali -->
        <div v-if="boneDef?.bilateral" class="bone-field">
          <label class="bone-field-label">{{ t('osteo_laterality') }}</label>
          <Select
            v-model="localData.laterality_certainty"
            :options="LATERALITY_OPTIONS"
            optionValue="value"
            optionLabel="label"
            placeholder="—"
            size="small"
            class="bone-select"
            @change="pushUpdate"
          />
        </div>
      </template>

      <!-- Note -->
      <div class="bone-field">
        <label class="bone-field-label">{{ t('osteo_notes') }}</label>
        <Textarea
          v-model="localData.notes"
          rows="2"
          autoResize
          size="small"
          class="bone-textarea"
          @blur="pushUpdate"
        />
      </div>
    </div>
  </div>
</template>

<script setup>
import { reactive, computed, watch } from 'vue'
import { useI18n } from '@/i18n'
import Select   from 'primevue/select'
import Textarea from 'primevue/textarea'
import {
  BONES,
  CONSERVATION_OPTIONS,
  CERTAINTY_OPTIONS,
  LATERALITY_OPTIONS,
} from './bonesConfig.js'

const props = defineProps({
  boneId:   { type: String, required: true },
  boneData: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['update', 'close'])
const { t } = useI18n()

const boneDef = computed(() => BONES[props.boneId] ?? null)

const localData = reactive({
  present:              props.boneData.present,
  conservation:         props.boneData.conservation         ?? null,
  certainty:            props.boneData.certainty            ?? null,
  laterality_certainty: props.boneData.laterality_certainty ?? null,
  notes:                props.boneData.notes                ?? '',
})

watch(() => props.boneData, (v) => {
  localData.present              = v.present
  localData.conservation         = v.conservation         ?? null
  localData.certainty            = v.certainty            ?? null
  localData.laterality_certainty = v.laterality_certainty ?? null
  localData.notes                = v.notes                ?? ''
}, { deep: true })

const presenceValue = computed(() => {
  if (localData.present === true)  return 'present'
  if (localData.present === false) return 'absent'
  return 'undocumented'
})

function onPresenceChange(val) {
  if (val === 'undocumented') {
    localData.present              = undefined
    localData.conservation         = null
    localData.certainty            = null
    localData.laterality_certainty = null
  } else if (val === 'absent') {
    localData.present              = false
    localData.conservation         = null
    localData.certainty            = null
    localData.laterality_certainty = null
  } else {
    localData.present = true
  }
  pushUpdate()
}

function pushUpdate() {
  const data = {}
  if (localData.present === true) {
    data.present = true
    if (localData.conservation)         data.conservation         = localData.conservation
    if (localData.certainty)            data.certainty            = localData.certainty
    if (localData.laterality_certainty) data.laterality_certainty = localData.laterality_certainty
  } else if (localData.present === false) {
    data.present = false
  }
  if (localData.notes) data.notes = localData.notes
  emit('update', data)
}
</script>

<style scoped>
.bone-panel {
  width: 220px;
  border: 1px solid var(--p-surface-200);
  border-radius: 6px;
  background: var(--p-surface-0);
  font-size: 0.82rem;
  flex-shrink: 0;
}
.bone-panel-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 6px 10px;
  background: var(--p-surface-100);
  border-bottom: 1px solid var(--p-surface-200);
  border-radius: 6px 6px 0 0;
  font-weight: 600;
}
.bone-panel-close {
  background: none;
  border: none;
  cursor: pointer;
  font-size: 1.1rem;
  line-height: 1;
  color: var(--p-text-muted-color);
  padding: 0 2px;
}
.bone-panel-close:hover { color: var(--p-text-color); }
.bone-panel-body  { padding: 10px; display: flex; flex-direction: column; gap: 10px; }
.bone-field       { display: flex; flex-direction: column; gap: 4px; }
.bone-field-label { font-size: 0.75rem; color: var(--p-text-muted-color); font-weight: 500; }
.bone-radio-group { display: flex; flex-direction: column; gap: 4px; }
.bone-radio-label { display: flex; align-items: center; gap: 6px; cursor: pointer; }
.bone-radio-label input { cursor: pointer; }
.bone-select  { width: 100%; }
.bone-textarea { width: 100%; font-size: 0.8rem; }
</style>
