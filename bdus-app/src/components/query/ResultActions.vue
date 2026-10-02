<template>
  <!-- Small screens: every secondary action behind one menu -->
  <ADropdown v-if="compact" :trigger="['click']" placement="bottomRight">
    <AButton type="text" :title="t('more_actions')"><EllipsisOutlined /></AButton>
    <template #overlay>
      <AMenu @click="onMenu">
        <AMenuItem key="saved"><PushpinOutlined /> {{ t('saved_queries') }}</AMenuItem>
        <AMenuDivider />
        <AMenuItem v-if="list.columns.length" key="columns"><TableOutlined /> {{ t('preview_fields') }}</AMenuItem>
        <ASubMenu v-if="list.total > 0" key="export">
          <template #title><DownloadOutlined /> {{ t('export') }}</template>
          <AMenuItem key="export-csv">CSV</AMenuItem>
          <AMenuItem key="export-xlsx">XLSX</AMenuItem>
          <AMenuItem key="export-json">JSON</AMenuItem>
        </ASubMenu>
        <AMenuDivider />
        <AMenuItem key="chart"><BarChartOutlined /> {{ t('create_chart_from_search') }}</AMenuItem>
        <AMenuItem key="map"><CompassOutlined /> {{ t('view_on_map') }}</AMenuItem>
        <AMenuItem v-if="table?.fuzzy_date" key="timeline"><CalendarOutlined /> {{ t('chrono_timeline') }}</AMenuItem>
        <AMenuItem v-if="table?.rs" key="matrix"><ApartmentOutlined /> {{ t('harris_matrix') }}</AMenuItem>
      </AMenu>
    </template>
  </ADropdown>

  <template v-else>
    <ADivider type="vertical" />

    <!-- Column visibility -->
    <APopover v-if="list.columns.length" v-model:open="columnsOpen" trigger="click" placement="bottom">
      <template #content>
        <div class="col-toggler-popover">
          <div class="col-toggler-header">{{ t('preview_fields') }}</div>
          <ColumnList :prefs="prefs" />
        </div>
      </template>
      <AButton type="text" :title="t('preview_fields')" size="small"><TableOutlined /></AButton>
    </APopover>

    <!-- Export -->
    <APopover v-if="list.total > 0" v-model:open="exportOpen" trigger="click" placement="bottom">
      <template #content>
        <div class="col-toggler-popover">
          <div class="col-toggler-header">{{ t('export') }} ({{ list.total }} {{ t('records') }})</div>
          <div class="col-toggler-list">
            <div class="col-toggler-item" @click="exportAs('csv')"><FileOutlined /><span>CSV</span></div>
            <div class="col-toggler-item" @click="exportAs('xlsx')"><FileExcelOutlined /><span>XLSX</span></div>
            <div class="col-toggler-item" @click="exportAs('json')"><FileTextOutlined /><span>JSON</span></div>
          </div>
        </div>
      </template>
      <AButton type="text" :title="t('export')" size="small"><DownloadOutlined /></AButton>
    </APopover>

    <!-- Saved searches -->
    <AButton type="text" :title="t('saved_queries')" size="small" @click="savedOpen = true">
      <PushpinOutlined />
    </AButton>

    <!-- Create a chart from this view / search -->
    <AButton type="text" :title="t('create_chart_from_search')" size="small" @click="actions.createChart">
      <BarChartOutlined />
    </AButton>

    <!-- View on map -->
    <AButton type="text" :title="t('view_on_map')" size="small" @click="actions.openGeoface">
      <CompassOutlined />
    </AButton>

    <!-- Chronological timeline — only for tables with the fuzzy_date plugin -->
    <AButton v-if="table?.fuzzy_date" type="text" :title="t('chrono_timeline')" size="small" @click="actions.openTimeline">
      <CalendarOutlined />
    </AButton>

    <!-- Harris Matrix — only for tables with the RS plugin enabled -->
    <AButton v-if="table?.rs" type="text" :title="t('harris_matrix')" size="small" @click="actions.openMatrix">
      <ApartmentOutlined />
    </AButton>

    <!-- Add record — below the compact breakpoint the floating + button covers it -->
    <AButton v-if="list.canAdd" type="primary" size="small" class="add-record-btn" @click="actions.addRecord">
      <PlusOutlined /> {{ t('new_record') }}
    </AButton>
  </template>

  <!-- Dialogs live outside the toolbar so they work from both layouts -->
  <AModal v-model:open="savedOpen" :title="t('saved_queries')" :footer="null" width="36rem">
    <SavedQueriesPanel
      :currentSearch="query.currentSearch"
      :currentTb="table?.name ?? ''"
      @load-query="onLoadSaved"
    />
  </AModal>
  <AModal v-model:open="columnsDialog" :title="t('preview_fields')" :footer="null" width="28rem">
    <ColumnList :prefs="prefs" />
  </AModal>
