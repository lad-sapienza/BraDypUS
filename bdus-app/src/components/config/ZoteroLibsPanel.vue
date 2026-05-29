<template>
  <div class="cfg-panel">
    <div class="cfg-panel-header">
      <h2><i class="pi pi-book" /> {{ t('zotero_libraries') }}</h2>
      <Button
        :label="t('sync_all')"
        icon="pi pi-refresh"
        size="small"
        severity="secondary"
        outlined
        :loading="syncingAll"
        :disabled="libs.length === 0"
        @click="syncAll"
      />
    </div>

    <div class="panel-body">
      <p class="hint">{{ t('zotero_libs_hint') }}</p>

      <!-- ── Add library form ────────────────────────────────────── -->
      <section class="cfg-section">
        <div class="cfg-section-header">
          <span>{{ t('add_zotero_library') }}</span>
        </div>

        <div class="add-form">
          <!-- type -->
          <div class="form-field">
            <label>{{ t('library_type') }}</label>
            <SelectButton
              v-model="form.type"
              :options="typeOptions"
              option-label="label"
              option-value="value"
            />
          </div>

          <!-- Zotero ID -->
          <div class="form-field">
            <label>{{ t('zotero_id') }}</label>
            <InputText
              v-model="form.zotero_id"
              :placeholder="form.type === 'user' ? t('zotero_user_id_hint') : t('zotero_group_id_hint')"
              class="form-input"
            />
          </div>

          <!-- Display name -->
          <div class="form-field">
            <label>{{ t('name') }}</label>
            <InputText
              v-model="form.name"
              :placeholder="t('zotero_lib_name_hint')"
              class="form-input"
            />
          </div>

          <!-- API key (optional for public groups) -->
          <div class="form-field">
            <label>{{ t('api_key') }} <span class="optional">({{ t('optional') }})</span></label>
            <Password
              v-model="form.api_key"
              :placeholder="t('zotero_api_key_hint')"
              :feedback="false"
              toggleMask
              class="form-input"
            />
          </div>

          <!-- Citation style -->
          <div class="form-field">
            <label>{{ t('citation_style') }}</label>
            <InputText
              v-model="form.citation_style"
              :placeholder="t('citation_style_hint')"
              class="form-input"
            />
            <small class="field-hint">{{ t('citation_style_example') }}</small>
          </div>

          <div class="form-actions">
            <Button
              :label="t('add_zotero_library')"
              icon="pi pi-plus"
              :loading="adding"
              @click="addLib"
            />
          </div>
        </div>
      </section>

      <!-- ── Existing libraries ──────────────────────────────────── -->
      <section class="cfg-section">
        <div class="cfg-section-header">
          <span>{{ t('configured_libraries') }}</span>
        </div>

        <div v-if="loading" class="cfg-loading-center">
          <i class="pi pi-spin pi-spinner" />
        </div>

        <Message v-else-if="loadError" severity="error" :closable="false">{{ loadError }}</Message>

        <div v-else-if="libs.length === 0" class="cfg-empty-msg-sm">
          {{ t('no_zotero_libs') }}
        </div>

        <div v-else class="libs-list">
          <div
            v-for="lib in libs"
            :key="lib.id"
            class="lib-card"
          >
            <div class="lib-card-info">
              <div class="lib-card-name">
                <Tag
                  :value="lib.type"
                  :severity="lib.type === 'group' ? 'info' : 'secondary'"
                  class="type-tag"
                />
                {{ lib.name }}
              </div>
              <div class="lib-card-meta">
                <span class="meta-item">
                  <i class="pi pi-hashtag" />
                  {{ lib.zotero_id }}
                </span>
                <span class="meta-item">
                  <i class="pi pi-align-left" />
                  {{ lib.citation_style || 'chicago-author-date' }}
                </span>
                <span class="meta-item" :class="{ 'has-key': lib.has_api_key }">
                  <i :class="lib.has_api_key ? 'pi pi-lock' : 'pi pi-lock-open'" />
                  {{ lib.has_api_key ? t('api_key_set') : t('no_api_key') }}
                </span>
              </div>
            </div>

            <div class="lib-card-actions">
              <Button
                icon="pi pi-trash"
                text
                severity="danger"
                size="small"
                :title="t('delete')"
                :loading="deletingId === lib.id"
                @click="deleteLib(lib)"
              />
            </div>
          </div>
        </div>
      </section>

      <!-- Sync result message -->
      <Message
        v-if="syncResult"
        :severity="syncResult.severity"
        :closable="true"
        @close="syncResult = null"
      >
        {{ syncResult.text }}
      </Message>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import Button       from 'primevue/button'
import InputText    from 'primevue/inputtext'
import Password     from 'primevue/password'
import SelectButton from 'primevue/selectbutton'
import Message      from 'primevue/message'
import Tag          from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import { useI18n }  from '@/i18n'
import { api }      from '@/api'

const { t }  = useI18n()
const toast  = useToast()

// ── Data ──────────────────────────────────────────────────────────
const loading   = ref(false)
const loadError = ref(null)
const libs      = ref([])

