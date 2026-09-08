<template>
  <AppLayout>

    <!-- ── Password gate ──────────────────────────────────────────── -->
    <ConfigPasswordGate v-if="!store.unlocked" />

    <!-- ── Config shell ───────────────────────────────────────────── -->
    <div v-else class="cfg-shell" :class="{ 'cfg-shell--detail': hasPanel }">

      <!-- Mobile-only bar: opens the section nav as a drawer.
           Shown below 1024px ONLY once a section is open — until then the
           section list itself is the full-screen content, so there is
           nothing to toggle. The primary app sidebar gets the same drawer
           treatment in AppLayout.vue. -->
      <div class="cfg-mobilebar">
        <button class="cfg-mobilebar-btn" @click="navOpen = true" :title="t('sys_config')">
          <BarsOutlined />
        </button>
        <span class="cfg-mobilebar-title">{{ currentSectionLabel }}</span>
      </div>

      <!-- Section nav — inline on desktop; on mobile, full-screen until a
           section is picked, then it moves into the drawer below. -->
      <ConfigSidebar
        class="cfg-sidebar-inline"
        :active="panel"
        :selected-table="selectedTable"
        @select="setPanel"
        @select-table="openTable"
        @select-fields="openFields"
        @add-table="addTable"
      />

      <!-- Section nav — drawer on small screens (same component instance role) -->
      <ADrawer
        placement="left"
        :open="navOpen"
        :closable="false"
        :width="270"
        :body-style="{ padding: '0', display: 'flex' }"
        class="cfg-nav-drawer"
        @close="navOpen = false"
      >
        <ConfigSidebar
          :active="panel"
          :selected-table="selectedTable"
          @select="onNavSelect(setPanel, $event)"
          @select-table="onNavSelect(openTable, $event)"
          @select-fields="onNavSelect(openFields, $event)"
          @add-table="onNavSelect(addTable)"
        />
      </ADrawer>

      <!-- ── Right panel ─────────────────────────────────────────── -->
      <div class="cfg-main">

        <!-- Welcome / nothing selected -->
        <div v-if="!panel && !selectedTable" class="cfg-empty">
          <SettingOutlined class="cfg-empty-icon" />
          <p>{{ t('select_config_section') }}</p>
        </div>

        <!-- Panels rendered lazily; each component handles its own data fetching -->
        <component
          v-else
          :is="activeComponent"
          :tb="selectedTable"
          @saved="onSaved"
          @deleted="onDeleted"
          @renamed="onRenamed"
          @open-fields="openFields"
          @table-added="onSaved"
        />

      </div>
    </div>

  </AppLayout>
</template>

<script setup>
import { SettingOutlined, BarsOutlined } from '@ant-design/icons-vue'
import { ref, computed, watch, onMounted, defineAsyncComponent } from 'vue'
import { Drawer }                   from 'ant-design-vue'
import { useRoute, useRouter }      from 'vue-router'
import { useI18n }                  from '@/i18n'
import { useConfigStore }           from '@/stores/config'
import AppLayout                    from '@/components/AppLayout.vue'
import ConfigPasswordGate           from '@/components/config/ConfigPasswordGate.vue'
import ConfigSidebar                from '@/components/config/ConfigSidebar.vue'

const ADrawer = Drawer

// Lazy-load the heavy panels so the gate is fast
const ConfigAppForm    = defineAsyncComponent(() => import('@/components/config/ConfigAppForm.vue'))
const ConfigValidation = defineAsyncComponent(() => import('@/components/config/ConfigValidation.vue'))
const ConfigGeoface    = defineAsyncComponent(() => import('@/components/config/ConfigGeoface.vue'))
const ConfigApiKeys    = defineAsyncComponent(() => import('@/components/config/ApiKeysPanel.vue'))
const ConfigRelations  = defineAsyncComponent(() => import('@/components/config/ConfigRelations.vue'))
const ConfigTableForm  = defineAsyncComponent(() => import('@/components/config/ConfigTableForm.vue'))
const ConfigFieldList  = defineAsyncComponent(() => import('@/components/config/ConfigFieldList.vue'))
const ConfigZotero     = defineAsyncComponent(() => import('@/components/config/ZoteroLibsPanel.vue'))
const ConfigDbml       = defineAsyncComponent(() => import('@/components/config/DbmlPanel.vue'))

const { t } = useI18n()
const store  = useConfigStore()
const route  = useRoute()
const router = useRouter()

// ── Navigation state derived from URL ─────────────────────────────────
// panel: 'app' | 'validation' | 'geoface' | 'apikeys' | 'relations' | 'table' | 'fields' | null
const panel         = computed(() => route.params.panel ?? null)
const selectedTable = computed(() => route.params.tb    ?? null)

// A settings panel (or the "new table" form) is showing — i.e. not the
// empty "pick a section" state. Drives the mobile master/detail switch.
const hasPanel = computed(() => !!panel.value || !!selectedTable.value)

// ── Mobile section-nav drawer ────────────────────────────────────────
const navOpen = ref(false)

