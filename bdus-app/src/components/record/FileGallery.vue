<template>
  <div class="file-gallery">

    <!-- ── Edit mode: unified sortable list ──────────────────────── -->
    <template v-if="editMode">
      <div ref="sortableEl" class="sortable-list">
        <div
          v-for="f in localFiles"
          :key="f.link_id ?? f.id"
          :data-link-id="f.link_id"
          class="sortable-item"
          :class="{ 'is-image': f.is_image }"
        >
          <!-- drag handle -->
          <span class="drag-handle" :title="t('drag_to_sort')">
            <i class="pi pi-bars" />
          </span>

          <!-- thumbnail for images, icon for docs -->
          <Image
            v-if="f.is_image"
            :src="fileUrl(f)"
            :alt="f.description || f.filename || String(f.id)"
            preview
            :width="60"
            :height="45"
            image-class="sort-thumb"
          />
          <i v-else :class="['doc-icon', fileIcon(f.ext)]" />

          <!-- label -->
          <span class="sort-label">{{ docLabel(f) }}</span>
          <span v-if="f.description" class="sort-desc">{{ f.description }}</span>

          <!-- actions -->
          <a :href="fileUrl(f)" :download="downloadName(f)" class="file-action" :title="t('download')">
            <i class="pi pi-download" />
          </a>
          <button
            class="file-action file-delete-btn"
            :title="t('delete_file')"
            @click="confirmDeleteFile(f)"
          >
            <i class="pi pi-trash" />
          </button>
        </div>
      </div>

      <!-- sort feedback -->
      <div v-if="sortSaving" class="sort-saving">
        <i class="pi pi-spin pi-spinner" /> {{ t('saving') }}…
      </div>

      <!-- empty state in edit mode -->
      <div v-if="!localFiles.length" class="files-empty">—</div>

      <!-- upload -->
      <div class="upload-bar">
        <input ref="fileInput" type="file" class="hidden-input" @change="onFileSelected" />
        <Button
          :label="t('upload_file')"
          icon="pi pi-upload"
          size="small"
          severity="secondary"
          text
          :loading="uploading"
          @click="fileInput?.click()"
        />
      </div>
    </template>

    <!-- ── View mode: split images / docs ────────────────────────── -->
    <template v-else>
      <!-- Image thumbnails -->
      <div v-if="images.length" class="images-grid">
        <div v-for="f in images" :key="f.id" class="img-thumb">
          <Image
            :src="fileUrl(f)"
            :alt="f.description || f.filename || String(f.id)"
            :title="f.description || ''"
            preview
            :width="120"
            :height="90"
            image-class="thumb-img"
          />
          <div v-if="f.description" class="img-caption">{{ f.description }}</div>
          <div class="img-actions">
            <a :href="fileUrl(f)" :download="downloadName(f)" class="file-action" :title="t('download')">
              <i class="pi pi-download" />
            </a>
          </div>
        </div>
      </div>

      <!-- Document list -->
      <ul v-if="docs.length" class="docs-list">
        <li v-for="f in docs" :key="f.id" class="doc-item">
          <i :class="['doc-icon', fileIcon(f.ext)]" />
          <a :href="fileUrl(f)" target="_blank" rel="noopener noreferrer" class="doc-name">
            {{ docLabel(f) }}
          </a>
          <span v-if="f.description" class="doc-desc">{{ f.description }}</span>
          <a :href="fileUrl(f)" :download="downloadName(f)" class="file-action" :title="t('download')">
            <i class="pi pi-download" />
          </a>
        </li>
      </ul>

      <!-- Empty state -->
      <div v-if="!images.length && !docs.length" class="files-empty">—</div>
    </template>

  </div>
</template>

<script setup>
import { ref, computed, watch, onUnmounted, nextTick } from 'vue'
import Sortable from 'sortablejs'
import Image   from 'primevue/image'
import Button  from 'primevue/button'
import { useToast }   from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import { api, assetUrl } from '@/api'
import { useI18n }       from '@/i18n'
import { useAuthStore }  from '@/stores/auth'

const { t }   = useI18n()
const toast   = useToast()
const confirm = useConfirm()
const auth    = useAuthStore()

/** Resolve a file object to its full URL. */
function fileUrl(f) {
  return assetUrl(`projects/${auth.user?.app}/files/${f.id}.${f.ext}`)
}

