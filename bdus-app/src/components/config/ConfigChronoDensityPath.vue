<template>
  <div class="cfg-cdp-section">
    <div class="cfg-cdp-header">
      <span class="cfg-cdp-title"><ShareAltOutlined /> {{ t('chrono_density_path') }}</span>
      <AButton
        v-if="modelValue.length"
        type="text"
        size="small"
        @click="emit('update:modelValue', [])"
      >
        <template #icon><CloseOutlined /></template>
        {{ t('clear') }}
      </AButton>
    </div>

    <small class="cfg-hint">{{ t('help_chrono_density_path') }}</small>

    <div v-if="loading" class="cfg-cdp-loading"><LoadingOutlined spin /></div>

    <template v-else>
      <div class="cfg-cdp-breadcrumb" v-if="modelValue.length">
        <span>{{ tb }}</span>
        <template v-for="hop in modelValue" :key="hop">
          <RightOutlined />
          <span>{{ tableLabels[hop] ?? hop }}</span>
        </template>
      </div>

      <div v-for="(step, i) in steps" :key="i" class="cfg-cdp-step">
        <ASelect
          :value="step.value"
          :options="step.options.map(opt => ({ value: opt, label: tableLabels[opt] ?? opt }))"
          :placeholder="i === 0 ? t('chrono_density_path_start') : t('chrono_density_path_next')"
          allow-clear
          size="small"
          @change="v => selectAt(i, v)"
        >
          <template #option="{ value, label }">
            <span>{{ label }}</span>
            <ATag v-if="fuzzyDateTables.includes(value)" color="processing" class="cfg-cdp-badge">fuzzy_date</ATag>
          </template>
        </ASelect>

        <span v-if="!step.options.length" class="cfg-cdp-dead-end">
          {{ t('chrono_density_path_dead_end') }}
        </span>
      </div>

      <AAlert
        v-if="modelValue.length && !isValid"
        type="warning"
        :message="t('chrono_density_path_needs_fuzzy_date')"
        :closable="false"
        show-icon
      />
    </template>
  </div>
</template>

<script setup>
import { CloseOutlined, LoadingOutlined, RightOutlined, ShareAltOutlined } from '@ant-design/icons-vue'
import { ref, computed, onMounted } from 'vue'
import { Button as AButton, Select as ASelect, Alert as AAlert, Tag as ATag } from 'ant-design-vue'
import { useI18n } from '@/i18n'
import { api }      from '@/api'

const props = defineProps({
  tb:              { type: String, required: true },
  modelValue:      { type: Array,  default: () => [] },
  fuzzyDateTables: { type: Array,  default: () => [] },
})
const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()

const loading   = ref(false)
const relations = ref([])

async function load() {
  loading.value = true
  try {
    const res = await api.get('/api/config/relations')
    relations.value = res.data ?? []
  } catch { /* ignore */ } finally {
    loading.value = false
  }
}
onMounted(load)

const tableLabels = computed(() => {
  const map = {}
  for (const r of relations.value) {
    map[r.from_tb] = r.from_label
    map[r.to_tb]   = r.to_label
  }
  return map
})

// Direct FK children of a table (from_tb -> to_tb === parentTb), deduped,
// self-relations excluded — no fuzzy_date filter (see design note: an
// intermediate hop may be a bridge with no chrono data of its own).
function childrenOf(parentTb) {
  const seen = new Set()
  const result = []
  for (const r of relations.value) {
    if (r.to_tb === parentTb && r.from_tb !== parentTb && !seen.has(r.from_tb)) {
      seen.add(r.from_tb)
      result.push(r.from_tb)
    }
  }
  return result
}

// One select per configured hop, plus a trailing empty one to extend the
// chain. A step's options exclude every table already used earlier in the
// chain (root included) to prevent cycles, but keep the step's own current
// value visible even though it counts as "used" by itself.
const steps = computed(() => {
  const result = []
  const usedBefore = new Set([props.tb])
  let parent = props.tb
  for (let i = 0; i <= props.modelValue.length; i++) {
    const value   = props.modelValue[i] ?? null
    const options = childrenOf(parent).filter(t => t === value || !usedBefore.has(t))
    result.push({ parent, options, value })
    if (value == null) break
    usedBefore.add(value)
    parent = value
  }
  return result
})

function selectAt(i, value) {
  const newPath = props.modelValue.slice(0, i)
  if (value) newPath.push(value)
  emit('update:modelValue', newPath)
}

// Only the last table in the chain needs fuzzy_date active — mirrors the
// server-side check in Config::validateChronoDensityPath(). An empty path
// is always valid (falls back to the automatic 1-hop behaviour).
const isValid = computed(() => {
  if (!props.modelValue.length) return true
  const last = props.modelValue[props.modelValue.length - 1]
  return props.fuzzyDateTables.includes(last)
})
</script>

<style scoped>
.cfg-cdp-section {
  border-top: 1px solid var(--p-content-border-color);
  padding-top: 0.875rem;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}
.cfg-cdp-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.cfg-cdp-title {
  font-size: 0.78rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--p-text-muted-color);
  display: flex;
  align-items: center;
  gap: 0.35rem;
}
.cfg-cdp-loading {
  text-align: center;
  color: var(--p-text-muted-color);
  padding: 0.5rem;
}
.cfg-cdp-breadcrumb {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.3rem;
  font-size: 0.82rem;
  color: var(--p-text-color);
}
.cfg-cdp-breadcrumb i { color: var(--p-text-muted-color); font-size: 0.7rem; }
.cfg-cdp-step {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  max-width: 320px;
}
.cfg-cdp-step :deep(.ant-select) { flex: 1; }
.cfg-cdp-badge { margin-left: auto; font-size: 0.6rem; }
.cfg-cdp-dead-end {
  font-size: 0.72rem;
  color: var(--p-text-muted-color);
  font-style: italic;
}
</style>
