<template>
  <AppLayout>
    <div class="home-page">

      <div class="page-header">
        <h2>{{ t('dashboard') }}</h2>
        <p>{{ t('welcome_back', auth.user?.name) }}</p>
      </div>

      <div class="cards-grid">
        <Card v-for="item in modules" :key="item.labelKey">
          <template #header>
            <div class="card-icon-header">
              <i :class="['pi', item.icon]" />
            </div>
          </template>
          <template #title>{{ t(item.labelKey) }}</template>
          <template #content>
            <p>{{ t(item.descKey) }}</p>
          </template>
          <template #footer>
            <Button
              :label="t('open')"
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
import { useI18n } from '@/i18n'
import Card from 'primevue/card'
import Button from 'primevue/button'

const auth = useAuthStore()
const { t } = useI18n()

const modules = [
  { labelKey: 'user_mng',      icon: 'pi-users',     descKey: 'module_users_desc',    route: '/users'  },
  { labelKey: 'data_mng',      icon: 'pi-database',  descKey: 'module_data_desc',     route: null      },
  { labelKey: 'map',           icon: 'pi-map',       descKey: 'module_map_desc',      route: null      },
  { labelKey: 'chart',         icon: 'pi-chart-bar', descKey: 'module_charts_desc',   route: null      },
  { labelKey: 'harris_matrix', icon: 'pi-share-alt', descKey: 'module_harris_desc',   route: null      },
  { labelKey: 'sys_config',    icon: 'pi-cog',       descKey: 'module_settings_desc', route: null      },
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
