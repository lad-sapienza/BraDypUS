<template>
  <fieldset class="record-section">
    <legend>{{ t('geodata') }}</legend>

    <div v-if="!entries.length && !editMode" class="geodata-empty">
      {{ t('no_geodata') }}
    </div>

    <div v-for="entry in entries" :key="entry.id" class="geodata-entry">
      <ATextarea
        v-model:value="entry.draftWkt"
        :readonly="!editMode"
        :autoSize="{ minRows: 2, maxRows: 6 }"
        class="geodata-wkt"
      />
      <div v-if="editMode" class="geodata-entry-actions">
        <AButton
          v-if="entry.draftWkt.trim() !== entry.savedWkt"
          type="text"
          size="small"
          :loading="savingId === entry.id"
          :title="t('save')"
          @click="saveEntry(entry)"
        >
          <template #icon><CheckOutlined /></template>
        </AButton>
        <button
          class="geodata-delete-btn"
          :title="t('delete')"
          :disabled="deletingId === entry.id"
          @click="deleteEntry(entry)"
        >
          <component :is="deletingId === entry.id ? LoadingOutlined : DeleteOutlined" :spin="deletingId === entry.id" />
        </button>
      </div>
    </div>

    <!-- ── Add geometry (edit mode) ──────────────────────────────── -->
    <div v-if="editMode" class="add-geodata-panel">
      <AButton type="text" size="small" @click="addPanelOpen = !addPanelOpen">
        <template #icon><PlusOutlined /></template>
        {{ t('add_geometry') }}
      </AButton>

      <div v-if="addPanelOpen" class="add-geodata-form">
        <ATextarea
          v-model:value="newWkt"
          :placeholder="t('wkt_placeholder')"
          :autoSize="{ minRows: 2, maxRows: 6 }"
        />
        <AButton
          type="primary"
          size="small"
          :loading="adding"
          :disabled="!newWkt.trim()"
          @click="addGeometry"
        >
          {{ t('save') }}
        </AButton>
      </div>
    </div>
  </fieldset>
</template>

<script setup>
import { CheckOutlined, DeleteOutlined, LoadingOutlined, PlusOutlined } from '@ant-design/icons-vue'
import { ref, computed, watch } from 'vue'
import { Button as AButton, Input } from 'ant-design-vue'
import { useToast } from '@/composables/useNotify'
import { api } from '@/api'
import { useI18n } from '@/i18n'

const ATextarea = Input.TextArea

const { t } = useI18n()
const toast = useToast()

const props = defineProps({
  /**
   * Geodata object as returned by record_ctrl::getRecord() — keyed by
   * bdus_geodata.id, each value { id, table_link, id_link, geometry, geojson }
   * (see Record\Read::getGeodata()). `geometry.val` is the raw WKT string.
   */
  geodata:  { type: Object, default: () => ({}) },
  editMode: { type: Boolean, default: false },
  /** Full table name (with prefix) of the current record */
  recordTb: { type: String, default: null },
  /** Numeric id of the current record */
  recordId: { type: [String, Number], default: null },
})

const emit = defineEmits([
  /** Fired after a successful add/edit/delete — parent re-fetches the record. */
  'geodata-changed',
])

// Local editable copy: draftWkt tracks the textarea, savedWkt is what's
// actually persisted — the per-entry Save button only appears once they differ.
const entries = ref([])

function buildEntries() {
  entries.value = Object.values(props.geodata).map(entry => {
    const wkt = entry.geometry?.val ?? ''
    return { id: entry.id.val, draftWkt: wkt, savedWkt: wkt }
  })
}
watch(() => props.geodata, buildEntries, { immediate: true })

const savingId   = ref(null)
const deletingId = ref(null)

async function saveEntry(entry) {
  savingId.value = entry.id
  try {
    const res = await api.put('/api/geoface/feature', {
      geodata: [{ id: entry.id, geometry: entry.draftWkt.trim() }],
    })
    if (res.status === 'error') {
      toast.add({ severity: 'error', summary: t('generic_error'), detail: t(res.code), life: 5000 })
      return
    }
    toast.add({ severity: 'success', summary: t('geodata'), detail: t('ok_update_geometry'), life: 3000 })
    emit('geodata-changed')
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 5000 })
  } finally {
    savingId.value = null
  }
}

async function deleteEntry(entry) {
  deletingId.value = entry.id
  try {
    const res = await api.delete('/api/geoface/feature', { ids: [entry.id] })
    if (res.status === 'error') {
      toast.add({ severity: 'error', summary: t('generic_error'), detail: t(res.code), life: 5000 })
      return
    }
    toast.add({ severity: 'success', summary: t('geodata'), detail: t('ok_delete_geodata'), life: 3000 })
    emit('geodata-changed')
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 5000 })
  } finally {
    deletingId.value = null
  }
}

// ── Add geometry ─────────────────────────────────────────────────────
const addPanelOpen = ref(false)
const newWkt        = ref('')
const adding         = ref(false)

async function addGeometry() {
  adding.value = true
  try {
    const res = await api.post('/api/geoface/feature', {
      tb: props.recordTb, id: props.recordId, geometry: newWkt.value.trim(),
    })
    if (res.status === 'error') {
      toast.add({ severity: 'error', summary: t('generic_error'), detail: t(res.code), life: 5000 })
      return
    }
    toast.add({ severity: 'success', summary: t('geodata'), detail: t('ok_insert_geodata'), life: 3000 })
    newWkt.value    = ''
    addPanelOpen.value = false
    emit('geodata-changed')
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 5000 })
  } finally {
    adding.value = false
  }
}
</script>

<style scoped>
.geodata-entry {
  display: flex;
  align-items: flex-start;
  gap: 0.35rem;
}
.geodata-entry + .geodata-entry {
  margin-top: 0.5rem;
}

.geodata-wkt {
  flex: 1;
  font-family: var(--p-font-family-monospace, monospace);
  font-size: 0.8rem;
}

.geodata-entry-actions {
  display: flex;
  align-items: center;
  gap: 0.1rem;
  flex-shrink: 0;
  padding-top: 0.2rem;
}

.geodata-delete-btn {
  background: none;
  border: none;
  padding: 0 0.3rem;
  cursor: pointer;
  color: var(--p-text-muted-color);
  font-size: 0.85rem;
  line-height: 1.8;
}
.geodata-delete-btn:hover { color: var(--p-red-500); }
.geodata-delete-btn:disabled { opacity: 0.5; cursor: default; }

.geodata-empty {
  color: var(--p-text-muted-color);
  font-style: italic;
  font-size: 0.875rem;
}

.add-geodata-panel {
  margin-top: 0.75rem;
}

.add-geodata-form {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  margin-top: 0.5rem;
}
</style>
