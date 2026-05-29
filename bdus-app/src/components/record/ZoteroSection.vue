<template>
  <fieldset class="record-section">
    <legend>
      <i class="pi pi-book" style="margin-right: 0.35rem;" />
      {{ t('bibliography') }}
      <span v-if="syncing" class="sync-indicator">
        <i class="pi pi-spin pi-spinner" />
      </span>
    </legend>

    <!-- ── Existing links ──────────────────────────────────────── -->
    <div v-if="localLinks.length" ref="sortableEl" class="zotero-list">
      <div
        v-for="link in localLinks"
        :key="link.id"
        :data-id="link.id"
        class="zotero-item"
        :class="{ 'is-detached': link.detached }"
      >
        <!-- drag handle (edit mode only) -->
        <span v-if="editMode" class="drag-handle" :title="t('drag_to_sort')">
          <i class="pi pi-bars" />
        </span>

        <!-- citation body -->
        <div class="zotero-body">
          <!-- detached warning -->
          <div v-if="link.detached" class="detached-badge">
            <i class="pi pi-exclamation-triangle" />
            {{ t('zotero_item_detached') }}
          </div>

          <!-- full formatted citation (HTML from Zotero CSL) -->
          <div
            v-if="link.full_citation"
            class="full-citation"
            v-html="link.full_citation"
          />
          <!-- fallback: author/year if no full citation cached yet -->
          <div v-else-if="link.author_year" class="author-year">
            {{ link.author_year }}
          </div>
          <div v-else class="author-year muted">{{ link.zotero_key }}</div>

          <!-- pages + notes (inline in read mode, editable in edit mode) -->
          <div class="link-meta">
            <template v-if="!editMode">
              <span v-if="link.pages" class="meta-chip">
                <i class="pi pi-file-edit" /> {{ link.pages }}
              </span>
              <span v-if="link.notes" class="meta-chip">
                <i class="pi pi-comment" /> {{ link.notes }}
              </span>
            </template>
            <template v-else>
              <InputText
                v-model="link._pages"
                :placeholder="t('pages')"
                size="small"
                class="meta-input"
                @blur="saveMetaField(link, 'pages')"
              />
              <InputText
                v-model="link._notes"
                :placeholder="t('notes')"
                size="small"
                class="meta-input meta-input--notes"
                @blur="saveMetaField(link, 'notes')"
              />
            </template>
          </div>

          <!-- link to Zotero online (when available) -->
          <a
            v-if="link.zotero_url"
            :href="link.zotero_url"
            target="_blank"
            rel="noopener noreferrer"
            class="zotero-ext-link"
            :title="t('open_in_zotero')"
          >
            <i class="pi pi-external-link" /> Zotero
          </a>
        </div>

        <!-- delete button (edit mode) -->
        <button
          v-if="editMode"
          class="zotero-delete-btn"
          :title="t('delete')"
          :disabled="deletingId === link.id"
          @click="deleteLink(link)"
        >
          <i :class="deletingId === link.id ? 'pi pi-spin pi-spinner' : 'pi pi-times'" />
        </button>
      </div>
    </div>

    <!-- empty state -->
    <div v-if="!localLinks.length && !editMode" class="zotero-empty">
      {{ t('no_bibliography') }}
    </div>

    <!-- ── Add panel (edit mode) ───────────────────────────────── -->
    <div v-if="editMode" class="add-panel">
      <Button
        :label="t('add_reference')"
        icon="pi pi-plus"
        size="small"
        severity="secondary"
        text
        @click="addPanelOpen = !addPanelOpen"
      />

      <div v-if="addPanelOpen" class="add-form">
        <!-- Library selector -->
        <Select
          v-model="selectedLibId"
          :options="libs"
          option-label="name"
          option-value="id"
          :placeholder="t('select_library')"
          :loading="loadingLibs"
          class="lib-select"
          @change="clearSearch"
        />

        <!-- Search input (shown once a library is selected) -->
        <div v-if="selectedLibId" class="search-row">
          <IconField>
            <InputIcon class="pi pi-search" />
            <InputText
              v-model="searchQuery"
              :placeholder="t('search_in_zotero')"
              class="search-input"
              @input="onSearchInput"
            />
          </IconField>
          <ProgressSpinner
            v-if="searching"
            style="width: 1.2rem; height: 1.2rem;"
          />
        </div>

        <!-- Search results -->
        <div v-if="searchResults.length" class="search-results">
          <div
            v-for="r in searchResults"
            :key="r.key"
            class="search-result-item"
            @click="selectResult(r)"
          >
            <div class="result-citation" v-html="r.full_citation || r.author_year" />
          </div>
        </div>
        <div v-else-if="searchQuery.length >= 2 && !searching" class="search-empty">
          {{ t('no_results') }}
        </div>
      </div>
    </div>
  </fieldset>
</template>

<script setup>
import { ref, watch, onMounted, nextTick } from 'vue'
import Button        from 'primevue/button'
import Select        from 'primevue/select'
import InputText     from 'primevue/inputtext'
import InputIcon     from 'primevue/inputicon'
import IconField     from 'primevue/iconfield'
import ProgressSpinner from 'primevue/progressspinner'
import { useToast }  from 'primevue/usetoast'
import Sortable      from 'sortablejs'
import { api }       from '@/api'
import { useI18n }   from '@/i18n'

