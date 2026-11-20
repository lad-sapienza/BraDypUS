<template>
  <div class="char-palette-wrap">
    <!-- Long lists hide behind a button, so a narrow column is not filled with buttons. -->
    <button
      v-if="collapsible"
      type="button"
      class="char-toggle"
      :aria-expanded="open"
      @mousedown.prevent
      @click="open = !open"
    >
      <span class="char-toggle-icon">Ω</span>
      <span class="char-toggle-label">{{ t('char_palette') }}</span>
      <span class="char-toggle-count">{{ items.length }}</span>
      <DownOutlined class="char-toggle-chevron" :class="{ open }" />
    </button>

    <div v-show="!collapsible || open" class="char-palette" :class="{ preview }" role="group" :aria-label="t('char_palette')">
      <button
        v-for="(item, i) in items"
        :key="i"
        type="button"
        class="char-btn"
        :title="item.value === item.label ? undefined : item.value"
        :disabled="preview"
        @mousedown.prevent
        @click="insert(item.value)"
      >{{ item.label }}</button>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { DownOutlined } from '@ant-design/icons-vue'
import { useI18n } from '@/i18n'
import { insertAtCursor } from '@/utils/textInsert'

/** A list longer than this hides behind a button. */
const INLINE_MAX = 16

const props = defineProps({
  /** [{ label, value }] — the buttons. */
  items:   { type: Array, required: true },
  /** Returns the textarea element the buttons write into. */
  getEl:   { type: Function, default: null },
  /** Show the buttons only (no insertion, never collapsed): the char maps panel. */
  preview: { type: Boolean, default: false },
})

const { t } = useI18n()
const open = ref(false)
const collapsible = computed(() => !props.preview && props.items.length > INLINE_MAX)

// mousedown is cancelled on the buttons so the textarea keeps its focus and selection.
function insert(value) {
  if (props.getEl) insertAtCursor(props.getEl(), value)
}
</script>

<style scoped>
.char-palette-wrap {
  margin-top: 4px;
  /* Takes the width of the field, adds nothing to it: a long button label must not widen a grid column. */
  width: 0;
  min-width: 100%;
}
.char-toggle {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  max-width: 100%;
  padding: 0 8px;
  white-space: nowrap;
  font-size: 0.78rem;
  line-height: 1.7;
  cursor: pointer;
  color: var(--p-text-muted-color, #666);
  background: transparent;
  border: 1px dashed var(--p-content-border-color, #d9d9d9);
  border-radius: 4px;
}
.char-toggle:hover {
  color: var(--p-primary-color, #4f46e5);
  border-color: var(--p-primary-color, #4f46e5);
}
.char-toggle-icon { font-weight: 600; }
.char-toggle-label { min-width: 0; overflow: hidden; text-overflow: ellipsis; }
.char-toggle-count { opacity: 0.7; }
.char-toggle-chevron { font-size: 0.65rem; transition: transform 0.15s; }
.char-toggle-chevron.open { transform: rotate(180deg); }

.char-palette {
  display: flex;
  flex-wrap: wrap;
  gap: 3px;
  margin-top: 4px;
  /* Many buttons in a narrow column: scroll inside the strip, don't push the form down. */
  max-height: 9rem;
  overflow-y: auto;
}
.char-palette.preview {
  margin-top: 0;
  max-height: 12rem;
}
.char-btn {
  min-width: 24px;
  padding: 0 4px;
  font-size: 0.95rem;
  line-height: 1.5;
  cursor: pointer;
  color: inherit;
  background: var(--bdus-surface, #fff);
  border: 1px solid var(--p-content-border-color, #d9d9d9);
  border-radius: 4px;
}
.char-btn:hover:not(:disabled) {
  border-color: var(--p-primary-color, #4f46e5);
  color: var(--p-primary-color, #4f46e5);
}
.char-btn:disabled {
  cursor: default;
}
</style>
