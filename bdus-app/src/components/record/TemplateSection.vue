<template>
  <fieldset class="template-section record-section">
    <legend
      :class="{ 'legend-collapsible': section.collapsible }"
      @click="toggleCollapse"
    >
      {{ section.label }}
      <span v-if="section.collapsible" class="collapse-icon">
        <component :is="isCollapsed ? RightOutlined : DownOutlined" />
      </span>
    </legend>

    <div v-show="!isCollapsed" class="section-content">

      <!-- Accordion section -->
      <div v-if="isAccordion" class="accordion-panels">
        <div
          v-for="(panel, pi) in section.content"
          :key="pi"
          class="accordion-panel"
          :class="{ 'accordion-panel--open': openPanels[pi] }"
        >
          <div class="accordion-panel-header" @click="togglePanel(pi)">
            <span class="accordion-panel-label">{{ panel.label }}</span>
            <component
              :is="openPanels[pi] ? DownOutlined : RightOutlined"
              class="accordion-panel-icon"
            />
          </div>
          <div v-show="openPanels[pi]" class="accordion-panel-body">
            <div class="template-fields">
              <div
                v-for="item in (panel.fields ?? [])"
                :key="item.field"
                class="template-field-cell"
                :style="{ gridColumn: 'span ' + widthToSpan(item.width ?? '1/1') }"
              >
                <FieldDisplay
                  v-if="mode === 'read'"
                  :schema="fieldSchema(item.field)"
                  :value="record.core[item.field]"
                />
                <FieldEditor
                  v-else
                  :schema="fieldSchema(item.field)"
                  :tb="tb"
                  :modelValue="editData.core[item.field]"
                  @update:modelValue="v => editData.core[item.field] = v"
                />
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Plugin section -->
      <PluginSection
        v-else-if="isPlugin"
        :schema="schema.plugins[section.plugin]"
        :plugin="record.plugins[section.plugin]"
        :mode="mode"
        :edit-rows="editData.plugins[section.plugin] ?? []"
        :column-layout="section.content"
        @update:edit-rows="v => editData.plugins[section.plugin] = v"
      />

      <!-- Core fields section (core fields and plugin blocks, in declared order) -->
      <div v-else class="template-fields">
        <template v-for="(block, bi) in blocks" :key="bi">
          <div
            v-if="block.plugin"
            class="template-field-cell template-plugin-block"
            style="grid-column: span 12"
          >
            <PluginSection
              v-if="schema.plugins?.[block.plugin]"
              :schema="schema.plugins[block.plugin]"
              :plugin="record.plugins[block.plugin]"
              :mode="mode"
              :edit-rows="editData.plugins[block.plugin] ?? []"
              :column-layout="block.layout"
              @update:edit-rows="v => editData.plugins[block.plugin] = v"
            />
          </div>
          <div
            v-else
            class="template-field-cell"
            :style="{ gridColumn: 'span ' + widthToSpan(block.item.width ?? '1/1') }"
          >
            <FieldDisplay
              v-if="mode === 'read'"
              :schema="fieldSchema(block.item.field)"
              :value="record.core[block.item.field]"
            />
            <FieldEditor
              v-else
              :schema="fieldSchema(block.item.field)"
              :tb="tb"
              :modelValue="editData.core[block.item.field]"
              @update:modelValue="v => editData.core[block.item.field] = v"
            />
          </div>
        </template>
      </div>

    </div>
  </fieldset>
</template>

<script setup>
import { DownOutlined, RightOutlined } from '@ant-design/icons-vue'
import { ref, computed } from 'vue'
import FieldDisplay  from './FieldDisplay.vue'
import FieldEditor   from './FieldEditor.vue'
import PluginSection from './PluginSection.vue'
import { widthToSpan } from './templateUtils.js'

const props = defineProps({
  /** One section object from template.sections */
  section:  { type: Object, required: true },
  /** 'read' | 'edit' */
  mode:     { type: String, default: 'read' },
  /** Full table id (e.g. 'items'), passed to FieldEditor */
  tb:       { type: String, required: true },
  /** Full record data from getRecord() API */
  record:   { type: Object, required: true },
  /** record.schema (fields list and plugin schemas) */
  schema:   { type: Object, required: true },
  /** Reactive editData { core, plugins } object — mutated directly */
  editData: { type: Object, required: true },
})

const isPlugin    = computed(() => !!props.section.plugin)

/**
 * Core-section content may mix core fields ({ field, width }) and plugin
 * fields ({ plugin, field, width }; field "*" = all plugin fields).
 * Consecutive items of the same plugin are rendered as one plugin table.
 */
const blocks = computed(() => {
  const out = []
  for (const item of props.section.content ?? []) {
    if (!item.plugin) {
      out.push({ item })
      continue
    }
    const last = out[out.length - 1]
    let block = last && last.plugin === item.plugin ? last : null
    if (!block) {
      block = { plugin: item.plugin, layout: [] }
      out.push(block)
    }
    if (item.field === '*' || block.layout === null) {
      block.layout = null
    } else {
      block.layout.push({ field: item.field, width: item.width ?? '1/1' })
    }
  }
  return out
})
const isAccordion = computed(() => props.section.type === 'accordion')

// Collapsed state for the outer fieldset — initialised from template definition
const isCollapsed = ref(!!props.section.collapsed)

function toggleCollapse() {
  if (props.section.collapsible) {
    isCollapsed.value = !isCollapsed.value
  }
}

// Per-panel open state for accordion sections — initialised from panel.open (default: open)
const openPanels = ref(
  (props.section.content ?? []).map(panel => panel.open !== false)
)

function togglePanel(pi) {
  openPanels.value[pi] = !openPanels.value[pi]
}

/**
 * Find the field schema by name, or return a minimal fallback so the
 * component never crashes on an unknown field.
 */
function fieldSchema(name) {
  return (props.schema.fields ?? []).find(f => f.name === name)
    ?? { name, label: name, type: 'text' }
}
</script>

<style scoped>
/* .record-section base style (border, padding, radius) is in main.css */

.legend-collapsible {
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 0.4rem;
  user-select: none;
}

.collapse-icon {
  margin-left: auto;
  font-size: 0.75rem;
  opacity: 0.7;
}

.section-content {
  padding-top: 0.5rem;
}

.template-fields {
  display: grid;
  grid-template-columns: repeat(12, 1fr);
  gap: 0 1.5rem;
}

.template-field-cell {
  min-width: 0;
  box-sizing: border-box;
}

/* ── Accordion ── */
.accordion-panels {
  display: flex;
  flex-direction: column;
  gap: 0;
  border: 1px solid var(--p-content-border-color);
  border-radius: 6px;
  overflow: hidden;
}

.accordion-panel {
  border-bottom: 1px solid var(--p-content-border-color);
}
.accordion-panel:last-child {
  border-bottom: none;
}

.accordion-panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.55rem 0.85rem;
  cursor: pointer;
  user-select: none;
  background: color-mix(in srgb, var(--p-primary-color) 4%, transparent);
  transition: background 0.12s;
}
.accordion-panel-header:hover {
  background: color-mix(in srgb, var(--p-primary-color) 9%, transparent);
}
.accordion-panel--open .accordion-panel-header {
  background: color-mix(in srgb, var(--p-primary-color) 7%, transparent);
}

.accordion-panel-label {
  font-size: 0.875rem;
  font-weight: 600;
}

.accordion-panel-icon {
  font-size: 0.75rem;
  opacity: 0.6;
}

.accordion-panel-body {
  padding: 0.75rem 0.85rem;
}
</style>