const props = defineProps({
  /**
   * Array of file objects as returned by record_ctrl::getRecord().
   * Each: { id, link_id, link_sort, ext, filename, description,
   *         keywords, printable, is_image }
   */
  files:    { type: Array,           default: () => [] },
  /** When true, shows sort handles, delete buttons and upload button */
  editMode: { type: Boolean,         default: false },
  /** Table of the record this gallery belongs to */
  recordTb: { type: String,          default: null },
  /** Id of the record this gallery belongs to */
  recordId: { type: [String, Number], default: null },
})

const emit = defineEmits([
  /** Emitted after a successful upload. Payload: the new file object. */
  'file-uploaded',
  /** Emitted after a successful delete. Payload: the deleted file id. */
  'file-deleted',
  /** Emitted after a successful sort. Payload: new ordered file array. */
  'files-reordered',
])

// ── Local copy for sort manipulation ───────────────────────────────
const localFiles = ref([...props.files])
watch(() => props.files, files => { localFiles.value = [...files] }, { deep: true })

// ── View-mode computed partitions ───────────────────────────────────
const images = computed(() => (props.files ?? []).filter(f => f.is_image))
const docs   = computed(() => (props.files ?? []).filter(f => !f.is_image))

// ── SortableJS integration ──────────────────────────────────────────
const sortableEl = ref(null)
let   sortable   = null
const sortSaving = ref(false)

function initSortable() {
  if (!sortableEl.value) return
  sortable = Sortable.create(sortableEl.value, {
    handle:    '.drag-handle',
    animation: 150,
    onEnd:     handleSortEnd,
  })
}

function destroySortable() {
  sortable?.destroy()
  sortable = null
}

// Create/destroy sortable when editMode toggles
watch(
  () => props.editMode,
  async (active) => {
    if (active) {
      await nextTick()
      initSortable()
    } else {
      destroySortable()
    }
  },
  { immediate: true }
)

onUnmounted(destroySortable)

async function handleSortEnd() {
  // Read the new order directly from the DOM
  const linkIds = [...sortableEl.value.children]
    .map(el => parseInt(el.dataset.linkId))
    .filter(n => !isNaN(n))

  // Optimistically update localFiles to match DOM order
  const byLinkId = Object.fromEntries(localFiles.value.map(f => [f.link_id, f]))
  localFiles.value = linkIds.map(id => byLinkId[id]).filter(Boolean)

  sortSaving.value = true
  try {
    const res = await api.post('file_ctrl', 'sortFiles', { order: linkIds })
    if (res.status === 'error') {
      toast.add({ severity: 'error', summary: t('generic_error'), detail: t(res.code), life: 5000 })
      // Revert to prop order on error
      localFiles.value = [...props.files]
      return
    }
    emit('files-reordered', localFiles.value)
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 5000 })
    localFiles.value = [...props.files]
  } finally {
    sortSaving.value = false
  }
}

// ── Upload ─────────────────────────────────────────────────────────
const fileInput = ref(null)
const uploading = ref(false)

async function onFileSelected(evt) {
  const file = evt.target.files?.[0]
  if (!file) return
  evt.target.value = ''

  uploading.value = true
  try {
    const res = await api.upload(
      'record_ctrl', 'uploadFile', file, 'file',
      { tb: props.recordTb, id: props.recordId }
    )
    if (res.status === 'error') {
      toast.add({ severity: 'error', summary: t('generic_error'), detail: t(res.code), life: 5000 })
      return
    }
    toast.add({ severity: 'success', summary: t('upload_file'), detail: t('ok_file_uploaded'), life: 3000 })
    emit('file-uploaded', res.file)
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 5000 })
  } finally {
    uploading.value = false
  }
}

// ── Delete ─────────────────────────────────────────────────────────
function confirmDeleteFile(file) {
  confirm.require({
    message:  t('confirm_delete_file'),
    header:   t('delete_file'),
    icon:     'pi pi-exclamation-triangle',
    severity: 'danger',
    accept:   () => doDeleteFile(file),
  })
}

