<template>
  <fieldset class="template-section record-section">
    <legend
      :class="{ 'legend-collapsible': section.collapsible }"
      @click="toggleCollapse"
    >
      {{ section.label }}
      <span v-if="section.collapsible" class="collapse-icon">
        <i :class="isCollapsed ? 'pi pi-chevron-right' : 'pi pi-chevron-down'" />
      </span>
    </legend>

    <div v-show="!isCollapsed" class="section-content">

      <!-- Plugin section -->
      <PluginSection
        v-if="isPlugin"
        :schema="schema.plugins[section.plugin]"
        :plugin="record.plugins[section.plugin]"
        :mode="mode"
        :edit-rows="editData.plugins[section.plugin] ?? []"
        :column-layout="section.content"
        @update:edit-rows="v => editData.plugins[section.plugin] = v"
      />

      <!-- Core fields section -->
      <div v-else class="template-fields">
        <div
          v-for="item in section.content"
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
  </fieldset>
</template>

<script setup>
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

// Detect whether this is a plugin section
const isPlugin = computed(() => !!props.section.plugin)

// Collapsed state — initialised from template definition
const isCollapsed = ref(!!props.section.collapsed)

function toggleCollapse() {
  if (props.section.collapsible) {
    isCollapsed.value = !isCollapsed.value
  }
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
</style>