const { t }  = useI18n()
const toast  = useToast()

const props = defineProps({
  /** bibliography object from record model, keyed by link id */
  bibliography: { type: Object,           default: () => ({}) },
  editMode:     { type: Boolean,          default: false },
  recordTb:     { type: String,           default: null },
  recordId:     { type: [String, Number], default: null },
})

const emit = defineEmits(['bib-added', 'bib-deleted'])

// ── Local reactive copy of links ──────────────────────────────────
const localLinks = ref([])

watch(
  () => props.bibliography,
  (val) => {
    localLinks.value = Object.values(val ?? {})
      .sort((a, b) => (a.sort ?? 0) - (b.sort ?? 0))
      .map(l => ({ ...l, _pages: l.pages ?? '', _notes: l.notes ?? '' }))
  },
  { immediate: true, deep: true }
)

// ── Background sync on mount ──────────────────────────────────────
const syncing = ref(false)

onMounted(async () => {
  if (!props.recordTb || !props.recordId || !localLinks.value.length) return
  syncing.value = true
  try {
    const res = await api.post(`/api/zotero/sync/${props.recordTb}/${props.recordId}`)
    if ((res.updated ?? 0) > 0 || (res.detached ?? 0) > 0) {
      // Reload bibliography section from API
      const fresh = await api.get(`/api/zotero/links/${props.recordTb}/${props.recordId}`)
      localLinks.value = (fresh.links ?? []).map(l => ({
        ...l, _pages: l.pages ?? '', _notes: l.notes ?? '',
      }))
    }
  } catch { /* non-fatal */ }
  finally { syncing.value = false }
})

// ── Drag-to-sort ──────────────────────────────────────────────────
const sortableEl  = ref(null)
const sortSaving  = ref(false)
let sortableInst  = null

watch(
  () => props.editMode,
  async (editing) => {
    await nextTick()
    if (editing && sortableEl.value) {
      sortableInst = Sortable.create(sortableEl.value, {
        handle: '.drag-handle',
        animation: 150,
        onEnd: onSortEnd,
      })
    } else {
      sortableInst?.destroy()
      sortableInst = null
    }
  }
)

async function onSortEnd() {
  if (!sortableEl.value) return
  const orderedIds = [...sortableEl.value.querySelectorAll('.zotero-item')]
    .map(el => parseInt(el.dataset.id, 10))
    .filter(Boolean)

  // Update local order
  const map = Object.fromEntries(localLinks.value.map(l => [l.id, l]))
  localLinks.value = orderedIds.map((id, i) => ({ ...map[id], sort: i }))

  sortSaving.value = true
  try {
    await Promise.all(
      orderedIds.map((id, i) => api.patch(`/api/zotero/link/${id}`, { sort: i }))
    )
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 4000 })
  } finally {
    sortSaving.value = false
  }
}

// ── Delete ────────────────────────────────────────────────────────
const deletingId = ref(null)

async function deleteLink(link) {
  deletingId.value = link.id
  try {
    const res = await api.delete(`/api/zotero/link/${link.id}`)
    if (res.status !== 'success') {
      toast.add({ severity: 'error', summary: t('generic_error'), detail: t(res.code), life: 5000 })
      return
    }
    localLinks.value = localLinks.value.filter(l => l.id !== link.id)
    emit('bib-deleted', link.id)
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 5000 })
  } finally {
    deletingId.value = null
  }
}

// ── Inline edit: pages / notes ────────────────────────────────────
async function saveMetaField(link, field) {
  const newVal = field === 'pages' ? link._pages : link._notes
  if (newVal === (link[field] ?? '')) return  // unchanged
  try {
    await api.patch(`/api/zotero/link/${link.id}`, { [field]: newVal })
    link[field] = newVal
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 4000 })
  }
}

// ── Add panel ─────────────────────────────────────────────────────
const addPanelOpen  = ref(false)
const libs          = ref([])
const loadingLibs   = ref(false)
const selectedLibId = ref(null)
const searchQuery   = ref('')
const searchResults = ref([])
const searching     = ref(false)
let   searchTimer   = null

async function loadLibs() {
  if (libs.value.length) return
  loadingLibs.value = true
  try {
    const res = await api.get('/api/zotero/libs')
    libs.value = res.libs ?? []
  } catch { /* non-fatal */ }
  finally { loadingLibs.value = false }
}

watch(addPanelOpen, (open) => { if (open) loadLibs() })

function clearSearch() {
  searchQuery.value  = ''
  searchResults.value = []
}

function onSearchInput() {
  clearTimeout(searchTimer)
  if (searchQuery.value.length < 2) {
    searchResults.value = []
    return
  }
  searchTimer = setTimeout(doSearch, 350)
}

