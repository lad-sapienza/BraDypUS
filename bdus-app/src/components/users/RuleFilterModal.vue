<template>
  <AModal
    :open="open"
    :title="title"
    :width="720"
    :ok-text="t('grp_apply_filter')"
    :cancel-text="t('cancel')"
    destroy-on-close
    @ok="apply"
    @cancel="$emit('update:open', false)"
  >
    <AAlert v-if="error" type="error" :message="error" show-icon :closable="false" class="rfm-error" />
    <FilterEditor v-if="open" ref="editor" :table="table" :filter="filter" />
  </AModal>
</template>

<script setup>
// A modal around FilterEditor: used by the permissions grid to edit the filter
// of one rule. Emits `apply` with the filter object.
import { ref, watch } from 'vue'
import { Alert as AAlert, Modal as AModal } from 'ant-design-vue'
import { useI18n } from '@/i18n'
import FilterEditor from './FilterEditor.vue'

const props = defineProps({
  open:   { type: Boolean, default: false },
  title:  { type: String, default: '' },
  table:  { type: String, required: true },
  filter: { type: Object, default: null },
})
const emit = defineEmits(['update:open', 'apply'])

const { t } = useI18n()
const editor = ref(null)
const error  = ref('')

watch(() => props.open, v => { if (v) error.value = '' })

function apply() {
  const res = editor.value?.getFilter()
  if (!res || res.error) {
    error.value = res?.error ?? ''
    return
  }
  emit('apply', res.filter)
  emit('update:open', false)
}
</script>

<style scoped>
.rfm-error { margin-bottom: 0.75rem; }
</style>
