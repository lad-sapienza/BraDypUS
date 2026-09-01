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
            <BarsOutlined />
          </span>

          <!-- thumbnail for images, icon for docs -->
          <AImage
            v-if="f.is_image"
            :src="fileUrl(f)"
            :alt="f.description || f.filename || String(f.id)"
            :preview="true"
            :width="60"
            :height="45"
            class="sort-thumb"
          />
          <component v-else :is="fileIcon(f.ext)" class="doc-icon" />

          <!-- label -->
          <span class="sort-label">{{ docLabel(f) }}</span>
          <span v-if="f.description" class="sort-desc">{{ f.description }}</span>

          <!-- actions -->
          <a :href="fileUrl(f)" :download="downloadName(f)" class="file-action" :title="t('download')">
            <DownloadOutlined />
          </a>
          <button
            class="file-action file-unlink-btn"
            :title="t('unlink_file')"
            @click="confirmUnlinkFile(f)"
          >
            <LinkOutlined />
          </button>
          <button
            class="file-action file-delete-btn"
            :title="t('delete_file')"
            @click="confirmDeleteFile(f)"
          >
            <DeleteOutlined />
          </button>
        </div>
      </div>

      <!-- sort feedback -->
      <div v-if="sortSaving" class="sort-saving">
        <LoadingOutlined spin /> {{ t('saving') }}…
      </div>

      <!-- empty state in edit mode -->
      <div v-if="!localFiles.length" class="files-empty">—</div>

      <!-- upload / drop zone -->
      <div
        class="upload-bar"
        :class="{ 'drag-over': isDragOver }"
        @dragenter.prevent="isDragOver = true"
        @dragover.prevent="isDragOver = true"
        @dragleave.prevent="isDragOver = false"
        @drop.prevent="onDrop"
      >
        <input ref="fileInput" type="file" class="hidden-input" @change="onFileSelected" />
        <UploadOutlined class="upload-bar-icon" />
        <span class="upload-bar-hint">{{ t('drag_drop_or') }}</span>
        <AButton size="small" :loading="uploading" @click="fileInput?.click()">
          {{ t('upload_file') }}
        </AButton>
        <AButton size="small" @click="openFilePicker">
          <template #icon><LinkOutlined /></template>
          {{ t('link_existing_file') }}
        </AButton>
      </div>

      <!-- File picker Dialog -->
      <AModal
        v-model:open="filePickerDialog"
        :title="t('file_picker_title')"
        width="680px"
        :footer="null"
      >
        <div class="picker-search">
          <AInput
            v-model:value="pickerSearch"
            :placeholder="t('file_picker_search')"
            size="small"
            class="picker-search-input"
            @input="debouncedPickerLoad"
          />
        </div>
        <ATable
          :columns="pickerColumns"
          :dataSource="pickerFiles"
          :loading="pickerLoading"
          :pagination="pickerPagination"
          :customRow="pickerCustomRow"
          :locale="{ emptyText: t('files_empty') }"
          size="small"
          rowKey="id"
          class="picker-table clickable-rows"
          @change="onPickerTableChange"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'thumb'">
              <img v-if="record.is_image" :src="fileUrl(record)" class="picker-thumb" />
              <component v-else :is="fileIcon(record.ext)" class="picker-icon" />
            </template>
            <template v-else-if="column.key === 'filename'">
              <span>{{ record.filename }}.{{ record.ext }}</span>
              <div v-if="record.description" class="picker-desc">{{ record.description }}</div>
            </template>
            <template v-else-if="column.key === 'links'">
              <span style="font-size: 0.78rem; color: var(--p-text-muted-color)">
                {{ record.links.length }} {{ t('file_picker_links') }}
              </span>
            </template>
          </template>
        </ATable>
      </AModal>
    </template>

    <!-- ── View mode: split images / docs ────────────────────────── -->
    <template v-else>
      <!-- Image thumbnails -->
      <div v-if="images.length" class="images-grid">
        <div v-for="f in images" :key="f.id" class="img-thumb">
          <AImage
            :src="fileUrl(f)"
            :alt="f.description || f.filename || String(f.id)"
            :title="f.description || ''"
            :preview="true"
            :width="120"
            :height="90"
            class="thumb-img"
          />
          <div v-if="f.description" class="img-caption">{{ f.description }}</div>
          <div class="img-actions">
            <a :href="fileUrl(f)" :download="downloadName(f)" class="file-action" :title="t('download')">
              <DownloadOutlined />
            </a>
          </div>
        </div>
      </div>

      <!-- Document list -->
      <ul v-if="docs.length" class="docs-list">
        <li v-for="f in docs" :key="f.id" class="doc-item">
          <component :is="fileIcon(f.ext)" class="doc-icon" />
          <a :href="fileUrl(f)" target="_blank" rel="noopener noreferrer" class="doc-name">
            {{ docLabel(f) }}
          </a>
          <span v-if="f.description" class="doc-desc">{{ f.description }}</span>
          <a :href="fileUrl(f)" :download="downloadName(f)" class="file-action" :title="t('download')">
            <DownloadOutlined />
          </a>
        </li>
      </ul>

      <!-- Empty state -->
      <div v-if="!images.length && !docs.length" class="files-empty">—</div>
    </template>

  </div>
