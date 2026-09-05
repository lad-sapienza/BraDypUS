<template>
  <div class="login-wrapper">
    <button class="dark-toggle" :title="t(`${NEXT_MODE[themeMode]}_mode`)" @click="toggleDark">
      <component :is="modeIcon" />
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
          <WarningOutlined class="upgrade-icon" />
          <div>
            <strong>{{ t('major_upgrade_required') }}</strong>
            <p class="upgrade-hint">{{ t('major_upgrade_hint') }}</p>
          </div>
        </div>

        <div v-if="upgradeDone" class="upgrade-done">
          <div>
            <CheckCircleOutlined style="color:var(--p-green-500)" />
            {{ t('upgrade_complete_login') }}
          </div>

          <div v-if="verifyResult" class="upgrade-verify">
            <p class="upgrade-verify-line">
              {{ t('verify_heading') }}:
              <strong>{{ verifyResult.summary.passed }}</strong> {{ t('verify_passed') }} ·
              <strong>{{ verifyResult.summary.warnings }}</strong> {{ t('verify_warnings') }} ·
              <strong :class="{ 'verify-fail': verifyResult.summary.failed > 0 }">
                {{ verifyResult.summary.failed }}
              </strong> {{ t('verify_failed') }}
            </p>

            <ul v-if="verifyResult.failed.length" class="upgrade-verify-list verify-fail">
              <li v-for="item in verifyResult.failed" :key="item">{{ item }}</li>
            </ul>
            <ul v-else-if="verifyResult.warnings.length" class="upgrade-verify-list">
              <li v-for="item in verifyResult.warnings" :key="item">{{ item }}</li>
            </ul>

            <p class="upgrade-verify-hint">
              {{ verifyResult.summary.failed > 0 ? t('verify_hint_fail') : t('verify_hint_ok') }}
            </p>
          </div>

          <p v-if="recordDir" class="upgrade-verify-hint">
            {{ t('verify_record_saved') }} <code>{{ recordDir }}</code>
          </p>
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
            <template #icon><UploadOutlined /></template>
            {{ t('major_upgrade_apply') }}
          </AButton>
        </form>
      </template>

      <!-- ── Normal login form ─────────────────────────────────────────────── -->
      <template v-else-if="form.app && mode === 'login'">
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
            <template #icon><LoginOutlined /></template>
            Login
          </AButton>
        </form>

        <div v-if="mailConfigured" class="auth-mode-links">
          <a href="#" @click.prevent="switchMode('forgot')">{{ t('forgot_password') }}</a>
          <a href="#" @click.prevent="switchMode('register')">{{ t('create_account') }}</a>
        </div>

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
              <template #icon><component :is="resolveIcon(p.icon)" /></template>
              {{ p.label }}
            </AButton>
          </div>
        </div>
      </template>

      <!-- ── Forgot password ────────────────────────────────────────────────── -->
      <template v-else-if="form.app && mode === 'forgot'">
        <p class="auth-mode-title">{{ t('request_password_reset_title') }}</p>

        <div v-if="forgotSent" class="auth-mode-done">
          <CheckCircleOutlined style="color:var(--p-green-500)" />
          {{ t('password_reset_requested') }}
        </div>
        <form v-else @submit.prevent="handleForgotPassword">
          <div class="field">
            <label for="forgot-email">Email</label>
            <AInput
              id="forgot-email"
              v-model:value="forgotEmail"
              type="email"
              placeholder="you@example.com"
              :disabled="forgotLoading"
            />
          </div>

          <AAlert v-if="forgotError" type="error" :message="forgotError" :closable="false" show-icon />

          <AButton type="primary" html-type="submit" block :loading="forgotLoading" :disabled="!forgotEmail">
            {{ t('send_reset_link') }}
          </AButton>
        </form>

        <div class="auth-mode-links">
          <a href="#" @click.prevent="switchMode('login')">{{ t('back_to_login') }}</a>
        </div>
      </template>

      <!-- ── Self-registration ──────────────────────────────────────────────── -->
      <template v-else-if="form.app && mode === 'register'">
        <p class="auth-mode-title">{{ t('create_account') }}</p>

        <div v-if="registerDone" class="auth-mode-done">
          <CheckCircleOutlined style="color:var(--p-green-500)" />
          {{ registerDoneMessage }}
        </div>
        <form v-else @submit.prevent="handleRegister">
          <div class="field">
            <label for="register-name">{{ t('name') }}</label>
            <AInput id="register-name" v-model:value="registerForm.name" :disabled="registerLoading" />
          </div>
          <div class="field">
            <label for="register-email">Email</label>
            <AInput
              id="register-email"
              v-model:value="registerForm.email"
              type="email"
              placeholder="you@example.com"
              :disabled="registerLoading"
            />
          </div>
          <div class="field">
            <label for="register-password">{{ t('password') }}</label>
            <AInputPassword id="register-password" v-model:value="registerForm.password" :disabled="registerLoading" />
          </div>
          <div class="field">
            <label for="register-password2">{{ t('confirm_new_password') }}</label>
            <AInputPassword id="register-password2" v-model:value="registerForm.password2" :disabled="registerLoading" />
          </div>

          <AAlert v-if="registerError" type="error" :message="registerError" :closable="false" show-icon />

          <AButton
            type="primary"
            html-type="submit"
            block
            :loading="registerLoading"
            :disabled="!registerForm.name || !registerForm.email || !registerForm.password"
          >
            {{ t('register') }}
          </AButton>
        </form>

        <div class="auth-mode-links">
          <a href="#" @click.prevent="switchMode('login')">{{ t('already_have_account') }}</a>
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
import { BulbFilled, BulbOutlined, CheckCircleOutlined, DesktopOutlined, LoginOutlined, UploadOutlined, WarningOutlined } from '@ant-design/icons-vue'
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/api'
import { useI18n } from '@/i18n'
import { useDarkMode, NEXT_MODE } from '@/composables/useDarkMode'
import { resolveIcon } from '@/utils/icons'
import { Select as ASelect, Input, Button as AButton, Alert as AAlert, Tag as ATag } from 'ant-design-vue'

