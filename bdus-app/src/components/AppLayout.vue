<template>
  <div class="layout">

    <!-- ── Topbar (always visible) ───────────────────────── -->
    <header class="topbar app-topbar">
      <button class="topbar-btn" @click="drawerOpen = !drawerOpen" title="Menu">
        <i class="pi pi-bars" />
      </button>
      <span class="topbar-brand">BraDypUS</span>
      <!-- User menu -->
      <Button
        text
        size="small"
        class="topbar-user-btn"
        :title="auth.user?.name"
        @click="userMenu.toggle($event)"
      >
        <i class="pi pi-user" style="font-size:0.9rem" />
        <span class="topbar-user-name">{{ auth.user?.name }}</span>
        <i class="pi pi-chevron-down" style="font-size:0.65rem;opacity:0.6" />
      </Button>
      <Menu ref="userMenu" :model="userMenuItems" popup />

      <!-- Profile dialog -->
      <Dialog
        v-model:visible="profileVisible"
        :header="t('user_profile')"
        modal
        :style="{ width: '420px' }"
      >
        <UserForm
          :initial="profileData"
          :saving="profileSaving"
          @save="saveProfile"
          @cancel="profileVisible = false"
        />
      </Dialog>

      <Select
        v-model="currentLocale"
        :options="availableLocales"
        optionLabel="flag"
        optionValue="code"
        class="locale-select"
        size="small"
        @change="e => setLocale(e.value)"
      >
        <template #value="{ value }">
          <span>{{ availableLocales.find(l => l.code === value)?.flag }}</span>
        </template>
        <template #option="{ option }">
          <span>{{ option.flag }} {{ option.label }}</span>
        </template>
      </Select>
    </header>

    <!-- ── Overlay (mobile drawer) ───────────────────────── -->
    <div v-if="drawerOpen" class="sidebar-overlay" @click="drawerOpen = false" />

    <!-- ── Sidebar ────────────────────────────────────────── -->
    <aside class="sidebar app-sidebar" :class="{ open: drawerOpen, collapsed: sidebarCollapsed }">

      <!-- collapse toggle — desktop only -->
      <button class="collapse-btn desktop-only" @click="sidebarCollapsed = !sidebarCollapsed"
              :title="sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'">
        <i :class="['pi', sidebarCollapsed ? 'pi-chevron-right' : 'pi-chevron-left']" />
      </button>

      <nav class="sidebar-nav">
        <template v-for="group in navGroups" :key="group.labelKey">
          <div class="nav-group-label">{{ t(group.labelKey) }}</div>
          <template v-for="item in group.items" :key="item.to">

            <!-- "Gestione dati" — accordion toggle, not a router link -->
            <template v-if="item.to === '/data'">
              <button
                class="nav-item nav-item-accordion"
                :class="{ 'is-active': isDataRoute, disabled: item.disabled }"
                :disabled="item.disabled"
                :title="t(item.labelKey)"
                @click="dataAccordionOpen = !dataAccordionOpen"
              >
                <i :class="['pi', item.icon]" />
                <span class="nav-label">{{ t(item.labelKey) }}</span>
                <i
                  class="pi pi-chevron-down accordion-chevron nav-label"
                  :class="{ open: dataAccordionOpen }"
                />
              </button>

              <!-- Table sub-list: shown when accordion is open -->
              <template v-if="dataAccordionOpen">
                <div v-if="tablesLoading" class="nav-tables-loading">
                  <i class="pi pi-spin pi-spinner" />
                </div>
                <RouterLink
                  v-for="tbl in navTables"
                  :key="tbl.name"
                  :to="`/data?tb=${encodeURIComponent(tbl.name)}`"
                  class="nav-item nav-table-item"
                  :class="{ active: route.query.tb === tbl.name }"
                  :title="tbl.label"
                  @click="closeMobileDrawer()"
                >
                  <i class="pi pi-table" />
                  <span class="nav-label nav-table-label">{{ tbl.label }}</span>
                </RouterLink>
              </template>
            </template>

            <!-- All other nav items — normal RouterLink or disabled span -->
            <component
              v-else
              :is="item.disabled ? 'span' : 'RouterLink'"
              v-bind="item.disabled ? {} : { to: item.to }"
              class="nav-item"
              :class="{ disabled: item.disabled }"
              :title="t(item.labelKey)"
              @click="!item.disabled && closeMobileDrawer()"
            >
              <i :class="['pi', item.icon]" />
              <span class="nav-label">{{ t(item.labelKey) }}</span>
            </component>

          </template>
        </template>
      </nav>

      <div class="sidebar-footer">
        <button class="nav-item nav-logout" @click="handleLogout" :title="t('logout')">
          <i class="pi pi-sign-out" />
          <span class="nav-label">{{ t('logout') }}</span>
        </button>
      </div>
    </aside>

    <!-- ── Page content ───────────────────────────────────── -->
    <main class="main-content">
      <slot />
    </main>

  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import { useAuthStore } from '@/stores/auth'
