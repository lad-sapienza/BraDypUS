<template>
  <fieldset class="record-section osteo-section">
    <legend>{{ t('osteo_label') }}</legend>

    <!-- Read mode ──────────────────────────────────────────────────────── -->
    <template v-if="!editMode">
      <div v-if="individuals.length === 0" class="osteo-empty">—</div>
      <template v-else>
        <div v-if="individuals.length > 1" class="osteo-tabs">
          <button
            v-for="ind in individuals" :key="ind.id"
            class="osteo-tab"
            :class="{ active: activeId === ind.id }"
            @click="activeId = ind.id"
          >
            {{ ind.label || (t('osteo_individual') + ' ' + ind.id) }}
          </button>
        </div>
        <div v-for="ind in individuals" :key="ind.id" v-show="activeId === ind.id" class="osteo-individual-view">
          <p v-if="ind.label" class="osteo-ind-label">{{ ind.label }}</p>
          <OsteologySvg :bones="ind.bones" :edit-mode="false" />
          <p v-if="ind.notes" class="osteo-ind-notes">{{ ind.notes }}</p>
        </div>
      </template>
    </template>

    <!-- Edit mode ──────────────────────────────────────────────────────── -->
    <template v-else>
      <!-- Tabs -->
      <div class="osteo-tabs">
        <button
          v-for="ind in individuals" :key="ind.id"
          class="osteo-tab"
          :class="{ active: activeId === ind.id }"
          @click="activeId = ind.id"
        >
          {{ ind.label || (t('osteo_individual') + ' ' + ind.id) }}
          <span class="osteo-tab-remove" @click.stop="removeIndividual(ind.id)" title="Rimuovi">×</span>
        </button>
        <button class="osteo-tab osteo-tab-add" @click="addIndividual">
          + {{ t('osteo_add_individual') }}
        </button>
      </div>

      <!-- Active individual editor -->
      <div
        v-for="ind in individuals" :key="ind.id"
        v-show="activeId === ind.id"
        class="osteo-edit-layout"
      >
        <!-- Individual metadata -->
        <div class="osteo-ind-meta">
          <InputText
            v-model="ind.label"
            :placeholder="t('osteo_individual_label')"
            size="small"
            @blur="pushUpdate"
          />
          <InputText
            v-model="ind.notes"
            :placeholder="t('osteo_individual_notes')"
            size="small"
            @blur="pushUpdate"
          />
        </div>

        <!-- SVG + bone panel -->
        <div class="osteo-editor-row">
          <OsteologySvg
            :bones="ind.bones"
            :edit-mode="true"
            :selected-bone="selectedBone"
            @bone-click="onBoneClick(ind, $event)"
          />
          <BonePanel
            v-if="selectedBone && activeIndividual"
            :bone-id="selectedBone"
            :bone-data="activeIndividual.bones[selectedBone] ?? {}"
            @update="onBoneUpdate(activeIndividual, selectedBone, $event)"
            @close="selectedBone = null"
          />
        </div>
      </div>
    </template>
  </fieldset>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useI18n } from '@/i18n'
import InputText from 'primevue/inputtext'
import OsteologySvg from './OsteologySvg.vue'
import BonePanel    from './BonePanel.vue'
import { newIndividual } from './bonesConfig.js'

const props = defineProps({
  modelValue: { type: String, default: null }, // JSON string from osteo_data field
  editMode:   { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue'])
const { t } = useI18n()

// ── Parsed state ────────────────────────────────────────────────────────────
const individuals  = ref([])
const activeId     = ref(null)
const selectedBone = ref(null)

const activeIndividual = computed(() =>
  individuals.value.find(i => i.id === activeId.value) ?? null
)

function parseModelValue(json) {
  if (!json) return []
  try {
    const parsed = JSON.parse(json)
    return Array.isArray(parsed?.individuals) ? parsed.individuals : []
  } catch {
    return []
  }
}

function syncFromProps() {
  const inds = parseModelValue(props.modelValue)
  individuals.value = inds
  if (inds.length > 0 && !inds.find(i => i.id === activeId.value)) {
    activeId.value = inds[0].id
  }
}

syncFromProps()

watch(() => props.modelValue, syncFromProps)

// ── Mutations ────────────────────────────────────────────────────────────────
function pushUpdate() {
  emit('update:modelValue', JSON.stringify({ individuals: individuals.value }))
}

function addIndividual() {
  const nextId = individuals.value.length
    ? Math.max(...individuals.value.map(i => i.id)) + 1
    : 1
  const ind = newIndividual(nextId)
  individuals.value.push(ind)
  activeId.value = nextId
  selectedBone.value = null
  pushUpdate()
}

function removeIndividual(id) {
  if (individuals.value.length <= 1) {
    // Remove last individual → ask if user wants to clear
    individuals.value = []
    activeId.value    = null
  } else {
    individuals.value = individuals.value.filter(i => i.id !== id)
    if (activeId.value === id) activeId.value = individuals.value[0]?.id ?? null
  }
  selectedBone.value = null
  pushUpdate()
}

function onBoneClick(ind, boneId) {
  activeId.value     = ind.id
  selectedBone.value = boneId
}

function onBoneUpdate(ind, boneId, data) {
  // data is empty object → remove bone from record
  if (Object.keys(data).length === 0) {
    const bones = { ...ind.bones }
    delete bones[boneId]
    ind.bones = bones
  } else {
    ind.bones = { ...ind.bones, [boneId]: data }
  }
  pushUpdate()
}
</script>

<style scoped>
.osteo-section { margin-top: 1rem; }
.osteo-empty   { color: var(--p-text-muted-color); padding: 4px 0; }

/* Tabs */
.osteo-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin-bottom: 10px;
}
.osteo-tab {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 10px;
  border: 1px solid var(--p-surface-300);
  border-radius: 4px 4px 0 0;
  background: var(--p-surface-100);
  cursor: pointer;
  font-size: 0.82rem;
  color: var(--p-text-color);
  border-bottom-color: transparent;
}
.osteo-tab.active {
  background: var(--p-surface-0);
  border-color: var(--p-surface-300);
  border-bottom-color: var(--p-surface-0);
  font-weight: 600;
}
.osteo-tab:hover:not(.active) { background: var(--p-surface-200); }
.osteo-tab-add {
  background: none;
  border-color: transparent;
  color: var(--p-primary-color);
}
.osteo-tab-add:hover { background: var(--p-primary-50, #eff6ff); }
.osteo-tab-remove {
  font-size: 1rem;
  line-height: 1;
  opacity: 0.5;
  padding: 0 2px;
}
.osteo-tab-remove:hover { opacity: 1; }

/* Individual view */
.osteo-individual-view { display: flex; flex-direction: column; gap: 6px; }
.osteo-ind-label { font-weight: 600; font-size: 0.9rem; margin: 0; }
.osteo-ind-notes { font-size: 0.82rem; color: var(--p-text-muted-color); margin: 0; }

/* Individual editor */
.osteo-ind-meta {
  display: flex;
  gap: 8px;
  margin-bottom: 10px;
  flex-wrap: wrap;
}
.osteo-ind-meta .p-inputtext { flex: 1; min-width: 160px; }

.osteo-editor-row {
  display: flex;
  gap: 12px;
  align-items: flex-start;
  flex-wrap: wrap;
}
</style>
