<template>
  <div class="files-cards" data-test="files-cards">
    <div v-if="!files.length && !loading" class="cards-empty">{{ t('files_empty') }}</div>

    <div
      v-for="f in files"
      :key="f.id"
      class="file-card"
      :class="{ selected: isSelected(f.id) }"
      data-test="file-card"
    >
      <label class="card-check" @click.stop>
        <ACheckbox :checked="isSelected(f.id)" @change="$emit('toggle', f.id)" />
      </label>

      <button type="button" class="card-media" :title="t('files_preview_hint')" @click="$emit('open', f)">
        <img v-if="f.is_image" :src="thumbUrl(f, fileUrl(f))" :alt="f.filename" loading="lazy" class="card-thumb" />
        <component v-else :is="fileIcon(f.ext)" class="card-icon" />
      </button>

      <div class="card-body">
        <div class="card-name" :title="`${f.filename}.${f.ext}`">
          <span class="card-name-text">{{ f.filename }}</span><span class="card-ext">.{{ f.ext }}</span>
        </div>

        <div class="card-badges">
          <span v-if="!f.links.length" class="badge badge-orphan">{{ t('files_orphan_badge') }}</span>
          <span v-if="f.public" class="badge badge-public">{{ t('files_public_badge') }}</span>
          <span v-if="f.links.length" class="badge">{{ t('files_links_count', { n: f.links.length }) }}</span>
        </div>

        <ul v-if="lines(f).length" class="card-lines">
          <li v-for="l in lines(f)" :key="l.label" :title="l.value">
            <span class="card-line-label">{{ l.label }}</span> {{ l.value }}
          </li>
        </ul>
      </div>

      <div class="card-actions">
        <button type="button" class="action-btn" :title="t('files_details')" @click="$emit('edit', f)"><EditOutlined /></button>
        <button type="button" class="action-btn" :disabled="!f.can_update" :title="t('replace_file')" @click="$emit('replace', f)"><SyncOutlined /></button>
        <button type="button" class="action-btn action-btn-danger" :disabled="!f.can_delete" :title="t('delete_file')" @click="$emit('delete', f)"><DeleteOutlined /></button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { Checkbox as ACheckbox } from 'ant-design-vue'
import { DeleteOutlined, EditOutlined, SyncOutlined } from '@ant-design/icons-vue'
import { apiUrl } from '@/api'
import { useI18n } from '@/i18n'
import { thumbUrl } from '@/utils/thumbUrl'
import { originalPath } from '@/utils/filePaths'
import { cardLines } from '@/utils/filesList'
import { fileIcon } from './fileIcon'

const props = defineProps({
  files:    { type: Array,  default: () => [] },
  fields:   { type: Array,  default: () => [] },
  visible:  { type: Array,  default: () => [] },
  selected: { type: Array,  default: () => [] },
  loading:  { type: Boolean, default: false },
})
defineEmits(['toggle', 'open', 'edit', 'replace', 'delete'])

const { t } = useI18n()

const isSelected = id => props.selected.includes(id)
const lines = f => cardLines(f, props.fields, props.visible, 3)

function fileUrl(f) {
  const path = originalPath(f)
  return path ? apiUrl(path) : ''
}
</script>

<style scoped>
.files-cards {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
  gap: 0.9rem;
  padding: 0.9rem 1rem;
  overflow: auto;
  align-content: start;
}
.cards-empty { grid-column: 1 / -1; text-align: center; color: var(--p-text-muted-color); padding: 2rem; font-style: italic; }

.file-card {
  position: relative;
  display: flex;
  flex-direction: column;
  border: 1px solid var(--p-content-border-color);
  border-radius: 8px;
  background: var(--p-content-background, transparent);
  overflow: hidden;
}
.file-card.selected { border-color: var(--p-primary-color); box-shadow: 0 0 0 1px var(--p-primary-color); }

.card-check { position: absolute; top: 6px; left: 8px; z-index: 2; background: rgba(255,255,255,.85); border-radius: 4px; padding: 1px 4px; }

.card-media {
  display: flex; align-items: center; justify-content: center;
  height: 150px;
  border: none; padding: 0; cursor: zoom-in;
  background: var(--p-content-hover-background);
}
.card-thumb { width: 100%; height: 100%; object-fit: cover; display: block; }
.card-icon { font-size: 3rem; color: var(--p-text-muted-color); }

.card-body { padding: 0.5rem 0.65rem 0.3rem; display: flex; flex-direction: column; gap: 0.3rem; flex: 1; min-width: 0; }
.card-name { display: flex; font-size: 0.86rem; font-weight: 600; min-width: 0; }
.card-name-text { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.card-ext { color: var(--p-text-muted-color); font-weight: 400; flex: none; }

.card-badges { display: flex; flex-wrap: wrap; gap: 0.3rem; }
.badge { padding: 1px 6px; border-radius: 4px; font-size: 0.68rem; background: var(--p-content-hover-background); color: var(--p-text-muted-color); }
.badge-orphan { background: var(--p-orange-100, #fef3c7); color: var(--p-orange-700, #b45309); font-weight: 600; }
.badge-public { background: var(--p-green-100, #dcfce7); color: var(--p-green-700, #15803d); font-weight: 600; }

.card-lines { list-style: none; margin: 0; padding: 0; font-size: 0.76rem; display: flex; flex-direction: column; gap: 0.1rem; }
.card-lines li { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.card-line-label { color: var(--p-text-muted-color); margin-right: 0.25rem; }

.card-actions { display: flex; justify-content: flex-end; gap: 0.3rem; padding: 0.2rem 0.5rem 0.4rem; }
.action-btn { background: none; border: none; padding: 0.2rem; cursor: pointer; color: var(--p-text-muted-color); font-size: 0.9rem; line-height: 1; border-radius: 3px; }
.action-btn:hover:not(:disabled) { color: var(--p-primary-color); background: var(--p-content-hover-background); }
.action-btn-danger:hover:not(:disabled) { color: var(--p-red-500); }
.action-btn:disabled { opacity: .35; cursor: not-allowed; }
</style>
