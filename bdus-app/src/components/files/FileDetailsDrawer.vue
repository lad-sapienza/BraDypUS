<template>
  <ADrawer
    :open="open"
    :title="file ? `${file.filename}.${file.ext}` : ''"
    width="460"
    :destroy-on-close="true"
    @update:open="v => $emit('update:open', v)"
  >
    <template v-if="file">
      <div class="drawer-media">
        <img v-if="file.is_image" :src="thumbUrl(file, '')" :alt="file.filename" class="drawer-thumb" />
        <component v-else :is="fileIcon(file.ext)" class="drawer-icon" />
      </div>

      <AAlert v-if="!file.can_update" type="info" show-icon :closable="false" :message="t('files_read_only_note')" class="drawer-note" />

      <form class="drawer-form" data-test="file-details-form" @submit.prevent="save">
        <div v-for="f in formFields" :key="f.name" class="drawer-field">
          <label :for="`fd-${f.name}`">{{ f.label }}</label>

          <ASwitch v-if="f.type === 'boolean'" :id="`fd-${f.name}`" :checked="!!form[f.name]" :disabled="!file.can_update"
                   @update:checked="v => form[f.name] = v" />
          <ATextarea v-else-if="f.type === 'long_text' || f.type === 'md'" :id="`fd-${f.name}`" :value="form[f.name] ?? ''"
                     :rows="4" :disabled="!file.can_update" @update:value="v => form[f.name] = v || null" />
          <AInput v-else :id="`fd-${f.name}`" :value="form[f.name] ?? ''" :disabled="!file.can_update"
                  @update:value="v => form[f.name] = v === '' ? null : v" />
        </div>

        <div class="drawer-facts">
          <div><span>{{ t('files_col_links') }}</span>
            <template v-if="file.links.length">
              <RouterLink v-for="(l, i) in file.links" :key="i" :to="`/${appName}/record/${l.tb}/${l.record_id}`" class="record-link">{{ l.tb }} #{{ l.record_id }}</RouterLink>
            </template>
            <em v-else>{{ t('files_no_links') }}</em>
          </div>
          <div><span>UUID</span><code>{{ file.uuid }}</code></div>
        </div>
      </form>
    </template>

    <template #footer>
      <div class="drawer-footer">
        <AButton @click="$emit('update:open', false)">{{ t('cancel') }}</AButton>
        <AButton type="primary" :disabled="!file?.can_update || !dirty" :loading="saving" @click="save">{{ t('save') }}</AButton>
      </div>
    </template>
  </ADrawer>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { Drawer as ADrawer, Button as AButton, Input as AInput, Switch as ASwitch, Alert as AAlert } from 'ant-design-vue'
import { useI18n } from '@/i18n'
import { thumbUrl } from '@/utils/thumbUrl'
import { changedFields, editableNames, snapshotOf, stripExt } from '@/utils/filesList'
import { fileIcon } from './fileIcon'

const ATextarea = AInput.TextArea

const props = defineProps({
  open:    { type: Boolean, default: false },
  file:    { type: Object,  default: null },
  fields:  { type: Array,   default: () => [] },
  appName: { type: String,  default: '' },
  saving:  { type: Boolean, default: false },
})
const emit = defineEmits(['update:open', 'save'])
const { t } = useI18n()

const form = reactive({})
let snapshot = {}

// Every editable field, in the configured order (the extension is not part of the name)
const formFields = computed(() => props.fields.filter(f => editableNames(props.fields).includes(f.name)))

watch(() => [props.file, props.open], () => {
  for (const k of Object.keys(form)) delete form[k]
  if (!props.file) return
  const base = { ...props.file, filename: stripExt(props.file.filename, props.file.ext) }
  Object.assign(form, snapshotOf(base, props.fields))
  snapshot = snapshotOf(base, props.fields)
}, { immediate: true })

const dirty = computed(() => Object.keys(changedFields(form, snapshot, props.fields)).length > 0)

function save() {
  if (!props.file?.can_update) return
  const changes = changedFields(form, snapshot, props.fields)
  if ('filename' in changes) changes.filename = stripExt(changes.filename ?? '', props.file.ext)
  if (Object.keys(changes).length) emit('save', { file: props.file, changes })
}
</script>

<style scoped>
.drawer-media { display: flex; justify-content: center; margin-bottom: 1rem; }
.drawer-thumb { max-width: 100%; max-height: 200px; border-radius: 6px; border: 1px solid var(--p-content-border-color); }
.drawer-icon { font-size: 4rem; color: var(--p-text-muted-color); }
.drawer-note { margin-bottom: 0.8rem; }
.drawer-form { display: flex; flex-direction: column; gap: 0.8rem; }
.drawer-field { display: flex; flex-direction: column; gap: 0.25rem; }
.drawer-field label { font-size: 0.8rem; font-weight: 600; color: var(--p-text-muted-color); }
.drawer-facts { display: flex; flex-direction: column; gap: 0.4rem; font-size: 0.8rem; margin-top: 0.5rem; padding-top: 0.6rem; border-top: 1px solid var(--p-content-border-color); }
.drawer-facts span { display: inline-block; min-width: 4.5rem; color: var(--p-text-muted-color); font-weight: 600; }
.drawer-facts code { font-size: 0.72rem; word-break: break-all; }
.record-link { color: var(--p-primary-color); margin-right: 0.5rem; }
.drawer-footer { display: flex; justify-content: flex-end; gap: 0.5rem; }
</style>
