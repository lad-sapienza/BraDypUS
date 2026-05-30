<template>
  <div class="login-wrapper">
    <div class="login-card">
      <h1 class="login-title">BraDypUS</h1>

      <form @submit.prevent="handleLogin">
        <div class="field">
          <label for="app">Application</label>
          <Select
            id="app"
            v-model="form.app"
            :options="apps"
            optionLabel="name"
            placeholder="Select an application…"
            :loading="loadingApps"
            :disabled="loading"
            fluid
          >
            <template #option="{ option }">
              <div>
                <div class="app-option-name">{{ option.name }}</div>
                <div v-if="option.definition" class="app-option-definition">{{ option.definition }}</div>
              </div>
            </template>
          </Select>
        </div>

        <div class="field">
          <label for="email">Email</label>
          <InputText
            id="email"
            v-model="form.email"
            type="email"
            placeholder="you@example.com"
            :disabled="loading"
            fluid
          />
        </div>

        <div class="field">
          <label for="password">Password</label>
          <Password
            id="password"
            v-model="form.password"
            :feedback="false"
            toggleMask
            :disabled="loading"
            fluid
          />
        </div>

        <Message v-if="error" severity="error" :closable="false">
          {{ error }}
        </Message>

        <Button
          type="submit"
          label="Login"
          icon="pi pi-sign-in"
          :loading="loading"
          :disabled="!form.app || !form.email || !form.password"
          fluid
        />
      </form>

      <!-- OAuth2 / SSO section — only shown when the selected app has providers -->
      <div v-if="form.app && oauthProviders.length" class="oauth-section">
        <div class="oauth-divider"><span>or sign in with</span></div>
        <div class="oauth-buttons">
          <Button
            v-for="p in oauthProviders"
            :key="p.id"
            :label="p.label"
            :icon="p.icon"
            severity="secondary"
            outlined
            :loading="oauthLoading === p.id"
            :disabled="!!oauthLoading"
            fluid
            @click="handleOAuth(p.id)"
          />
        </div>
      </div>

      <div v-if="canCreateApp" class="create-app-link">
        <router-link to="/new-app">{{ t('create_new_app') }}</router-link>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/api'
import { useI18n } from '@/i18n'
import Select from 'primevue/select'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import Button from 'primevue/button'
import Message from 'primevue/message'

const { t } = useI18n()
const router = useRouter()
const auth = useAuthStore()

// form.app holds the full option object; .db is extracted on submit
const form = ref({ app: null, email: '', password: '' })
const loading = ref(false)
const loadingApps = ref(false)
const error = ref(null)
const apps = ref([])
const canCreateApp = ref(false)

// OAuth: per-app provider availability (fetched with app info)
const appOAuthConfig = ref({})   // { appDb: ['google', 'orcid'] }
const oauthLoading = ref(null)   // provider id currently loading

const PROVIDER_META = {
  google: { id: 'google', label: 'Google', icon: 'pi pi-google' },
  orcid:  { id: 'orcid',  label: 'ORCID',  icon: 'pi pi-id-card' },
}

/** Providers configured for the currently selected app, in display order */
const oauthProviders = computed(() => {
  if (!form.value.app?.db) return []
  const configured = appOAuthConfig.value[form.value.app.db] ?? []
  return configured.map(id => PROVIDER_META[id]).filter(Boolean)
})

onMounted(async () => {
  loadingApps.value = true
  try {
    const [appsRes, statusRes] = await Promise.all([
      api.get('/api/auth/apps'),
      api.get('/api/new-app/status'),
    ])
    apps.value = appsRes.apps ?? []
    // Pre-select if only one app available
    if (apps.value.length === 1) {
      form.value.app = apps.value[0]
    }
    canCreateApp.value = statusRes.permitted ?? false

    // Each app entry carries its own oauth provider list
    for (const app of apps.value) {
      if (Array.isArray(app.oauth) && app.oauth.length) {
        appOAuthConfig.value[app.db] = app.oauth
      }
    }
  } catch {
    apps.value = []
  } finally {
    loadingApps.value = false
  }
})

async function handleLogin() {
  error.value = null
  loading.value = true
  try {
    await auth.login(form.value.email, form.value.password, form.value.app?.db)
    router.push(`/${auth.user.app}/`)
  } catch (e) {
    error.value = t(e.message)
  } finally {
    loading.value = false
  }
}

async function handleOAuth(provider) {
  if (!form.value.app?.db) return
  oauthLoading.value = provider
  error.value = null
  try {
    const origin = window.location.origin
    const res = await api.get(
      `/api/auth/oauth/${provider}/redirect`,
      { app: form.value.app.db, origin }
    )
    if (res.status === 'success' && res.url) {
      // Navigate the browser to the provider's authorization page.
      // This is a full page navigation, not a fetch.
      window.location.href = res.url
    } else {
      error.value = res.text ?? 'OAuth initialization failed.'
      oauthLoading.value = null
    }
  } catch (e) {
    error.value = e.message ?? 'OAuth initialization failed.'
    oauthLoading.value = null
  }
}
</script>

<style scoped>
/* .login-wrapper / .login-card / .login-title live in main.css (shared with NewAppView) */

.field {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  margin-bottom: 1.2rem;
}

.field label {
  font-size: 0.9rem;
  font-weight: 500;
  color: var(--p-text-muted-color);
}

.app-option-name {
  font-weight: 600;
}

.app-option-definition {
  font-size: 0.8rem;
  color: var(--p-text-muted-color);
  margin-top: 0.1rem;
}

.create-app-link {
  text-align: center;
  margin-top: 1.25rem;
  font-size: 0.85rem;
}
.create-app-link a {
  color: var(--p-text-muted-color);
  text-decoration: none;
}
.create-app-link a:hover {
  color: var(--p-primary-color);
}

/* ── OAuth section ─────────────────────────────────────────────── */
.oauth-section {
  margin-top: 1.5rem;
}

.oauth-divider {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-bottom: 1rem;
  color: var(--p-text-muted-color);
  font-size: 0.8rem;
}

.oauth-divider::before,
.oauth-divider::after {
  content: '';
  flex: 1;
  height: 1px;
  background: var(--p-content-border-color);
}

.oauth-buttons {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}
</style>