import { useI18n } from '@/i18n'
import { api } from '@/api'
import { useTables } from '@/composables/useTables'
import Select from 'primevue/select'
import Button from 'primevue/button'
import Menu from 'primevue/menu'
import Dialog from 'primevue/dialog'
import UserForm from '@/components/users/UserForm.vue'

const router = useRouter()
const route  = useRoute()
const auth   = useAuthStore()
const { tables: navTables, loading: tablesLoading, loadTables } = useTables()

// Load table list when navigating to /data (or when already there on mount)
const isDataRoute       = computed(() => route.path === '/data')
const dataAccordionOpen = ref(isDataRoute.value)

watch(isDataRoute, (val) => {
  if (val) {
    dataAccordionOpen.value = true   // auto-open when entering /data
    loadTables()
  }
}, { immediate: true })
const toast = useToast()
const { t, locale, setLocale, availableLocales } = useI18n()
const currentLocale = computed({
  get: () => locale.value,
  set: (v) => setLocale(v)
})

// ── User menu (topbar) ─────────────────────────────────────────
const userMenu        = ref()
const profileVisible  = ref(false)
const profileData     = ref({})
const profileSaving   = ref(false)

const userMenuItems = computed(() => [
  {
    label:   t('user_profile'),
    icon:    'pi pi-user-edit',
    command: openProfile,
  },
  { separator: true },
  {
    label:   t('logout'),
    icon:    'pi pi-sign-out',
    command: handleLogout,
    class:   'menu-item-danger',
  },
])

async function openProfile() {
  try {
    const res = await api.get('user_ctrl', 'showUserForm', { id: auth.user?.id })
    profileData.value   = res
    profileVisible.value = true
  } catch (e) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.message, life: 4000 })
  }
}

async function saveProfile(data) {
  profileSaving.value = true
  try {
    const res = await api.post('user_ctrl', 'saveUserData', data)
    if (res.status !== 'success') throw new Error(res.text ?? res.code)
    toast.add({ severity: 'success', summary: t('user_data_saved'), life: 3000 })
    profileVisible.value = false
    // Refresh auth store so topbar name updates
    await auth.fetchMe()
  } catch (e) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.message, life: 4000 })
  } finally {
    profileSaving.value = false
  }
}

// Mobile drawer (< 1024px)
const drawerOpen = ref(false)

// Desktop collapse (>= 1024px)
const sidebarCollapsed = ref(false)

function closeMobileDrawer() {
  if (window.innerWidth < 1024) drawerOpen.value = false
}

// Close drawer on resize to desktop
function onResize() {
  if (window.innerWidth >= 1024) drawerOpen.value = false
}
onMounted(() => window.addEventListener('resize', onResize))
onUnmounted(() => window.removeEventListener('resize', onResize))

async function handleLogout() {
  await auth.logout()
  router.push('/login')
}