</template>

<script setup>
// The record list's result actions: columns, export, saved searches, chart,
// map, timeline, matrix, new record — as an icon row, or behind one "⋯" menu on
// small screens. The state it needs arrives as reactive objects from DataView.
import { ref, watch } from 'vue'
import {
  ApartmentOutlined, BarChartOutlined, CalendarOutlined, CompassOutlined, DownloadOutlined, EllipsisOutlined,
  FileExcelOutlined, FileOutlined, FileTextOutlined, PlusOutlined, PushpinOutlined, TableOutlined,
} from '@ant-design/icons-vue'
import {
  Divider as ADivider, Popover as APopover, Modal as AModal, Button as AButton, Dropdown as ADropdown, Menu as AMenu,
} from 'ant-design-vue'
import { useI18n } from '@/i18n'
import SavedQueriesPanel from '@/components/SavedQueriesPanel.vue'
import ColumnList from './ColumnList.vue'

const props = defineProps({
  compact: { type: Boolean, default: false },
  table:   { type: Object, default: null },        // the selected table
  list:    { type: Object, required: true },        // reactive(useRecordList(...)): columns, total, canAdd
  prefs:   { type: Object, required: true },        // reactive(useColumnPrefs(...))
  query:   { type: Object, required: true },        // reactive(useRecordQuery(...)): currentSearch, loadSaved
  actions: { type: Object, required: true },        // useResultActions(...)
})

const AMenuItem    = AMenu.Item
const ASubMenu     = AMenu.SubMenu
const AMenuDivider = AMenu.Divider
const { t } = useI18n()

const columnsOpen    = ref(false)   // the popover (icon row)
const exportOpen     = ref(false)
const columnsDialog  = ref(false)   // the dialog (compact menu)
const savedOpen      = ref(false)

// AntD's Popover is a plain v-model per instance: two triggers can be open at
// once with no built-in exclusivity. Enforce it.
watch(columnsOpen, v => { if (v) exportOpen.value = false })
watch(exportOpen,  v => { if (v) columnsOpen.value = false })
// The dialog only exists in the compact layout.
watch(() => props.compact, v => { if (!v) columnsDialog.value = false })

function exportAs(format) {
  exportOpen.value = false
  props.actions.exportAs(format)
}

function onLoadSaved(payload) {
  if (props.query.loadSaved(payload)) savedOpen.value = false
}

function onMenu({ key }) {
  switch (key) {
    case 'saved':    savedOpen.value = true; break
    case 'columns':  columnsDialog.value = true; break
    case 'chart':    props.actions.createChart(); break
    case 'map':      props.actions.openGeoface(); break
    case 'timeline': props.actions.openTimeline(); break
    case 'matrix':   props.actions.openMatrix(); break
    default:
      if (key.startsWith('export-')) exportAs(key.slice('export-'.length))
  }
}
</script>

<style scoped>
.col-toggler-popover { min-width: 200px; }
.col-toggler-header {
  font-size: 0.7rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--p-text-muted-color);
  padding: 0.5rem 0.75rem 0.25rem;
}
.col-toggler-list { max-height: 260px; overflow-y: auto; padding: 0.25rem 0; }
.col-toggler-item {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.35rem 0.75rem;
  cursor: pointer;
  font-size: 0.875rem;
  border-radius: 4px;
  transition: background 0.12s;
}
.col-toggler-item:hover { background: var(--p-content-hover-background); }
.col-toggler-item .anticon { color: var(--p-primary-color); font-size: 0.95rem; }

/* Pushed to the right of the toolbar row. */
.add-record-btn { margin-left: auto; flex-shrink: 0; }
</style>
