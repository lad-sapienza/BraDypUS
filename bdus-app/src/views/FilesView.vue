<template>
  <AppLayout>
  <div class="files-view">

    <!-- ── Toolbar ────────────────────────────────────────────── -->
    <div class="files-toolbar">
      <span class="files-title">{{ t('files_mng_title') }}</span>

      <ARadio.Group v-model:value="viewMode" size="small" button-style="solid" data-test="view-switch" @change="onViewChange">
        <ARadio.Button value="table" :title="t('files_view_table')"><TableOutlined /></ARadio.Button>
        <ARadio.Button value="cards" :title="t('files_view_cards')"><AppstoreOutlined /></ARadio.Button>
      </ARadio.Group>

      <AButton
        :type="orphansOnly ? 'primary' : 'default'"
        size="small"
        @click="orphansOnly = !orphansOnly; reload()"
      >
        <template #icon><FilterOutlined /></template>
        {{ t('files_orphans_only') }}
      </AButton>

      <ASelect v-model:value="publicFilter" size="small" class="files-public-filter" :options="publicOptions" @change="reload" />

      <AInput
        v-model:value="searchQuery"
        size="small"
        allow-clear
        :placeholder="t('files_search_placeholder')"
        class="files-search"
        @update:value="onSearchInput"
      >
        <template #prefix><SearchOutlined /></template>
      </AInput>

      <APopover v-if="viewMode === 'table' || viewMode === 'cards'" trigger="click" placement="bottomRight">
        <template #content>
          <div class="cols-chooser" data-test="columns-chooser">
            <ACheckbox
              v-for="f in chooserFields"
              :key="f.name"
              :checked="shownNames.includes(f.name)"
              @change="toggleColumn(f.name)"
            >{{ colTitle(f) }}</ACheckbox>
          </div>
        </template>
        <AButton size="small"><template #icon><SettingOutlined /></template>{{ t('files_columns') }}</AButton>
      </APopover>

      <AButton size="small" :loading="loading" @click="reload">
        <template #icon><ReloadOutlined /></template>
        {{ t('log_refresh') }}
      </AButton>
    </div>

    <!-- ── Bulk actions: only when something is selected ──────── -->
    <div v-if="selected.length" class="files-bulk" data-test="bulk-bar">
      <span class="bulk-count">{{ t('files_selected', { n: selected.length }) }}</span>
      <AButton size="small" :disabled="!selectedUpdatable.length" @click="bulkPublic(true)">{{ t('files_bulk_public') }}</AButton>
      <AButton size="small" :disabled="!selectedUpdatable.length" @click="bulkPublic(false)">{{ t('files_bulk_private') }}</AButton>
      <AButton size="small" danger :disabled="!selectedDeletable.length" @click="confirmBulkDelete">
        <template #icon><DeleteOutlined /></template>{{ t('files_bulk_delete', { n: selectedDeletable.length }) }}
      </AButton>
      <AButton size="small" type="text" @click="selected = []">{{ t('files_clear_selection') }}</AButton>
    </div>

    <!-- ── Table view ─────────────────────────────────────────── -->
    <div v-show="viewMode === 'table'" ref="tableWrap" class="files-table">
      <ATable
        :columns="columns"
        :dataSource="files"
        :loading="loading"
        :pagination="pagination"
        :row-selection="rowSelection"
        :scroll="{ y: tableScrollY }"
        :locale="{ emptyText: t('files_empty') }"
        size="small"
        rowKey="id"
        @change="onTableChange"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'preview'">
            <span class="preview-trigger" @click="openPreview(record)" :title="t('files_preview_hint')">
              <img
                v-if="record.is_image"
                :src="thumbUrl(record, fileUrl(record))"
                :alt="record.filename"
                class="file-thumb"
                loading="lazy"
              />
              <component v-else :is="fileIcon(record.ext)" class="file-icon-lg" />
            </span>
          </template>
          <template v-else-if="column.key === 'filename'">
            <div class="filename-edit-row">
              <AInput
                :value="record.filename"
                size="small"
                class="meta-input filename-input"
                :disabled="!record.can_update"
                @update:value="v => record.filename = v"
                @blur="saveMeta(record)"
                @keyup.enter="saveMeta(record)"
              />
              <span class="filename-ext">.{{ record.ext }}</span>
            </div>
            <div v-if="!record.links.length" class="orphan-badge">{{ t('files_orphan_badge') }}</div>
          </template>
          <template v-else-if="column.key === 'links'">
            <div v-if="record.links.length" class="links-list">
              <RouterLink
                v-for="(lnk, i) in record.links"
                :key="i"
                :to="`/${appName}/record/${lnk.tb}/${lnk.record_id}`"
                class="record-link"
              >
                {{ lnk.tb }} #{{ lnk.record_id }}
              </RouterLink>
            </div>
            <span v-else class="no-links">{{ t('files_no_links') }}</span>
          </template>
          <template v-else-if="column.key === 'actions'">
            <div class="row-actions">
              <button class="action-btn" :title="t('files_details')" @click="openDetails(record)">
                <EditOutlined />
              </button>
              <button class="action-btn" :disabled="!record.can_update" :title="t('replace_file')" @click="startReplace(record)">
                <SyncOutlined />
              </button>
              <button class="action-btn action-btn-danger" :disabled="!record.can_delete" :title="t('delete_file')" @click="confirmDelete(record)">
                <DeleteOutlined />
              </button>
            </div>
          </template>
          <!-- Any other configured field (system or custom) -->
          <template v-else-if="fieldType(column.key) === 'boolean'">
            <ASwitch
              size="small"
              :checked="!!record[column.key]"
              :disabled="!record.can_update || !isEditable(column.key)"
              @update:checked="v => { record[column.key] = v; saveMeta(record) }"
            />
          </template>
          <template v-else-if="isTextual(fieldType(column.key)) && isEditable(column.key)">
            <AInput
              :value="record[column.key] ?? ''"
              size="small"
              class="meta-input"
              :disabled="!record.can_update"
              @update:value="v => record[column.key] = v || null"
              @blur="saveMeta(record)"
              @keyup.enter="saveMeta(record)"
            />
          </template>
          <template v-else>
            <span class="plain-cell">{{ record[column.key] ?? '' }}</span>
          </template>
        </template>
      </ATable>
    </div>

    <!-- ── Cards view ─────────────────────────────────────────── -->
    <div v-if="viewMode === 'cards'" class="files-cards-wrap">
      <FilesCards
        :files="files"
        :fields="fieldsMeta"
        :visible="visible"
        :selected="selected"
        :loading="loading"
        @toggle="toggleSelected"
        @open="openPreview"
        @edit="openDetails"
        @replace="startReplace"
        @delete="confirmDelete"
      />
      <div class="cards-pagination">
        <APagination
          :current="currentPage"
          :page-size="perPage"
          :total="total"
          :page-size-options="['25', '50', '100']"
          show-size-changer
          size="small"
          @change="onCardsPage"
        />
      </div>
    </div>

    <!-- Hidden file input for replace -->
    <input
      ref="replaceInput"
      type="file"
      style="display: none"
      @change="onReplaceFileSelected"
    />

    <FileDetailsDrawer
      v-model:open="detailsOpen"
      :file="detailsFile"
      :fields="fieldsMeta"
      :app-name="appName"
      :saving="detailsSaving"
      @save="onDetailsSave"
      @replace="startReplace"
    />

    <!-- File preview Dialog -->
    <AModal
      v-model:open="previewDialog"
      :title="previewFile ? `${previewFile.filename}.${previewFile.ext}` : ''"
      width="calc(100vw - 32px)"
      :style="{ top: '16px' }"
      wrap-class-name="file-preview-modal"
      :footer="previewNav ? undefined : null"
    >
      <!-- Previous / Next across every file the list matches, not just this page -->
      <template v-if="previewNav" #footer>
        <div class="preview-nav">
          <AButton size="small" :disabled="!previewNav.prev" :title="t('file_nav_prev')" @click="goToPreview(previewNav.prev)">
            <template #icon><LeftOutlined /></template>
          </AButton>
          <span class="preview-nav-pos">{{ t('record_nav_position', { n: previewNav.index + 1, total: previewNav.total + (previewTruncated ? '+' : '') }) }}</span>
          <AButton size="small" :disabled="!previewNav.next" :title="t('file_nav_next')" @click="goToPreview(previewNav.next)">
            <template #icon><RightOutlined /></template>
          </AButton>
        </div>
      </template>
      <template v-if="previewFile">
        <img
          v-if="previewFile.is_image"
          :src="fileUrl(previewFile)"
          :alt="previewFile.filename"
          class="file-preview-img"
        />
        <iframe
          v-else
          :src="fileUrl(previewFile)"
          class="file-preview-frame"
        />
      </template>
    </AModal>

  </div>
  </AppLayout>
