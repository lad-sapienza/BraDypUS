<template>
  <div class="file-gallery">

    <!-- ── Image thumbnails ──────────────────────────────────────── -->
    <div v-if="images.length" class="images-grid">
      <div v-for="f in images" :key="f.id" class="img-thumb">
        <!--
          PrimeVue Image: renders a thumbnail; the `preview` prop adds a
          click-to-fullscreen lightbox with zoom/rotate controls built-in.
        -->
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
        <a :href="f.url" :download="downloadName(f)" class="img-dl" :title="t('download')">
          <i class="pi pi-download" />
        </a>
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
        <a :href="f.url" :download="downloadName(f)" class="doc-dl" :title="t('download')">
          <i class="pi pi-download" />
        </a>
      </li>
    </ul>

  </div>
</template>

<script setup>
import { computed } from 'vue'
import Image from 'primevue/image'
import { useI18n } from '@/i18n'

const { t } = useI18n()

const props = defineProps({
  /**
   * Array of file objects as returned by record_ctrl::getRecord().
   * Each object: { id, ext, filename, description, keywords, printable, url, is_image }
   */
  files: {
    type: Array,
    default: () => [],
  },
})

// ── Partition ──────────────────────────────────────────────────────
const images = computed(() => (props.files ?? []).filter(f => f.is_image))
const docs   = computed(() => (props.files ?? []).filter(f => !f.is_image))

// ── Helpers ────────────────────────────────────────────────────────

/** Human-readable filename for a document row */
function docLabel(f) {
  const name = f.filename || String(f.id)
  return f.ext ? `${name}.${f.ext}` : name
}

/** Suggested filename for the `download` attribute */
function downloadName(f) {
  const name = f.filename || String(f.id)
  return f.ext ? `${name}.${f.ext}` : name
}

/**
 * Maps a file extension to a PrimeIcon class.
 * Falls back to `pi-file` for unknown types.
 */
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

/* Force uniform thumbnail size; PrimeVue Image respects width/height on the wrapper */
:deep(.thumb-img) {
  width:  120px;
  height:  90px;
  object-fit: cover;
  border-radius: 4px;
  border: 1px solid var(--p-surface-border);
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

.img-dl {
  font-size: 0.75rem;
  color: var(--p-text-muted-color);
  text-decoration: none;
}
.img-dl:hover { color: var(--p-primary-color); }

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

.doc-dl {
  color: var(--p-text-muted-color);
  text-decoration: none;
  flex-shrink: 0;
}
.doc-dl:hover { color: var(--p-primary-color); }
</style>
