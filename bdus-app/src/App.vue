<template>
  <AConfigProvider :theme="antdTheme">
    <AApp>
      <ThemeTokenBridge />
      <RouterView />
    </AApp>
  </AConfigProvider>
</template>

<script setup>
import { computed } from 'vue'
import { RouterView } from 'vue-router'
import { ConfigProvider as AConfigProvider, App as AApp, theme } from 'ant-design-vue'
import ThemeTokenBridge from '@/components/ThemeTokenBridge.vue'
// Importing useDarkMode here ensures the .dark-mode class is applied to <html>
// on every page (including /login) as soon as the app boots.
import { useDarkMode } from '@/composables/useDarkMode'
import { antdPrimaryColor } from '@/composables/useAppColor'
const { isDark } = useDarkMode()

/* Unlike PrimeVue (CSS-variable + class-selector dark mode, "just works" for
 * anything already on the page), AntD's dark mode is opt-in per subtree via
 * ConfigProvider's theme.algorithm — components outside a ConfigProvider
 * (or missing this prop) silently stay light. */
const antdTheme = computed(() => ({
  algorithm: isDark.value ? theme.darkAlgorithm : theme.defaultAlgorithm,
  token: { colorPrimary: antdPrimaryColor.value },
}))
</script>

<style>
/* AntD's <a-app> renders an unstyled div (class "ant-app") between #app and
   our own layout root. #app is `display:flex; flex-direction:column;
   height:100%` expecting its child to fill that height, but a plain div
   defaults to `flex: 0 1 auto` and just grows to fit its content instead —
   breaking the height:100% chain that .app-shell, .app-sidebar and every
   overflow-y:auto container below it rely on. Without this, content taller
   than the viewport is silently clipped by html/body's overflow:hidden
   instead of scrolling inside the intended containers. */
#app > .ant-app {
  flex: 1;
  min-height: 0;
}

/* Fixed elements (topbar, sidebar) need global solid backgrounds
   because scoped CSS + position:fixed can break stacking/paint.
   Uses --bdus-surface defined in main.css (flips in .dark-mode). */
.app-topbar {
  background-color: var(--bdus-surface) !important;
  border-bottom: 1px solid var(--p-content-border-color) !important;
  box-shadow: 0 2px 8px rgba(0,0,0,0.10) !important;
}

.app-sidebar {
  background-color: var(--bdus-surface) !important;
  border-right: 1px solid var(--p-content-border-color) !important;
  box-shadow: 2px 0 12px rgba(0,0,0,0.06) !important;
}

.app-table-sidebar {
  background-color: var(--bdus-surface) !important;
}
</style>