// Back to the section list (no panel) → make sure the drawer isn't stuck open.
watch(hasPanel, (v) => { if (!v) navOpen.value = false })

// Run a nav action, then close the mobile drawer.
function onNavSelect(fn, arg) {
  fn(arg)
  navOpen.value = false
}

// Human label for the current section, shown in the mobile bar.
const PANEL_LABEL_KEY = {
  app:        'app_settings',
  validation: 'validate_app',
  geoface:    'geoface',
  apikeys:    'api_keys',
  relations:  'cfg_relations',
  zotero:     'zotero_libraries',
  dbml:       'dbml_title',
}
const currentSectionLabel = computed(() => {
  const p = panel.value
  if (p === 'table' || p === 'fields') {
    const lbl = store.tables.find(x => x.name === selectedTable.value)?.label
      ?? selectedTable.value ?? t('table_settings')
    return p === 'fields' ? `${lbl} · ${t('fields')}` : lbl
  }
  return PANEL_LABEL_KEY[p] ? t(PANEL_LABEL_KEY[p]) : t('sys_config')
})

const activeComponent = computed(() => {
  if (panel.value === 'app')        return ConfigAppForm
  if (panel.value === 'validation') return ConfigValidation
  if (panel.value === 'geoface')    return ConfigGeoface
  if (panel.value === 'apikeys')    return ConfigApiKeys
  if (panel.value === 'relations')  return ConfigRelations
  if (panel.value === 'table')      return ConfigTableForm
  if (panel.value === 'fields')     return ConfigFieldList
  if (panel.value === 'zotero')     return ConfigZotero
  if (panel.value === 'dbml')       return ConfigDbml
  return null
})

// ── Navigation ─────────────────────────────────────────────────────────

function setPanel(name) {
  router.push({ path: `/${route.params.app}/config/${name}` })
}

function openTable(tbName) {
  router.push({ path: `/${route.params.app}/config/table/${tbName}` })
}

function openFields(tbName) {
  router.push({ path: `/${route.params.app}/config/fields/${tbName}` })
}

function addTable() {
  router.push({ path: `/${route.params.app}/config/table` })
}

// ── Events from child panels ───────────────────────────────────────────

async function onSaved() {
  await store.loadTables(true)
}

async function onDeleted() {
  await store.loadTables(true)
  router.push({ path: `/${route.params.app}/config` })
}

async function onRenamed(newName) {
  await store.loadTables(true)
  if (selectedTable.value) {
    router.push({ path: `/${route.params.app}/config/table/${newName}` })
  }
}

// ── Load table list as soon as unlocked ───────────────────────────────
// onMounted covers the case where the page loads while already unlocked.
// The watch covers the normal flow: user submits the password gate after mount.
onMounted(() => {
  if (store.unlocked) store.loadTables()
})
watch(() => store.unlocked, (unlocked) => {
  if (unlocked) store.loadTables()
})
</script>

<style scoped>
.cfg-shell {
  display: flex;
  flex: 1;
  min-height: 0;
  overflow: hidden;
}

.cfg-main {
  flex: 1;
  min-width: 0;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
}

.cfg-empty {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 1rem;
  color: var(--p-text-muted-color);
}

.cfg-empty-icon {
  font-size: 3rem;
  opacity: 0.3;
}

/* ── Mobile section-nav bar ──────────────────────────────────────────
   Only ever visible below 1024px, and only once a section is open
   (`.cfg-shell--detail`); see the media query at the end of this block. */
.cfg-mobilebar {
  display: none;
  align-items: center;
  gap: 0.4rem;
  padding: 0.4rem 0.6rem;
  border-bottom: 1px solid var(--p-content-border-color);
  background: var(--bdus-surface);
  flex-shrink: 0;
}

.cfg-mobilebar-btn {
  border: none;
  background: transparent;
  cursor: pointer;
  color: var(--p-text-color);
  font-size: 1.1rem;
  line-height: 1;
  padding: 0.3rem 0.45rem;
  border-radius: 4px;
}
.cfg-mobilebar-btn:hover { background: var(--p-content-hover-background); }

.cfg-mobilebar-title {
  min-width: 0;
  font-size: 0.9rem;
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.cfg-nav-drawer :deep(.cfg-sidebar) {
  border-right: none;
  flex: 1;
}

@media (max-width: 1023px) {
  .cfg-shell { flex-direction: column; }

  /* No section chosen yet → the section list IS the screen: full width,
     no drawer, no top bar (mobile master/detail "list" view). */
  .cfg-shell:not(.cfg-shell--detail) .cfg-sidebar-inline {
    width: 100% !important;
    flex: 1 1 auto !important;
  }
  .cfg-shell:not(.cfg-shell--detail) .cfg-mobilebar,
  .cfg-shell:not(.cfg-shell--detail) .cfg-main { display: none; }

  /* A section is open → sidebar folds into the drawer, panel goes full width. */
  .cfg-shell--detail .cfg-mobilebar { display: flex; }
  .cfg-shell--detail .cfg-sidebar-inline { display: none !important; }
  .cfg-shell--detail .cfg-main { min-height: 0; }
}
</style>
