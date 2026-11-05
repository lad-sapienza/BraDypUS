<template>
  <div class="cfg-panel">

    <div class="cfg-panel-header">
      <h2><DatabaseOutlined /> {{ t('db_objects') }}</h2>
      <AButton size="small" :loading="loading" @click="load">
        <template #icon><ReloadOutlined /></template>
        {{ t('reload') }}
      </AButton>
    </div>

    <div class="panel-body">
      <p class="hint">{{ t('db_objects_hint') }}</p>

      <AAlert v-if="loadError" type="error" :message="loadError" :closable="false" show-icon />

      <div v-if="loading && !objects.length" class="cfg-loading-center"><LoadingOutlined spin /></div>

      <p v-else-if="!objects.length && !loadError" class="hint dbo-empty">{{ t('db_objects_none') }}</p>

      <div v-else class="dbo-list">
        <div v-for="o in objects" :key="o.name" class="dbo-row" :data-name="o.name">
          <div class="dbo-main">
            <span class="dbo-name">{{ o.name }}</span>
            <ATag v-if="o.kind !== 'missing'" :color="o.kind === 'view' ? 'purple' : 'blue'">{{ t(o.kind === 'view' ? 'db_kind_view' : 'db_kind_table') }}</ATag>
            <ATag v-if="o.inactive" color="orange">{{ t('db_inactive') }}</ATag>
            <ATag v-if="o.kind === 'view'" :color="o.view_writable === 'none' ? 'default' : 'green'">
              {{ o.view_writable === 'none' ? t('view_read_only_badge') : t('view_writable_badge') }}
            </ATag>
            <small class="dbo-cols">{{ o.columns }} {{ t('fields').toLowerCase() }}</small>
          </div>
          <div class="dbo-status">
            <small v-if="!o.activatable" class="dbo-reason">{{ t('dbo_reason_' + o.reason) }}</small>
            <AButton v-if="o.kind !== 'missing'" size="small" :type="o.activatable ? 'primary' : 'default'" @click="openPreview(o.name)">
              {{ o.inactive ? t('dbo_reactivate') : (o.activatable ? t('dbo_preview_activate') : t('dbo_details')) }}
            </AButton>
            <template v-if="o.inactive">
              <AButton size="small" danger @click="removeConfig(o.name)">{{ t('remove_from_config') }}</AButton>
              <AButton v-if="o.kind === 'table'" size="small" danger @click="openDrop(o.name)">
                <template #icon><DeleteOutlined /></template>
                {{ t('delete_table') }}
              </AButton>
            </template>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Preview / activate ──────────────────────────────────────────── -->
    <AModal v-model:open="previewVisible" :title="preview?.name || ''" width="640px" :footer="null">
      <div v-if="previewLoading" class="cfg-loading-center"><LoadingOutlined spin /></div>
      <AAlert v-else-if="previewError" type="error" :message="previewError" :closable="false" show-icon />

      <template v-else-if="preview">
        <AAlert
          v-if="preview.inactive"
          type="info" show-icon :closable="false" class="dbo-block"
          :message="t('dbo_inactive_note')"
        />
        <AAlert
          v-else-if="!preview.activatable"
          type="warning" show-icon :closable="false" class="dbo-block"
          :message="t('dbo_reason_' + preview.reason)"
        />
        <AAlert
          v-else-if="preview.kind === 'view'"
          type="info" show-icon :closable="false" class="dbo-block"
          :message="t('dbo_view_note')"
        />
        <p v-else class="hint">{{ t('dbo_table_note') }}</p>

        <h4 v-if="!preview.inactive">{{ t('dbo_proposed_fields') }}</h4>
        <div v-if="!preview.inactive" class="dbo-fields">
          <div v-for="f in preview.fields" :key="f.name" class="dbo-field">
            <code>{{ f.name }}</code>
            <span>{{ f.type }}</span>
            <small>{{ f.db_type }}</small>
          </div>
        </div>

        <template v-if="!preview.inactive && preview.relations.length">
          <h4>{{ t('dbo_relations_found') }}</h4>
          <div v-for="r in preview.relations" :key="r.from_col" class="dbo-rel">
            <code>{{ r.from_col }}</code> → <code>{{ r.to_tb }}.{{ r.to_col }}</code>
            <small v-if="!r.configured" class="dbo-reason"> — {{ t('dbo_rel_target_missing') }}</small>
          </div>
        </template>

        <details v-if="preview.sql" class="dbo-sql">
          <summary>{{ t('view_sql') }}</summary>
          <pre>{{ preview.sql }}</pre>
        </details>

        <div class="dbo-footer">
          <AButton size="small" @click="previewVisible = false">{{ t('cancel') }}</AButton>
          <AButton
            type="primary" size="small"
            :disabled="!preview.activatable" :loading="activating"
            @click="activate"
          >
            <template #icon><CheckOutlined /></template>
            {{ preview.inactive ? t('dbo_reactivate') : t('dbo_activate') }}
          </AButton>
        </div>
      </template>
    </AModal>

    <!-- ── Drop a deactivated table: type its name ─────────────────────── -->
    <AModal v-model:open="dropVisible" :title="t('delete_table') + ': ' + dropName" width="460px" :footer="null">
      <AAlert type="error" show-icon :closable="false" :message="t('dbo_drop_warning')" class="dbo-block" />
      <label class="dbo-drop-label">{{ t('dbo_drop_type_name') }} <code>{{ dropName }}</code></label>
      <AInput v-model:value="dropTyped" size="small" autofocus @keyup.enter="confirmDrop" />
      <div class="dbo-footer">
        <AButton size="small" @click="dropVisible = false">{{ t('cancel') }}</AButton>
        <AButton danger type="primary" size="small" :disabled="dropTyped !== dropName" :loading="dropping" @click="confirmDrop">
          <template #icon><DeleteOutlined /></template>
          {{ t('delete_table') }}
        </AButton>
      </div>
    </AModal>

  </div>