async function doDeleteFile(file) {
  try {
    const res = await api.post('record_ctrl', 'deleteFile', { fileId: file.id })
    if (res.status === 'error') {
      toast.add({ severity: 'error', summary: t('generic_error'), detail: t(res.code), life: 5000 })
      return
    }
    toast.add({ severity: 'success', summary: t('delete_file'), detail: t('ok_file_deleted'), life: 3000 })
    emit('file-deleted', file.id)
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 5000 })
  }
}

// ── Helpers ────────────────────────────────────────────────────────
function docLabel(f) {
  const name = f.filename || String(f.id)
  return f.ext ? `${name}.${f.ext}` : name
}

function downloadName(f) {
  const name = f.filename || String(f.id)
  return f.ext ? `${name}.${f.ext}` : name
}

function fileIcon(ext) {
  const e = (ext ?? '').toLowerCase()
  if (['pdf'].includes(e))                        return 'pi pi-file-pdf'
  if (['doc', 'docx', 'odt', 'rtf'].includes(e)) return 'pi pi-file-word'
  if (['xls', 'xlsx', 'ods'].includes(e))        return 'pi pi-file-excel'
  if (['mp3', 'wav', 'ogg', 'wma'].includes(e))  return 'pi pi-volume-up'
  if (['mp4', 'mov', 'avi', 'mkv'].includes(e))  return 'pi pi-video'
  if (['zip', 'rar', 'tar', 'gz'].includes(e))   return 'pi pi-box'
  if (['svg', 'ai', 'eps'].includes(e))          return 'pi pi-pen-to-square'
  return 'pi pi-file'
}
</script>

<style scoped>
.file-gallery {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

/* ── Sortable list (edit mode) ── */
.sortable-list {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.sortable-item {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.3rem 0.4rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: 4px;
  background: var(--p-content-background);
  font-size: 0.875rem;
  cursor: default;
}

.sortable-item.sortable-ghost {
  opacity: 0.4;
  background: var(--p-primary-50, #e8f0fe);
}

.drag-handle {
  color: var(--p-text-muted-color);
  cursor: grab;
  flex-shrink: 0;
  font-size: 0.9rem;
  padding: 0 0.2rem;
}
.drag-handle:active { cursor: grabbing; }

:deep(.sort-thumb) {
  width:  60px;
  height: 45px;
  object-fit: cover;
  border-radius: 3px;
  border: 1px solid var(--p-content-border-color);
  display: block;
  flex-shrink: 0;
}

.sort-label {
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.sort-desc {
  color: var(--p-text-muted-color);
  font-size: 0.8rem;
  flex-shrink: 0;
  max-width: 160px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.sort-saving {
  font-size: 0.8rem;
  color: var(--p-text-muted-color);
}

/* ── Image thumbnails (view mode) ── */
.images-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
}

.img-thumb {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.25rem;
  position: relative;
}

:deep(.thumb-img) {
  width:  120px;
  height:  90px;
  object-fit: cover;
  border-radius: 4px;
  border: 1px solid var(--p-content-border-color);
  display: block;
}

.img-caption {
  font-size: 0.72rem;
  color: var(--p-text-muted-color);
  max-width: 120px;
  text-align: center;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.img-actions {
  display: flex;
  align-items: center;
  gap: 0.3rem;
}

/* ── Document list (view mode) ── */
.docs-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.doc-item {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.875rem;
}

.doc-icon {
  color: var(--p-text-muted-color);
  flex-shrink: 0;
}

.doc-name {
  color: var(--p-primary-color);
  text-decoration: none;
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.doc-name:hover { text-decoration: underline; }

.doc-desc {
  color: var(--p-text-muted-color);
  font-size: 0.8rem;
  flex-shrink: 0;
  max-width: 200px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* ── Shared action buttons ── */
.file-action {
  background: none;
  border: none;
  padding: 0;
  cursor: pointer;
  color: var(--p-text-muted-color);
  text-decoration: none;
  font-size: 0.85rem;
  line-height: 1;
  flex-shrink: 0;
}
.file-action:hover { color: var(--p-primary-color); }
.file-delete-btn:hover { color: var(--p-red-500); }

/* ── Empty state ── */
.files-empty {
  color: var(--p-text-muted-color);
  font-style: italic;
}

/* ── Upload bar ── */
.upload-bar { margin-top: 0.25rem; }
.hidden-input { display: none; }
</style>
