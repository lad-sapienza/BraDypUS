<template>
  <ALayout class="app-shell" has-sider>

    <!-- ── Sidebar (desktop) ──────────────────────────────── -->
    <ALayoutSider
      class="app-sidebar desktop-only"
      :collapsed="sidebarCollapsed"
      :trigger="null"
      collapsible
      width="220"
      :theme="isDark ? 'dark' : 'light'"
    >
      <AppNavMenu />
      <button class="collapse-btn" @click="sidebarCollapsed = !sidebarCollapsed"
              :title="sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'">
        <i :class="['pi', sidebarCollapsed ? 'pi-chevron-right' : 'pi-chevron-left']" />
      </button>
      <div class="sidebar-footer">
        <button class="nav-logout-btn" @click="handleLogout" :title="t('logout')">
          <i class="pi pi-sign-out" />
          <span v-if="!sidebarCollapsed">{{ t('logout') }}</span>
        </button>
      </div>
    </ALayoutSider>

    <!-- ── Sidebar (mobile drawer) ────────────────────────── -->
    <ADrawer
      placement="left"
      :open="drawerOpen"
      @close="drawerOpen = false"
      :closable="false"
      width="240"
      class="mobile-only"
      :body-style="{ padding: '0' }"
    >
      <AppNavMenu @navigate="drawerOpen = false" />
      <div class="sidebar-footer">
        <button class="nav-logout-btn" @click="handleLogout" :title="t('logout')">
          <i class="pi pi-sign-out" />
          <span>{{ t('logout') }}</span>
        </button>
      </div>
    </ADrawer>

    <ALayout>
      <!-- ── Topbar ───────────────────────────────────────── -->
      <ALayoutHeader class="topbar app-topbar">
        <button class="topbar-btn mobile-only" @click="drawerOpen = !drawerOpen" title="Menu">
          <i class="pi pi-bars" />
        </button>
        <span class="topbar-brand">
          BraDypUS
          <a
            class="topbar-by-lad"
            href="https://purl.org/lad"
            target="_blank"
            rel="noopener"
            title="LAD — Laboratorio di Archeologia Digitale, Sapienza Università di Roma"
          >by LAD</a>
          <span v-if="auth.user?.app" class="topbar-app-name">· {{ auth.user.app }}</span>
        </span>

        <button class="topbar-btn" :title="t('open_command_palette')" @click="commandPalette?.open()">
          <i class="pi pi-search" />
        </button>

        <ADropdown trigger="click" placement="bottomRight">
          <button class="topbar-btn topbar-user-btn" :title="auth.user?.name">
            <i class="pi pi-user" style="font-size:0.9rem" />
            <span class="topbar-user-name">{{ auth.user?.name }}</span>
            <i class="pi pi-chevron-down" style="font-size:0.65rem;opacity:0.6" />
          </button>
          <template #overlay>
            <AMenu>
              <AMenuItem key="profile" @click="openProfile">
                <i class="pi pi-user-edit" /> {{ t('user_profile') }}
              </AMenuItem>
              <AMenuDivider />
              <AMenuItem key="logout" danger @click="handleLogout">
                <i class="pi pi-sign-out" /> {{ t('logout') }}
              </AMenuItem>
            </AMenu>
          </template>
        </ADropdown>

        <AModal
          v-model:open="profileVisible"
          :title="t('user_profile')"
          :footer="null"
          width="420px"
        >
          <UserForm
            :initial="profileData"
            :saving="profileSaving"
            @save="saveProfile"
            @cancel="profileVisible = false"
          />
        </AModal>

        <button class="topbar-btn" :title="isDark ? t('light_mode') : t('dark_mode')" @click="toggleDark">
          <i :class="isDark ? 'pi pi-sun' : 'pi pi-moon'" />
        </button>

        <ASelect
          :value="currentLocale"
          @update:value="v => setLocale(v)"
          :options="availableLocales.map(l => ({ value: l.code, label: l.flag }))"
          class="locale-select"
          size="small"
        >
          <template #optionRender="{ option }">
            {{ availableLocales.find(l => l.code === option.value)?.flag }}
            {{ availableLocales.find(l => l.code === option.value)?.label }}
          </template>
        </ASelect>
      </ALayoutHeader>

      <!-- ── Page content ─────────────────────────────────── -->
      <ALayoutContent class="main-content">
        <slot />
      </ALayoutContent>
    </ALayout>

    <CommandPalette ref="commandPalette" @open-profile="openProfile" />

  </ALayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { Layout, Menu, Dropdown, Modal, Select, Drawer } from 'ant-design-vue'
import { useRoute } from 'vue-router'
import { useToast }   from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import { useAuthStore } from '@/stores/auth'
import { useI18n } from '@/i18n'
import { api } from '@/api'
import { useDarkMode }  from '@/composables/useDarkMode'
import { applyColor }   from '@/composables/useAppColor'
import UserForm from '@/components/users/UserForm.vue'
import CommandPalette from '@/components/CommandPalette.vue'
import AppNavMenu from '@/components/AppNavMenu.vue'

const ALayout        = Layout
const ALayoutHeader   = Layout.Header
const ALayoutSider    = Layout.Sider
const ALayoutContent  = Layout.Content
const AMenu           = Menu
const AMenuItem       = Menu.Item
const AMenuDivider    = Menu.Divider
const ADropdown       = Dropdown
const AModal          = Modal
const ASelect         = Select
const ADrawer         = Drawer

