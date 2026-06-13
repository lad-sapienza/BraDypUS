<template>
  <div class="cfg-panel">

    <!-- ── Header ─────────────────────────────────────────────────────── -->
    <div class="cfg-panel-header">
      <h2><i class="pi pi-file-export" /> {{ t('dbml_title') }}</h2>
    </div>

    <div class="panel-body">

    <!-- ── Export ─────────────────────────────────────────────────────── -->
    <section class="cfg-section">
      <div class="cfg-section-header">
        <span>{{ t('dbml_export') }}</span>
      </div>
      <p class="hint">{{ t('dbml_export_hint') }}</p>
      <Button
        :label="t('dbml_download')"
        icon="pi pi-download"
        :loading="exporting"
        @click="doExport"
      />
      <Message v-if="exportError" severity="error" :closable="false" class="mt-2">
        {{ exportError }}
      </Message>
    </section>

    <!-- ── Import ─────────────────────────────────────────────────────── -->
    <section class="cfg-section">
      <div class="cfg-section-header">
        <span>{{ t('dbml_import') }}</span>
      </div>
      <p class="hint">{{ t('dbml_import_hint') }}</p>

      <!-- Textarea + file reader -->
      <div class="dbml-input-row">
        <Textarea
          v-model="dbmlText"
          :placeholder="t('dbml_paste_placeholder')"
          rows="10"
          class="dbml-textarea"
          :disabled="previewing || applying"
        />
        <div class="dbml-input-actions">
          <label class="cfg-action-btn" :title="t('dbml_open_file')">
            <i class="pi pi-folder-open" />
            <span>{{ t('dbml_open_file') }}</span>
            <input type="file" accept=".dbml,.txt" style="display:none" @change="readFile" />
          </label>
          <Button
            :label="t('dbml_analyse')"
            icon="pi pi-search"
            :loading="previewing"
            :disabled="!dbmlText.trim()"
            @click="doPreview"
          />
        </div>
      </div>

      <!-- Parse error -->
      <Message v-if="parseError" severity="error" :closable="false" class="mt-2">
        {{ parseError }}
      </Message>

      <!-- ── Preview results ──────────────────────────────────────────── -->
      <template v-if="preview">

        <!-- Vocabularies -->
        <div v-if="preview.vocabularies.length" class="dbml-preview-block">
          <h4>{{ t('dbml_vocabularies_to_import') }}</h4>
          <div v-for="voc in preview.vocabularies" :key="voc.name" class="dbml-voc-row">
            <strong>{{ voc.name }}</strong>
            <span class="dbml-voc-values">{{ voc.values.join(', ') }}</span>
          </div>
        </div>

        <!-- Tables -->
        <div class="dbml-preview-block">
          <h4>{{ t('dbml_tables_to_import') }}</h4>

          <div
            v-for="tb in preview.tables"
            :key="tb.name"
            class="dbml-tb-row"
            :class="{ 'dbml-tb-error': tb.errors.length, 'dbml-tb-warn': !tb.errors.length && tb.warnings.length }"
          >
            <div class="dbml-tb-header">
              <span class="dbml-tb-name">{{ tb.name }}</span>
              <span v-if="tb.label" class="dbml-tb-label">{{ tb.label }}</span>
              <span v-if="tb.is_plugin" class="dbml-badge dbml-badge-info">plugin → {{ tb.plugin_of }}</span>
            </div>

            <!-- Hard errors (block apply) -->
            <div v-for="err in tb.errors" :key="err.code" class="dbml-msg dbml-msg-error">
              <i class="pi pi-times-circle" />
              {{ t(err.code, err) }}
            </div>

            <!-- Warnings (apply continues) -->
            <div v-for="w in tb.warnings" :key="w.code" class="dbml-msg dbml-msg-warn">
              <i class="pi pi-exclamation-triangle" />
              {{ t(w.code, w) }}
            </div>

            <!-- Field list (collapsed by default) -->
            <details v-if="!tb.errors.length" class="dbml-fields">
              <summary>{{ tb.fields.length }} {{ t('dbml_fields') }}</summary>
              <div class="dbml-field-list">
                <div v-for="f in tb.fields" :key="f.name" class="dbml-field-row">
                  <code>{{ f.name }}</code>
                  <span class="dbml-field-type">{{ f.type }} / {{ f.db_type }}</span>
                  <span v-if="f.vocabulary_set" class="dbml-badge dbml-badge-voc">voc: {{ f.vocabulary_set }}</span>
                  <span v-if="f.id_from_tb" class="dbml-badge dbml-badge-fk">→ {{ f.id_from_tb }}</span>
                </div>
              </div>
            </details>
          </div>
        </div>

        <!-- Apply / result -->
        <div class="dbml-apply-row">
          <Button
            :label="t('dbml_apply')"
            icon="pi pi-check"
            severity="success"
            :loading="applying"
            :disabled="preview.has_errors"
            @click="doApply"
          />
          <span v-if="preview.has_errors" class="dbml-apply-blocked">
            {{ t('dbml_has_errors') }}
          </span>
        </div>

        <!-- Apply result -->
        <Message v-if="applyResult" :severity="applyResult.severity" :closable="false" class="mt-2">
          {{ applyResult.text }}
        </Message>
      </template>

    </section>

    </div><!-- /.panel-body -->
  </div>
