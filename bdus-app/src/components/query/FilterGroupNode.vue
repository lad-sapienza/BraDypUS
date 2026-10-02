<template>
  <div class="fg" :class="groupClass">
    <div class="fg-head">
      <span class="fg-label">{{ nested ? t('qb_group_match') : t('qb_conditions_match') }}</span>
      <ASegmented
        :value="node.op"
        :options="opOptions"
        size="small"
        @change="v => setOperator(root, path, v)"
      />
      <span class="fg-spacer" />
      <template v-if="nested">
        <AButton type="text" size="small" :title="t('qb_ungroup')" :aria-label="t('qb_ungroup')" @click="ungroup(root, path)">
          <MenuFoldOutlined />
        </AButton>
        <AButton type="text" size="small" danger :title="t('qb_remove_group')" :aria-label="t('qb_remove_group')" @click="remove(root, path)">
          <DeleteOutlined />
        </AButton>
      </template>
    </div>

    <template v-for="(child, i) in node.c" :key="keyOf(child)">
      <FilterGroupNode v-if="child.t === 'g'" :node="child" :path="[...path, i]" />

      <div v-else class="fg-row">
        <span class="fg-join">{{ i > 0 ? (node.op === 'AND' ? t('qb_and') : t('qb_or')) : '' }}</span>

        <ASelect
          v-model:value="child.fld"
          :options="fields"
          :placeholder="t('adv_pick_field')"
          size="small"
          show-search
          :filter-option="filterOption"
          class="fg-field"
          @change="onFieldChange(child)"
        />
        <ASelect
          v-model:value="child.operator"
          :options="operators"
          size="small"
          class="fg-operator"
        />
        <AAutoComplete
          v-if="!NO_VALUE_OPS.includes(child.operator)"
          v-model:value="child.value"
          :options="(child._suggestions ?? []).map(s => ({ value: s }))"
          size="small"
          class="fg-value"
          @search="q => loadSuggestions(child, q)"
        />
        <span v-else class="fg-value" />

        <span class="fg-actions">
          <AButton
            type="text" size="small"
            :disabled="!canIndent(root, [...path, i])"
            :title="indentTitle(i)" :aria-label="indentTitle(i)"
            @click="indent(root, [...path, i])"
          ><MenuUnfoldOutlined /></AButton>
          <AButton
            type="text" size="small"
            :disabled="!canOutdent([...path, i])"
            :title="t('qb_outdent')" :aria-label="t('qb_outdent')"
            @click="outdent(root, [...path, i])"
          ><MenuFoldOutlined /></AButton>
          <AButton
            type="text" danger size="small"
            :disabled="onlyRow"
            :title="t('qb_remove_row')" :aria-label="t('qb_remove_row')"
            @click="remove(root, [...path, i])"
          ><MinusOutlined /></AButton>
        </span>
      </div>
    </template>

    <div class="fg-add">
      <AButton type="link" size="small" @click="addCondition(root, path)">
        <PlusOutlined /> {{ t('qb_add_condition') }}
      </AButton>
      <AButton v-if="canAddGroup(path)" type="link" size="small" @click="addGroup(root, path)">
        <PlusOutlined /> {{ t('qb_add_group') }}
      </AButton>
    </div>
  </div>
</template>

<script>
import { toRaw } from 'vue'

// Stable keys for children, so moving a row between groups keeps its
// autocomplete state instead of reusing another row's by position.
const ids = new WeakMap()
let nextId = 0
export function keyOf(node) {
  const raw = toRaw(node)
  if (!ids.has(raw)) ids.set(raw, ++nextId)
  return ids.get(raw)
}
</script>

<script setup>
import { computed, inject } from 'vue'
import { DeleteOutlined, MenuFoldOutlined, MenuUnfoldOutlined, MinusOutlined, PlusOutlined } from '@ant-design/icons-vue'
import { Button as AButton, Select as ASelect, AutoComplete as AAutoComplete, Segmented as ASegmented } from 'ant-design-vue'
import { useI18n } from '@/i18n'
import { api } from '@/api'
import {
  NO_VALUE_OPS, nodeAt, addCondition, addGroup, canAddGroup, setOperator, remove, ungroup,
  canIndent, indent, canOutdent, outdent,
} from '@/utils/filterTree'
import { FILTER_BUILDER_KEY } from './filterBuilderContext'

