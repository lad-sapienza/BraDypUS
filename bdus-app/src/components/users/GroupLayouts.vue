<template>
  <div class="gl">
    <AAlert type="info" show-icon :message="t('grp_layouts_not_security')" class="gl-note" />
    <small class="gl-hint">{{ t('grp_layouts_hint') }}</small>

    <div v-for="(row, i) in rows" :key="i" class="gl-row">
      <ASelect
        v-model:value="row.table"
        class="gl-table"
        :options="tableOptions"
        :placeholder="t('grp_layout_table')"
        :disabled="readonly"
        @change="onTable(row)"
      />
      <ASelect
        v-model:value="row.templates"
        mode="multiple"
        class="gl-templates"
        :options="optionsFor(row.table)"
        :placeholder="t('grp_layout_templates')"
        :disabled="readonly || !row.table"
      />
      <AButton v-if="!readonly" type="text" danger :title="t('delete')" @click="rows.splice(i, 1)">
        <template #icon><DeleteOutlined /></template>
      </AButton>
    </div>

    <div v-if="!readonly" class="gl-actions">
      <AButton size="small" @click="rows.push({ table: null, templates: [] })">
        <template #icon><PlusOutlined /></template>
        {{ t('grp_layout_add') }}
      </AButton>
      <AButton type="primary" size="small" :loading="saving" :disabled="!dirty" @click="save">
        {{ t('save') }}
      </AButton>
    </div>
    <AAlert v-if="error" type="error" show-icon :message="error" />
  </div>
</template>

<script setup>
// "Record layouts": per table, the templates the members of the group must use.
// An interface convenience, not a permission.
import { computed, reactive, ref, watch } from 'vue'
import { DeleteOutlined, PlusOutlined } from '@ant-design/icons-vue'
import { Alert as AAlert, Button as AButton, Select as ASelect } from 'ant-design-vue'
import { api as http } from '@/api'
import { useI18n } from '@/i18n'
import { layoutsToRows, rowsToLayouts } from '@/utils/groupLayouts'

const props = defineProps({
  tables:   { type: Array, default: () => [] },     // [{ name, label }]
  layouts:  { type: Object, default: null },        // {table: [template]} | null
  readonly: { type: Boolean, default: false },
  saving:   { type: Boolean, default: false },
  error:    { type: String, default: '' },
})
const emit = defineEmits(['save'])

const { t } = useI18n()
const rows = ref([])
const baseline = ref('{}')
const available = reactive({})                      // table → template names

const tableOptions = computed(() => props.tables.map(tb => ({ value: tb.name, label: tb.label || tb.name })))
const optionsFor = table => (available[table] ?? []).map(n => ({ value: n, label: n }))
const current = computed(() => rowsToLayouts(rows.value))
const dirty = computed(() => !current.value.error && JSON.stringify(current.value.layouts) !== baseline.value)

async function loadTemplates(table) {
  if (!table || available[table]) return
  try {
    // An admin is never restricted, so this lists every template of the table.
    available[table] = (await http.get(`/api/record/${table}/templates`)).templates ?? []
  } catch { available[table] = [] }
}
function onTable(row) { row.templates = []; loadTemplates(row.table) }

watch(() => props.layouts, v => {
  rows.value = layoutsToRows(v)
  baseline.value = JSON.stringify(rowsToLayouts(rows.value).layouts)
  rows.value.forEach(r => loadTemplates(r.table))
}, { immediate: true })

function save() {
  const parsed = current.value
  if (parsed.error) { emit('save', { error: t(parsed.error) }); return }
  emit('save', { layouts: parsed.layouts })
}
</script>

<style scoped>
.gl { display: flex; flex-direction: column; gap: 0.6rem; align-items: flex-start; }
.gl-note { width: 100%; }
.gl-hint { color: var(--p-text-muted-color); }
.gl-row { display: flex; gap: 0.5rem; width: 100%; align-items: center; flex-wrap: wrap; }
.gl-table { flex: 0 0 220px; }
.gl-templates { flex: 1; min-width: 200px; }
.gl-actions { display: flex; gap: 0.5rem; }
</style>
