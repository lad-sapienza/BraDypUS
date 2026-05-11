<template>
  <Toast position="bottom-right" />
  <ConfirmDialog />
  <RouterView />
</template>

<script setup>
import Toast         from 'primevue/toast'
import ConfirmDialog from 'primevue/confirmdialog'
import { RouterView } from 'vue-router'
</script>

<style>
* {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
}

body {
  font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto,
               Oxygen, Ubuntu, Cantarell, 'Helvetica Neue', Arial, sans-serif;
  background: var(--p-surface-ground, #f8f9fa);
  color: var(--p-text-color, #212529);
  min-height: 100vh;
}

/* Fixed elements (topbar, sidebar) need global solid backgrounds
   because scoped CSS + position:fixed can break stacking/paint */
.app-topbar {
  background-color: var(--p-surface-card, #ffffff) !important;
  border-bottom: 1px solid var(--p-surface-border, #dee2e6) !important;
  box-shadow: 0 2px 8px rgba(0,0,0,0.10) !important;
}

.app-sidebar {
  background-color: var(--p-surface-card, #ffffff) !important;
  border-right: 1px solid var(--p-surface-border, #dee2e6) !important;
  box-shadow: 2px 0 12px rgba(0,0,0,0.10) !important;
}

.app-table-sidebar {
  background-color: var(--p-surface-card, #ffffff) !important;
}
</style>