</template>

<script setup>
import { BarsOutlined, DeleteOutlined, DownloadOutlined, EditOutlined, FileExcelOutlined, FileOutlined, FilePdfOutlined, FileWordOutlined, FileZipOutlined, LinkOutlined, LoadingOutlined, SoundOutlined, UploadOutlined, VideoCameraOutlined } from '@ant-design/icons-vue'
import { ref, computed, watch, onUnmounted, nextTick } from 'vue'
import Sortable   from 'sortablejs'
import { Table as ATable, Image as AImage, Button as AButton, Modal as AModal, Input as AInput } from 'ant-design-vue'
import { useToast, useConfirm } from '@/composables/useNotify'
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
  /** Emitted after a successful upload or link. Payload: the new file object. */
  'file-uploaded',
  /** Emitted after a successful delete or unlink. Payload: the file id. */
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
    const res = await api.post('/api/files/sort', { order: linkIds })
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
const fileInput  = ref(null)
const uploading  = ref(false)
const isDragOver = ref(false)

async function uploadFile(file) {
  uploading.value = true
  try {
    const res = await api.upload(
      `/api/record/${props.recordTb}/${props.recordId}/file`, file, 'file'
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

function onFileSelected(evt) {
  const file = evt.target.files?.[0]
  if (!file) return
  evt.target.value = ''
  uploadFile(file)
}

function onDrop(evt) {
  isDragOver.value = false
  const file = evt.dataTransfer.files?.[0]
  if (file) uploadFile(file)
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
    const res = await api.delete(`/api/file/${file.id}`)
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

// ── Unlink ─────────────────────────────────────────────────────────
function confirmUnlinkFile(file) {
  confirm.require({
    message:  t('confirm_unlink_file'),
    header:   t('unlink_file'),
    icon:     'pi pi-info-circle',
    severity: 'warn',
    accept:   () => doUnlinkFile(file),
  })
}

async function doUnlinkFile(file) {
  const linkId = file.link_id
  if (!linkId) return
  try {
    const res = await api.delete(`/api/file-link/${linkId}`)
    if (res.status === 'error') {
      toast.add({ severity: 'error', summary: t('generic_error'), detail: t(res.code), life: 5000 })
      return
    }
    toast.add({ severity: 'success', summary: t('unlink_file'), detail: t('ok_file_unlinked'), life: 3000 })
    emit('file-deleted', file.id)
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 5000 })
  }
}

// ── File picker (link existing file) ──────────────────────────────
const filePickerDialog = ref(false)
const pickerFiles      = ref([])
const pickerTotal      = ref(0)
const pickerLoading    = ref(false)
const pickerPage       = ref(1)
const pickerPerPage    = ref(10)
const pickerSearch     = ref('')
let   pickerDebounce   = null

async function loadPickerFiles() {
  pickerLoading.value = true
  try {
    const params = { page: pickerPage.value, per_page: pickerPerPage.value }
    if (pickerSearch.value.trim()) params.search = pickerSearch.value.trim()
    const data = await api.get('/api/files', params)
    pickerFiles.value = data.files ?? []
    pickerTotal.value = data.total ?? 0
  } catch {
    // non-critical
  } finally {
    pickerLoading.value = false
  }
}

function debouncedPickerLoad() {
  clearTimeout(pickerDebounce)
  pickerDebounce = setTimeout(() => {
    pickerPage.value = 1
    loadPickerFiles()
  }, 300)
}

const pickerColumns = computed(() => [
  { title: '',                       key: 'thumb',    width: 60 },
  { title: t('files_col_filename'),  key: 'filename' },
  { title: t('files_col_links'),     key: 'links',    width: 120 },
])

const pickerPagination = computed(() => ({
  current: pickerPage.value,
  pageSize: pickerPerPage.value,
  total: pickerTotal.value,
  position: ['bottomCenter'],
}))

function onPickerTableChange(pagination) {
  pickerPage.value    = pagination.current
  pickerPerPage.value = pagination.pageSize
  loadPickerFiles()
}

function pickerCustomRow(record) {
  return { onClick: () => onPickerSelect({ data: record }) }
}

function openFilePicker() {
  pickerPage.value   = 1
  pickerSearch.value = ''
  filePickerDialog.value = true
  loadPickerFiles()
}

async function onPickerSelect(event) {
  const file = event.data
  filePickerDialog.value = false
  try {
    const res = await api.post(
      `/api/record/${props.recordTb}/${props.recordId}/link-file`,
      { fileId: file.id }
    )
    if (res.status === 'error') {
      toast.add({ severity: 'error', summary: t('generic_error'), detail: t(res.code), life: 5000 })
      return
    }
    toast.add({ severity: 'success', summary: t('link_existing_file'), detail: t('ok_file_linked'), life: 3000 })
    emit('file-uploaded', res.file)
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
  if (['pdf'].includes(e))                       return FilePdfOutlined
  if (['doc', 'docx', 'odt', 'rtf'].includes(e)) return FileWordOutlined
  if (['xls', 'xlsx', 'ods'].includes(e))        return FileExcelOutlined
  if (['mp3', 'wav', 'ogg', 'wma'].includes(e))  return SoundOutlined
  if (['mp4', 'mov', 'avi', 'mkv'].includes(e))  return VideoCameraOutlined
  if (['zip', 'rar', 'tar', 'gz'].includes(e))   return FileZipOutlined
  if (['svg', 'ai', 'eps'].includes(e))          return EditOutlined
  return FileOutlined
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
.file-unlink-btn:hover { color: var(--p-orange-500, #f97316); }
.file-delete-btn:hover { color: var(--p-red-500); }

/* ── File picker ── */
.picker-search {
  margin-bottom: 0.75rem;
}
.picker-search-input {
  width: 100%;
}
.picker-table {
  font-size: 0.82rem;
}
.clickable-rows :deep(.ant-table-tbody > tr) { cursor: pointer; }
.clickable-rows :deep(.ant-table-tbody > tr:hover > td) { background: var(--p-content-hover-background) !important; }
.picker-thumb {
  width: 48px;
  height: 36px;
  object-fit: cover;
  border-radius: 3px;
  border: 1px solid var(--p-content-border-color);
  display: block;
}
.picker-icon {
  font-size: 1.4rem;
  color: var(--p-text-muted-color);
  display: block;
  text-align: center;
}
.picker-desc {
  color: var(--p-text-muted-color);
  font-size: 0.75rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* ── Empty state ── */
.files-empty {
  color: var(--p-text-muted-color);
  font-style: italic;
}

/* ── Upload / drop zone ── */
.upload-bar {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  margin-top: 0.25rem;
  padding: 0.5rem 0.75rem;
  border: 1.5px dashed var(--p-content-border-color);
  border-radius: 6px;
  transition: background 0.15s, border-color 0.15s;
  cursor: default;
}
.upload-bar.drag-over {
  border-color: var(--p-primary-color);
  background: color-mix(in srgb, var(--p-primary-color) 8%, transparent);
}
.upload-bar-icon {
  color: var(--p-text-muted-color);
  font-size: 1rem;
  flex-shrink: 0;
}
.upload-bar.drag-over .upload-bar-icon { color: var(--p-primary-color); }
.upload-bar-hint {
  font-size: 0.8rem;
  color: var(--p-text-muted-color);
  flex: 1;
}
.hidden-input { display: none; }
</style>
