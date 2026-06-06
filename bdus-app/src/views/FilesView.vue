<template>
  <AppLayout>
  <div class="files-view">

    <!-- ── Toolbar ────────────────────────────────────────────── -->
    <div class="files-toolbar">
      <span class="files-title">{{ t('files_mng_title') }}</span>

      <ToggleButton
        v-model="orphansOnly"
        :onLabel="t('files_orphans_only')"
        :offLabel="t('files_orphans_only')"
        onIcon="pi pi-filter"
        offIcon="pi pi-filter"
        size="small"
        @change="reload"
      />

      <Button
        icon="pi pi-refresh"
        :label="t('log_refresh')"
        size="small"
        severity="secondary"
        :loading="loading"
        @click="reload"
      />
    </div>

    <!-- ── Table ─────────────────────────────────────────────── -->
    <DataTable
      :value="files"
      lazy
      paginator
      :rows="perPage"
      :totalRecords="total"
      :loading="loading"
      :rowsPerPageOptions="[25, 50, 100]"
      paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink RowsPerPageDropdown CurrentPageReport"
      :currentPageReportTemplate="`{first}–{last} / {totalRecords}`"
      scrollable
      scrollHeight="flex"
      size="small"
      class="files-table"
      dataKey="id"
      @page="onPage"
    >
      <template #empty>
        <span class="empty-msg">{{ t('files_empty') }}</span>
      </template>

      <!-- Preview -->
      <Column :header="t('files_col_preview')" style="width: 80px; flex-shrink: 0;">
        <template #body="{ data }">
          <span class="preview-trigger" @click="openPreview(data)" :title="t('files_preview_hint')">
            <img
              v-if="data.is_image"
              :src="fileUrl(data)"
              :alt="data.filename"
              class="file-thumb"
            />
            <i v-else :class="['file-icon-lg', fileIcon(data.ext)]" />
          </span>
        </template>
      </Column>

      <!-- Filename -->
      <Column :header="t('files_col_filename')" style="width: 160px; flex-shrink: 0;">
        <template #body="{ data }">
          <span class="filename-link" @click="openPreview(data)">
            {{ data.filename }}.{{ data.ext }}
          </span>
          <div v-if="!data.links.length" class="orphan-badge">{{ t('files_orphan_badge') }}</div>
        </template>
      </Column>

      <!-- Description -->
      <Column :header="t('files_col_description')" style="min-width: 0; flex: 1;">
        <template #body="{ data }">
          <InputText
            :modelValue="data.description ?? ''"
            size="small"
            class="meta-input"
            @update:modelValue="v => data.description = v || null"
            @blur="saveMeta(data)"
            @keyup.enter="saveMeta(data)"
          />
        </template>
      </Column>

      <!-- Keywords -->
      <Column :header="t('files_col_keywords')" style="width: 200px; flex-shrink: 0;">
        <template #body="{ data }">
          <InputText
            :modelValue="data.keywords ?? ''"
            size="small"
            class="meta-input"
            @update:modelValue="v => data.keywords = v || null"
            @blur="saveMeta(data)"
            @keyup.enter="saveMeta(data)"
          />
        </template>
      </Column>

      <!-- Linked records -->
      <Column :header="t('files_col_links')" style="width: 200px; flex-shrink: 0;">
        <template #body="{ data }">
          <div v-if="data.links.length" class="links-list">
            <RouterLink
              v-for="(lnk, i) in data.links"
              :key="i"
              :to="`/${appName}/record/${lnk.tb}/${lnk.record_id}`"
              class="record-link"
            >
              {{ lnk.tb }} #{{ lnk.record_id }}
            </RouterLink>
          </div>
          <span v-else class="no-links">{{ t('files_no_links') }}</span>
        </template>
      </Column>

      <!-- Actions -->
      <Column style="width: 80px; flex-shrink: 0;">
        <template #body="{ data }">
          <div class="row-actions">
            <button class="action-btn" :title="t('replace_file')" @click="startReplace(data)">
              <i class="pi pi-sync" />
            </button>
            <button class="action-btn action-btn-danger" :title="t('delete_file')" @click="confirmDelete(data)">
              <i class="pi pi-trash" />
            </button>
          </div>
        </template>
      </Column>

    </DataTable>

    <!-- Hidden file input for replace -->
    <input
      ref="replaceInput"
      type="file"
      style="display: none"
      @change="onReplaceFileSelected"
    />

    <!-- File preview Dialog -->
    <Dialog
      v-model:visible="previewDialog"
      :header="previewFile ? `${previewFile.filename}.${previewFile.ext}` : ''"
      modal
      :style="previewFile?.is_image ? 'width: auto; max-width: 90vw' : 'width: 80vw; max-width: 1100px'"
      :pt="{ content: { style: 'padding: 0; overflow: hidden;' } }"
    >
      <template v-if="previewFile">
        <img
          v-if="previewFile.is_image"
          :src="fileUrl(previewFile)"
          :alt="previewFile.filename"
          style="display: block; max-width: 88vw; max-height: 82vh; object-fit: contain;"
        />
        <iframe
          v-else
          :src="fileUrl(previewFile)"
          style="width: 100%; height: 78vh; border: none; display: block;"
        />
      </template>
    </Dialog>

  </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { RouterLink, useRoute }     from 'vue-router'