</template>

<script setup>
import { AppstoreOutlined, DeleteOutlined, EditOutlined, FilterOutlined, LeftOutlined, ReloadOutlined, RightOutlined, SearchOutlined, SettingOutlined, SyncOutlined, TableOutlined } from '@ant-design/icons-vue'
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { RouterLink, useRoute }     from 'vue-router'
import { useToast, useConfirm } from '@/composables/useNotify'
import AppLayout    from '@/components/AppLayout.vue'
import FilesCards   from '@/components/files/FilesCards.vue'
import FileDetailsDrawer from '@/components/files/FileDetailsDrawer.vue'
import { fileIcon } from '@/components/files/fileIcon'
import {
  Table as ATable, Button as AButton, Input as AInput, Modal as AModal, Radio as ARadio, Select as ASelect,
  Popover as APopover, Checkbox as ACheckbox, Switch as ASwitch, Pagination as APagination
} from 'ant-design-vue'
import { api, apiUrl } from '@/api'
import { useI18n }       from '@/i18n'
import { useAuthStore }  from '@/stores/auth'
import { thumbUrl } from '@/utils/thumbUrl'
import { originalPath } from '@/utils/filePaths'
import {
  columnFields, visibleColumns, defaultVisible, snapshotOf, changedFields, stripExt, isTextual,
  deletable, updatable, loadPrefs, savePrefs, editableNames,
} from '@/utils/filesList'

