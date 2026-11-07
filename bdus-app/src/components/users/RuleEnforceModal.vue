<template>
  <AModal
    :open="open"
    :title="title"
    :width="640"
    :ok-text="t('grp_apply_filter')"
    :cancel-text="t('cancel')"
    destroy-on-close
    @ok="apply"
    @cancel="$emit('update:open', false)"
  >
    <p class="rem-hint">{{ t('grp_enforce_hint') }}</p>
    <AAlert v-if="error" type="error" :message="error" show-icon :closable="false" class="rem-error" />

    <div v-for="(row, i) in rows" :key="i" class="rem-row">
      <AInput v-model:value="row.field" class="rem-field" :placeholder="t('grp_enforce_field')" />
      <AInput v-model:value="row.values" class="rem-values" :placeholder="t('grp_enforce_values')" />
      <AButton type="text" danger :title="t('delete')" @click="rows.splice(i, 1)">
        <template #icon><DeleteOutlined /></template>
      </AButton>
    </div>
    <AButton size="small" @click="rows.push({ field: '', values: '' })">
      <template #icon><PlusOutlined /></template>
      {{ t('grp_enforce_add') }}
    </AButton>
  </AModal>
</template>

<script setup>
// Edits the `enforce` map of a create/update rule: {field: value | variable | list}.
// Values are typed as text, several separated by commas; variables such as
// $GROUP.site are written as they are. The server checks the field names.
import { reactive, ref, watch } from 'vue'
import { DeleteOutlined, PlusOutlined } from '@ant-design/icons-vue'
import { Alert as AAlert, Button as AButton, Input as AInput, Modal as AModal } from 'ant-design-vue'
import { useI18n } from '@/i18n'
import { enforceToRows, rowsToEnforce } from '@/utils/groupAttrs'

const props = defineProps({
  open:    { type: Boolean, default: false },
  title:   { type: String, default: '' },
  enforce: { type: Object, default: null },
})
const emit = defineEmits(['update:open', 'apply'])

const { t } = useI18n()
const rows  = reactive([])
const error = ref('')

watch(() => props.open, v => {
  if (!v) return
  error.value = ''
  rows.splice(0, rows.length, ...enforceToRows(props.enforce))
}, { immediate: true })

function apply() {
  const res = rowsToEnforce(rows)
  if (res.error) {
    error.value = t(res.error)
    return
  }
  emit('apply', res.enforce)
  emit('update:open', false)
}
</script>

<style scoped>
.rem-hint { color: var(--p-text-muted-color); font-size: 0.85rem; margin-top: 0; }
.rem-error { margin-bottom: 0.75rem; }
.rem-row { display: flex; gap: 0.5rem; margin-bottom: 0.5rem; }
.rem-field { flex: 0 0 38%; }
.rem-values { flex: 1; }
</style>
