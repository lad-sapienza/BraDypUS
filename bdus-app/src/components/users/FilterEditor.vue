<template>
  <div class="fe">
    <div v-if="config.loading.value" class="fe-loading"><ASpin size="small" /></div>

    <template v-else>
      <!-- The builder, when it can show the filter -->
      <template v-if="mode === 'builder'">
        <FilterBuilder :tree="tree" :fields="config.fields.value" :operators="config.operatorOptions.value" />
        <p class="fe-hint">{{ t('grp_filter_vars') }}</p>
      </template>

      <!-- Raw JSON, for filters the builder cannot represent -->
      <template v-else>
        <AAlert v-if="cannotShow" type="info" :message="t('grp_filter_json_hint')" show-icon :closable="false" class="fe-alert" />
        <ATextarea v-model:value="json" :rows="8" class="fe-json" spellcheck="false" />
        <p class="fe-hint">{{ t('grp_filter_vars') }}</p>
      </template>

      <AButton type="link" size="small" class="fe-switch" @click="switchMode">
        {{ mode === 'builder' ? t('grp_filter_json') : t('grp_filter_builder') }}
      </AButton>
    </template>
  </div>
</template>

<script setup>
// Edits one backend filter: with the query builder when it can show it, as JSON
// otherwise. The parent reads the result with getFilter() — { filter } on success,
// { error } with a translated reason when there is nothing usable to save.
import { computed, ref, watch } from 'vue'
import { Alert as AAlert, Button as AButton, Input, Spin as ASpin } from 'ant-design-vue'
import { useI18n } from '@/i18n'
import { useSearchConfig } from '@/composables/useSearchConfig'
import { emptyTree, filterToTree, treeToFilter, countActive, cloneTree } from '@/utils/filterTree'
import FilterBuilder from '@/components/query/FilterBuilder.vue'

const ATextarea = Input.TextArea

const props = defineProps({
  table:  { type: String, required: true },
  filter: { type: Object, default: null },   // the stored filter (null = none yet)
})

const { t } = useI18n()
const table  = computed(() => ({ name: props.table }))
const config = useSearchConfig(table)

const mode       = ref('builder')
const tree       = ref(emptyTree())
const json       = ref('')
const cannotShow = ref(false)

const ctx = () => ({
  mainTb: props.table,
  fieldMeta: fld => config.fields.value.find(f => f.value === fld),
  operators: config.operatorOptions.value.map(o => o.value),
})

const pretty = f => (f ? JSON.stringify(f, null, 2) : '')

async function init() {
  config.invalidate()
  await config.load()
  const built = filterToTree(props.filter, ctx())
  if (built) {
    tree.value = built
    mode.value = 'builder'
    cannotShow.value = false
  } else {
    json.value = pretty(props.filter)
    mode.value = 'json'
    cannotShow.value = true
  }
}

function switchMode() {
  if (mode.value === 'builder') {
    json.value = pretty(treeToFilter(tree.value, ctx()))
    cannotShow.value = false
    mode.value = 'json'
    return
  }
  let parsed = null
  try { parsed = json.value.trim() ? JSON.parse(json.value) : null } catch { parsed = undefined }
  const built = parsed === undefined ? null : filterToTree(parsed, ctx())
  if (!built) {
    cannotShow.value = true   // stay in JSON: the builder cannot show it
    return
  }
  tree.value = cloneTree(built)
  mode.value = 'builder'
}

/** { filter } (an object, never empty) or { error }. */
function getFilter() {
  if (mode.value === 'builder') {
    if (countActive(tree.value) === 0) return { error: t('grp_filter_empty') }
    return { filter: treeToFilter(tree.value, ctx()) }
  }
  let parsed
  try { parsed = JSON.parse(json.value) } catch { return { error: t('grp_filter_invalid_json') } }
  if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed) || Object.keys(parsed).length === 0) {
    return { error: t('grp_filter_empty') }
  }
  return { filter: parsed }
}

watch(() => [props.table, props.filter], init, { immediate: true })
defineExpose({ getFilter })
</script>

<style scoped>
.fe { display: flex; flex-direction: column; gap: 0.6rem; min-width: 0; }
.fe-loading { display: flex; justify-content: center; padding: 1rem; }
.fe-hint { margin: 0; font-size: 0.8rem; color: var(--p-text-muted-color); }
.fe-json { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.82rem; }
.fe-switch { align-self: flex-start; padding-left: 0; }
</style>
