<template>
  <div class="gpg">
    <AAlert v-if="error" type="error" :message="error" show-icon closable class="gpg-alert" @close="$emit('clear-error')" />

    <div class="gpg-scroll">
      <table class="gpg-table">
        <thead>
          <tr>
            <th class="gpg-tb">{{ t('grp_table') }}</th>
            <th v-for="a in ACTIONS" :key="a" :title="t(`grp_action_${a}`)">
              <span class="gpg-col">{{ t(`grp_action_${a}`) }}</span>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="tb in tables" :key="tb.name">
            <td class="gpg-tb"><strong>{{ tb.label || tb.name }}</strong> <small>{{ tb.name }}</small></td>

            <!-- Create: a switch, no row dimension -->
            <td>
              <ASwitch
                size="small"
                :checked="cells[tb.name].create.mode === 'all'"
                :aria-label="`${tb.name} ${t('grp_action_create')}`"
                @change="on => setMode(tb.name, 'create', on ? 'all' : 'off')"
              />
            </td>

            <!-- Read / update / delete: off, all rows, or the rows a filter selects -->
            <td v-for="a in ROW_ACTIONS" :key="a" class="gpg-cell">
              <ASegmented
                :key="`${tb.name}-${a}-${revision}`"
                size="small"
                :value="cells[tb.name][a].mode"
                :options="modeOptions"
                :aria-label="`${tb.name} ${t(`grp_action_${a}`)}`"
                @change="m => setMode(tb.name, a, m)"
              />
              <AButton
                v-if="cells[tb.name][a].mode === 'filter'"
                type="text"
                size="small"
                :title="t('grp_edit_filter')"
                @click="editFilter(tb.name, a)"
              ><template #icon><FilterOutlined /></template></AButton>
            </td>
          </tr>
          <tr v-if="!tables.length">
            <td colspan="5" class="gpg-empty">{{ t('grp_no_tables') }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="gpg-actions">
      <span v-if="dirty" class="gpg-dirty">{{ t('grp_unsaved') }}</span>
      <AButton :disabled="!dirty || saving" @click="reset">{{ t('grp_discard') }}</AButton>
      <AButton type="primary" :disabled="!dirty" :loading="saving" @click="$emit('save', toRules())">
        {{ t('grp_save_permissions') }}
      </AButton>
    </div>

    <RuleFilterModal
      v-if="editing"
      v-model:open="filterOpen"
      :title="t('grp_filter_for', editing.table, t(`grp_action_${editing.action}`))"
      :table="editing.table"
      :filter="cells[editing.table][editing.action].filter"
      @apply="applyFilter"
    />
  </div>
</template>

<script setup>
// The permissions grid of a group: one row per data table, one column per action
// (create / read / update / delete). Create is on/off; the others are off, all
// rows, or "filter" — the rows a backend filter selects. The grid keeps its own
// copy of the rules and hands the whole set back on save (PUT .../rules).
import { computed, reactive, ref, watch } from 'vue'
import { FilterOutlined } from '@ant-design/icons-vue'
import { Alert as AAlert, Button as AButton, Segmented as ASegmented, Switch as ASwitch } from 'ant-design-vue'
import { useI18n } from '@/i18n'
import RuleFilterModal from './RuleFilterModal.vue'

const ACTIONS     = ['create', 'read', 'update', 'delete']
const ROW_ACTIONS = ['read', 'update', 'delete']

const props = defineProps({
  tables: { type: Array, default: () => [] },   // [{ name, label }]
  rules:  { type: Array, default: () => [] },   // [{ table, action, filter }]
  saving: { type: Boolean, default: false },
  error:  { type: String, default: '' },
})
defineEmits(['save', 'clear-error'])

const { t } = useI18n()

const modeOptions = computed(() => [
  { value: 'off',    label: t('grp_mode_off') },
  { value: 'all',    label: t('grp_mode_all') },
  { value: 'filter', label: t('grp_mode_filter') },
])

// cells[table][action] = { mode: 'off' | 'all' | 'filter', filter: object|null }
const cells = reactive({})
const baseline = ref('')

function build() {
  const next = {}
  for (const tb of props.tables) {
    next[tb.name] = Object.fromEntries(ACTIONS.map(a => [a, { mode: 'off', filter: null }]))
  }
  for (const r of props.rules) {
    const cell = next[r.table]?.[r.action]
    if (!cell) continue            // a rule on a table the grid does not list is kept out of the way
    const hasFilter = r.filter && typeof r.filter === 'object' && !Array.isArray(r.filter) && Object.keys(r.filter).length > 0
    cell.mode   = hasFilter ? 'filter' : 'all'
    cell.filter = hasFilter ? r.filter : null
  }
  for (const k of Object.keys(cells)) delete cells[k]
  Object.assign(cells, next)
  baseline.value = signature()
}

/** The rules the grid currently describes. */
function toRules() {
  const out = []
  for (const tb of props.tables) {
    for (const a of ACTIONS) {
      const c = cells[tb.name]?.[a]
      if (!c || c.mode === 'off') continue
      out.push({ table: tb.name, action: a, filter: c.mode === 'filter' ? c.filter : null })
    }
  }
  return out
}

const signature = () => JSON.stringify(toRules())
const dirty = computed(() => signature() !== baseline.value)

function reset() { build() }

// ── Changing a cell ───────────────────────────────────────────────────────
const editing    = ref(null)    // { table, action } whose filter is being edited
const filterOpen = ref(false)
// A cancelled "filter" choice must not leave the segmented control showing it: re-render the cells.
const revision   = ref(0)
watch(filterOpen, open => { if (!open) revision.value++ })

function setMode(table, action, mode) {
  const cell = cells[table][action]
  if (mode === 'filter') {
    // A filter is needed to be "filter": open the editor; until it is applied the cell stays as it was.
    editing.value = { table, action }
    filterOpen.value = true
    return
  }
  cell.mode = mode
  if (mode !== 'filter') cell.filter = null
}

function editFilter(table, action) {
  editing.value = { table, action }
  filterOpen.value = true
}

function applyFilter(filter) {
  const { table, action } = editing.value
  cells[table][action].mode   = 'filter'
  cells[table][action].filter = filter
}

watch(() => [props.tables, props.rules], build, { immediate: true })
</script>

<style scoped>
.gpg { display: flex; flex-direction: column; gap: 0.75rem; }
.gpg-alert { margin: 0; }
.gpg-scroll { overflow-x: auto; }
.gpg-table { width: 100%; border-collapse: collapse; min-width: 560px; }
.gpg-table th, .gpg-table td {
  padding: 0.45rem 0.6rem;
  border-bottom: 1px solid var(--p-content-border-color);
  text-align: center;
  vertical-align: middle;
}
.gpg-table th {
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--p-text-muted-color);
  font-weight: 600;
}
.gpg-table .gpg-tb { text-align: left; }
.gpg-tb small { color: var(--p-text-muted-color); margin-left: 0.35rem; }
.gpg-cell { white-space: nowrap; }
.gpg-empty { color: var(--p-text-muted-color); }
.gpg-actions { display: flex; justify-content: flex-end; align-items: center; gap: 0.5rem; }
.gpg-dirty { margin-right: auto; color: var(--p-text-muted-color); font-size: 0.85rem; }
</style>
