<template>
  <Toast position="bottom-right" />
  <ConfirmDialog />
  <RouterView />
</template>

<script setup>
import Toast         from 'primevue/toast'
import ConfirmDialog from 'primevue/confirmdialog'
import { RouterView } from 'vue-router'
// Importing useDarkMode here ensures the .dark-mode class is applied to <html>
// on every page (including /login) as soon as the app boots.
import { useDarkMode } from '@/composables/useDarkMode'
useDarkMode()
</script>

<style>
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
