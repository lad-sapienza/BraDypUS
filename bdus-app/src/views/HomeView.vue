<template>
  <AppLayout>
    <div class="home-page">

      <!-- App identity banner -->
      <div class="app-banner">
        <div class="app-banner-text">
          <h1 class="app-name">{{ appName || 'BraDypUS' }}</h1>
          <p v-if="appDefinition" class="app-definition">{{ appDefinition }}</p>
        </div>
        <div class="app-banner-meta">
          <span class="welcome-chip">
            <i class="pi pi-user" />
            {{ auth.user?.name }}
          </span>
        </div>
      </div>

      <div class="page-header">
        <h2>{{ t('dashboard') }}</h2>
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
import { ref, onMounted } from 'vue'
import AppLayout from '@/components/AppLayout.vue'
import { useAuthStore } from '@/stores/auth'
import { useI18n } from '@/i18n'
import { api } from '@/api'
import Card from 'primevue/card'
import Button from 'primevue/button'

const auth = useAuthStore()
const { t } = useI18n()

const appName       = ref('')
const appDefinition = ref('')

onMounted(async () => {
  try {
    const res = await api.get('info_ctrl', 'getAppInfo')
    if (res.status === 'success') {
      appName.value       = res.name
      appDefinition.value = res.definition
    }
  } catch {
    // non-critical — fall back to empty
  }
})

const modules = [
  { labelKey: 'data_mng',          icon: 'pi-database',  descKey: 'module_data_desc',      route: '/data'      },
  { labelKey: 'user_mng',          icon: 'pi-users',      descKey: 'module_users_desc',     route: '/users'     },
  { labelKey: 'sys_config',        icon: 'pi-cog',        descKey: 'module_settings_desc',  route: '/config'    },
  { labelKey: 'design_templates',  icon: 'pi-palette',    descKey: 'module_templates_desc', route: '/templates' },
  { labelKey: 'map',               icon: 'pi-map',        descKey: 'module_map_desc',       route: null         },
  { labelKey: 'chart',             icon: 'pi-chart-bar',  descKey: 'module_charts_desc',    route: null         },
  { labelKey: 'harris_matrix',     icon: 'pi-share-alt',  descKey: 'module_harris_desc',    route: null         },
]
</script>

<style scoped>
.home-page {
  padding: 2rem;
  overflow-y: auto;
  height: 100%;
}

/* ── App banner ───────────────────────────────────────────── */
.app-banner {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  padding: 1.5rem 2rem;
  margin-bottom: 2rem;
  background: var(--p-highlight-background);
  border-radius: var(--p-border-radius-xl);
  border-left: 4px solid var(--p-primary-color);
}

.app-name {
  font-size: 1.6rem;
  font-weight: 700;
  color: var(--p-primary-color);
  margin-bottom: 0.25rem;
  text-transform: uppercase;
}

.app-definition {
  color: var(--p-text-muted-color);
  font-size: 0.95rem;
  margin: 0;
}

.welcome-chip {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.85rem;
  color: var(--p-text-muted-color);
  white-space: nowrap;
  padding-top: 0.25rem;
}

/* ── Section header ───────────────────────────────────────── */
.page-header {
  margin-bottom: 1.25rem;
}

.page-header h2 {
  font-size: 1.25rem;
  font-weight: 600;
  margin: 0;
}

/* ── Cards ────────────────────────────────────────────────── */
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
  background: var(--p-highlight-background);
}

.card-icon-header .pi {
  font-size: 2rem;
  color: var(--p-primary-color);
}
</style>
