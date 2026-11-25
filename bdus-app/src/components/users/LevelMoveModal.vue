<template>
  <AModal
    :open="open"
    :title="t('lvl_move_all_title')"
    :ok-text="t('lvl_move_all_run', plan.moved.length)"
    :cancel-text="t('close')"
    :confirm-loading="running"
    :ok-button-props="{ disabled: loading || plan.moved.length === 0 }"
    width="640px"
    @ok="run"
    @cancel="emit('update:open', false)"
  >
    <p class="hint">{{ t('lvl_move_all_intro') }}</p>

    <div v-if="loading" class="lvl-loading"><LoadingOutlined spin /></div>
    <AAlert v-else-if="error" type="error" show-icon :message="error" />
    <template v-else>
      <AAlert v-if="plan.moved.length === 0" type="info" show-icon :message="t('lvl_move_all_none')" />

      <section v-if="plan.moved.length" class="lvl-section">
        <h4>{{ t('lvl_move_all_would', plan.moved.length) }}</h4>
        <ul>
          <li v-for="m in plan.moved" :key="m.type + m.id">
            <ATag>{{ kindLabel(m.type) }}</ATag> {{ m.name }}
            <span class="hint">{{ LEVEL_GROUP[m.level] }} → Enter + {{ m.group }}</span>
          </li>
        </ul>
      </section>

      <section v-if="plan.refused.length" class="lvl-section">
        <h4>{{ t('lvl_move_all_refused', plan.refused.length) }}</h4>
        <ul>
          <li v-for="m in plan.refused" :key="m.type + m.id">
            <ATag>{{ kindLabel(m.type) }}</ATag> {{ m.name }}
            <span class="hint">
              {{ api.responseMessage(m, t) }}
              <template v-if="m.differences?.length"> ({{ t('lvl_diff_count', m.differences.length) }})</template>
            </span>
          </li>
        </ul>
      </section>
    </template>
  </AModal>
</template>

<script setup>
import { ref, watch } from 'vue'
import { LoadingOutlined } from '@ant-design/icons-vue'
import { Modal as AModal, Alert as AAlert, Tag as ATag } from 'ant-design-vue'
import { api }      from '@/api'
import { useI18n }  from '@/i18n'
import { useToast } from '@/composables/useNotify'
import { LEVEL_GROUP } from '@/composables/useLevelMove'

const props = defineProps({ open: { type: Boolean, default: false } })
const emit  = defineEmits(['update:open', 'done'])

const { t }  = useI18n()
const toast  = useToast()

const loading = ref(false)
const running = ref(false)
const error   = ref(null)
const plan    = ref({ moved: [], refused: [] })

const kindLabel = type => t(type === 'user' ? 'lvl_move_user' : 'lvl_move_key')

// Opening the dialog asks the server what it would do, without writing anything.
watch(() => props.open, async isOpen => {
  if (!isOpen) return
  loading.value = true
  error.value   = null
  plan.value    = { moved: [], refused: [] }
  try {
    const res = await api.post('/api/levels/move-to-groups', { dry_run: true })
    if (res.status !== 'success') throw new Error(api.responseMessage(res, t) || t('error'))
    plan.value = { moved: res.moved ?? [], refused: res.refused ?? [] }
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
})

async function run() {
  running.value = true
  try {
    const res = await api.post('/api/levels/move-to-groups', { dry_run: false })
    if (res.status !== 'success') throw new Error(api.responseMessage(res, t) || t('error'))
    toast.add({
      severity: 'success',
      summary: t('lvl_move_all_done', res.moved?.length ?? 0, res.refused?.length ?? 0),
      life: 6000,
    })
    emit('update:open', false)
    emit('done')
  } catch (e) {
    toast.add({ severity: 'error', summary: e.message, life: 5000 })
  } finally {
    running.value = false
  }
}
</script>

<style scoped>
.hint { color: var(--p-text-muted-color); font-size: 0.85rem; }
.lvl-loading { text-align: center; font-size: 1.5rem; padding: 1.5rem; color: var(--p-text-muted-color); }
.lvl-section { margin-top: 1rem; }
.lvl-section h4 { margin: 0 0 0.4rem; font-size: 0.9rem; }
.lvl-section ul { margin: 0; padding-left: 1.1rem; display: flex; flex-direction: column; gap: 0.25rem; }
</style>