async function doSearch() {
  if (!selectedLibId.value || searchQuery.value.length < 2) return
  searching.value = true
  try {
    const res = await api.get('/api/zotero/search', {
      lib_id: selectedLibId.value,
      q:      searchQuery.value,
      limit:  20,
    })
    searchResults.value = res.results ?? []
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 4000 })
    searchResults.value = []
  } finally {
    searching.value = false
  }
}

async function selectResult(result) {
  try {
    const res = await api.post('/api/zotero/link', {
      tb:         props.recordTb,
      record_id:  props.recordId,
      lib_id:     selectedLibId.value,
      zotero_key: result.key,
      sort:       localLinks.value.length,
    })
    if (res.status !== 'success') {
      toast.add({ severity: 'error', summary: t('generic_error'), detail: t(res.code), life: 5000 })
      return
    }

    // Append new link to local list
    const newLink = {
      id:           res.id,
      lib_id:       selectedLibId.value,
      lib_name:     libs.value.find(l => l.id === selectedLibId.value)?.name ?? '',
      zotero_key:   result.key,
      author_year:  result.author_year,
      full_citation: result.full_citation,
      zotero_url:   result.zotero_url ?? null,
      pages:        null, notes: null,
      sort:         localLinks.value.length,
      detached:     false,
      _pages: '', _notes: '',
    }
    localLinks.value.push(newLink)
    emit('bib-added', newLink)

    toast.add({ severity: 'success', summary: t('bibliography'), detail: t('ok_bib_added'), life: 3000 })

    // Reset search but keep lib selected for chained additions
    searchQuery.value   = ''
    searchResults.value = []
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 5000 })
  }
}
</script>

<style scoped>
/* ── List ── */
.zotero-list {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}

.zotero-item {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  padding: 0.5rem 0.4rem;
  border-radius: 6px;
  background: var(--p-surface-50, #fafafa);
  border: 1px solid var(--p-surface-200, #e5e7eb);
}

.zotero-item.is-detached {
  border-color: var(--p-orange-300, #fdba74);
  background: var(--p-orange-50, #fff7ed);
}

.drag-handle {
  cursor: grab;
  color: var(--p-text-muted-color);
  padding-top: 0.15rem;
  flex-shrink: 0;
}

/* ── Citation body ── */
.zotero-body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.detached-badge {
  font-size: 0.75rem;
  color: var(--p-orange-600, #ea580c);
  display: flex;
  align-items: center;
  gap: 0.3rem;
}

.full-citation :deep(div) {
  font-size: 0.875rem;
  line-height: 1.5;
}

.author-year {
  font-size: 0.875rem;
  font-weight: 500;
}

.author-year.muted {
  color: var(--p-text-muted-color);
  font-weight: normal;
  font-style: italic;
}

/* ── Meta chips (read mode) ── */
.link-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  margin-top: 0.15rem;
}

.meta-chip {
  font-size: 0.78rem;
  color: var(--p-text-muted-color);
  display: inline-flex;
  align-items: center;
  gap: 0.2rem;
}

/* ── Meta inputs (edit mode) ── */
.meta-input {
  width: 9rem;
}
.meta-input--notes {
  width: 14rem;
}

/* ── External Zotero link ── */
.zotero-ext-link {
  font-size: 0.78rem;
  color: var(--p-primary-color);
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 0.2rem;
  margin-top: 0.1rem;
  width: fit-content;
}
.zotero-ext-link:hover { text-decoration: underline; }

/* ── Delete button ── */
.zotero-delete-btn {
  background: none;
  border: none;
  padding: 0.15rem;
  cursor: pointer;
  color: var(--p-text-muted-color);
  font-size: 0.8rem;
  flex-shrink: 0;
  margin-top: 0.1rem;
}
.zotero-delete-btn:hover { color: var(--p-red-500); }
.zotero-delete-btn:disabled { opacity: 0.4; cursor: default; }

/* ── Sync indicator ── */
.sync-indicator {
  font-size: 0.75rem;
  color: var(--p-text-muted-color);
  margin-left: 0.4rem;
  vertical-align: middle;
}

/* ── Empty ── */
.zotero-empty {
  color: var(--p-text-muted-color);
  font-style: italic;
  font-size: 0.875rem;
}

/* ── Add panel ── */
.add-panel { margin-top: 0.75rem; }

.add-form {
  margin-top: 0.5rem;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.lib-select { max-width: 260px; }

.search-row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.search-input { width: 100%; max-width: 380px; }

/* ── Search results ── */
.search-results {
  border: 1px solid var(--p-surface-300, #d1d5db);
  border-radius: 6px;
  max-height: 320px;
  overflow-y: auto;
  background: var(--p-surface-0, #fff);
}

.search-result-item {
  padding: 0.5rem 0.75rem;
  cursor: pointer;
  border-bottom: 1px solid var(--p-surface-100, #f3f4f6);
  font-size: 0.875rem;
}
.search-result-item:last-child { border-bottom: none; }
.search-result-item:hover { background: var(--p-surface-50, #f9fafb); }

.result-citation :deep(div) { margin: 0; }

.search-empty {
  font-size: 0.875rem;
  color: var(--p-text-muted-color);
  font-style: italic;
}
</style>
