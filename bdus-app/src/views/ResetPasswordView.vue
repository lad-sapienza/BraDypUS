<template>
  <div class="login-wrapper">
    <div class="login-card">
      <img src="@/assets/bdus.svg" alt="BraDypUS logo" />
      <h1 class="login-title">BraDypUS</h1>
      <p class="reset-subtitle">{{ t('reset_password_title') }}</p>

      <AAlert v-if="!email || !token" type="error" :message="t('invalid_or_expired_reset_token')" :closable="false" show-icon />

      <template v-else-if="done">
        <div class="reset-done">
          <CheckCircleOutlined style="color:var(--p-green-500)" />
          {{ t('ok_password_update') }}
        </div>
        <AButton type="primary" block @click="router.push('/login')">{{ t('back_to_login') }}</AButton>
      </template>

      <form v-else @submit.prevent="handleSubmit">
        <div class="field">
          <label for="new-password">{{ t('new_password') }}</label>
          <AInputPassword id="new-password" v-model:value="password" :disabled="loading" />
          <small class="field-hint">{{ t('password_too_short') }}</small>
        </div>
        <div class="field">
          <label for="new-password2">{{ t('confirm_new_password') }}</label>
          <AInputPassword id="new-password2" v-model:value="password2" :disabled="loading" />
        </div>

        <AAlert v-if="error" type="error" :message="error" :closable="false" show-icon />

        <AButton type="primary" html-type="submit" block :loading="loading" :disabled="!isPasswordValid">
          {{ t('reset_password') }}
        </AButton>
      </form>

      <div v-if="!done" class="reset-back-link">
        <router-link to="/login">{{ t('back_to_login') }}</router-link>
      </div>
    </div>

    <footer class="login-footer">
      <p><strong>BraDypUS</strong> v{{ appVersion }}</p>
    </footer>
  </div>
</template>

<script setup>
/**
 * ResetPasswordView — landing page for the link emailed by
 * Login::requestPasswordReset(). Expects ?email=...&token=... on
 * /:app/reset-password (route param `app` is only used to send the
 * confirm request to the right application, never displayed).
 */
import { CheckCircleOutlined } from '@ant-design/icons-vue'
import { ref, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '@/api'
import { useI18n } from '@/i18n'
import { Input, Button as AButton, Alert as AAlert } from 'ant-design-vue'

const AInputPassword = Input.Password

const { t }    = useI18n()
const route    = useRoute()
const router   = useRouter()
const appVersion = __APP_VERSION__

const email = computed(() => String(route.query.email ?? ''))
const token = computed(() => String(route.query.token ?? ''))

const password  = ref('')
const password2 = ref('')
const loading   = ref(false)
const error     = ref(null)
const done      = ref(false)

// Mirrors Controllers\Login::MIN_PASSWORD_LENGTH — the backend rejects
// anything shorter anyway, so gate the button on it instead of just "non-empty".
const MIN_PASSWORD_LENGTH = 8
const isPasswordValid = computed(() => password.value.length >= MIN_PASSWORD_LENGTH)

async function handleSubmit() {
  error.value = null

  if (password.value !== password2.value) {
    error.value = t('passwords_dont_match')
    return
  }

  loading.value = true
  try {
    const res = await api.post('/api/auth/password-reset/confirm', {
      app:      route.params.app,
      email:    email.value,
      token:    token.value,
      password: password.value,
    })
    if (res.status !== 'success') throw new Error(res.code)
    done.value = true
  } catch (e) {
    error.value = t(e.message ?? 'generic_error')
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
/* .login-wrapper / .login-card / .login-title / .login-footer live in main.css */

.reset-subtitle {
  text-align: center;
  font-size: 0.9rem;
  color: var(--p-text-muted-color);
  margin: -0.5rem 0 1.25rem;
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

.field-hint {
  font-size: 0.75rem;
  color: var(--p-text-muted-color);
}

.reset-done {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.9rem;
  padding: 0.5rem 0 1.25rem;
}

.reset-back-link {
  text-align: center;
  margin-top: 1.25rem;
  font-size: 0.85rem;
}
.reset-back-link a {
  color: var(--p-text-muted-color);
  text-decoration: none;
}
.reset-back-link a:hover {
  color: var(--p-primary-color);
}
</style>
