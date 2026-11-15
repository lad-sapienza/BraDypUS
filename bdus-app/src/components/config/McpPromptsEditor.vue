<template>
  <section class="cfg-section">
    <div class="cfg-section-header">
      <span>{{ t('mcp_prompts') }}</span>
      <span class="header-actions">
        <AButton size="small" :disabled="loading || !!error" @click="addExamples">
          {{ t('mcp_prompts_examples') }}
        </AButton>
        <AButton size="small" type="primary" :disabled="loading || !!error" @click="openNew">
          <template #icon><PlusOutlined /></template>
          {{ t('mcp_prompt_new') }}
        </AButton>
      </span>
    </div>
    <small class="hint">{{ t('mcp_prompts_hint') }}</small>

    <div v-if="loading" class="cfg-loading-center"><LoadingOutlined spin /></div>
    <AAlert v-else-if="error" type="error" :message="error" show-icon />
    <p v-else-if="!prompts.length" class="hint">{{ t('mcp_prompts_none') }}</p>

    <ul v-else class="prompt-list">
      <li v-for="(p, i) in prompts" :key="p.id" class="prompt-item">
        <div class="prompt-main">
          <strong>{{ p.title || p.name }}</strong>
          <code class="prompt-name">{{ p.name }}</code>
          <small v-if="p.description" class="hint">{{ p.description }}</small>
          <small v-if="p.arguments.length" class="hint">
            {{ t('mcp_prompt_arguments') }}: {{ p.arguments.map(a => a.name + (a.required ? '*' : '')).join(', ') }}
          </small>
        </div>
        <span class="prompt-actions">
          <AButton type="text" size="small" :disabled="i === 0" :title="t('move_up')" @click="move(i, -1)">
            <template #icon><ArrowUpOutlined /></template>
          </AButton>
          <AButton type="text" size="small" :disabled="i === prompts.length - 1" :title="t('move_down')" @click="move(i, 1)">
            <template #icon><ArrowDownOutlined /></template>
          </AButton>
          <AButton type="text" size="small" :title="t('edit')" @click="openEdit(p)">
            <template #icon><EditOutlined /></template>
          </AButton>
          <AButton type="text" size="small" danger :title="t('delete')" @click="confirmDelete(p)">
            <template #icon><DeleteOutlined /></template>
          </AButton>
        </span>
      </li>
    </ul>

    <AModal
      v-model:open="modalOpen"
      :title="editingId ? t('mcp_prompt_edit') : t('mcp_prompt_new')"
      :ok-text="t('save')"
      :cancel-text="t('cancel')"
      :confirm-loading="saving"
      :ok-button-props="{ disabled: !canSave }"
      width="720px"
      @ok="save"
    >
      <div class="prompt-form">
        <label class="field">
          <span>{{ t('mcp_prompt_name') }}</span>
          <AInput v-model:value="draft.name" :maxlength="64" placeholder="season_report" />
          <small class="hint">{{ t('mcp_prompt_name_hint') }}</small>
        </label>
        <label class="field">
          <span>{{ t('mcp_prompt_title') }}</span>
          <AInput v-model:value="draft.title" :maxlength="100" />
        </label>
        <label class="field">
          <span>{{ t('mcp_prompt_description') }}</span>
          <AInput v-model:value="draft.description" :maxlength="500" />
        </label>

        <div class="field">
          <span>{{ t('mcp_prompt_arguments') }}</span>
          <small class="hint">{{ t('mcp_prompt_arguments_hint') }}</small>
          <div v-for="(a, i) in draft.arguments" :key="i" class="arg-row">
            <AInput v-model:value="a.name" :maxlength="32" placeholder="site" class="arg-name" />
            <AInput v-model:value="a.description" :maxlength="200" :placeholder="t('mcp_prompt_description')" class="arg-desc" />
            <label class="arg-required">
              <ASwitch v-model:checked="a.required" size="small" />
              <small>{{ t('mcp_prompt_required') }}</small>
            </label>
            <AButton type="text" size="small" danger :title="t('delete')" @click="draft.arguments.splice(i, 1)">
              <template #icon><DeleteOutlined /></template>
            </AButton>
          </div>
          <AButton size="small" :disabled="draft.arguments.length >= MAX_ARGUMENTS" @click="addArgument">
            <template #icon><PlusOutlined /></template>
            {{ t('mcp_prompt_add_argument') }}
          </AButton>
        </div>

        <label class="field">
          <span>{{ t('template') }}</span>
          <ATextArea v-model:value="draft.template" :rows="10" :maxlength="20000" show-count />
          <small class="hint">{{ t('mcp_prompt_template_hint') }}</small>
        </label>
        <AAlert v-if="undeclared.length" type="warning" show-icon
          :message="t('undeclared_prompt_placeholder') + ': ' + undeclared.map(n => '{{' + n + '}}').join(', ')" />
      </div>
    </AModal>
  </section>
