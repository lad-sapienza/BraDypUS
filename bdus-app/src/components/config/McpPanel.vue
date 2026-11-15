<template>
  <div class="cfg-panel">
    <div class="cfg-panel-header">
      <h2><RobotOutlined /> {{ t('mcp_title') }}</h2>
      <AButton type="primary" :loading="saving" :disabled="loading || !!error || !dirty" @click="save">
        {{ t('save') }}
      </AButton>
    </div>

    <div v-if="loading" class="cfg-loading-center"><LoadingOutlined spin /></div>
    <AAlert v-else-if="error" type="error" :message="error" show-icon class="mcp-body" />

    <div v-else class="mcp-body">
      <p class="hint">{{ t('mcp_hint') }}</p>

      <section class="cfg-section">
        <div class="cfg-section-header"><span>{{ t('mcp_endpoint') }}</span></div>
        <div class="endpoint-row">
          <code class="endpoint-code">{{ endpoint }}</code>
          <AButton type="text" size="small" :title="t('mcp_copy_endpoint')" @click="copyEndpoint">
            <template #icon><CopyOutlined /></template>
          </AButton>
        </div>
        <small class="hint">{{ t('mcp_endpoint_hint') }}</small>
      </section>

      <section class="cfg-section">
        <div class="cfg-section-header"><span>{{ t('mcp_switches') }}</span></div>

        <label class="switch-row">
          <ASwitch v-model:checked="form.enabled" />
          <span>
            <strong>{{ t('mcp_enabled') }}</strong>
            <small class="hint">{{ t('mcp_enabled_hint') }}</small>
          </span>
        </label>

        <label class="switch-row">
          <ASwitch v-model:checked="form.write" :disabled="!form.enabled" />
          <span>
            <strong>{{ t('mcp_write') }}</strong>
            <small class="hint">{{ t('mcp_write_hint') }}</small>
          </span>
        </label>

        <label class="switch-row">
          <ASwitch v-model:checked="form.delete" :disabled="!form.enabled" />
          <span>
            <strong>{{ t('mcp_delete') }}</strong>
            <small class="hint">{{ t('mcp_delete_hint') }}</small>
          </span>
        </label>

        <AAlert
          v-if="form.enabled && (form.write || form.delete)"
          type="warning"
          show-icon
          :message="t('mcp_no_user_warning')"
        />
      </section>

      <section class="cfg-section">
        <div class="cfg-section-header"><span>{{ t('mcp_guide') }}</span></div>
        <small class="hint">{{ t('mcp_guide_hint') }}</small>
        <ATextArea v-model:value="form.guide" :rows="8" :maxlength="20000" show-count />
      </section>

      <McpPromptsEditor />
    </div>
  </div>
</template>

<script setup>
import { CopyOutlined, LoadingOutlined, RobotOutlined } from '@ant-design/icons-vue'
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { Button as AButton, Switch as ASwitch, Alert as AAlert, Input } from 'ant-design-vue'
import { useToast } from '@/composables/useNotify'
import { useI18n }  from '@/i18n'
import { api }      from '@/api'
import McpPromptsEditor from '@/components/config/McpPromptsEditor.vue'

const ATextArea = Input.TextArea

const { t }  = useI18n()
const toast  = useToast()
const route  = useRoute()

const EMPTY = { enabled: false, write: false, delete: false, guide: '' }

const loading = ref(true)
const saving  = ref(false)
const error   = ref(null)
const form    = ref({ ...EMPTY })
const saved   = ref({ ...EMPTY })

const endpoint = computed(() => `${window.location.origin}/${route.params.app}/api/mcp`)
const dirty    = computed(() => JSON.stringify(form.value) !== JSON.stringify(saved.value))

function fromServer(m) {
  return {
    enabled: !!m?.enabled,
    write:   !!m?.write,
    delete:  !!m?.delete,
    guide:   String(m?.guide ?? ''),
  }
}

async function load() {
  loading.value = true
  error.value   = null
  try {
    const res = await api.get('/api/config/app')
    if (res.status === 'error') throw new Error(t(res.code) || res.code)
    saved.value = fromServer(res.main?.mcp)
    form.value  = { ...saved.value }
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  try {
    // Switching MCP off also switches writing and deleting off: they do nothing
    // without it, and must not come back on by themselves with the next enable.
    const mcp = { ...form.value }
    if (!mcp.enabled) {
      mcp.write  = false
      mcp.delete = false
    }
    // Only the MCP settings are sent: the server leaves everything else alone.
    const res = await api.put('/api/config/app', { mcp })
    const ok  = res.status === 'success'
    toast.add({
      severity: ok ? 'success' : 'error',
      summary:  t(ok ? 'saved' : (res.code || 'error')),
      life: 4000,
    })
    if (ok) {
      saved.value = fromServer(mcp)
      form.value  = { ...saved.value }
    }
  } catch (e) {
    toast.add({ severity: 'error', summary: e.message, life: 5000 })
  } finally {
    saving.value = false
  }
}

async function copyEndpoint() {
  try {
    await navigator.clipboard.writeText(endpoint.value)
    toast.add({ severity: 'success', summary: t('copied'), life: 2000 })
  } catch {
    // clipboard blocked: the text is selectable on the page
  }
}

onMounted(load)
</script>

<style scoped>
.cfg-panel {
  flex: 1;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.cfg-panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 1rem 1.25rem 0.75rem;
  border-bottom: 1px solid var(--p-content-border-color);
  flex-shrink: 0;
}
.cfg-panel-header h2 {
  margin: 0;
  font-size: 1rem;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
.mcp-body {
  flex: 1;
  overflow-y: auto;
  padding: 1rem 1.25rem;
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}
.hint {
  display: block;
  margin: 0;
  font-size: 0.875rem;
  color: var(--p-text-muted-color);
}
.cfg-section {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}
.cfg-section-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-weight: 600;
  font-size: 0.85rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--p-text-muted-color);
  border-bottom: 1px solid var(--p-content-border-color);
  padding-bottom: 0.4rem;
}
.switch-row {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
  cursor: pointer;
}
.switch-row > span {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
}
.endpoint-row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
.endpoint-code {
  font-family: monospace;
  font-size: 0.875rem;
  background: var(--p-content-background);
  border: 1px solid var(--p-content-border-color);
  border-radius: 4px;
  padding: 0.25rem 0.5rem;
  word-break: break-all;
  user-select: all;
}
.cfg-loading-center {
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 2rem;
  color: var(--p-text-muted-color);
  padding: 2rem;
}
</style>