import { useToast }   from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import AppLayout    from '@/components/AppLayout.vue'
import DataTable    from 'primevue/datatable'
import Column       from 'primevue/column'
import Button       from 'primevue/button'
import InputText    from 'primevue/inputtext'
import ToggleButton from 'primevue/togglebutton'
import Dialog       from 'primevue/dialog'
import { api, assetUrl } from '@/api'
import { useI18n }       from '@/i18n'
import { useAuthStore }  from '@/stores/auth'

const { t }   = useI18n()
const toast   = useToast()
const confirm = useConfirm()
const route   = useRoute()
const auth    = useAuthStore()

const appName = computed(() => auth.user?.app ?? route.params.app)

// ── State ──────────────────────────────────────────────────────────
const files       = ref([])
const total       = ref(0)
const loading     = ref(false)
const currentPage = ref(1)
const perPage     = ref(25)
const orphansOnly = ref(false)

// ── Fetch ───────────────────────────────────────────────────────────
async function fetchFiles() {
  loading.value = true
  try {
    const params = {
      page:         currentPage.value,
      per_page:     perPage.value,
      orphans_only: orphansOnly.value ? 1 : undefined,
    }
    const data = await api.get('/api/files', params)
    if (data.status === 'error') throw new Error(t(data.code))
    files.value = data.files ?? []
    total.value = data.total ?? 0
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 5000 })
  } finally {
    loading.value = false
  }
}

function reload() {
  currentPage.value = 1
  fetchFiles()
}

function onPage(event) {
  currentPage.value = event.page + 1
  perPage.value     = event.rows
  fetchFiles()
}

// ── Metadata save ──────────────────────────────────────────────────
async function saveMeta(file) {
  try {
    const res = await api.patch(`/api/file/${file.id}`, {
      description: file.description,
      keywords:    file.keywords,
    })
    if (res.status === 'error') {
      toast.add({ severity: 'error', summary: t('generic_error'), detail: t(res.code), life: 4000 })
    }
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 4000 })
  }
}

// ── Delete ─────────────────────────────────────────────────────────
function confirmDelete(file) {
  confirm.require({
    message:  t('confirm_delete_file'),
    header:   t('delete_file'),
    icon:     'pi pi-exclamation-triangle',
    severity: 'danger',
    accept:   () => doDelete(file),
  })
}

async function doDelete(file) {
  try {
    const res = await api.delete(`/api/file/${file.id}`)
    if (res.status === 'error') {
      toast.add({ severity: 'error', summary: t('generic_error'), detail: t(res.code), life: 5000 })
      return
    }
    toast.add({ severity: 'success', summary: t('delete_file'), detail: t('ok_file_deleted'), life: 3000 })
    files.value = files.value.filter(f => f.id !== file.id)
    total.value = Math.max(0, total.value - 1)
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 5000 })
  }
}

// ── Replace file ───────────────────────────────────────────────────
const replaceInput    = ref(null)
const replacingFileId = ref(null)

function startReplace(file) {
  replacingFileId.value = file.id
  replaceInput.value?.click()
}

async function onReplaceFileSelected(evt) {
  const file = evt.target.files?.[0]
  evt.target.value = ''
  if (!file || !replacingFileId.value) return

  try {
    const res = await api.upload(`/api/file/${replacingFileId.value}/replace`, file, 'file')
    if (res.status === 'error') {
      toast.add({ severity: 'error', summary: t('generic_error'), detail: t(res.code), life: 5000 })
      return
    }
    toast.add({ severity: 'success', summary: t('replace_file'), detail: t('ok_file_replaced'), life: 3000 })
    const target = files.value.find(f => f.id === replacingFileId.value)
    if (target) {
      target.ext      = res.ext
      target.filename = res.filename
      target.is_image = res.is_image
    }
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 5000 })
  } finally {
    replacingFileId.value = null
  }
}

