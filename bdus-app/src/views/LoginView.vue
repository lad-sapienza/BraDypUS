<template>
  <div class="login-wrapper">
    <div class="login-card">
      <h1 class="login-title">BraDypUS</h1>

      <!-- ── App selector — always visible ────────────────────────────────── -->
      <div class="field">
        <label for="app">Application</label>
        <Select
          id="app"
          v-model="form.app"
          :options="apps"
          optionLabel="name"
          placeholder="Select an application…"
          :loading="loadingApps"
          :disabled="loading || upgrading"
          fluid
        >
          <!-- Dropdown option: show name, definition, and upgrade badge if needed -->
          <template #option="{ option }">
            <div class="app-option">
              <div class="app-option-row">
                <span class="app-option-name">{{ option.name }}</span>
                <Tag
                  v-if="option.upgrade === 'major'"
                  :value="t('upgrade_tag_major')"
                  severity="danger"
                  class="app-option-tag"
                />
                <Tag
                  v-else-if="option.upgrade === 'minor'"
                  :value="t('upgrade_tag_minor')"
                  severity="warn"
                  class="app-option-tag"
                />
              </div>
              <div v-if="option.definition" class="app-option-definition">{{ option.definition }}</div>
            </div>
          </template>

          <!-- Selected-value display: keep badge visible after selection -->
          <template #value="{ value, placeholder }">
            <div v-if="value" class="app-selected">
              <span>{{ value.name }}</span>
              <Tag
                v-if="value.upgrade === 'major'"
                :value="t('upgrade_tag_major')"
                severity="danger"
                class="app-option-tag"
              />
              <Tag
                v-else-if="value.upgrade === 'minor'"
                :value="t('upgrade_tag_minor')"
                severity="warn"
                class="app-option-tag"
              />
            </div>
            <span v-else class="p-select-placeholder">{{ placeholder }}</span>
          </template>
        </Select>
      </div>

      <!-- ── Major upgrade panel ────────────────────────────────────────────── -->
      <template v-if="upgradeState === 'major'">
        <div class="upgrade-banner">
          <span class="pi pi-exclamation-triangle upgrade-icon" />
          <div>
            <strong>{{ t('major_upgrade_required') }}</strong>
            <p class="upgrade-hint">{{ t('major_upgrade_hint') }}</p>
          </div>
        </div>

        <div v-if="upgradeDone" class="upgrade-done">
          <span class="pi pi-check-circle" style="color:var(--p-green-500)" />
          {{ t('upgrade_complete_login') }}
        </div>

        <form v-else @submit.prevent="handleMajorUpgrade">
          <p class="upgrade-auth-hint">{{ t('major_upgrade_auth_hint') }}</p>

          <div class="field">
            <label for="upgrade-email">Email (superadmin)</label>
            <InputText
              id="upgrade-email"
              v-model="upgradeForm.email"
              type="email"
              placeholder="superadmin@example.com"
              :disabled="upgrading"
              fluid
            />
          </div>

          <div class="field">
            <label for="upgrade-password">Password</label>
            <Password
              id="upgrade-password"
              v-model="upgradeForm.password"
              :feedback="false"
              toggleMask
              :disabled="upgrading"
              fluid
            />
          </div>

          <Message v-if="upgradeError" severity="error" :closable="false">
            {{ upgradeError }}
          </Message>

          <Button
            type="submit"
            :label="t('major_upgrade_apply')"
            icon="pi pi-upload"
            severity="warning"
            :loading="upgrading"
            :disabled="!upgradeForm.email || !upgradeForm.password"
            fluid
          />
        </form>
      </template>

      <!-- ── Normal login form ─────────────────────────────────────────────── -->
      <template v-else-if="form.app">
        <form @submit.prevent="handleLogin">
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
            :disabled="!form.email || !form.password"
            fluid
          />
        </form>

        <!-- OAuth2 / SSO section -->
        <div v-if="oauthProviders.length" class="oauth-section">
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
      </template>

      <div v-if="canCreateApp" class="create-app-link">
        <router-link to="/new-app">{{ t('create_new_app') }}</router-link>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/api'
import { useI18n } from '@/i18n'
import Select from 'primevue/select'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Tag from 'primevue/tag'

const { t } = useI18n()
const router = useRouter()
const auth = useAuthStore()

const form = ref({ app: null, email: '', password: '' })
const loading = ref(false)
const loadingApps = ref(false)
const error = ref(null)
const apps = ref([])
const canCreateApp = ref(false)