const navGroups = computed(() => [
  {
    labelKey: 'nav_general',
    items: [
      { labelKey: 'dashboard',      icon: 'pi-home',         to: '/',             disabled: false },
    ],
  },
  {
    labelKey: 'nav_data',
    items: [
      { labelKey: 'data_mng',       icon: 'pi-database',     to: '/data',         disabled: false },
      { labelKey: 'find_replace',   icon: 'pi-search-plus',  to: '/find-replace', disabled: true  },
      { labelKey: 'vocabulary_mng', icon: 'pi-book',         to: '/vocabularies', disabled: false },
      { labelKey: 'history',        icon: 'pi-history',      to: '/history',      disabled: true  },
    ],
  },
  {
    labelKey: 'nav_visualisation',
    items: [
      { labelKey: 'saved_queries',  icon: 'pi-list',         to: '/queries',      disabled: true  },
      { labelKey: 'chart',          icon: 'pi-chart-bar',    to: '/charts',       disabled: true  },
      { labelKey: 'map',            icon: 'pi-map',          to: '/map',          disabled: true  },
      { labelKey: 'harris_matrix',  icon: 'pi-share-alt',    to: '/matrix',       disabled: true  },
    ],
  },
  {
    labelKey: 'nav_import_export',
    items: [
      { labelKey: 'export',         icon: 'pi-download',     to: '/exports',      disabled: true  },
      { labelKey: 'import_geodata', icon: 'pi-map-marker',   to: '/import-geo',   disabled: true  },
      { labelKey: 'backup',         icon: 'pi-save',         to: '/backups',      disabled: true  },
    ],
  },
  {
    labelKey: 'nav_admin',
    items: [
      { labelKey: 'user_mng',       icon: 'pi-users',        to: '/users',        disabled: false },
      { labelKey: 'sys_config',     icon: 'pi-cog',          to: '/config',       disabled: true  },
      { labelKey: 'free_sql',       icon: 'pi-code',         to: '/free-sql',     disabled: true  },
      { labelKey: 'empty_cache',    icon: 'pi-trash',        to: '/empty-cache',  disabled: true  },
    ],
  },
  {
    labelKey: 'nav_system',
    items: [
      { labelKey: 'test',           icon: 'pi-check-circle', to: '/test',         disabled: true  },
      { labelKey: 'app_log',        icon: 'pi-list',         to: '/log',          disabled: false },
      { labelKey: 'info',           icon: 'pi-info-circle',  to: '/info',         disabled: true  },
    ],
  },
])
</script>

<style scoped>
/* ── Shell ────────────────────────────────────────────────── */
.layout {
  display: flex;
  flex-direction: column;
  min-height: 100vh;
}

/* ── Topbar ───────────────────────────────────────────────── */
.topbar {
  position: fixed;
  top: 0; left: 0; right: 0;
  height: 48px;
  z-index: 200;
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0 1rem;
  /* background handled globally in App.vue (.app-topbar) */
}

.topbar-btn {
  border: none;
  background: transparent;
  font-size: 1.1rem;
  cursor: pointer;
  color: var(--p-text-color);
  padding: 0.3rem;
  border-radius: 4px;
  line-height: 1;
}
.topbar-btn:hover { background: var(--p-surface-hover); }

.topbar-brand {
  font-weight: 700;
  font-size: 1.05rem;
  color: var(--p-primary-color);
  flex: 1;
}

.topbar-user-btn {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.82rem;
  color: var(--p-text-muted-color);
  padding: 0.25rem 0.5rem !important;
  border-radius: 6px;
}
.topbar-user-btn:hover { color: var(--p-text-color) !important; }

.topbar-user-name {
  max-width: 120px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* Red tint for logout item in user menu */
:global(.menu-item-danger > .p-menuitem-content) { color: var(--p-red-400) !important; }

:deep(.locale-select) {
  width: 60px;
  font-size: 1.1rem;
}
:deep(.locale-select .p-select-label) {
  padding: 0.25rem 0.5rem;
  text-align: center;
}

/* ── Overlay ──────────────────────────────────────────────── */
.sidebar-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.35);
  z-index: 149;
}

/* ── Sidebar ──────────────────────────────────────────────── */
.sidebar {
  position: fixed;
  top: 48px;
  left: 0;
  bottom: 0;
  width: 220px;
  z-index: 150;
  /* background handled globally in App.vue (.app-sidebar) */
  display: flex;
  flex-direction: column;
  overflow: hidden;
  transition: transform 0.22s ease, width 0.22s ease;

  /* hidden by default on mobile */
  transform: translateX(-100%);
}

/* mobile: open drawer */
.sidebar.open {
  transform: translateX(0);
}

/* desktop: always visible */
@media (min-width: 1024px) {
  .sidebar {
    transform: translateX(0);
  }
  .sidebar.collapsed {
    width: 52px;
  }
}

