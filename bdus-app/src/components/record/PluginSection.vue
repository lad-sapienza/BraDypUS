<template>
  <fieldset class="plugin-section record-section" v-if="hasContent">
    <legend>{{ schema?.label ?? plugin.metadata?.tb_label }}</legend>

    <!-- Read mode: table -->
    <template v-if="mode === 'read'">
      <div v-if="!dataRows.length" class="plugin-empty">—</div>
      <div v-else class="plugin-table-wrap">
        <table class="plugin-table">
          <thead>
            <tr>
              <th v-for="col in visibleFields" :key="col.name">{{ col.label }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, idx) in dataRows" :key="idx">
              <td v-for="col in visibleFields" :key="col.name">
                <FieldDisplay :schema="col" :value="row[col.name]" />
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- Edit mode: editable rows -->
    <template v-else>
      <div class="plugin-edit-rows">
        <div
          v-for="(row, idx) in editRows.filter(r => !r._delete)"
          :key="row._uid"
          class="plugin-edit-row"
        >
          <div class="plugin-row-fields" :class="{ 'has-layout': columnLayout }">
            <div
              v-for="col in editableFields"
              :key="col.name"
              :style="columnLayout ? { gridColumn: 'span ' + (columnWidthMap[col.name] ?? 12) } : {}"
            >
              <FieldEditor
                :schema="col"
                :tb="plugin.metadata.tb_id"
                :modelValue="row.fields[col.name]"
                @update:modelValue="v => row.fields[col.name] = v"
              />
            </div>
          </div>
          <Button
            icon="pi pi-trash"
            severity="danger"
            text
            size="small"
            :title="t('remove_row')"
            @click="removeRow(idx)"
          />
        </div>
      </div>

      <Button
        :label="t('add_row')"
        icon="pi pi-plus"
        size="small"
        severity="secondary"
        text
        class="add-row-btn"
        @click="addRow"
      />
    </template>
  </fieldset>
</template>

<script setup>
import { computed } from 'vue'
import Button      from 'primevue/button'
import FieldDisplay from './FieldDisplay.vue'
import FieldEditor  from './FieldEditor.vue'
import { useI18n }  from '@/i18n'
import { widthToSpan } from './templateUtils.js'

const { t } = useI18n()

const props = defineProps({
  /** Plugin schema from getRecord() → schema.plugins[tb] */
  schema:   { type: Object, default: null },
  /** Plugin data from getRecord() → plugins[tb] = { metadata, data } */
  plugin:   { type: Object, required: true },
  /** 'read' | 'edit' */
  mode:     { type: String, default: 'read' },
  /** Reactive edit rows array (only used in edit mode) */
  editRows: { type: Array, default: () => [] },
  /**
   * Optional column layout from a template section: array of { field, width? }.
   * When provided, only listed fields are shown (in declared order).
   */
  columnLayout: { type: Array, default: null },
})
const emit = defineEmits(['update:editRows'])

// ── Fields ─────────────────────────────────────────────────────

/** Map of field name → grid column span number, built from columnLayout (if any). */
const columnWidthMap = computed(() => {
  if (!props.columnLayout) return {}
  const map = {}
  for (const item of props.columnLayout) {
    map[item.field] = widthToSpan(item.width ?? '1/1')
  }
  return map
})

const visibleFields = computed(() => {
  const allFields = (props.schema?.fields ?? []).filter(
    f => !f.hide && !['id', 'table_link', 'id_link', 'sort'].includes(f.name)
  )
  if (!props.columnLayout) {
    return allFields
  }
  // Respect order and filter from columnLayout — skip fields not in schema
  const byName = Object.fromEntries(allFields.map(f => [f.name, f]))
  return props.columnLayout
    .filter(item => item.field in byName)
    .map(item => byName[item.field])
})

const editableFields = computed(() =>
  visibleFields.value.filter(f => !f.readonly && !f.disabled)
)

// ── Data rows (read mode) ──────────────────────────────────────
const dataRows = computed(() => {
  const data = props.plugin?.data
  if (!data) return []
  return Array.isArray(data) ? data : Object.values(data)
})

const hasContent = computed(() =>
  props.mode === 'edit' || dataRows.value.length > 0
)

// ── Edit rows management ───────────────────────────────────────
let _uid = 0

function addRow() {
  const blank = { _uid: _uid++, _delete: false, _isNew: true, fields: {} }
  editableFields.value.forEach(f => { blank.fields[f.name] = f.def_value ?? null })
  emit('update:editRows', [...props.editRows, blank])
}

function removeRow(idx) {
  const rows = [...props.editRows]
  const active = rows.filter(r => !r._delete)
  const row = active[idx]
  if (row._isNew) {
    // New rows are fully removed
    emit('update:editRows', rows.filter(r => r._uid !== row._uid))
  } else {
    // Existing rows are marked for deletion
    row._delete = true
    emit('update:editRows', rows)
  }
}
</script>

<style scoped>
.plugin-section { margin-top: 1.25rem; }

.plugin-empty {
  color: var(--p-text-muted-color);
  font-style: italic;
  padding: 0.4rem 0;
}

.plugin-table-wrap { overflow-x: auto; }

.plugin-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.85rem;
}
.plugin-table th {
  text-align: left;
  font-size: 0.72rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--p-text-muted-color);
  padding: 0.3rem 0.5rem;
  border-bottom: 2px solid var(--p-content-border-color);
}
.plugin-table td {
  padding: 0.25rem 0.5rem;
  border-bottom: 1px solid var(--p-content-border-color);
  vertical-align: top;
}
/* In plugin table, suppress FieldDisplay border/padding */
.plugin-table td :deep(.field-display) {
  border: none;
  padding: 0;
}
.plugin-table td :deep(.field-label) { display: none; }

/* Edit rows */
.plugin-edit-row {
  display: flex;
  gap: 0.5rem;
  align-items: flex-start;
  padding: 0.5rem 0;
  border-bottom: 1px solid var(--p-content-border-color);
}
.plugin-row-fields {
  flex: 1;
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 0.5rem;
}
.plugin-row-fields.has-layout {
  display: grid;
  grid-template-columns: repeat(12, 1fr);
  gap: 0 0.5rem;
}
.add-row-btn { margin-top: 0.5rem; }
</style>
