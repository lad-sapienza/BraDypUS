<template>
  <fieldset class="record-section">
    <legend>{{ t('linked_records') }}</legend>

    <!-- ── Existing links grouped by table ──────────────────────── -->
    <div
      v-for="(group, groupTb) in linksByTable"
      :key="groupTb"
      class="link-group"
    >
      <div class="link-group-label">{{ group.tb_label }}</div>
      <ul class="links-list">
        <li v-for="ml in group.items" :key="ml.key" class="link-item">
          <span v-if="ml.label" class="link-label-chip">{{ ml.label }}</span>
          <router-link :to="`/${route.params.app}/record/${ml.tb_id}/${ml.ref_id}`" class="link-ref">
            {{ ml.ref_label }}
          </router-link>
          <button
            v-if="editMode"
            class="link-delete-btn"
            :title="t('delete')"
            :disabled="deletingId === ml.key"
            @click="deleteLink(ml)"
          >
            <i :class="deletingId === ml.key ? 'pi pi-spin pi-spinner' : 'pi pi-times'" />
          </button>
        </li>
      </ul>
    </div>

    <!-- Empty state (view mode) -->
    <div v-if="!hasLinks && !editMode" class="links-empty">
      {{ t('no_user_links') }}
    </div>

    <!-- ── Add link panel (edit mode) ──────────────────────────── -->
    <div v-if="editMode" class="add-link-panel">
      <Button
        :label="t('add_link')"
        icon="pi pi-plus"
        size="small"
        severity="secondary"
        text
        @click="addPanelOpen = !addPanelOpen"
      />

      <div v-if="addPanelOpen" class="add-link-form">
        <!-- Table selector -->
        <Select
          v-model="selectedTable"
          :options="tableOptions"
          option-label="label"
          option-value="name"
          :placeholder="t('select_table')"
          class="add-link-select"
          @change="onTableChange"
        />

        <!-- Record search (shown once a table is selected) -->
        <AutoComplete
          v-if="selectedTable"
          v-model="selectedRecord"
          :suggestions="suggestions"
          option-label="label"
          :placeholder="t('type_to_search')"
          :min-length="0"
          force-selection
          class="add-link-autocomplete"
          @complete="onSearch"
          @item-select="onRecordSelected"
        />

        <!-- Optional relation label (shown once a record is selected) -->
        <InputText
          v-if="selectedRecord"
          v-model="linkLabel"
          :placeholder="t('link_label_placeholder')"
          size="small"
          class="add-link-label"
        />
      </div>
    </div>
  </fieldset>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useRoute }  from 'vue-router'
import Button      from 'primevue/button'
import Select      from 'primevue/select'
import AutoComplete from 'primevue/autocomplete'
import InputText   from 'primevue/inputtext'
import { useToast } from 'primevue/usetoast'
import { api }      from '@/api'
import { useI18n }  from '@/i18n'
import { useTables } from '@/composables/useTables'

const route     = useRoute()
const { t }     = useI18n()
const toast     = useToast()
const { tables } = useTables()

const props = defineProps({
  /**
   * Manual links object as returned by record_ctrl::getRecord().
   * Keyed by userlinks.id; each value:
   *   { key, tb_id, tb_label, ref_id, ref_label, sort }
   */
  links:    { type: Object,          default: () => ({}) },
  editMode: { type: Boolean,         default: false },
  /** Full table name (with prefix) of the current record */
  recordTb: { type: String,          default: null },
  /** Numeric id of the current record */
  recordId: { type: [String, Number], default: null },
})

const emit = defineEmits([
  /** Payload: the new link object (same shape as links entries) */
  'link-added',
  /** Payload: the deleted link key (userlinks.id) */
  'link-deleted',
])

// ── Grouped view ──────────────────────────────────────────────────
const hasLinks = computed(() => Object.keys(props.links).length > 0)

const linksByTable = computed(() => {
  const groups = {}
  for (const ml of Object.values(props.links)) {
    if (!groups[ml.tb_id]) {
      groups[ml.tb_id] = { tb_label: ml.tb_label, items: [] }
    }
    groups[ml.tb_id].items.push(ml)
  }
  return groups
})

// ── Delete ────────────────────────────────────────────────────────
const deletingId = ref(null)