const { t }   = useI18n()
const toast   = useToast()
const confirm = useConfirm()
const route   = useRoute()
const auth    = useAuthStore()

const appName = computed(() => auth.user?.app ?? route.params.app)

// ── What the page shows: the fields the server describes (system and custom) ──
const fieldsMeta = ref([])
const visible    = ref([])        // the columns the user chose ([] = the default)
const viewMode   = ref('cards')

const fieldType  = name => fieldsMeta.value.find(f => f.name === name)?.type
const isEditable = name => editableNames(fieldsMeta.value).includes(name)

/** The label of a column: the application's own translation for a system field, else its configured label. */
function colTitle(f) {
  const k = `files_col_${f.name}`
  const tr = t(k)
  return tr !== k ? tr : f.label
}

const chooserFields = computed(() => columnFields(fieldsMeta.value))
const shownNames    = computed(() => visibleColumns(fieldsMeta.value, visible.value).map(f => f.name))

function toggleColumn(name) {
  const now = new Set(shownNames.value)
  now.has(name) ? now.delete(name) : now.add(name)
  visible.value = columnFields(fieldsMeta.value).map(f => f.name).filter(n => now.has(n))
}

// ── State ──────────────────────────────────────────────────────────
const files        = ref([])
const total        = ref(0)
const loading      = ref(false)
const currentPage  = ref(1)
const perPage      = ref(25)
const orphansOnly  = ref(false)
const publicFilter = ref('')
const searchQuery  = ref('')
const sortField    = ref(null)
const sortDir      = ref('desc')
const selected     = ref([])
let searchDebounce = null

const publicOptions = computed(() => [
  { value: '',  label: t('files_public_all') },
  { value: '1', label: t('files_public_yes') },
  { value: '0', label: t('files_public_no') },
])

const columns = computed(() => [
  { title: t('files_col_preview'), key: 'preview', width: 80 },
  ...visibleColumns(fieldsMeta.value, visible.value).map(f => ({
    title: colTitle(f),
    key: f.name,
    dataIndex: f.name,
    sorter: true,
    sortOrder: sortField.value === f.name ? (sortDir.value === 'asc' ? 'ascend' : 'descend') : null,
    width: f.name === 'filename' ? 190 : (f.type === 'boolean' ? 90 : 200),
  })),
  { title: t('files_col_links'), key: 'links',   width: 190 },
  { title: '',                   key: 'actions', width: 110 },
])

