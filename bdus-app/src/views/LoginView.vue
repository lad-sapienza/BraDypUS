<template>
  <div class="login-wrapper">
    <button class="dark-toggle" :title="isDark ? t('light_mode') : t('dark_mode')" @click="toggleDark">
      <span :class="isDark ? 'pi pi-sun' : 'pi pi-moon'" />
    </button>
    <div class="login-card">
      <img src="@/assets/bdus.svg" alt="BraDypUS logo" />
      <h1 class="login-title">BraDypUS</h1>

      <!-- ── App selector — always visible ────────────────────────────────── -->
      <div class="field">
        <label for="app">Application</label>
        <ASelect
          id="app"
          v-model:value="selectedAppDb"
          :options="appOptions"
          placeholder="Select an application…"
          :loading="loadingApps"
          :disabled="loading || upgrading"
          show-search
          style="width:100%"
        >
          <!-- Dropdown option: show name, definition, and upgrade badge if needed -->
          <template #option="option">
            <div class="app-option">
              <div class="app-option-row">
                <span class="app-option-name">{{ option.name }}</span>
                <ATag v-if="option.upgrade === 'major'" color="error" class="app-option-tag">
                  {{ t('upgrade_tag_major') }}
                </ATag>
                <ATag v-else-if="option.upgrade === 'minor'" color="warning" class="app-option-tag">
                  {{ t('upgrade_tag_minor') }}
                </ATag>
              </div>
              <div v-if="option.definition" class="app-option-definition">{{ option.definition }}</div>
            </div>
          </template>

          <!-- Selected-value display: keep badge visible after selection -->
          <template #optionLabel="option">
            <div class="app-selected">
              <span>{{ option.name }}</span>
              <ATag v-if="option.upgrade === 'major'" color="error" class="app-option-tag">
                {{ t('upgrade_tag_major') }}
              </ATag>
              <ATag v-else-if="option.upgrade === 'minor'" color="warning" class="app-option-tag">
                {{ t('upgrade_tag_minor') }}
              </ATag>
            </div>
          </template>
        </ASelect>
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
            <AInput
              id="upgrade-email"
              v-model:value="upgradeForm.email"
              type="email"
              placeholder="superadmin@example.com"
              :disabled="upgrading"
            />
          </div>

          <div class="field">
            <label for="upgrade-password">Password</label>
            <AInputPassword
              id="upgrade-password"
              v-model:value="upgradeForm.password"
              :disabled="upgrading"
            />
          </div>

          <AAlert v-if="upgradeError" type="error" :message="upgradeError" :closable="false" show-icon />

          <AButton
            danger
            html-type="submit"
            block
            :loading="upgrading"
            :disabled="!upgradeForm.email || !upgradeForm.password"
          >
            <template #icon><i class="pi pi-upload" /></template>
            {{ t('major_upgrade_apply') }}
          </AButton>
        </form>
      </template>

      <!-- ── Normal login form ─────────────────────────────────────────────── -->
      <template v-else-if="form.app">
        <form @submit.prevent="handleLogin">
          <div class="field">
            <label for="email">Email</label>
            <AInput
              id="email"
              v-model:value="form.email"
              type="email"
              placeholder="you@example.com"
              :disabled="loading"
            />
          </div>

          <div class="field">
            <label for="password">Password</label>
            <AInputPassword
              id="password"
              v-model:value="form.password"
              :disabled="loading"
            />
          </div>

          <AAlert v-if="error" type="error" :message="error" :closable="false" show-icon />

          <AButton type="primary" html-type="submit" block :loading="loading" :disabled="!form.email || !form.password">
            <template #icon><i class="pi pi-sign-in" /></template>
            Login
          </AButton>
        </form>

        <!-- OAuth2 / SSO section -->
        <div v-if="oauthProviders.length" class="oauth-section">
          <div class="oauth-divider"><span>or sign in with</span></div>
          <div class="oauth-buttons">
            <AButton
              v-for="p in oauthProviders"
              :key="p.id"
              block
              :loading="oauthLoading === p.id"
              :disabled="!!oauthLoading"
              @click="handleOAuth(p.id)"
            >
              <template #icon><i :class="p.icon" /></template>
              {{ p.label }}
            </AButton>
          </div>
        </div>
      </template>

      <div v-if="canCreateApp" class="create-app-link">
        <router-link to="/new-app">{{ t('create_new_app') }}</router-link>
      </div>
    </div>

    <footer class="login-footer">
      <p><strong>BraDypUS</strong> v{{ appVersion }}</p>
      <p>
        <a href="https://github.com/lad-sapienza/BraDypUS" target="_blank" rel="noopener">
          Free and open source software (AGPL-3.0)
        </a>
        <br />
        <a href="https://purl.org/lad" target="_blank" rel="noopener">
          By LAD, Sapienza University of Rome
        </a>
        &nbsp;·&nbsp;
        <a href="https://github.com/lad-sapienza/BraDypUS/issues" target="_blank" rel="noopener">
          Report an issue
        </a>
      </p>
    </footer>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/api'
import { useI18n } from '@/i18n'
import { useDarkMode } from '@/composables/useDarkMode'
import { Select as ASelect, Input, Button as AButton, Alert as AAlert, Tag as ATag } from 'ant-design-vue'

const AInput         = Input
const AInputPassword = Input.Password

const { t } = useI18n()
const router = useRouter()
const auth = useAuthStore()
const { isDark, toggle: toggleDark } = useDarkMode()
const appVersion = __APP_VERSION__

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

// AntD's Select needs a primitive `value` for reliable option matching — the
// app's `db` id — while the rest of the component's logic keeps working with
// the full app object (form.value.app), as before.
const appOptions = computed(() => apps.value.map(a => ({
  value:      a.db,
  label:      a.name,
  name:       a.name,
  definition: a.definition,
  upgrade:    a.upgrade,
})))

const selectedAppDb = computed({
  get: () => form.value.app?.db ?? null,
  set: (db) => { form.value.app = apps.value.find(a => a.db === db) ?? null },
})

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

/* ── Dark mode toggle (top-right corner of the wrapper) ─────────────────── */
.dark-toggle {
  position: fixed;
  top: 1rem;
  right: 1rem;
  background: none;
  border: none;
  cursor: pointer;
  color: var(--p-text-muted-color);
  font-size: 1.1rem;
  padding: 0.4rem;
  border-radius: 50%;
  transition: color 0.2s, background 0.2s;
}
.dark-toggle:hover {
  color: var(--p-primary-color);
  background: var(--p-content-hover-background);
}

/* ── Footer ─────────────────────────────────────────────────────────────── */
.login-footer {
  text-align: center;
  margin-top: 1.5rem;
  font-size: 0.78rem;
  color: var(--p-text-muted-color);
  line-height: 1.7;
}
.login-footer a {
  color: var(--p-text-muted-color);
  text-decoration: none;
}
.login-footer a:hover {
  color: var(--p-primary-color);
}

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