</template>

<script setup>
import { CheckOutlined, DatabaseOutlined, DeleteOutlined, LoadingOutlined, ReloadOutlined } from '@ant-design/icons-vue'
import { ref, onMounted } from 'vue'
import { Button as AButton, Alert as AAlert, Input as AInput, Modal as AModal, Tag as ATag } from 'ant-design-vue'
import { useConfirm } from '@/composables/useNotify'
import { useI18n }  from '@/i18n'
import { useToast } from '@/composables/useNotify'
import { api }      from '@/api'

const emit = defineEmits(['table-added'])

const { t }  = useI18n()
const toast  = useToast()
const confirm = useConfirm()

const objects   = ref([])
const loading   = ref(false)
const loadError = ref(null)

const previewVisible = ref(false)
const previewLoading = ref(false)
const previewError   = ref(null)
const preview        = ref(null)
const activating     = ref(false)

async function load() {
  loading.value   = true
  loadError.value = null
  try {
    const res = await api.get('/api/config/db-objects')
    if (res.status !== 'success') throw new Error(api.responseMessage(res, t))
    objects.value = res.objects ?? []
  } catch (e) {
    loadError.value = e.message
  } finally {
    loading.value = false
  }
}

async function openPreview(name) {
  previewVisible.value = true
  previewLoading.value = true
  previewError.value   = null
  preview.value        = null
  try {
    const res = await api.get(`/api/config/db-objects/${encodeURIComponent(name)}`)
    if (res.status !== 'success') throw new Error(api.responseMessage(res, t))
    preview.value = res
  } catch (e) {
    previewError.value = e.message
  } finally {
    previewLoading.value = false
  }
}

async function activate() {
  if (!preview.value) return
  activating.value = true
  try {
    const res = await api.post(`/api/config/db-objects/${encodeURIComponent(preview.value.name)}/activate`, {})
    toast.add({
      severity: res.status === 'success' ? 'success' : 'error',
      summary:  t('saved'),
      detail:   res.status === 'success'
        ? t(res.code === 'table_reactivated' ? 'table_reactivated' : 'db_object_activated')
        : api.responseMessage(res, t),
      life: 4000,
    })
    if (res.status === 'success') {
      previewVisible.value = false
      emit('table-added')
      await load()
    }
  } catch (e) {
    toast.add({ severity: 'error', summary: e.message, life: 4000 })
  } finally {
    activating.value = false
  }
}

