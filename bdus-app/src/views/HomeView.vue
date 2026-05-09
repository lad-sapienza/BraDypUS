<template>
  <AppLayout>
    <div class="home">
      <h2>Dashboard</h2>
      <p class="subtitle">Welcome, {{ auth.user?.name }}</p>
    </div>
  </AppLayout>
</template>

<script setup>
import AppLayout from '@/components/AppLayout.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
</script>

<style scoped>
.home {
  padding: 2rem;
}

.subtitle {
  color: var(--p-text-muted-color);
  margin-top: 0.5rem;
}
</style>
