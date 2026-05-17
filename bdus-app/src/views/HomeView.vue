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

      <!-- Welcome text (between banner and cards) -->
      <div v-if="welcomeContent || isAdmin" class="welcome-section">

        <!-- View mode -->
        <div v-if="!editingWelcome">
          <div
            v-if="welcomeContent"
            class="welcome-body"
            v-html="renderedWelcome"
          />
          <div v-else class="welcome-empty">
            <i class="pi pi-info-circle" />
            {{ t('welcome_empty_hint') }}
          </div>
          <div v-if="isAdmin" class="welcome-actions">
            <Button
              :label="t('edit')"
              icon="pi pi-pencil"
              size="small"
              severity="secondary"
              outlined
              @click="startEdit"
            />
          </div>
        </div>

        <!-- Edit mode (admin only) -->
        <div v-else class="welcome-editor">
          <Textarea
            v-model="editBuffer"
            :placeholder="t('welcome_placeholder')"
            rows="8"
            class="w-full welcome-textarea"
            autoResize
          />
          <div class="welcome-editor-hint">
            <i class="pi pi-info-circle" />
            {{ t('welcome_md_hint') }}
          </div>
          <div class="welcome-editor-actions">
            <Button
              :label="t('cancel')"
              severity="secondary"
              outlined
              size="small"
              @click="cancelEdit"
            />
            <Button
              :label="t('save')"
              icon="pi pi-check"
              size="small"
              :loading="saving"
              @click="saveWelcome"
            />
          </div>
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
import { ref, computed, onMounted } from 'vue'
import { marked } from 'marked'
import AppLayout from '@/components/AppLayout.vue'
import { useAuthStore } from '@/stores/auth'
import { useI18n } from '@/i18n'
import { api } from '@/api'
import { useToast } from 'primevue/usetoast'
import Card     from 'primevue/card'
import Button   from 'primevue/button'
import Textarea from 'primevue/textarea'

const auth  = useAuthStore()
const { t } = useI18n()
const toast = useToast()

const appName       = ref('')
const appDefinition = ref('')

// ── Welcome text ──────────────────────────────────────────────────────────────
const welcomeContent  = ref('')   // raw MD/HTML from server
const editingWelcome  = ref(false)
const editBuffer      = ref('')
const saving          = ref(false)

const isAdmin = computed(() => {
  const priv = auth.user?.privilege
  return priv !== undefined && priv <= 20   // adm or super_adm
})

// Render MD + raw HTML (marked passes HTML through unchanged)
const renderedWelcome = computed(() => marked.parse(welcomeContent.value || ''))

function startEdit() {
  editBuffer.value    = welcomeContent.value
  editingWelcome.value = true
}

function cancelEdit() {
  editingWelcome.value = false
}

async function saveWelcome() {
  saving.value = true
  try {
    const res = await api.post('frontpage_editor_ctrl', 'saveWelcome', { content: editBuffer.value })
    if (res.status === 'success') {
      welcomeContent.value  = editBuffer.value
      editingWelcome.value  = false
      toast.add({ severity: 'success', summary: t('save'), detail: t(res.code), life: 3000 })
    } else {
      toast.add({ severity: 'error', summary: t('error'), detail: t(res.code ?? 'error'), life: 4000 })
    }
  } catch (e) {
    toast.add({ severity: 'error', summary: t('error'), detail: String(e), life: 4000 })
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  // Load app info and welcome text in parallel
  const [infoRes, welcomeRes] = await Promise.allSettled([
    api.get('info_ctrl', 'getAppInfo'),
    api.get('frontpage_editor_ctrl', 'getWelcome'),
  ])

  if (infoRes.status === 'fulfilled' && infoRes.value.status === 'success') {
    appName.value       = infoRes.value.name
    appDefinition.value = infoRes.value.definition
  }

  if (welcomeRes.status === 'fulfilled') {
    welcomeContent.value = welcomeRes.value.content ?? ''
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

/* ── Welcome section ─────────────────────────────────────── */
.welcome-section {
  margin-bottom: 2rem;
  padding: 1.25rem 1.5rem;
  background: var(--p-surface-card);
  border: 1px solid var(--p-surface-border);
  border-radius: var(--p-border-radius-lg);
}

.welcome-body {
  line-height: 1.7;
  color: var(--p-text-color);
}

/* Scope markdown-generated styles */
.welcome-body :deep(h1),
.welcome-body :deep(h2),
.welcome-body :deep(h3) {
  font-weight: 600;
  margin: 0.75rem 0 0.4rem;
  color: var(--p-text-color);
}

.welcome-body :deep(p)  { margin: 0.4rem 0; }
.welcome-body :deep(ul),
.welcome-body :deep(ol) { padding-left: 1.5rem; margin: 0.4rem 0; }
.welcome-body :deep(a)  { color: var(--p-primary-color); }
.welcome-body :deep(code) {
  background: var(--p-surface-ground);
  padding: 0.1em 0.3em;
  border-radius: 3px;
  font-size: 0.88em;
}

.welcome-empty {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  color: var(--p-text-muted-color);
  font-size: 0.9rem;
}

.welcome-actions {
  margin-top: 0.75rem;
  display: flex;
  justify-content: flex-end;
}

.welcome-editor { display: flex; flex-direction: column; gap: 0.5rem; }

.welcome-textarea { font-family: monospace; font-size: 0.88rem; }

.welcome-editor-hint {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.8rem;
  color: var(--p-text-muted-color);
}

.welcome-editor-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  margin-top: 0.25rem;
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
