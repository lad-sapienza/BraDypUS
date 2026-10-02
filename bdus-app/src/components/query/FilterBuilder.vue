<template>
  <div class="fb">
    <FilterGroupNode :node="tree" :path="[]" />
    <p v-if="countActive(tree) > 1" class="fb-formula">
      {{ t('qb_equivalent') }}: <code>{{ formulaText }}</code>
    </p>
  </div>
</template>

<script setup>
// The query builder: an editable tree of groups and conditions (see
// utils/filterTree.js). The tree is edited in place — the parent owns it and
// reads the filter from it; this component renders and mutates it.
import { computed, provide, toRef } from 'vue'
import { useI18n } from '@/i18n'
import { countActive, formula } from '@/utils/filterTree'
import FilterGroupNode from './FilterGroupNode.vue'
import { FILTER_BUILDER_KEY } from './filterBuilderContext'

const props = defineProps({
  tree:      { type: Object, required: true },
  fields:    { type: Array,  default: () => [] },   // [{ value, label, ref_tb?, ref_field? }]
  operators: { type: Array,  default: () => [] },   // [{ value, label }]
})

const { t } = useI18n()

provide(FILTER_BUILDER_KEY, {
  root:      toRef(props, 'tree'),
  fields:    toRef(props, 'fields'),
  operators: toRef(props, 'operators'),
})

const formulaText = computed(() => formula(props.tree, {
  fieldLabel:    fld => props.fields.find(f => f.value === fld)?.label ?? fld,
  operatorLabel: op  => (props.operators.find(o => o.value === op)?.label ?? op).toLowerCase(),
  and: t('qb_and'),
  or:  t('qb_or'),
}))
</script>

<style scoped>
/* The container the group nodes' narrow-layout rule measures. */
.fb { container: fb / inline-size; display: flex; flex-direction: column; gap: 0.6rem; min-width: 0; }
.fb-formula {
  margin: 0;
  padding: 0.4rem 0.6rem;
  border: 1px dashed var(--p-content-border-color);
  border-radius: 6px;
  font-size: 0.85rem;
  opacity: 0.85;
  overflow-wrap: anywhere;
}
.fb-formula code { font-size: 0.82rem; }
</style>
