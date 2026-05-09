<template>
  <Toast />
  <RouterView />
</template>

<script setup>
import Toast from 'primevue/toast'
import { RouterView } from 'vue-router'
</script>

<style>
* {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
}

body {
  font-family: var(--p-font-family);
  background: var(--p-surface-ground);
  color: var(--p-text-color);
  min-height: 100vh;
}
</style>
