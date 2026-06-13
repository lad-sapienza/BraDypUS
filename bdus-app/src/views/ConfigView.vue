<template>
  <AppLayout>

    <!-- ── Password gate ──────────────────────────────────────────── -->
    <ConfigPasswordGate v-if="!store.unlocked" />

    <!-- ── Config shell ───────────────────────────────────────────── -->
    <div v-else class="cfg-shell">

      <ConfigSidebar
        :active="panel"
        :selected-table="selectedTable"
        @select="setPanel"
        @select-table="openTable"
        @select-fields="openFields"
        @add-table="addTable"
      />

      <!-- ── Right panel ─────────────────────────────────────────── -->
      <div class="cfg-main">

        <!-- Welcome / nothing selected -->
        <div v-if="!panel && !selectedTable" class="cfg-empty">
          <i class="pi pi-cog cfg-empty-icon" />
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
import { computed, watch, onMounted, defineAsyncComponent } from 'vue'
import { useRoute, useRouter }      from 'vue-router'
import { useI18n }                  from '@/i18n'
import { useConfigStore }           from '@/stores/config'
import AppLayout                    from '@/components/AppLayout.vue'
import ConfigPasswordGate           from '@/components/config/ConfigPasswordGate.vue'
import ConfigSidebar                from '@/components/config/ConfigSidebar.vue'

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
</style>
