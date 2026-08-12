<template>
  <Dialog
    v-model:visible="visible"
    :showHeader="false"
    :closeOnEscape="false"
    :dismissableMask="true"
    modal
    position="top"
    class="command-palette-dialog"
    :style="{ width: '600px' }"
    @hide="reset"
    @after-show="focusInput"
  >
    <div class="cp-wrap">
      <div v-if="mode" class="cp-breadcrumb">
        <button class="cp-back" :title="t('close')" @click="exitMode">
          <i class="pi pi-arrow-left" />
        </button>
        <span>{{ mode.label }}</span>
      </div>

      <IconField class="cp-input-wrap">
        <InputIcon class="pi pi-search" />
        <InputText
          ref="inputRef"
          v-model="query"
          :placeholder="mode ? t('select_table') : t('type_to_search')"
          fluid
        />
      </IconField>

      <ul v-if="filtered.length" class="cp-results">
        <template v-for="(item, idx) in filtered" :key="item.id">
          <li v-if="!mode && (idx === 0 || filtered[idx - 1].type !== item.type)" class="cp-group-label">
            {{ groupLabel(item.type) }}
          </li>
          <li
            class="cp-item"
            :class="{ active: idx === activeIndex }"
            @mouseenter="activeIndex = idx"
            @click="select(item)"
          >
            <i :class="['pi', item.icon]" />
            <span class="cp-item-label">{{ item.label }}</span>
            <i v-if="item.type === 'parametric'" class="pi pi-angle-right cp-item-chevron" />
          </li>
        </template>
      </ul>
      <div v-else class="cp-empty">{{ t('command_no_results') }}</div>
    </div>
  </Dialog>
</template>

<script setup>
import { ref, computed, nextTick, onMounted, onUnmounted, watch } from 'vue'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import IconField from 'primevue/iconfield'
import InputIcon from 'primevue/inputicon'
import { useI18n } from '@/i18n'
import { useCommands } from '@/composables/useCommands'

const emit = defineEmits(['open-profile'])

const { t } = useI18n()
const { commands } = useCommands({ onOpenProfile: () => emit('open-profile') })

const visible     = ref(false)
const query       = ref('')
const mode        = ref(null)   // null (root) | parametric command object (table-picking step)
const activeIndex = ref(0)
const inputRef    = ref()

const DIACRITICS_RE = /[\u0300-\u036f]/g

function normalize(str) {
  return String(str ?? '')
    .toLowerCase()
    .normalize('NFD')
    .replace(DIACRITICS_RE, '')
}

const filtered = computed(() => {
  const source = mode.value ? mode.value.argOptions : commands.value
  const q = normalize(query.value)
  if (!q) return source
  return source.filter(c => normalize(c.label).includes(q))
})

watch(query, () => { activeIndex.value = 0 })

function groupLabel(type) {
  if (type === 'nav')   return t('command_group_navigation')
  if (type === 'table') return t('command_group_tables')
  return t('command_group_actions') // 'action' and 'parametric'
}

function focusInput() {
  nextTick(() => inputRef.value?.$el?.focus())
}

function open() {
  visible.value = true
  // If the dialog was already open, @after-show won't re-fire — focus directly.
  focusInput()
}

function close() {
  visible.value = false
}

function reset() {
  query.value = ''
  mode.value = null
  activeIndex.value = 0
}

function exitMode() {
  mode.value = null
  query.value = ''
  activeIndex.value = 0
  focusInput()
}

function select(item) {
  if (!mode.value && item.type === 'parametric') {
    mode.value = item
    query.value = ''
    activeIndex.value = 0
    focusInput()
    return
  }
  if (mode.value) {
    mode.value.run(item.value)
  } else {
    item.run()
  }
  close()
}

// Single window-level listener for everything, deliberately not scoped to the
// input: if focus ever leaves it (a stray click, the mask, …) Escape and the
// open/close shortcut must still work rather than going dead.
function handleGlobalKeydown(e) {
  if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
    e.preventDefault()
    visible.value ? close() : open()
    return
  }
  if (!visible.value) return
  if (e.key === 'ArrowDown') {
    e.preventDefault()
    activeIndex.value = Math.min(activeIndex.value + 1, filtered.value.length - 1)
  } else if (e.key === 'ArrowUp') {
    e.preventDefault()
    activeIndex.value = Math.max(activeIndex.value - 1, 0)
  } else if (e.key === 'Enter') {
    e.preventDefault()
    const item = filtered.value[activeIndex.value]
    if (item) select(item)
  } else if (e.key === 'Escape') {
    e.preventDefault()
    if (mode.value) exitMode()
    else close()
  }
}
onMounted(() => window.addEventListener('keydown', handleGlobalKeydown))
onUnmounted(() => window.removeEventListener('keydown', handleGlobalKeydown))

defineExpose({ open })
</script>

<style scoped>
:deep(.command-palette-dialog) {
  margin-top: 12vh;
}
:deep(.command-palette-dialog .p-dialog-content) {
  padding: 0;
  overflow: hidden;
  border-radius: 8px;
}

.cp-wrap {
  display: flex;
  flex-direction: column;
  max-height: 60vh;
}

.cp-breadcrumb {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.6rem 1rem 0;
  font-size: 0.8rem;
  color: var(--p-text-muted-color);
}
.cp-back {
  border: none;
  background: transparent;
  cursor: pointer;
  color: var(--p-text-muted-color);
  padding: 0.2rem;
  display: flex;
  border-radius: 4px;
}
.cp-back:hover { background: var(--p-content-hover-background); color: var(--p-text-color); }

.cp-input-wrap {
  padding: 0.75rem 1rem;
  flex-shrink: 0;
}
:deep(.cp-input-wrap input) {
  border: none;
  box-shadow: none;
  font-size: 1rem;
}

.cp-results {
  list-style: none;
  margin: 0;
  padding: 0.25rem 0 0.5rem;
  overflow-y: auto;
  border-top: 1px solid var(--p-content-border-color);
}

.cp-group-label {
  font-size: 0.68rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--p-text-muted-color);
  padding: 0.6rem 1rem 0.25rem;
}

.cp-item {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  padding: 0.5rem 1rem;
  cursor: pointer;
  font-size: 0.9rem;
}
.cp-item .pi:first-child {
  width: 1.1rem;
  text-align: center;
  color: var(--p-text-muted-color);
  flex-shrink: 0;
}
.cp-item.active {
  background: var(--p-highlight-background);
  color: var(--p-primary-color);
}
.cp-item.active .pi:first-child { color: var(--p-primary-color); }

.cp-item-label {
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.cp-item-chevron {
  opacity: 0.5;
  font-size: 0.75rem;
}

.cp-empty {
  padding: 1.5rem 1rem;
  text-align: center;
  color: var(--p-text-muted-color);
  font-size: 0.85rem;
  border-top: 1px solid var(--p-content-border-color);
}
</style>