/* ── Collapse button (desktop only) ───────────────────────── */
.collapse-btn {
  display: none;
  align-self: flex-end;
  margin: 0.4rem 0.4rem 0;
  padding: 0.3rem 0.5rem;
  border: none;
  background: transparent;
  color: var(--p-text-muted-color);
  cursor: pointer;
  border-radius: 4px;
  font-size: 0.8rem;
  flex-shrink: 0;
}
.collapse-btn:hover { background: var(--p-surface-hover); }

@media (min-width: 1024px) {
  .desktop-only { display: flex; }
}

/* ── Nav ──────────────────────────────────────────────────── */
.sidebar-nav {
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
  padding: 0.5rem 0;
}

.nav-group-label {
  font-size: 0.68rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.07em;
  color: var(--p-text-muted-color);
  padding: 0.75rem 1rem 0.2rem;
  white-space: nowrap;
  overflow: hidden;
  transition: opacity 0.15s, height 0.15s;
}

.nav-item {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.55rem 1rem;
  text-decoration: none;
  color: var(--p-text-color);
  font-size: 0.875rem;
  cursor: pointer;
  border: none;
  background: transparent;
  width: 100%;
  white-space: nowrap;
  transition: background 0.15s;
}

.nav-item:hover:not(.disabled) { background: var(--p-surface-hover); }

.nav-item.router-link-active {
  background: var(--p-primary-50);
  color: var(--p-primary-color);
  font-weight: 600;
}

.nav-item.disabled {
  opacity: 0.35;
  cursor: not-allowed;
  pointer-events: none;
}

.nav-item .pi {
  font-size: 1rem;
  flex-shrink: 0;
  width: 1.1rem;
  text-align: center;
}

/* hide labels when collapsed (desktop) */
@media (min-width: 1024px) {
  .sidebar.collapsed .nav-group-label { opacity: 0; height: 0; padding: 0; }
  .sidebar.collapsed .nav-label       { display: none; }
  .sidebar.collapsed .nav-item        { justify-content: center; padding: 0.6rem; }
}

/* ── Accordion nav item (Gestione Dati) ───────────────────── */
.nav-item-accordion {
  width: 100%;
  border: none;
  background: transparent;
  cursor: pointer;
  text-align: left;
}

.nav-item-accordion.is-active {
  color: var(--p-primary-color);
  font-weight: 600;
}

.accordion-chevron {
  margin-left: auto;
  font-size: 0.7rem;
  opacity: 0.6;
  transition: transform 0.2s ease;
  flex-shrink: 0;
  width: auto !important;   /* override .nav-item .pi fixed width */
}
.accordion-chevron.open { transform: rotate(0deg); }
.accordion-chevron:not(.open) { transform: rotate(-90deg); }

/* ── Table sub-items ──────────────────────────────────────── */
.nav-tables-loading {
  padding: 0.4rem 1rem 0.4rem 2.5rem;
  color: var(--p-text-muted-color);
  font-size: 0.8rem;
}

.nav-table-item {
  padding-left: 2.25rem;   /* indent relative to parent nav-item */
  font-size: 0.82rem;
  color: var(--p-text-muted-color);
}

.nav-table-item .pi {
  font-size: 0.78rem;
  color: var(--p-text-muted-color);
}

.nav-table-item.active,
.nav-table-item.router-link-active {
  background: var(--p-primary-50);
  color: var(--p-primary-color);
  font-weight: 600;
}
.nav-table-item.active .pi,
.nav-table-item.router-link-active .pi {
  color: var(--p-primary-color);
}

.nav-table-label {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  line-height: 1.3;
}

/* collapsed sidebar: hide table sub-items entirely (no icons to show) */
@media (min-width: 1024px) {
  .sidebar.collapsed .nav-table-item { display: none; }
}

/* ── Footer ───────────────────────────────────────────────── */
.sidebar-footer {
  border-top: 1px solid var(--p-surface-border);
  padding: 0.25rem 0;
  flex-shrink: 0;
}

.nav-logout { color: var(--p-red-400); }
.nav-logout:hover { background: var(--p-red-50) !important; }

/* ── Main content ─────────────────────────────────────────── */
.main-content {
  margin-top: 48px;
  flex: 1;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  height: calc(100vh - 48px);
  transition: margin-left 0.22s ease;
}

@media (min-width: 1024px) {
  .main-content { margin-left: 220px; }
}
</style>
