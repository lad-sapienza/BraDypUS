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
        />

      </div>
    </div>

  </AppLayout>
</template>

<script setup>
import { ref, computed, watch, onMounted, defineAsyncComponent } from 'vue'
import { useI18n }                  from '@/i18n'
import { useConfigStore }           from '@/stores/config'
import AppLayout                    from '@/components/AppLayout.vue'
import ConfigPasswordGate           from '@/components/config/ConfigPasswordGate.vue'
import ConfigSidebar                from '@/components/config/ConfigSidebar.vue'

// Lazy-load the heavy panels so the gate is fast
const ConfigAppForm    = defineAsyncComponent(() => import('@/components/config/ConfigAppForm.vue'))
const ConfigValidation = defineAsyncComponent(() => import('@/components/config/ConfigValidation.vue'))
const ConfigGeoface    = defineAsyncComponent(() => import('@/components/config/ConfigGeoface.vue'))
const ConfigTableForm  = defineAsyncComponent(() => import('@/components/config/ConfigTableForm.vue'))
const ConfigFieldList  = defineAsyncComponent(() => import('@/components/config/ConfigFieldList.vue'))

const { t } = useI18n()
const store = useConfigStore()

// ── Navigation state ───────────────────────────────────────────────────
// panel: 'app' | 'validation' | 'geoface' | 'table' | 'fields' | null
const panel         = ref(null)
const selectedTable = ref(null)
const addingTable   = ref(false)   // true → ConfigTableForm in "new" mode

const activeComponent = computed(() => {
  if (panel.value === 'app')        return ConfigAppForm
  if (panel.value === 'validation') return ConfigValidation
  if (panel.value === 'geoface')    return ConfigGeoface
  if (panel.value === 'table')      return ConfigTableForm
  if (panel.value === 'fields')     return ConfigFieldList
  return null
})

// ── Actions ────────────────────────────────────────────────────────────

function setPanel(name) {
  panel.value         = name
  selectedTable.value = null
  addingTable.value   = false
}

function openTable(tbName) {
  selectedTable.value = tbName
  addingTable.value   = false
  panel.value         = 'table'
}

function openFields(tbName) {
  selectedTable.value = tbName
  panel.value         = 'fields'
}

function addTable() {
  selectedTable.value = null
  addingTable.value   = true
  panel.value         = 'table'
}

// ── Events from child panels ───────────────────────────────────────────

async function onSaved() {
  await store.loadTables(true)
}

async function onDeleted() {
  panel.value         = null
  selectedTable.value = null
  await store.loadTables(true)
}

async function onRenamed(newName) {
  await store.loadTables(true)
  if (selectedTable.value) selectedTable.value = newName
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
