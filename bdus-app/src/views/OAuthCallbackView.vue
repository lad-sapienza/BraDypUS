<template>
  <div class="login-wrapper">
    <div class="login-card" style="text-align: center;">
      <h1 class="login-title">BraDypUS</h1>

      <ProgressSpinner v-if="!done" style="width:48px; height:48px; margin: 1.5rem 0;" />

      <Message v-if="errorMsg" severity="error" :closable="false">
        {{ errorMsg }}
      </Message>

      <div v-if="errorMsg" style="margin-top: 1rem;">
        <router-link to="/login">← Back to login</router-link>
      </div>
    </div>
  </div>
</template>

<script setup>
/**
 * OAuthCallbackView — landing page for OAuth2 redirects.
 *
 * The PHP callback endpoint redirects the browser to:
 *   /#/oauth-callback?token=JWT&app=APP      (success)
 *   /#/oauth-callback?error=CODE&app=APP     (failure)
 *
 * This view reads the query params, stores the token (on success), and
 * navigates to the home page — or shows an error with a back-to-login link.
 */
import { onMounted, ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useI18n } from '@/i18n'
import ProgressSpinner from 'primevue/progressspinner'
import Message from 'primevue/message'

const router   = useRouter()
const route    = useRoute()
const auth     = useAuthStore()
const { t }    = useI18n()

const done     = ref(false)
const errorMsg = ref(null)

const ERROR_LABELS = {
  no_account:             'No BraDypUS account is linked to this identity. Please contact an administrator.',
  invalid_state:          'Authentication session expired or invalid. Please try again.',
  invalid_request:        'The authentication request was malformed.',
  provider_not_configured:'This provider is not configured for this application.',
  oauth_error:            'An error occurred during authentication. Please try again.',
}

onMounted(() => {
  const { token, error } = route.query

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
</script>
