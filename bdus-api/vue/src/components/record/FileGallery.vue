<template>
  <div class="file-gallery">

    <!-- ── Image thumbnails ──────────────────────────────────────── -->
    <div v-if="images.length" class="images-grid">
      <div v-for="f in images" :key="f.id" class="img-thumb">
        <Image
          :src="f.url"
          :alt="f.description || f.filename || String(f.id)"
          :title="f.description || ''"
          preview
          :width="120"
          :height="90"
          image-class="thumb-img"
        />
        <div v-if="f.description" class="img-caption">{{ f.description }}</div>
        <div class="img-actions">
          <a :href="f.url" :download="downloadName(f)" class="file-action" :title="t('download')">
            <i class="pi pi-download" />
          </a>
          <button
            v-if="editMode"
            class="file-action file-delete-btn"
            :title="t('delete_file')"
            @click="confirmDeleteFile(f)"
          >
            <i class="pi pi-trash" />
          </button>
        </div>
      </div>
    </div>

    <!-- ── Document / generic files list ────────────────────────── -->
    <ul v-if="docs.length" class="docs-list">
      <li v-for="f in docs" :key="f.id" class="doc-item">
        <i :class="['doc-icon', fileIcon(f.ext)]" />
        <a :href="f.url" target="_blank" rel="noopener noreferrer" class="doc-name">
          {{ docLabel(f) }}
        </a>
        <span v-if="f.description" class="doc-desc">{{ f.description }}</span>
        <a :href="f.url" :download="downloadName(f)" class="file-action" :title="t('download')">
          <i class="pi pi-download" />
        </a>
        <button
          v-if="editMode"
          class="file-action file-delete-btn"
          :title="t('delete_file')"
          @click="confirmDeleteFile(f)"
        >
          <i class="pi pi-trash" />
        </button>
      </li>
    </ul>

    <!-- ── Empty state ───────────────────────────────────────────── -->
    <div v-if="!images.length && !docs.length && !editMode" class="files-empty">—</div>

    <!-- ── Upload button (edit mode) ────────────────────────────── -->
    <div v-if="editMode" class="upload-bar">
      <input
        ref="fileInput"
        type="file"
        class="hidden-input"
        @change="onFileSelected"
      />
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

  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import Image   from 'primevue/image'
import Button  from 'primevue/button'
import { useToast }   from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import { api }     from '@/api'
import { useI18n } from '@/i18n'

const { t }   = useI18n()
const toast   = useToast()
const confirm = useConfirm()

const props = defineProps({
  /**
   * Array of file objects as returned by record_ctrl::getRecord().
   * Each object: { id, ext, filename, description, keywords, printable, url, is_image }
   */
  files:    { type: Array, default: () => [] },
  /** When true, shows delete buttons and the upload button */
  editMode: { type: Boolean, default: false },
  /** Table of the record this gallery belongs to (required in edit mode) */
  recordTb: { type: String, default: null },
  /** Id of the record this gallery belongs to (required in edit mode) */
  recordId: { type: [String, Number], default: null },
})

const emit = defineEmits([
  /** Emitted after a successful upload. Payload: the new file object. */
  'file-uploaded',
  /** Emitted after a successful delete. Payload: the deleted file id. */
  'file-deleted',
])

// ── Partition ──────────────────────────────────────────────────────
const images = computed(() => (props.files ?? []).filter(f => f.is_image))
const docs   = computed(() => (props.files ?? []).filter(f => !f.is_image))

// ── Upload ─────────────────────────────────────────────────────────
const fileInput = ref(null)
const uploading = ref(false)

async function onFileSelected(evt) {
  const file = evt.target.files?.[0]
  if (!file) return
  // Reset input so the same file can be re-selected if needed
  evt.target.value = ''

  uploading.value = true
  try {
    const res = await api.upload(
      'record_ctrl',
      'uploadFile',
      file,
      'file',
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
  if (['pdf'].includes(e))                       return 'pi pi-file-pdf'
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

/* ── Image thumbnails ── */
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

/* ── Document list ── */
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

/* ── Shared action icon buttons ── */
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
