<template>
  <div class="field-display" :class="{ hidden: schema.hide }" v-if="!schema.hide">
    <label class="field-label">
      {{ schema.label }}
      <span v-if="schema.help" class="field-help pi pi-info-circle" :title="schema.help" />
    </label>

    <div class="field-value" :dir="schema.direction || 'ltr'">

      <!-- Empty -->
      <span v-if="displayValue === null || displayValue === ''" class="field-empty">—</span>

      <!-- Custom widget served from projects/{app}/widgets/{name}.js -->
      <DynamicWidget
        v-else-if="schema.widget"
        :name="schema.widget"
        :value="String(displayValue)"
      />

      <!-- long_text: preserve line breaks, auto-link URLs -->
      <div v-else-if="schema.type === 'long_text'"
           class="field-long-text"
           v-html="linkedText(displayValue)" />

      <!-- md: rendered markdown -->
      <div v-else-if="schema.type === 'md'"
           class="field-md"
           v-html="marked.parse(String(displayValue))" />

      <!-- multi_select: comma-separated chips -->
      <div v-else-if="schema.type === 'multi_select'" class="field-chips">
        <Tag v-for="v in multiValues" :key="v" :value="v" severity="secondary" />
      </div>

      <!-- boolean -->
      <span v-else-if="schema.type === 'boolean'">
        {{ displayValue == 1 || displayValue === 'yes' || displayValue === true ? t('yes') : t('no') }}
      </span>

      <!-- default: plain text (shows val_label for id_from_tb fields) -->
      <span v-else>{{ displayValue }}</span>

    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import Tag from 'primevue/tag'
import { marked } from 'marked'
import { useI18n } from '@/i18n'
import DynamicWidget from '@/components/record/DynamicWidget.vue'

const { t } = useI18n()

const props = defineProps({
  /** Field schema from getRecord() → schema.fields[*] */
  schema: { type: Object, required: true },
  /** Field value object from getRecord() → core[name] = { name, label, val, val_label? } */
  value:  { type: Object, default: null },
})

// Prefer val_label (for id_from_tb FK fields), else val
const displayValue = computed(() => {
  if (!props.value) return null
  return props.value.val_label ?? props.value.val ?? null
})

const multiValues = computed(() => {
  if (!props.value?.val) return []
  return String(props.value.val).split(';').map(v => v.trim()).filter(Boolean)
})

function linkedText(text) {
  if (!text) return ''
  // Escape HTML first
  const escaped = String(text)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
  // Auto-link URLs
  const linked = escaped.replace(
    /(https?:\/\/[^\s<>"]+)/g,
    '<a href="$1" target="_blank" rel="noopener">$1</a>'
  )
  // Preserve line breaks
  return linked.replace(/\n/g, '<br>')
}
</script>

<style scoped>
.field-display {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  padding: 0.4rem 0;
  border-bottom: 1px solid var(--p-content-border-color);
}

.field-label {
  font-size: 0.72rem;
  font-weight: 600;
  text-transform: uppercase;
  color: var(--p-text-muted-color);
  letter-spacing: 0.04em;
}

.field-help {
  margin-left: 0.25rem;
  cursor: help;
  opacity: 0.6;
}

.field-value {
  font-size: 0.9rem;
  color: var(--p-text-color);
  word-break: break-word;
}

.field-empty {
  color: var(--p-text-muted-color);
  font-style: italic;
}

.field-long-text {
  white-space: pre-wrap;
  line-height: 1.55;
}

.field-md {
  font-size: 0.875rem;
  line-height: 1.6;
}
.field-md :deep(p)        { margin: 0 0 0.5rem; }
.field-md :deep(strong)   { font-weight: 600; }
.field-md :deep(em)       { font-style: italic; }
.field-md :deep(ul),
.field-md :deep(ol)       { padding-left: 1.5rem; margin: 0 0 0.5rem; }
.field-md :deep(a)        { color: var(--p-primary-color); }

.field-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem;
}
</style>