const pagination = computed(() => ({
  current: currentPage.value,
  pageSize: perPage.value,
  total: total.value,
  showSizeChanger: true,
  pageSizeOptions: ['25', '50', '100'],
  position: ['bottomCenter'],
}))

const rowSelection = computed(() => ({
  selectedRowKeys: selected.value,
  onChange: keys => { selected.value = keys },
}))

const selectedDeletable = computed(() => deletable(files.value, selected.value))
const selectedUpdatable = computed(() => updatable(files.value, selected.value))

function toggleSelected(id) {
  selected.value = selected.value.includes(id) ? selected.value.filter(x => x !== id) : [...selected.value, id]
}

// ── AntD Table: fill available flex space (no scrollHeight="flex" equivalent) ──
const tableWrap    = ref(null)
const tableScrollY = ref(400)
let resizeObs = null

function measureTableHeight() {
  if (!tableWrap.value) return
  const total_      = tableWrap.value.clientHeight
  const headerH     = tableWrap.value.querySelector('.ant-table-thead')?.getBoundingClientRect().height ?? 40
  const paginationH = tableWrap.value.querySelector('.ant-pagination')?.getBoundingClientRect().height ?? 32
  tableScrollY.value = Math.max(200, total_ - headerH - paginationH - 16)
}

onMounted(() => {
  resizeObs = new ResizeObserver(measureTableHeight)
  if (tableWrap.value) resizeObs.observe(tableWrap.value)
})
onUnmounted(() => resizeObs?.disconnect())
watch(files, () => { measureTableHeight() })

// ── What the user chose is remembered, per application ─────────────
onMounted(() => {
  const p = loadPrefs(window.localStorage, appName.value)
  viewMode.value = p.view
  visible.value  = p.visible
})
watch([viewMode, visible], () => savePrefs(window.localStorage, appName.value, { view: viewMode.value, visible: visible.value }), { deep: true })
function onViewChange() { /* the watcher saves it */ }

// ── Fetch ───────────────────────────────────────────────────────────
/** The filters of the current list: what Previous/Next must follow too. */
function listParams() {
  return {
    orphans_only: orphansOnly.value ? 1 : undefined,
    public:       publicFilter.value === '' ? undefined : publicFilter.value,
    search:       searchQuery.value || undefined,
    sort_field:   sortField.value || undefined,
    sort_dir:     sortField.value ? sortDir.value : undefined,
  }
}

async function fetchFiles() {
  loading.value = true
  try {
    const data = await api.get('/api/files', { page: currentPage.value, per_page: perPage.value, ...listParams() })
    if (data.status === 'error') throw new Error(t(data.code))
    if (data.fields) fieldsMeta.value = data.fields
    files.value = (data.files ?? []).map(f => {
      const file = { ...f, filename: stripExt(f.filename, f.ext) }
      return { ...file, _meta: snapshotOf(file, fieldsMeta.value) }
    })
    total.value = data.total ?? 0
    // A selection never outlives its page
    selected.value = selected.value.filter(id => files.value.some(f => f.id === id))
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 5000 })
  } finally {
    loading.value = false
  }
}

function reload() {
  currentPage.value = 1
  selected.value = []
  fetchFiles()
}

function onSearchInput() {
  clearTimeout(searchDebounce)
  searchDebounce = setTimeout(reload, 350)
}

function onTableChange(paginationEvt, _filters, sorter) {
  const field = sorter?.order ? sorter.columnKey ?? sorter.field : null
  const dir   = sorter?.order === 'ascend' ? 'asc' : 'desc'
  const sortChanged = field !== sortField.value || (field && dir !== sortDir.value)
  sortField.value = field
  sortDir.value   = dir
  currentPage.value = sortChanged ? 1 : paginationEvt.current
  perPage.value     = paginationEvt.pageSize
  fetchFiles()
}

function onCardsPage(page, size) {
  currentPage.value = size !== perPage.value ? 1 : page
  perPage.value     = size
  fetchFiles()
}

