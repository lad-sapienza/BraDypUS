<template>
  <AppLayout>
    <div class="home-page">

      <div class="page-header">
        <h2>Dashboard</h2>
        <p>Welcome back, <strong>{{ auth.user?.name }}</strong></p>
      </div>

      <div class="cards-grid">
        <Card v-for="item in modules" :key="item.label">
          <template #header>
            <div class="card-icon-header">
              <i :class="['pi', item.icon]" />
            </div>
          </template>
          <template #title>{{ item.label }}</template>
          <template #content>
            <p>{{ item.description }}</p>
          </template>
          <template #footer>
            <Button
              label="Open"
              icon="pi pi-arrow-right"
              iconPos="right"
              :disabled="!item.route"
              @click="item.route && $router.push(item.route)"
            />
          </template>
        </Card>
      </div>

    </div>
  </AppLayout>
</template>

<script setup>
import AppLayout from '@/components/AppLayout.vue'
import { useAuthStore } from '@/stores/auth'
import Card from 'primevue/card'
import Button from 'primevue/button'

const auth = useAuthStore()

const modules = [
  { label: 'Users',         icon: 'pi-users',     description: 'Manage users and access privileges.',                    route: '/users'  },
  { label: 'Data',          icon: 'pi-database',  description: 'Browse, search and edit records in your tables.',        route: null      },
  { label: 'Map',           icon: 'pi-map',       description: 'Visualize and edit geospatial data.',                    route: null      },
  { label: 'Charts',        icon: 'pi-chart-bar', description: 'Build and run charts from saved SQL queries.',           route: null      },
  { label: 'Harris Matrix', icon: 'pi-share-alt', description: 'View stratigraphic relationships as a diagram.',         route: null      },
  { label: 'Settings',      icon: 'pi-cog',       description: 'Configure tables, fields and application options.',      route: null      },
]
</script>

<style scoped>
.home-page {
  padding: 2rem;
}

.page-header {
  margin-bottom: 2rem;
}

.page-header h2 {
  font-size: 1.6rem;
  font-weight: 700;
  margin-bottom: 0.3rem;
}

.page-header p {
  color: var(--p-text-muted-color);
}

.cards-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 1.2rem;
}

.card-icon-header {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 80px;
  background: var(--p-primary-50);
}

.card-icon-header .pi {
  font-size: 2rem;
  color: var(--p-primary-color);
}
</style>
