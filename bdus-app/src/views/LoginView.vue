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

      <div v-if="canCreateApp" class="create-app-link">
        <router-link to="/new-app">{{ t('create_new_app') }}</router-link>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
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

onMounted(async () => {
  loadingApps.value = true
  try {
    const [appsRes, statusRes] = await Promise.all([
      api.get('login_ctrl', 'listApps'),
      api.get('new_app_ctrl', 'getStatus'),
    ])
    apps.value = appsRes.apps ?? []
    // Pre-select if only one app available
    if (apps.value.length === 1) {
      form.value.app = apps.value[0]
    }
    canCreateApp.value = statusRes.permitted ?? false
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
    router.push('/')
  } catch (e) {
    error.value = t(e.message)
  } finally {
    loading.value = false
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
</style>
