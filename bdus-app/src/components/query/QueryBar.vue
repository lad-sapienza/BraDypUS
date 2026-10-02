<template>
  <div class="search-area">

    <!-- Row 1: the text box, the Filters button, and (slot) the result actions -->
    <div class="search-bar" :class="{ 'is-compact': compact }">
      <!-- Live search: runs as you type (debounced); Enter runs it at once -->
      <AInput
        v-model:value="query.fastSearch"
        :placeholder="query.inputPlaceholder"
        :disabled="query.inputLocked"
        :title="query.inputLocked && !query.filtersOpen ? t('qb_remove_to_type') : undefined"
        allow-clear
        class="search-input-wrap"
        @change="query.onFastInput"
        @press-enter="query.onFastEnter"
      >
        <template #prefix><SearchOutlined /></template>
      </AInput>

      <!-- Filters: the builder and SQL live behind this one button -->
      <ABadge :count="query.appliedCount" :offset="[-2, 2]" :number-style="{ backgroundColor: 'var(--p-primary-color)' }">
        <AButton
          :type="query.filtersOpen ? 'primary' : 'default'"
          :size="compact ? 'middle' : 'small'"
          :title="t('qb_filters')"
          :aria-expanded="query.filtersOpen"
          class="filters-btn"
          @click="query.toggleFilters"
        >
          <ControlOutlined />
          <span v-if="!compact">{{ t('qb_filters') }}</span>
        </AButton>
      </ABadge>

      <slot name="actions" />
    </div>

    <!-- What is applied, as removable chips (a group is one chip) -->
    <div v-if="query.chips.length" class="query-chips">
      <template v-for="(chip, k) in query.chips" :key="chip.key">
        <span v-if="k > 0" class="chip-join">{{ query.chipJoin }}</span>
        <ATag
          color="warning"
          closable
          class="query-chip"
          @close="e => { e.preventDefault(); query.removeChip(chip) }"
        >{{ chip.label }}</ATag>
      </template>
      <AButton type="link" size="small" @click="query.reset">{{ t('qb_remove_filters') }}</AButton>
    </div>

    <!-- Filters panel: builder or SQL (alternatives to the text box) -->
    <Transition name="slide">
      <div v-if="query.filtersOpen" class="search-panel">
        <ASegmented :value="query.openPanel" :options="query.filtersTabs" size="small" class="filters-tabs" @change="query.setTab" />

        <template v-if="query.openPanel === 'advanced'">
          <div v-if="config.loading" class="adv-loading">
            <ASpin size="small" />
          </div>
          <!-- Query builder: a tree of groups and conditions -->
          <FilterBuilder
            v-else
            :tree="query.advTree"
            :fields="config.fields"
            :operators="config.operatorOptions"
          />
        </template>

        <template v-else>
          <label class="expert-label">{{ t('sql_expert_search') }} — WHERE …</label>
          <p class="expert-hint">{{ t('sql_expert_search_hint') }}</p>
          <ATextarea
            v-model:value="query.expertQuery"
            :rows="3"
            class="expert-textarea"
          />
        </template>

        <div class="search-panel-actions">
          <AButton type="primary" size="small" @click="query.apply">
            <SearchOutlined /> {{ t('qb_apply') }}
          </AButton>
          <AButton size="small" @click="query.closeFilters">{{ t('qb_close') }}</AButton>
        </div>
      </div>
    </Transition>

  </div>
</template>

<script setup>
// The record list's query bar. It renders the state of useRecordQuery (passed
// in as a reactive object, so its refs read as plain values here) and calls its
// actions; it owns no search logic of its own.
import { ControlOutlined, SearchOutlined } from '@ant-design/icons-vue'
import {
  Input, Tag as ATag, Spin as ASpin, Button as AButton, Badge as ABadge, Segmented as ASegmented,
} from 'ant-design-vue'
import { useI18n } from '@/i18n'
import FilterBuilder from './FilterBuilder.vue'

defineProps({
  query:   { type: Object,  required: true },   // reactive(useRecordQuery(...))
  config:  { type: Object,  required: true },   // reactive(useSearchConfig(...))
  compact: { type: Boolean, default: false },   // small screen: icon-only Filters button
})

const AInput    = Input
const ATextarea = Input.TextArea
const { t } = useI18n()
</script>

<style scoped>
.search-area {
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  gap: 0;
  border: 1px solid var(--p-content-border-color);
  border-radius: 6px;
  overflow: hidden;
}

.search-bar {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.5rem 0.75rem;
  background: var(--bdus-surface);
}
.search-bar.is-compact { flex-wrap: wrap; }

.search-input-wrap { flex: 1; min-width: 0; }
.filters-btn { display: inline-flex; align-items: center; gap: 0.35rem; }

/* ── Applied filters ─────────────────────────────────────── */
.query-chips {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.35rem;
  padding: 0 0.75rem 0.5rem;
  background: var(--bdus-surface);
}
.query-chip { margin-inline-end: 0; max-width: 100%; white-space: normal; overflow-wrap: anywhere; }
.chip-join { font-size: 0.75rem; opacity: 0.7; }

/* ── Filters panel ───────────────────────────────────────── */
.search-panel {
  padding: 0.75rem 1rem;
  border-top: 1px solid var(--p-content-border-color);
  background: var(--bdus-bg);
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  /* A nested query tree can run long: leave room for it and Apply below. */
  max-height: min(70vh, 560px);
  overflow-y: auto;
}
.filters-tabs { align-self: flex-start; }
.adv-loading { display: flex; justify-content: center; padding: 1rem; }

.expert-label {
  font-size: 0.82rem;
  font-weight: 500;
  color: var(--p-text-muted-color);
}
.expert-textarea { font-family: monospace; font-size: 0.85rem; }
.expert-hint {
  font-size: 0.78rem;
  color: var(--p-text-muted-color);
  margin: 0.15rem 0 0.5rem;
}
.search-panel-actions { display: flex; gap: 0.5rem; flex-shrink: 0; }

/* ── Slide transition ────────────────────────────────────── */
.slide-enter-active, .slide-leave-active {
  transition: max-height 0.22s ease, opacity 0.22s ease;
  overflow: hidden;
}
.slide-enter-from, .slide-leave-to { max-height: 0; opacity: 0; }
.slide-enter-to, .slide-leave-from { max-height: 380px; }
</style>