const props = defineProps({
  node: { type: Object, required: true },   // the group this node renders
  path: { type: Array,  default: () => [] }, // its position from the root
})

const { t } = useI18n()
const ctx = inject(FILTER_BUILDER_KEY)
const root = computed(() => ctx.root.value)
const fields = computed(() => ctx.fields.value)
const operators = computed(() => ctx.operators.value)

const nested = computed(() => props.path.length > 0)
const groupClass = computed(() => (nested.value ? ['nested', `d${Math.min(props.path.length, 3)}`] : []))
const opOptions = computed(() => [
  { value: 'AND', label: t('qb_all') },
  { value: 'OR',  label: t('qb_any') },
])

// A lone row can't be removed: the builder always keeps one to fill in.
const onlyRow = computed(() => root.value.c.length === 1 && root.value.c[0].t === 'c')

function indentTitle(i) {
  const prev = props.node.c[i - 1]
  return prev?.t === 'g' ? t('qb_move_into_group') : t('qb_group_up')
}

function filterOption(input, option) {
  return String(option?.label ?? '').toLowerCase().includes(String(input).toLowerCase())
}

function onFieldChange(leaf) {
  leaf.value = ''
  leaf._suggestions = null
}

// Autocomplete: distinct values of the chosen field that contain what was typed.
async function loadSuggestions(leaf, query) {
  if (!leaf.fld) return
  const [tb, fld] = leaf.fld.split(':')
  try {
    const res = await api.get(`/api/search/${tb}/values`, { fld })
    leaf._suggestions = (Array.isArray(res.values) ? res.values : [])
      .filter(v => v != null && v !== '' && String(v).toLowerCase().includes(query.toLowerCase()))
      .slice(0, 50)
  } catch {
    leaf._suggestions = []
  }
}
</script>

<style scoped>
.fg { display: flex; flex-direction: column; gap: 0.4rem; min-width: 0; }
.fg.nested {
  border: 1px solid var(--p-content-border-color);
  border-left: 3px solid var(--p-primary-color);
  border-radius: 6px;
  padding: 0.5rem;
  margin-left: 2rem;
  background: var(--bdus-surface);
}
.fg.nested.d2 { background: var(--bdus-bg); }
.fg.nested.d3 { border-left-color: var(--ant-color-text-tertiary, #999); }

.fg-head { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
.fg-label { font-size: 0.8rem; opacity: 0.7; }
.fg-spacer { flex: 1; }

.fg-row { display: flex; align-items: center; gap: 0.35rem; min-width: 0; }
.fg-join { width: 2rem; flex-shrink: 0; font-size: 0.75rem; opacity: 0.7; text-align: center; }
.fg-field { flex: 2; min-width: 0; }
.fg-operator { width: 8.5rem; flex-shrink: 0; }
.fg-value { flex: 1.5; min-width: 0; }
.fg-actions { display: inline-flex; flex-shrink: 0; }

.fg-add { display: flex; gap: 0.75rem; padding-left: 2rem; }
.fg.nested > .fg-add { padding-left: 0; }

/* Narrow: the row wraps — field, operator and actions on top, value below. */
@container fb (max-width: 560px) {
  .fg-row { flex-wrap: wrap; row-gap: 0.25rem; }
  .fg-join { width: 1.4rem; }
  .fg-field, .fg-operator { flex: 1 1 28%; width: auto; }
  .fg-actions { order: 3; }
  .fg-value { order: 4; flex: 1 1 100%; margin-left: 1.75rem; }
  .fg.nested { margin-left: 0.75rem; padding: 0.4rem; }
  .fg-add { padding-left: 0.75rem; }
}
</style>
