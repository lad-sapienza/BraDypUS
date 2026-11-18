<template>
  <div class="char-palette" role="group" :aria-label="t('char_palette')">
    <button
      v-for="(item, i) in items"
      :key="i"
      type="button"
      class="char-btn"
      :title="item.value === item.label ? undefined : item.value"
      @mousedown.prevent
      @click="insert(item.value)"
    >{{ item.label }}</button>
  </div>
</template>

<script setup>
import { useI18n } from '@/i18n'
import { insertAtCursor } from '@/utils/textInsert'

const props = defineProps({
  /** [{ label, value }] — the buttons. */
  items:  { type: Array, required: true },
  /** Returns the textarea element the buttons write into. */
  getEl:  { type: Function, required: true },
})

const { t } = useI18n()

// mousedown is cancelled on the buttons so the textarea keeps its focus and selection.
function insert(value) {
  insertAtCursor(props.getEl(), value)
}
</script>

<style scoped>
.char-palette {
  display: flex;
  flex-wrap: wrap;
  gap: 3px;
  margin-top: 4px;
  /* Many buttons in a narrow column: scroll inside the strip, don't push the form down. */
  max-height: 6.5rem;
  overflow-y: auto;
}
.char-btn {
  min-width: 24px;
  padding: 0 4px;
  font-size: 0.95rem;
  line-height: 1.5;
  cursor: pointer;
  color: inherit;
  background: var(--ant-color-bg-container, #fff);
  border: 1px solid var(--ant-color-border, #d9d9d9);
  border-radius: 4px;
}
.char-btn:hover {
  border-color: var(--ant-color-primary, #4f46e5);
  color: var(--ant-color-primary, #4f46e5);
}
</style>