const AInput         = Input
const AInputPassword = Input.Password

const { t } = useI18n()
const router = useRouter()
const auth = useAuthStore()
const { isDark, mode: themeMode, toggle: toggleDark } = useDarkMode()
const modeIcon = computed(() => themeMode.value === 'system' ? DesktopOutlined : (isDark.value ? BulbFilled : BulbOutlined))
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
const verifyResult = ref(null)
const recordDir = ref(null)

// ── Forgot password / self-registration ──────────────────────────────────
// mail_configured is instance-wide (from listApps): both flows depend on it,
// so a deploy that never set RESEND_API_KEY just doesn't show either link.
const mode = ref('login') // 'login' | 'forgot' | 'register'
const mailConfigured = ref(false)

const forgotEmail   = ref('')
const forgotLoading = ref(false)
const forgotError   = ref(null)
const forgotSent    = ref(false)

const registerForm    = ref({ name: '', email: '', password: '', password2: '' })
const registerLoading = ref(false)
const registerError   = ref(null)
const registerDone    = ref(false)
const registerDoneMessage = ref('')

function switchMode(next) {
  mode.value = next
  error.value = null
  forgotError.value = null
  forgotSent.value = false
  forgotEmail.value = ''
  registerError.value = null
  registerDone.value = false
  registerForm.value = { name: '', email: '', password: '', password2: '' }
}

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
    mailConfigured.value = appsRes.mail_configured ?? false
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

// Reset upgrade + auth-mode form state on app change.
watch(() => form.value.app, () => {
  upgradeError.value = null
  upgradeDone.value = false
  upgradeForm.value = { email: '', password: '' }
  error.value = null
  switchMode('login')
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

async function handleForgotPassword() {
  forgotError.value = null
  forgotLoading.value = true
  try {
    const res = await api.post('/api/auth/password-reset/request', {
      app: form.value.app?.db,
      email: forgotEmail.value,
    })
    if (res.status !== 'success') throw new Error(res.code)
    forgotSent.value = true
  } catch (e) {
    forgotError.value = t(e.message ?? 'generic_error')
  } finally {
    forgotLoading.value = false
  }
}

async function handleRegister() {
  registerError.value = null

  if (registerForm.value.password !== registerForm.value.password2) {
    registerError.value = t('passwords_dont_match')
    return
  }

  registerLoading.value = true
  try {
    const res = await api.post('/api/auth/register', {
      app:      form.value.app?.db,
      name:     registerForm.value.name,
      email:    registerForm.value.email,
      password: registerForm.value.password,
    })
    if (res.status !== 'success') throw new Error(res.code)
    registerDoneMessage.value = api.responseMessage(res, t, registerForm.value.email)
    registerDone.value = true
  } catch (e) {
    registerError.value = t(e.message ?? 'generic_error')
  } finally {
    registerLoading.value = false
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
      verifyResult.value = res.verify ?? null
      recordDir.value = res.record_dir ?? null
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

/* ── Forgot password / self-registration ──────────────────────────── */
.auth-mode-title {
  font-size: 0.9rem;
  color: var(--p-text-muted-color);
  margin: 0 0 1rem;
}

.auth-mode-links {
  display: flex;
  justify-content: space-between;
  margin-top: 0.75rem;
  font-size: 0.85rem;
}

.auth-mode-links a {
  color: var(--p-text-muted-color);
  text-decoration: none;
}
.auth-mode-links a:hover {
  color: var(--p-primary-color);
}

.auth-mode-done {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.9rem;
  padding: 0.75rem 0;
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
  flex-direction: column;
  gap: 0.75rem;
  font-size: 0.9rem;
  padding: 0.75rem 0;
}

.upgrade-done > div:first-child {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.upgrade-verify {
  border-top: 1px solid var(--p-content-border-color, #e5e7eb);
  padding-top: 0.6rem;
  font-size: 0.82rem;
}

.upgrade-verify-line {
  margin: 0 0 0.4rem;
}

.upgrade-verify-list {
  margin: 0 0 0.4rem;
  padding-left: 1.1rem;
}

.upgrade-verify-list li {
  margin: 0.1rem 0;
}

.upgrade-verify-hint {
  margin: 0;
  color: var(--p-text-muted-color, #6b7280);
}

.verify-fail {
  color: var(--p-red-500, #ef4444);
}
</style>