</template>

<script setup>
import { ArrowDownOutlined, ArrowUpOutlined, DeleteOutlined, EditOutlined, LoadingOutlined, PlusOutlined } from '@ant-design/icons-vue'
import { ref, computed, onMounted } from 'vue'
import { Button as AButton, Switch as ASwitch, Alert as AAlert, Input as AInput, Modal as AModal } from 'ant-design-vue'
import { useToast, useConfirm } from '@/composables/useNotify'
import { useI18n }  from '@/i18n'
import { api }      from '@/api'

const ATextArea = AInput.TextArea

const { t }   = useI18n()
const toast   = useToast()
const confirm = useConfirm()

const MAX_ARGUMENTS = 10
const PLACEHOLDER_RE = /\{\{\s*([a-z_][a-z0-9_]*)\s*\}\}/g

const loading = ref(true)
const error   = ref(null)
const prompts = ref([])

const modalOpen = ref(false)
const saving    = ref(false)
const editingId = ref(null)
const draft     = ref(emptyDraft())

function emptyDraft() {
  return { name: '', title: '', description: '', arguments: [], template: '' }
}

// Placeholders the template uses but no argument declares: the server refuses them.
const undeclared = computed(() => {
  const declared = new Set(draft.value.arguments.map(a => a.name.trim()))
  const used = [...draft.value.template.matchAll(PLACEHOLDER_RE)].map(m => m[1])
  return [...new Set(used)].filter(n => !declared.has(n))
})

const canSave = computed(() =>
  /^[a-z0-9][a-z0-9_-]{0,63}$/.test(draft.value.name.trim())
  && draft.value.template.trim() !== ''
  && undeclared.value.length === 0
)

function message(res) {
  const base = api.responseMessage(res, t) || t('error_saved')
  const detail = Array.isArray(res?.detail) ? res.detail.join(', ') : (typeof res?.detail === 'string' ? res.detail : '')
  return detail ? `${base}: ${detail}` : base
}