const route   = useRoute()
const auth    = useAuthStore()
const confirm = useConfirm()
const { isDark, toggle: toggleDark } = useDarkMode()

onMounted(async () => {
  try {
    const res = await api.get('/api/info/app')
    if (res?.color) applyColor(res.color)
  } catch {
    // non-critical — default Aura indigo stays in place
  }
})
const toast = useToast()
const { t, locale, setLocale, availableLocales } = useI18n()
const currentLocale = computed(() => locale.value)

// ── Command palette (topbar trigger + Cmd/Ctrl+K, handled inside the component) ──
const commandPalette = ref()

// ── User menu / profile dialog ───────────────────────────────────
const profileVisible  = ref(false)
const profileData     = ref({})
const profileSaving   = ref(false)

async function openProfile() {
  try {
    const res = await api.get(`/api/user/${auth.user?.id}`)
    profileData.value   = res
    profileVisible.value = true
  } catch (e) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.message, life: 4000 })
  }
}

async function saveProfile(data) {
  profileSaving.value = true
  try {
    const res = await api.post('/api/user', data)
    if (res.status !== 'success') throw new Error(res.code ?? 'generic_error')
    toast.add({ severity: 'success', summary: t('user_data_saved'), life: 3000 })
    profileVisible.value = false
    if (data.name  !== undefined) auth.updateProfile({ name:  data.name  })
    if (data.email !== undefined) auth.updateProfile({ email: data.email })
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

function handleLogout() {
  confirm.require({
    message:      t('logout_confirm_message'),
    header:       t('logout'),
    icon:         'pi pi-sign-out',
    severity:     'danger',
    rejectProps:  { label: t('cancel'), severity: 'secondary', outlined: true },
    acceptProps:  { label: t('logout'), severity: 'danger' },
    accept: async () => {
      await auth.logout()
      window.location.hash = '/login'
    },
  })
}
</script>

<style scoped>
.app-shell {
  height: 100%;
  overflow: hidden;
}

/* ── Responsive show/hide (Sider vs Drawer) ──────────────────── */
.mobile-only { display: none; }
@media (max-width: 1023px) {
  .desktop-only { display: none !important; }
  .mobile-only  { display: inline-flex; }
}

/* ── Sidebar ──────────────────────────────────────────────────── */
.app-sidebar {
  display: flex;
  flex-direction: column;
  height: 100%;
  overflow: hidden;
  border-right: 1px solid var(--p-content-border-color);
}
.app-sidebar :deep(.ant-layout-sider-children) {
  display: flex;
  flex-direction: column;
  height: 100%;
}
/* AntD's Sider doesn't scroll its content by default — the nav Menu must be
   made the scroll region itself, and (classic flexbox trap) needs min-height:0
   or a flex child refuses to shrink below its content size, defeating overflow. */
.app-sidebar :deep(.ant-menu) {
  flex: 1 1 auto;
  min-height: 0;
  overflow-y: auto;
}

.collapse-btn,
.sidebar-footer {
  flex-shrink: 0;
}

.collapse-btn {
  margin: 0.3rem 0.5rem;
  align-self: flex-end;
  padding: 0.3rem 0.5rem;
  border: none;
  background: transparent;
  color: var(--p-text-muted-color);
  cursor: pointer;
  border-radius: 4px;
  font-size: 0.8rem;
}
.collapse-btn:hover { background: var(--p-content-hover-background); }

.sidebar-footer {
  margin-top: auto;
  border-top: 1px solid var(--p-content-border-color);
  padding: 0.4rem;
}
.nav-logout-btn {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  width: 100%;
  padding: 0.55rem 0.75rem;
  border: none;
  background: transparent;
  color: var(--p-red-400);
  cursor: pointer;
  font-size: 0.875rem;
  border-radius: 4px;
}
.nav-logout-btn:hover { background: color-mix(in srgb, var(--p-red-400) 12%, transparent); }

/* ── Topbar ───────────────────────────────────────────────────── */
.topbar {
  height: 48px;
  line-height: 48px;
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0 1rem;
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
  display: inline-flex;
  align-items: center;
}
.topbar-btn:hover { background: var(--p-content-hover-background); }

.topbar-brand {
  font-weight: 700;
  font-size: 1.05rem;
  color: var(--p-primary-color);
  flex: 1;
  display: flex;
  align-items: baseline;
  gap: 0.4rem;
  overflow: hidden;
}

.topbar-app-name {
  color: var(--p-text-muted-color);
  font-weight: 400;
  font-size: 0.85rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.topbar-by-lad {
  color: var(--p-text-muted-color);
  font-weight: 400;
  font-size: 0.72rem;
  text-decoration: none;
  white-space: nowrap;
  flex-shrink: 0;
}
.topbar-by-lad:hover {
  color: var(--p-primary-color);
  text-decoration: underline;
}

.topbar-user-btn {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.82rem;
  color: var(--p-text-muted-color);
}
.topbar-user-btn:hover { color: var(--p-text-color); }

.topbar-user-name {
  max-width: 120px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

:deep(.locale-select) {
  width: 64px;
}

/* ── Main content ─────────────────────────────────────────────── */
.main-content {
  overflow: auto;
  height: calc(100vh - 48px);
}
</style>
