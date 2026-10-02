<template>
  <div class="col-toggler-list">
    <div
      v-for="col in prefs.available"
      :key="col.name"
      class="col-toggler-item"
      @click="prefs.toggle(col.name)"
    >
      <component :is="prefs.visible.includes(col.name) ? CheckSquareOutlined : BorderOutlined" />
      <span>{{ col.label }}</span>
    </div>
  </div>
  <div class="col-toggler-actions">
    <AButton type="text" size="small" @click="prefs.selectAll">{{ t('select_all') }}</AButton>
    <AButton type="text" size="small" @click="prefs.reset">{{ t('reset') }}</AButton>
  </div>
</template>

<script setup>
// The checklist of columns the list can show — the same markup in the popover
// (icon row) and in the dialog (compact menu).
import { BorderOutlined, CheckSquareOutlined } from '@ant-design/icons-vue'
import { Button as AButton } from 'ant-design-vue'
import { useI18n } from '@/i18n'

defineProps({
  prefs: { type: Object, required: true },   // reactive(useColumnPrefs(...))
})

const { t } = useI18n()
</script>

<style scoped>
.col-toggler-list { max-height: 260px; overflow-y: auto; padding: 0.25rem 0; }
.col-toggler-item {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.35rem 0.75rem;
  cursor: pointer;
  font-size: 0.875rem;
  border-radius: 4px;
  transition: background 0.12s;
}
.col-toggler-item:hover { background: var(--p-content-hover-background); }
.col-toggler-item .anticon { color: var(--p-primary-color); font-size: 0.95rem; }
.col-toggler-actions {
  display: flex;
  gap: 0.25rem;
  padding: 0.4rem 0.5rem 0.25rem;
  border-top: 1px solid var(--p-content-border-color);
}
</style>