async function deleteLink(ml) {
  deletingId.value = ml.key
  try {
    const res = await api.delete(`/api/manual-link/${ml.key}`)
    if (res.status === 'error') {
      toast.add({ severity: 'error', summary: t('generic_error'), detail: t(res.code), life: 5000 })
      return
    }
    toast.add({ severity: 'success', summary: t('linked_records'), detail: t('ok_userlink_erased'), life: 3000 })
    emit('link-deleted', ml.key)
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 5000 })
  } finally {
    deletingId.value = null
  }
}

// ── Add link ──────────────────────────────────────────────────────
const addPanelOpen  = ref(false)
const selectedTable = ref(null)
const selectedRecord = ref(null)
const suggestions   = ref([])
const linkLabel     = ref('')

/** All non-plugin user tables, excluding the current table to avoid self-links */
const tableOptions = computed(() =>
  (tables.value ?? []).filter(tb => tb.name !== props.recordTb)
)

function onTableChange() {
  selectedRecord.value = null
  suggestions.value    = []
  linkLabel.value      = ''
}

async function onSearch(event) {
  if (!selectedTable.value) return
  try {
    const res = await api.get(`/api/record/${selectedTable.value}/link-candidates`, {
      q: event.query ?? '',
    })
    // Exclude records already linked
    const linked = new Set(
      Object.values(props.links)
        .filter(l => l.tb_id === selectedTable.value)
        .map(l => l.ref_id)
    )
    suggestions.value = (res.data ?? [])
      .filter(r => !linked.has(r.id))
      .map(r => ({ id: r.id, label: String(r.label ?? r.id) }))
  } catch (e) {
    suggestions.value = []
  }
}

async function onRecordSelected(event) {
  const candidate = event.value
  if (!candidate || !selectedTable.value) return

  try {
    const res = await api.post('/api/manual-link', {
      tb_one: props.recordTb,
      id_one: props.recordId,
      tb_two: selectedTable.value,
      id_two: candidate.id,
      label:  linkLabel.value || null,
    })

    if (res.status === 'error') {
      toast.add({ severity: 'error', summary: t('generic_error'), detail: t(res.code), life: 5000 })
    } else {
      toast.add({ severity: 'success', summary: t('linked_records'), detail: t('all_links_saved'), life: 3000 })
      emit('link-added', res.link)
    }
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 5000 })
  } finally {
    // Reset search and label; keep table selected for chained additions
    selectedRecord.value = null
    suggestions.value    = []
    linkLabel.value      = ''
  }
}
</script>

<style scoped>
/* ── Link groups ── */
.link-group + .link-group {
  margin-top: 0.75rem;
}

.link-group-label {
  font-size: 0.78rem;
  font-weight: 600;
  color: var(--p-text-muted-color);
  text-transform: uppercase;
  letter-spacing: 0.04em;
  margin-bottom: 0.25rem;
}

.links-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
}

.link-item {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.875rem;
}

.link-ref {
  color: var(--p-primary-color);
  text-decoration: none;
  flex: 1;
}
.link-ref:hover { text-decoration: underline; }

.link-delete-btn {
  background: none;
  border: none;
  padding: 0 0.15rem;
  cursor: pointer;
  color: var(--p-text-muted-color);
  font-size: 0.8rem;
  line-height: 1;
  flex-shrink: 0;
}
.link-delete-btn:hover { color: var(--p-red-500); }
.link-delete-btn:disabled { opacity: 0.5; cursor: default; }

/* ── Empty ── */
.links-empty {
  color: var(--p-text-muted-color);
  font-style: italic;
  font-size: 0.875rem;
}

/* ── Add panel ── */
.add-link-panel {
  margin-top: 0.75rem;
}

.add-link-form {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-top: 0.5rem;
  align-items: center;
}

.add-link-select {
  min-width: 180px;
  max-width: 220px;
}

.add-link-autocomplete {
  flex: 1;
  min-width: 180px;
}

.add-link-label {
  min-width: 140px;
  max-width: 200px;
}

/* ── Link label chip ── */
.link-label-chip {
  display: inline-block;
  background: var(--p-highlight-background);
  color: var(--p-primary-700);
  border-radius: 4px;
  padding: 0.05rem 0.35rem;
  font-size: 0.72rem;
  font-weight: 600;
  letter-spacing: 0.02em;
  flex-shrink: 0;
}
</style>