// ── Metadata save ──────────────────────────────────────────────────
// The extension is shown separately (see .filename-ext) and never part of the editable value.
// blur/enter fire on every focus-out or keypress regardless of whether the value changed:
// comparing against the last-saved snapshot is what skips the no-op API calls.
async function saveMeta(file) {
  file.filename = stripExt(file.filename, file.ext)
  const changes = changedFields(file, file._meta, fieldsMeta.value)
  if (!Object.keys(changes).length) return

  try {
    const res = await api.patch(`/api/file/${file.id}`, changes)
    if (res.status === 'error') {
      toast.add({ severity: 'error', summary: t('generic_error'), detail: t(res.code), life: 4000 })
      return
    }
    file._meta = snapshotOf(file, fieldsMeta.value)
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 4000 })
  }
}

// ── Details drawer: every field, custom ones included ──────────────
const detailsOpen   = ref(false)
const detailsFile   = ref(null)
const detailsSaving = ref(false)

function openDetails(file) {
  detailsFile.value = file
  detailsOpen.value = true
}

async function onDetailsSave({ file, changes }) {
  detailsSaving.value = true
  try {
    const res = await api.patch(`/api/file/${file.id}`, changes)
    if (res.status === 'error') {
      toast.add({ severity: 'error', summary: t('generic_error'), detail: t(res.code), life: 4000 })
      return
    }
    Object.assign(file, changes)
    file._meta = snapshotOf(file, fieldsMeta.value)
    toast.add({ severity: 'success', summary: t('files_details'), detail: t('ok_file_updated'), life: 2500 })
    detailsOpen.value = false
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 4000 })
  } finally {
    detailsSaving.value = false
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
    selected.value = selected.value.filter(id => id !== file.id)
    total.value = Math.max(0, total.value - 1)
  } catch (e) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: e.message, life: 5000 })
  }
}

// ── Bulk actions: only on the files the principal may change ───────
function confirmBulkDelete() {
  const n = selectedDeletable.value.length
  confirm.require({
    message:  t('confirm_delete_files', { n }),
    header:   t('delete_file'),
    icon:     'pi pi-exclamation-triangle',
    severity: 'danger',
    accept:   doBulkDelete,
  })
}

async function doBulkDelete() {
  let done = 0
  for (const f of selectedDeletable.value) {
    try {
      const res = await api.delete(`/api/file/${f.id}`)
      if (res.status !== 'error') done++
    } catch { /* counted as not deleted */ }
  }
  toast.add({ severity: done ? 'success' : 'error', summary: t('delete_file'), detail: t('files_bulk_done', { n: done }), life: 4000 })
  selected.value = []
  fetchFiles()
}