</template>

<script setup>
import { ref }        from 'vue'
import Button         from 'primevue/button'
import Message        from 'primevue/message'
import Textarea       from 'primevue/textarea'
import { useI18n }    from '@/i18n'
import { useToast }   from 'primevue/usetoast'
import { api }        from '@/api'
import { getToken }   from '@/token'
import { useRoute }   from 'vue-router'

const emit = defineEmits(['table-added'])

const { t }   = useI18n()
const toast   = useToast()
const route   = useRoute()

// ── Export ────────────────────────────────────────────────────────────────────
const exporting   = ref(false)
const exportError = ref(null)

async function doExport() {
  exporting.value   = true
  exportError.value = null
  try {
    const app   = route.params.app
    const token = getToken()
    const url   = `/api/config/dbml`
    const res   = await fetch(url, {
      headers: { Authorization: `Bearer ${token}` },
    })
    if (!res.ok) throw new Error(`HTTP ${res.status}`)
    const blob     = await res.blob()
    const objUrl   = URL.createObjectURL(blob)
    const a        = document.createElement('a')
    const filename = (res.headers.get('Content-Disposition') ?? '')
      .match(/filename="?([^";\r\n]+)"?/)?.[1] ?? `${app}.dbml`
    a.href = objUrl
    a.download = filename
    a.click()
    URL.revokeObjectURL(objUrl)
  } catch (e) {
    exportError.value = e.message
  } finally {
    exporting.value = false
  }
}

// ── Import ────────────────────────────────────────────────────────────────────
const dbmlText   = ref('')
const previewing = ref(false)
const applying   = ref(false)
const parseError = ref(null)
const preview    = ref(null)
const applyResult = ref(null)

function readFile(evt) {
  const file = evt.target.files?.[0]
  if (!file) return
  const reader = new FileReader()
  reader.onload = e => { dbmlText.value = e.target.result }
  reader.readAsText(file)
  // Reset input so the same file can be re-selected
  evt.target.value = ''
}

async function doPreview() {
  previewing.value = true
  parseError.value = null
  preview.value    = null
  applyResult.value = null
  try {
    const res = await api.post('/api/config/dbml/preview', { dbml: dbmlText.value })
    if (res.status === 'error') throw new Error(t(res.code))
    preview.value = res.preview
  } catch (e) {
    parseError.value = e.message
  } finally {
    previewing.value = false
  }
}

