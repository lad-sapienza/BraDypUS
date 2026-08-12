<template>
  <AMenu
    mode="inline"
    :theme="isDark ? 'dark' : 'light'"
    :selectedKeys="selectedKeys"
    :openKeys="openKeys"
    @update:openKeys="v => openKeys = v"
    class="app-nav-menu"
  >
    <template v-for="group in navGroups" :key="group.labelKey">
      <AMenuItemGroup :title="t(group.labelKey)">

        <!-- "Gestione dati" — submenu with dynamic table sub-items -->
        <template v-for="item in group.items" :key="item.to">
          <ASubMenu
            v-if="item.labelKey === 'data_mng'"
            key="data_mng"
            :disabled="isDisabled(item)"
            @titleClick="loadTables()"
          >
            <template #title>
              <i :class="['pi', item.icon]" />
              <span>{{ t(item.labelKey) }}</span>
            </template>
            <AMenuItem v-if="tablesLoading" key="__loading" disabled>
              <i class="pi pi-spin pi-spinner" />
            </AMenuItem>
            <AMenuItem
              v-for="tbl in navTables"
              :key="`tbl:${tbl.name}`"
              @click="go(`/${route.params.app}/data?tb=${encodeURIComponent(tbl.name)}`)"
            >
              <i class="pi pi-table" />
              <span>{{ tbl.label }}</span>
            </AMenuItem>
          </ASubMenu>

          <AMenuItem
            v-else
            :key="item.to"
            :disabled="isDisabled(item)"
            @click="!isDisabled(item) && go(item.to)"
          >
            <i :class="['pi', item.icon]" />
            <span>{{ t(item.labelKey) }}</span>
          </AMenuItem>
        </template>

      </AMenuItemGroup>
    </template>
  </AMenu>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { Menu, MenuItem, SubMenu } from 'ant-design-vue'
import { useAuthStore } from '@/stores/auth'
import { useI18n } from '@/i18n'
import { useTables } from '@/composables/useTables'
import { useDarkMode } from '@/composables/useDarkMode'
import { buildNavGroups } from '@/commands/navItems'
import { hasPrivilege } from '@/commands/privilege'

const AMenu          = Menu
const AMenuItem      = MenuItem
const AMenuItemGroup = Menu.ItemGroup
const ASubMenu       = SubMenu

const emit = defineEmits(['navigate'])

const router = useRouter()
const route  = useRoute()
const auth   = useAuthStore()
const { t }  = useI18n()
const { isDark } = useDarkMode()
const { tables: navTables, loading: tablesLoading, loadTables } = useTables()

const navGroups = computed(() => buildNavGroups(route.params.app))

function isDisabled(item) {
  return !hasPrivilege(auth.user?.privilege_value, item.minPrivilege)
}

function go(to) {
  router.push(to)
  emit('navigate')
}

// ── Selection / open state driven by the current route ─────────────────
const isDataRoute = computed(() => {
  const app = route.params.app
  return route.path === `/${app}/data` ||
    route.path.startsWith(`/${app}/record/`) ||
    route.path.startsWith(`/${app}/geoface/`) ||
    route.path.startsWith(`/${app}/matrix/`)
})

const openKeys = ref(isDataRoute.value ? ['data_mng'] : [])

watch(isDataRoute, (val) => {
  if (val) {
    if (!openKeys.value.includes('data_mng')) openKeys.value = [...openKeys.value, 'data_mng']
    loadTables()
  }
}, { immediate: true })

const selectedKeys = computed(() => {
  if (route.query.tb) return [`tbl:${route.query.tb}`]
  if (route.params.tb) return [`tbl:${route.params.tb}`]
  const app = route.params.app
  const all = navGroups.value.flatMap(g => g.items)
  const match = all.find(item => item.to !== `/${app}` && route.path.startsWith(item.to))
    ?? all.find(item => item.to === route.path)
  return match ? [match.to] : []
})
</script>

<style scoped>
.app-nav-menu :deep(.pi) {
  font-size: 1rem;
  margin-right: 0.6rem;
}
</style>