// ── Remove the configuration of a deactivated table (data stays) ─────────
function removeConfig(name) {
  confirm.require({
    message:  t('warning_remove_from_config'),
    header:   t('remove_from_config'),
    icon:     'pi pi-exclamation-triangle',
    severity: 'danger',
    accept:   async () => {
      try {
        const res = await api.delete(`/api/config/table/${encodeURIComponent(name)}?keep_data=1`)
        toast.add({ severity: res.status === 'success' ? 'success' : 'error', summary: t('saved'), detail: api.responseMessage(res, t), life: 4000 })
        if (res.status === 'success') { emit('table-added'); await load() }
      } catch (e) {
        toast.add({ severity: 'error', summary: e.message, life: 4000 })
      }
    },
  })
}

// ── Drop a deactivated table: the one irreversible action, name to be typed ─
const dropVisible = ref(false)
const dropName    = ref('')
const dropTyped   = ref('')
const dropping    = ref(false)

function openDrop(name) {
  dropName.value    = name
  dropTyped.value   = ''
  dropVisible.value = true
}

async function confirmDrop() {
  if (dropTyped.value !== dropName.value) return
  dropping.value = true
  try {
    const res = await api.delete(`/api/config/table/${encodeURIComponent(dropName.value)}`)
    toast.add({ severity: res.status === 'success' ? 'success' : 'error', summary: t('saved'), detail: api.responseMessage(res, t), life: 4000 })
    if (res.status === 'success') { dropVisible.value = false; emit('table-added'); await load() }
  } catch (e) {
    toast.add({ severity: 'error', summary: e.message, life: 4000 })
  } finally {
    dropping.value = false
  }
}

onMounted(load)
</script>

<style scoped>
.cfg-panel { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
.cfg-panel-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 1rem 1.25rem 0.75rem; border-bottom: 1px solid var(--p-content-border-color); flex-shrink: 0;
}
.cfg-panel-header h2 { margin: 0; font-size: 1rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem; }
.panel-body { flex: 1; overflow-y: auto; padding: 1rem 1.25rem; }
.hint { color: var(--p-text-muted-color, #888); font-size: 0.85rem; }
.cfg-loading-center { display: flex; justify-content: center; padding: 2rem; }

.dbo-list { display: flex; flex-direction: column; gap: 0.5rem; }
.dbo-row {
  display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap;
  padding: 0.6rem 0.75rem; border: 1px solid var(--p-content-border-color); border-radius: 6px;
}
.dbo-main { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; min-width: 0; }
.dbo-name { font-weight: 600; word-break: break-all; }
.dbo-cols { color: var(--p-text-muted-color, #888); }
.dbo-status { display: flex; align-items: center; gap: 0.75rem; }
.dbo-reason { color: var(--p-orange-500, #d97706); }
.dbo-block { margin-bottom: 0.75rem; }
.dbo-fields { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 0.25rem 1rem; margin-bottom: 0.75rem; }
.dbo-field { display: flex; gap: 0.5rem; align-items: baseline; }
.dbo-field small { color: var(--p-text-muted-color, #888); }
.dbo-rel { margin-bottom: 0.25rem; }
.dbo-sql { margin: 0.75rem 0; }
.dbo-sql pre {
  max-height: 220px; overflow: auto; padding: 0.6rem; border-radius: 6px;
  background: var(--p-content-hover-background, rgba(127,127,127,.12)); white-space: pre-wrap; font-size: 0.8rem;
}
.dbo-drop-label { display: block; margin-bottom: 0.4rem; }
.dbo-footer { display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1rem; }
@media (max-width: 640px) { .panel-body { padding: 0.75rem 1rem; } }
</style>