const appOAuthConfig = ref({})
const oauthLoading = ref(null)

// Upgrade state
const upgradeForm = ref({ email: '', password: '' })
const upgradeError = ref(null)
const upgrading = ref(false)
const upgradeDone = ref(false)

const PROVIDER_META = {
  google: { id: 'google', label: 'Google', icon: 'pi pi-google' },
  orcid:  { id: 'orcid',  label: 'ORCID',  icon: 'pi pi-id-card' },
}

const oauthProviders = computed(() => {
  if (!form.value.app?.db) return []
  const configured = appOAuthConfig.value[form.value.app.db] ?? []
  return configured.map(id => PROVIDER_META[id]).filter(Boolean)
})

// upgradeState is driven by the selected app's `upgrade` field from listApps —
// no separate API call needed.
const upgradeState = computed(() => {
  if (upgradeDone.value) return null
  return form.value.app?.upgrade ?? null
})

onMounted(async () => {
  loadingApps.value = true
  try {
    const [appsRes, statusRes] = await Promise.all([
      api.get('/api/auth/apps'),
      api.get('/api/new-app/status'),
    ])
    apps.value = appsRes.apps ?? []
    if (apps.value.length === 1) {
      form.value.app = apps.value[0]
    }
    canCreateApp.value = statusRes.permitted ?? false

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

// Reset upgrade form state on app change.
watch(() => form.value.app, () => {
  upgradeError.value = null
  upgradeDone.value = false
  upgradeForm.value = { email: '', password: '' }
  error.value = null
})

async function handleLogin() {
  error.value = null
  loading.value = true
  try {
    const upgrade = await auth.login(form.value.email, form.value.password, form.value.app?.db)
    if (upgrade?.type === 'minor') {
      router.push(`/${auth.user.app}/upgrade`)
    } else {
      router.push(`/${auth.user.app}/`)
    }
  } catch (e) {
    error.value = t(e.message)
  } finally {
    loading.value = false
  }
}

async function handleMajorUpgrade() {
  upgradeError.value = null
  upgrading.value = true
  try {
    const res = await api.post('/api/upgrade/major', {
      app:      form.value.app?.db,
      email:    upgradeForm.value.email,
      password: upgradeForm.value.password,
    })
    if (res.status === 'success') {
      upgradeDone.value = true
      upgradeForm.value = { email: '', password: '' }
      // Refresh app list so the badge disappears on the now-upgraded app.
      try {
        const refreshed = await api.get('/api/auth/apps')
        apps.value = refreshed.apps ?? []
        const updatedApp = apps.value.find(a => a.db === form.value.app?.db)
        if (updatedApp) form.value.app = updatedApp
      } catch {
        // Non-fatal: badge will disappear on next full page load.
      }
    } else {
      upgradeError.value = t(res.code ?? 'upgrade_failed')
    }
  } catch (e) {
    upgradeError.value = t(e.message ?? 'upgrade_failed')
  } finally {
    upgrading.value = false
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
/* .login-wrapper / .login-card / .login-title live in main.css */

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

/* ── App option in dropdown ─────────────────────────────────────── */
.app-option {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
}

.app-option-row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.app-option-name {
  font-weight: 600;
}

.app-option-definition {
  font-size: 0.8rem;
  color: var(--p-text-muted-color);
}

.app-option-tag {
  font-size: 0.7rem !important;
  padding: 0.1em 0.45em !important;
  line-height: 1.4;
}

/* Selected-value display in the closed Select */
.app-selected {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

/* ── Create app link ────────────────────────────────────────────── */
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

/* ── OAuth section ──────────────────────────────────────────────── */
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

/* ── Upgrade panel ──────────────────────────────────────────────── */
.upgrade-banner {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
  background: color-mix(in srgb, var(--p-yellow-400) 12%, transparent);
  border: 1px solid color-mix(in srgb, var(--p-yellow-400) 40%, transparent);
  border-radius: 6px;
  padding: 0.9rem 1rem;
  margin-bottom: 1.25rem;
}

.upgrade-banner p {
  margin: 0.25rem 0 0;
  font-size: 0.82rem;
  color: var(--p-text-muted-color);
}

.upgrade-icon {
  font-size: 1.3rem;
  color: var(--p-yellow-600);
  flex-shrink: 0;
  margin-top: 0.1rem;
}

.upgrade-auth-hint {
  font-size: 0.85rem;
  color: var(--p-text-muted-color);
  margin: 0 0 1rem;
}

.upgrade-done {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.9rem;
  padding: 0.75rem 0;
}
</style>