const typeOptions = [
  { label: t('user_library'),  value: 'user'  },
  { label: t('group_library'), value: 'group' },
]

const form = reactive({
  type:           'group',
  zotero_id:      '',
  name:           '',
  api_key:        '',
  citation_style: '',
})

const adding     = ref(false)
const deletingId = ref(null)

// Sync state
const syncingAll = ref(false)
const syncResult = ref(null)   // { severity, text } | null

// ── Load ──────────────────────────────────────────────────────────
async function load() {
  loading.value   = true
  loadError.value = null
  try {
    const res = await api.get('/api/zotero/libs')
    if (res.status === 'error') throw new Error(t(res.code) || res.code)
    libs.value = res.libs ?? []
  } catch (e) {
    loadError.value = e.message
  } finally {
    loading.value = false
  }
}

// ── Add library ───────────────────────────────────────────────────
async function addLib() {
  if (!form.type || !form.zotero_id.trim() || !form.name.trim()) {
    toast.add({
      severity: 'warn',
      summary:  t('required_fields_missing'),
      detail:   `${t('library_type')}, ${t('zotero_id')}, ${t('name')}`,
      life:     4000,
    })
    return
  }

  adding.value = true
  try {
    const res = await api.post('/api/zotero/lib', {
      type:           form.type,
      zotero_id:      form.zotero_id.trim(),
      name:           form.name.trim(),
      api_key:        form.api_key.trim() || null,
      citation_style: form.citation_style.trim() || null,
    })

    if (res.status === 'error') {
      toast.add({ severity: 'error', summary: t('generic_error'), detail: t(res.code), life: 5000 })
      return
    }

    toast.add({ severity: 'success', summary: t('zotero_libraries'), detail: t('ok_lib_added'), life: 3000 })

    // Reset form
    form.zotero_id      = ''
    form.name           = ''
    form.api_key        = ''
    form.citation_style = ''

    await load()
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 5000 })
  } finally {
    adding.value = false
  }
}

// ── Delete library ────────────────────────────────────────────────
async function deleteLib(lib) {
  deletingId.value = lib.id
  try {
    const res = await api.delete(`/api/zotero/lib/${lib.id}`)
    if (res.status === 'error') {
      toast.add({ severity: 'error', summary: t('generic_error'), detail: t(res.code), life: 5000 })
      return
    }
    toast.add({ severity: 'success', summary: t('zotero_libraries'), detail: t('ok_lib_deleted'), life: 3000 })
    libs.value = libs.value.filter(l => l.id !== lib.id)
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 5000 })
  } finally {
    deletingId.value = null
  }
}

// ── Sync all ──────────────────────────────────────────────────────
async function syncAll() {
  syncingAll.value = true
  syncResult.value = null
  try {
    const res = await api.post('/api/zotero/sync')
    if (res.status === 'error') {
      syncResult.value = { severity: 'error', text: t(res.code) }
      return
    }
    const { updated = 0, detached = 0, errors = 0 } = res
    syncResult.value = {
      severity: errors > 0 ? 'warn' : 'success',
      text: t('zotero_sync_result', updated, detached, errors),
    }
    toast.add({ severity: syncResult.value.severity, summary: t('sync_all'), detail: syncResult.value.text, life: 5000 })
  } catch (e) {
    syncResult.value = { severity: 'error', text: e.message }
  } finally {
    syncingAll.value = false
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

/* ── Scrollable body ── */
.panel-body {
  flex: 1;
  overflow-y: auto;
  padding: 1rem 1.25rem;
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}

.hint {
  margin: 0;
  font-size: 0.875rem;
  color: var(--p-text-muted-color);
}

/* ── Section chrome ── */
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

/* ── Add form ── */
.add-form {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
  max-width: 480px;
}

.form-field {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
}

.form-field label {
  font-size: 0.82rem;
  font-weight: 600;
  color: var(--p-text-color);
}

.optional {
  font-weight: normal;
  color: var(--p-text-muted-color);
}

.form-input { width: 100%; }

.field-hint {
  font-size: 0.75rem;
  color: var(--p-text-muted-color);
}

.form-actions {
  display: flex;
  justify-content: flex-end;
  padding-top: 0.25rem;
}

/* ── Libraries list ── */
.libs-list {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.lib-card {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.6rem 0.75rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: 6px;
  background: var(--p-surface-50, #fafafa);
}

.lib-card-info {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.lib-card-name {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-weight: 600;
  font-size: 0.9rem;
}

.type-tag { flex-shrink: 0; }

.lib-card-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
}

.meta-item {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  font-size: 0.78rem;
  color: var(--p-text-muted-color);
}

.meta-item.has-key { color: var(--p-green-600, #16a34a); }

.lib-card-actions { flex-shrink: 0; }

/* ── Misc ── */
.cfg-loading-center {
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 2rem;
  color: var(--p-text-muted-color);
  padding: 2rem;
}

.cfg-empty-msg-sm {
  font-size: 0.85rem;
  color: var(--p-text-muted-color);
  padding: 0.5rem 0;
}
</style>