async function bulkPublic(value) {
  let done = 0
  for (const f of selectedUpdatable.value) {
    try {
      const res = await api.patch(`/api/file/${f.id}`, { public: value })
      if (res.status !== 'error') { f.public = value; f._meta = snapshotOf(f, fieldsMeta.value); done++ }
    } catch { /* counted as not changed */ }
  }
  toast.add({ severity: done ? 'success' : 'error', summary: t('files_col_public'), detail: t('files_bulk_done', { n: done }), life: 3000 })
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
      target.filename = stripExt(res.filename, res.ext)
      target.is_image = res.is_image
      if (target._meta) target._meta.filename = target.filename
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

// Every file the current list matches (same filters, same order), minimal
// descriptors in list order — fetched once per opening so Previous/Next can
// step past the visible page.
const previewList      = ref([])
const previewTruncated = ref(false)
let previewListToken   = 0

async function openPreview(file) {
  previewFile.value  = file
  previewDialog.value = true
  previewList.value  = []
  previewTruncated.value = false

  const token = ++previewListToken
  try {
    const data = await api.get('/api/files', { ids_only: 1, ...listParams() })
    // Closed, or reopened on another file, while the request was in flight.
    if (token !== previewListToken || data.status !== 'success') return
    previewList.value      = data.files ?? []
    previewTruncated.value = !!data.truncated
  } catch {
    // No list → no Previous/Next; the preview itself still works.
  }
}

const previewNav = computed(() => {
  if (!previewFile.value) return null
  const list  = previewList.value
  const index = list.findIndex(f => f.id === previewFile.value.id)
  if (index < 0) return null
  return {
    index,
    total: list.length,
    prev:  index > 0 ? list[index - 1] : null,
    next:  index < list.length - 1 ? list[index + 1] : null,
  }
})

function goToPreview(f) {
  if (!f) return
  previewFile.value = { ...f, filename: stripExt(f.filename, f.ext) }
}

// ← / → while the preview is open (not while typing in a field).
function onPreviewKey(e) {
  if (!previewDialog.value) return
  // Ant only hears Esc while focus is inside the modal; a Previous/Next button
  // that just became disabled (first/last file) drops focus to <body>.
  if (e.key === 'Escape') { previewDialog.value = false; return }
  if (!previewNav.value) return
  if (e.metaKey || e.ctrlKey || e.altKey || e.shiftKey) return
  if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return
  const el = e.target
  if (el instanceof Element && el.closest('input, textarea, select, [contenteditable="true"]')) return
  e.preventDefault()
  goToPreview(e.key === 'ArrowLeft' ? previewNav.value.prev : previewNav.value.next)
}
onMounted(() => window.addEventListener('keydown', onPreviewKey))
onUnmounted(() => window.removeEventListener('keydown', onPreviewKey))

watch(previewDialog, open => { if (!open) previewListToken++ })

// ── Helpers ─────────────────────────────────────────────────────────
function fileUrl(f) {
  const path = originalPath(f)
  return path ? apiUrl(path) : ''
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

.files-toolbar :deep(.ant-btn:last-child) { margin-left: auto; }

.files-search {
  width: 260px;
}

/* ── Table ────────────────────────────────────────────────────── */
.files-table {
  flex: 1;
  overflow: hidden;
  font-size: 0.82rem;
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
.preview-nav {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 10px;
}
.preview-nav-pos {
  font-size: 12px;
  color: var(--ant-color-text-secondary, #666);
  font-variant-numeric: tabular-nums;
}
.preview-trigger {
  cursor: zoom-in;
  display: block;
}

/* ── Filename ─────────────────────────────────────────────────── */
.filename-edit-row {
  display: flex;
  align-items: center;
  gap: 0.25rem;
}

.filename-input {
  min-width: 0;
  flex: 1;
}

.filename-ext {
  color: var(--p-text-muted-color);
  font-size: 0.78rem;
  white-space: nowrap;
}

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

/* ── Bulk bar, cards, columns ─────────────────────────────────── */
.files-bulk {
  display: flex; align-items: center; gap: 0.5rem;
  padding: 0.4rem 1rem;
  background: var(--p-content-hover-background);
  border-bottom: 1px solid var(--p-content-border-color);
  flex-shrink: 0;
}
.bulk-count { font-weight: 600; font-size: 0.82rem; margin-right: 0.4rem; }
.files-public-filter { width: 130px; }
.files-cards-wrap { flex: 1; min-height: 0; display: flex; flex-direction: column; overflow: hidden; }
.files-cards-wrap :deep(.files-cards) { flex: 1; min-height: 0; }
.cards-pagination { display: flex; justify-content: center; padding: 0.5rem; flex-shrink: 0; }
.cols-chooser { display: flex; flex-direction: column; gap: 0.35rem; min-width: 180px; max-height: 320px; overflow: auto; }
.plain-cell { font-size: 0.8rem; }
.action-btn:disabled { opacity: .35; cursor: not-allowed; }
</style>

<!-- Not scoped: the modal is teleported to <body>, outside this component. -->
<style>
/* The preview always opens (almost) full screen; the body takes whatever is
   left after the header/footer, so a tall image can never push the footer
   off-screen and the image fits the space instead of a guessed vh. */
.file-preview-modal .ant-modal {
  max-width: none;
  margin: 0 auto;
  padding-bottom: 0;
}
.file-preview-modal .ant-modal-content {
  display: flex;
  flex-direction: column;
  height: calc(100vh - 32px);
}
.file-preview-modal .ant-modal-body {
  flex: 1;
  min-height: 0;
  padding: 0;
  overflow: hidden;
  display: flex;
  justify-content: center;
  align-items: center;
}
/* Shrinks to fit, never enlarges: a small image keeps its natural size. */
.file-preview-modal .file-preview-img {
  display: block;
  max-width: 100%;
  max-height: 100%;
  object-fit: contain;
}
.file-preview-modal .file-preview-frame {
  display: block;
  width: 100%;
  height: 100%;
  border: none;
}
</style>
