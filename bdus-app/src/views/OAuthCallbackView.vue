<template>
  <div class="login-wrapper">
    <div class="login-card" style="text-align: center;">
      <h1 class="login-title">BraDypUS</h1>

      <ASpin v-if="!done" :size="'large'" style="margin: 1.5rem 0;" />

      <template v-else-if="noAccount">
        <AAlert type="warning" :message="t('oauth_no_account_title')" :closable="false" show-icon style="text-align: left;" />

        <template v-if="mode === 'choice'">
          <div class="auth-mode-links" style="justify-content: center; gap: 1.5rem; margin-top: 1.25rem;">
            <a href="#" @click.prevent="mode = 'link'">{{ t('oauth_link_action') }}</a>
            <a href="#" @click.prevent="mode = 'register'" v-if="mailConfigured">{{ t('oauth_register_action') }}</a>
          </div>
        </template>

        <template v-else-if="mode === 'link'">
          <p class="auth-mode-title">{{ t('oauth_link_title') }}</p>
          <form @submit.prevent="handleLink" style="text-align: left;">
            <div class="field">
              <label for="link-email">{{ t('email') }}</label>
              <AInput id="link-email" v-model:value="linkForm.email" type="email" placeholder="you@example.com" :disabled="linkLoading" />
            </div>
            <div class="field">
              <label for="link-password">{{ t('password') }}</label>
              <AInputPassword id="link-password" v-model:value="linkForm.password" :disabled="linkLoading" />
            </div>

            <AAlert v-if="linkError" type="error" :message="linkError" :closable="false" show-icon />

            <AButton type="primary" html-type="submit" block :loading="linkLoading" :disabled="!linkForm.email || !linkForm.password">
              {{ t('login') }}
            </AButton>
          </form>
        </template>

        <template v-else-if="mode === 'register'">
          <p class="auth-mode-title">{{ t('oauth_register_prompt') }}</p>

          <div v-if="registerDone" class="auth-mode-done">
            <CheckCircleOutlined style="color:var(--p-green-500)" />
            {{ registerDoneMessage }}
          </div>
          <form v-else @submit.prevent="handleRegister" style="text-align: left;">
            <div class="field">
              <label for="register-email">{{ t('email') }}</label>
              <AInput id="register-email" v-model:value="registerForm.email" type="email" placeholder="you@example.com" :disabled="registerLoading" />
            </div>

            <AAlert v-if="registerError" type="error" :message="registerError" :closable="false" show-icon />

            <AButton type="primary" html-type="submit" block :loading="registerLoading" :disabled="!registerForm.email">
              {{ t('register') }}
            </AButton>
          </form>
        </template>

        <div class="auth-mode-links" v-if="mode !== 'choice' && !registerDone">
          <a href="#" @click.prevent="mode = 'choice'">{{ t('back') }}</a>
          <router-link to="/login">{{ t('back_to_login') }}</router-link>
        </div>
        <div v-else-if="mode === 'choice'" style="margin-top: 1rem;">
          <router-link to="/login">{{ t('back_to_login') }}</router-link>
        </div>
      </template>

      <template v-else>
        <AAlert v-if="errorMsg" type="error" :message="errorMsg" :closable="false" show-icon />

        <div v-if="errorMsg" style="margin-top: 1rem;">
          <router-link to="/login">← Back to login</router-link>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
/**
 * OAuthCallbackView — landing page for OAuth2 redirects.
 *
 * The PHP callback endpoint redirects the browser to:
 *   /oauth-callback?token=JWT&app=APP                         (success)
 *   /oauth-callback?error=CODE&app=APP                        (failure)
 *   /oauth-callback?error=no_account&app=APP&pending=TOKEN    (no matching
 *     account — `pending` is a short-lived signed token carrying the OAuth
 *     identity, redeemed by either POST /api/auth/oauth/link or
 *     POST /api/auth/oauth/register below)
 */
import { onMounted, ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useI18n } from '@/i18n'
import { api } from '@/api'
import { Spin as ASpin, Alert as AAlert, Button as AButton, Input } from 'ant-design-vue'
import { CheckCircleOutlined } from '@ant-design/icons-vue'

const AInput         = Input
const AInputPassword = Input.Password

const router   = useRouter()
const route    = useRoute()
const auth     = useAuthStore()
const { t }    = useI18n()

const done      = ref(false)
const errorMsg  = ref(null)
const noAccount = ref(false)
const mode      = ref('choice') // 'choice' | 'link' | 'register'
const mailConfigured = ref(false)

const app     = ref(null)
const pending = ref(null)

const linkForm    = ref({ email: '', password: '' })
const linkLoading = ref(false)
const linkError   = ref(null)

const registerForm        = ref({ email: '' })
const registerLoading     = ref(false)
const registerError       = ref(null)
const registerDone        = ref(false)
const registerDoneMessage = ref('')

const ERROR_LABELS = {
  invalid_state:          'Authentication session expired or invalid. Please try again.',
  invalid_request:        'The authentication request was malformed.',
  provider_not_configured:'This provider is not configured for this application.',
  oauth_error:            'An error occurred during authentication. Please try again.',
}

onMounted(async () => {
  const { token, error } = route.query

  if (error === 'no_account') {
    done.value      = true
    noAccount.value = true
    app.value       = route.query.app ?? null
    pending.value   = route.query.pending ?? null
    try {
      const res = await api.get('/api/auth/apps')
      mailConfigured.value = res.mail_configured ?? false
    } catch {
      mailConfigured.value = false
    }
    return
  }

  if (error) {
    done.value     = true
    errorMsg.value = ERROR_LABELS[error] ?? t(error) ?? 'Authentication failed.'
    return
  }

  if (token) {
    try {
      auth.loginWithToken(token)
      router.replace(`/${auth.user.app}/`)
    } catch {
      done.value     = true
      errorMsg.value = 'The authentication token is invalid or has expired.'
    }
    return
  }

  // Neither token nor error — shouldn't happen
  done.value     = true
  errorMsg.value = 'No authentication data received.'
})

async function handleLink() {
  linkError.value = null
  linkLoading.value = true
  try {
    const res = await api.post('/api/auth/oauth/link', {
      app:      app.value,
      pending:  pending.value,
      email:    linkForm.value.email,
      password: linkForm.value.password,
    })
    if (res.status !== 'success') throw new Error(res.code)
    auth.loginWithToken(res.token)
    router.replace(`/${auth.user.app}/`)
  } catch (e) {
    linkError.value = t(e.message ?? 'generic_error')
  } finally {
    linkLoading.value = false
  }
}

async function handleRegister() {
  registerError.value = null
  registerLoading.value = true
  try {
    const res = await api.post('/api/auth/oauth/register', {
      app:     app.value,
      pending: pending.value,
      email:   registerForm.value.email,
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
</script>

<style scoped>
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

.auth-mode-title {
  font-size: 0.9rem;
  color: var(--p-text-muted-color);
  margin: 1rem 0;
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
</style>