async function load() {
  loading.value = true
  error.value   = null
  try {
    const res = await api.get('/api/mcp-prompts')
    if (res.status === 'error') throw new Error(message(res))
    prompts.value = res.prompts ?? []
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

// ── Edit ───────────────────────────────────────────────────────────────────

function openNew() {
  editingId.value = null
  draft.value     = emptyDraft()
  modalOpen.value = true
}

function openEdit(p) {
  editingId.value = p.id
  draft.value = {
    name: p.name, title: p.title, description: p.description, template: p.template,
    arguments: p.arguments.map(a => ({ name: a.name, description: a.description ?? '', required: !!a.required })),
  }
  modalOpen.value = true
}

function addArgument() {
  draft.value.arguments.push({ name: '', description: '', required: false })
}

function payload() {
  const d = draft.value
  return {
    name: d.name.trim(),
    title: d.title.trim(),
    description: d.description.trim(),
    template: d.template,
    arguments: d.arguments.map(a => ({ name: a.name.trim(), description: a.description.trim(), required: !!a.required })),
  }
}

async function save() {
  saving.value = true
  try {
    const res = editingId.value
      ? await api.put(`/api/mcp-prompt/${editingId.value}`, payload())
      : await api.post('/api/mcp-prompts', payload())
    if (res.status === 'error') {
      toast.add({ severity: 'error', summary: message(res), life: 6000 })
      return
    }
    toast.add({ severity: 'success', summary: t('ok_mcp_prompt_saved'), life: 3000 })
    modalOpen.value = false
    await load()
  } catch (e) {
    toast.add({ severity: 'error', summary: e.message, life: 6000 })
  } finally {
    saving.value = false
  }
}

// ── Delete, order ──────────────────────────────────────────────────────────

function confirmDelete(p) {
  confirm.require({
    header:   t('mcp_prompt_delete'),
    message:  t('mcp_prompt_delete_confirm', p.name),
    severity: 'danger',
    accept:   () => remove(p),
  })
}

async function remove(p) {
  try {
    const res = await api.delete(`/api/mcp-prompt/${p.id}`)
    toast.add({
      severity: res.status === 'success' ? 'success' : 'error',
      summary:  res.status === 'success' ? t('ok_mcp_prompt_deleted') : message(res),
      life: 4000,
    })
    await load()
  } catch (e) {
    toast.add({ severity: 'error', summary: e.message, life: 6000 })
  }
}

async function move(index, step) {
  const ids = prompts.value.map(p => p.id)
  const target = index + step
  if (target < 0 || target >= ids.length) return
  ;[ids[index], ids[target]] = [ids[target], ids[index]]
  try {
    const res = await api.put('/api/mcp-prompts/order', { ids })
    if (res.status === 'error') {
      toast.add({ severity: 'error', summary: message(res), life: 5000 })
    }
  } catch (e) {
    toast.add({ severity: 'error', summary: e.message, life: 5000 })
  }
  await load()
}

// ── Examples ───────────────────────────────────────────────────────────────

// Names are fixed identifiers; titles, descriptions and templates come from the
// locale files, so the examples are written in the interface language.
const EXAMPLES = [
  {
    name: 'season_report',
    arguments: [
      { key: 'site', required: true },
      { key: 'year', required: false },
    ],
  },
  {
    name: 'context_sheet',
    arguments: [
      { key: 'table', required: true },
      { key: 'description', required: true },
    ],
  },
]

async function addExamples() {
  const have = new Set(prompts.value.map(p => p.name))
  const todo = EXAMPLES.filter(e => !have.has(e.name))
  if (!todo.length) {
    toast.add({ severity: 'info', summary: t('mcp_prompts_examples_present'), life: 3000 })
    return
  }
  try {
    for (const e of todo) {
      const res = await api.post('/api/mcp-prompts', {
        name:        e.name,
        title:       t(`mcp_example_${e.name}_title`),
        description: t(`mcp_example_${e.name}_description`),
        template:    t(`mcp_example_${e.name}_template`),
        arguments:   e.arguments.map(a => ({
          name:        a.key,
          description: t(`mcp_example_${e.name}_arg_${a.key}`),
          required:    a.required,
        })),
      })
      if (res.status === 'error') {
        toast.add({ severity: 'error', summary: message(res), life: 6000 })
        break
      }
    }
  } catch (e) {
    toast.add({ severity: 'error', summary: e.message, life: 6000 })
  }
  await load()
}

onMounted(load)
</script>

<style scoped>
.cfg-section {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}
.cfg-section-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  flex-wrap: wrap;
  font-weight: 600;
  font-size: 0.85rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--p-text-muted-color);
  border-bottom: 1px solid var(--p-content-border-color);
  padding-bottom: 0.4rem;
}
.header-actions { display: flex; gap: 0.5rem; text-transform: none; letter-spacing: normal; }
.hint {
  display: block;
  margin: 0;
  font-size: 0.875rem;
  font-weight: 400;
  color: var(--p-text-muted-color);
}
.cfg-loading-center {
  display: flex;
  justify-content: center;
  font-size: 1.5rem;
  color: var(--p-text-muted-color);
  padding: 1rem;
}
.prompt-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}
.prompt-item {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.6rem 0.75rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: 6px;
}
.prompt-main { display: flex; flex-direction: column; gap: 0.15rem; min-width: 0; }
.prompt-name {
  font-family: monospace;
  font-size: 0.8rem;
  color: var(--p-text-muted-color);
}
.prompt-actions { display: flex; flex-shrink: 0; }
.prompt-form { display: flex; flex-direction: column; gap: 1rem; }
.field { display: flex; flex-direction: column; gap: 0.3rem; }
.field > span:first-child { font-weight: 600; }
.arg-row { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
.arg-name { flex: 0 0 9rem; }
.arg-desc { flex: 1 1 12rem; min-width: 8rem; }
.arg-required { display: flex; align-items: center; gap: 0.3rem; }
</style>