// ── Preview ────────────────────────────────────────────────────────
const previewDialog = ref(false)
const previewFile   = ref(null)

function openPreview(file) {
  previewFile.value  = file
  previewDialog.value = true
}

// ── Helpers ─────────────────────────────────────────────────────────
function fileUrl(f) {
  return assetUrl(`projects/${appName.value}/files/${f.id}.${f.ext}`)
}

function fileIcon(ext) {
  const e = (ext ?? '').toLowerCase()
  if (['pdf'].includes(e))                        return 'pi pi-file-pdf'
  if (['doc', 'docx', 'odt', 'rtf'].includes(e)) return 'pi pi-file-word'
  if (['xls', 'xlsx', 'ods'].includes(e))        return 'pi pi-file-excel'
  if (['mp3', 'wav', 'ogg', 'wma'].includes(e))  return 'pi pi-volume-up'
  if (['mp4', 'mov', 'avi', 'mkv'].includes(e))  return 'pi pi-video'
  if (['zip', 'rar', 'tar', 'gz'].includes(e))   return 'pi pi-box'
  if (['svg', 'ai', 'eps'].includes(e))          return 'pi pi-pen-to-square'
  return 'pi pi-file'
}

onMounted(fetchFiles)
</script>

<style scoped>
.files-view {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-height: 0;
  overflow: hidden;
}

/* ── Toolbar ──────────────────────────────────────────────────── */
.files-toolbar {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.6rem 1rem;
  border-bottom: 1px solid var(--p-content-border-color);
  flex-shrink: 0;
}

.files-title {
  font-weight: 700;
  font-size: 1rem;
  margin-right: 0.5rem;
}

.files-toolbar .p-button:last-child { margin-left: auto; }

/* ── Table ────────────────────────────────────────────────────── */
.files-table {
  flex: 1;
  overflow: hidden;
  font-size: 0.82rem;
}

:deep(.p-datatable-tbody > tr > td) {
  vertical-align: middle;
  padding: 0.3rem 0.5rem;
}

/* ── Preview ──────────────────────────────────────────────────── */
.file-thumb {
  width: 60px;
  height: 45px;
  object-fit: cover;
  border-radius: 3px;
  border: 1px solid var(--p-content-border-color);
  display: block;
}

.file-icon-lg {
  font-size: 1.6rem;
  color: var(--p-text-muted-color);
  display: block;
  text-align: center;
}

/* ── Preview trigger ─────────────────────────────────────────────── */
.preview-trigger {
  cursor: zoom-in;
  display: block;
}

/* ── Filename ─────────────────────────────────────────────────── */
.filename-link {
  color: var(--p-primary-color);
  text-decoration: none;
  font-size: 0.78rem;
  word-break: break-all;
  cursor: pointer;
}
.filename-link:hover { text-decoration: underline; }

.orphan-badge {
  display: inline-block;
  margin-top: 2px;
  padding: 1px 5px;
  border-radius: 4px;
  font-size: 0.68rem;
  font-weight: 600;
  background: var(--p-orange-100, #fef3c7);
  color: var(--p-orange-700, #b45309);
  white-space: nowrap;
}

/* ── Meta inputs ──────────────────────────────────────────────── */
.meta-input {
  width: 100%;
}

/* ── Links ────────────────────────────────────────────────────── */
.links-list {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
}

.record-link {
  color: var(--p-primary-color);
  text-decoration: none;
  font-size: 0.78rem;
}
.record-link:hover { text-decoration: underline; }

.no-links {
  color: var(--p-text-muted-color);
  font-style: italic;
  font-size: 0.78rem;
}

/* ── Actions ──────────────────────────────────────────────────── */
.row-actions {
  display: flex;
  gap: 0.4rem;
  align-items: center;
}

.action-btn {
  background: none;
  border: none;
  padding: 0.2rem;
  cursor: pointer;
  color: var(--p-text-muted-color);
  font-size: 0.85rem;
  line-height: 1;
  border-radius: 3px;
}
.action-btn:hover { color: var(--p-primary-color); background: var(--p-content-hover-background); }
.action-btn-danger:hover { color: var(--p-red-500); }

/* ── Empty ────────────────────────────────────────────────────── */
.empty-msg {
  color: var(--p-text-muted-color);
  font-style: italic;
}
</style>
