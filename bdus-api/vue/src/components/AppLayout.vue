<template>
  <div class="layout">
    <aside class="sidebar">
      <div class="sidebar-brand">BraDypUS</div>

      <nav class="sidebar-nav">
        <RouterLink to="/" class="nav-item">
          <i class="pi pi-home" />
          <span>Home</span>
        </RouterLink>
        <RouterLink to="/users" class="nav-item">
          <i class="pi pi-users" />
          <span>Users</span>
        </RouterLink>
      </nav>

      <div class="sidebar-footer">
        <Button
          label="Logout"
          icon="pi pi-sign-out"
          text
          size="small"
          @click="handleLogout"
        />
      </div>
    </aside>

    <main class="main-content">
      <slot />
    </main>
  </div>
</template>

<script setup>
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import Button from 'primevue/button'

const router = useRouter()
const auth = useAuthStore()

async function handleLogout() {
  await auth.logout()
  router.push('/login')
}
</script>

<style scoped>
.layout {
  display: flex;
  min-height: 100vh;
}

.sidebar {
  width: 220px;
  flex-shrink: 0;
  background: var(--p-surface-card);
  border-right: 1px solid var(--p-surface-border);
  display: flex;
  flex-direction: column;
  padding: 1rem 0;
}

.sidebar-brand {
  font-size: 1.2rem;
  font-weight: 700;
  color: var(--p-primary-color);
  padding: 0.5rem 1.2rem 1.5rem;
}

.sidebar-nav {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  padding: 0 0.5rem;
}

.nav-item {
  display: flex;
  align-items: center;
  gap: 0.7rem;
  padding: 0.6rem 0.8rem;
  border-radius: var(--p-border-radius-md);
  text-decoration: none;
  color: var(--p-text-color);
  font-size: 0.9rem;
  transition: background 0.15s;
}

.nav-item:hover {
  background: var(--p-surface-hover);
}

.nav-item.router-link-active {
  background: var(--p-primary-50);
  color: var(--p-primary-color);
  font-weight: 500;
}

.sidebar-footer {
  padding: 0.5rem;
  border-top: 1px solid var(--p-surface-border);
  margin-top: 1rem;
}

.main-content {
  flex: 1;
  overflow: auto;
  background: var(--p-surface-ground);
}
</style>