async function doApply() {
  applying.value    = true
  applyResult.value = null
  try {
    const res = await api.post('/api/config/dbml/apply', { dbml: dbmlText.value })
    if (res.status === 'error') throw new Error(t(res.code))
    const { created, skipped, warnings } = res.result
    const parts = []
    if (created.length)  parts.push(t('dbml_created_tables', { n: created.length, names: created.join(', ') }))
    if (skipped.length)  parts.push(t('dbml_skipped_tables', { n: skipped.length, names: skipped.join(', ') }))
    if (warnings.length) parts.push(t('dbml_warnings_count', { n: warnings.length }))
    applyResult.value = { severity: skipped.length ? 'warn' : 'success', text: parts.join(' · ') }
    if (created.length) emit('table-added')
    toast.add({ severity: 'success', summary: t('dbml_apply'), detail: parts[0], life: 4000 })
  } catch (e) {
    applyResult.value = { severity: 'error', text: e.message }
  } finally {
    applying.value = false
  }
}
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

.panel-body {
  flex: 1;
  overflow-y: auto;
  padding: 1rem 1.25rem;
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}

.hint {
  color: var(--p-text-muted-color);
  font-size: 0.875rem;
  margin-bottom: 0.75rem;
}
.mt-2 { margin-top: 0.5rem; }

/* ── Input area ─────────────────────────────────────────────── */
.dbml-input-row {
  display: flex;
  gap: 0.75rem;
  align-items: flex-start;
}
.dbml-textarea {
  flex: 1;
  font-family: monospace;
  font-size: 0.8rem;
  resize: vertical;
}
.dbml-input-actions {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  min-width: 9rem;
}

/* ── Preview blocks ─────────────────────────────────────────── */
.dbml-preview-block {
  margin-top: 1rem;
}
.dbml-preview-block h4 {
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--p-text-muted-color);
  text-transform: uppercase;
  letter-spacing: 0.04em;
  margin-bottom: 0.5rem;
}

/* Vocabulary rows */
.dbml-voc-row {
  display: flex;
  gap: 0.5rem;
  align-items: baseline;
  padding: 0.25rem 0;
  border-bottom: 1px solid var(--p-content-border-color);
}
.dbml-voc-values {
  color: var(--p-text-muted-color);
  font-size: 0.8rem;
}

/* Table rows */
.dbml-tb-row {
  border: 1px solid var(--p-content-border-color);
  border-radius: 6px;
  padding: 0.6rem 0.75rem;
  margin-bottom: 0.5rem;
  background: var(--p-content-background);
}
.dbml-tb-row.dbml-tb-error {
  border-color: var(--p-red-400);
  background: color-mix(in sRGB, var(--p-red-400) 8%, var(--p-content-background));
}
.dbml-tb-row.dbml-tb-warn {
  border-color: var(--p-yellow-400);
  background: color-mix(in sRGB, var(--p-yellow-400) 8%, var(--p-content-background));
}
.dbml-tb-header {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 0.25rem;
}
.dbml-tb-name {
  font-weight: 600;
  font-family: monospace;
}
.dbml-tb-label {
  color: var(--p-text-muted-color);
  font-size: 0.85rem;
}

/* Messages inside rows */
.dbml-msg {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.82rem;
  padding: 0.2rem 0;
}
.dbml-msg-error { color: var(--p-red-500); }
.dbml-msg-warn  { color: var(--p-yellow-600); }

/* Badges */
.dbml-badge {
  font-size: 0.72rem;
  padding: 0.1rem 0.4rem;
  border-radius: 3px;
  font-family: monospace;
}
.dbml-badge-info { background: var(--p-blue-100); color: var(--p-blue-700); }
.dbml-badge-voc  { background: var(--p-green-100); color: var(--p-green-700); }
.dbml-badge-fk   { background: var(--p-purple-100); color: var(--p-purple-700); }

/* Fields detail */
.dbml-fields { margin-top: 0.4rem; }
.dbml-fields summary {
  cursor: pointer;
  font-size: 0.8rem;
  color: var(--p-text-muted-color);
  user-select: none;
}
.dbml-field-list { padding: 0.4rem 0 0 1rem; }
.dbml-field-row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.8rem;
  padding: 0.1rem 0;
}
.dbml-field-type {
  color: var(--p-text-muted-color);
  font-size: 0.75rem;
}

/* Apply row */
.dbml-apply-row {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-top: 0.75rem;
}
.dbml-apply-blocked {
  font-size: 0.82rem;
  color: var(--p-red-500);
}
</style>
