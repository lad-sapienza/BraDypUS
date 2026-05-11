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
            optionValue="db"
            placeholder="Select an application…"
            :loading="loadingApps"
            :disabled="loading"
            fluid
          >
            <template #option="{ option }">
              <div>
                <div class="app-name">{{ option.name }}</div>
                <div v-if="option.definition" class="app-definition">{{ option.definition }}</div>
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
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/api'
import Select from 'primevue/select'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import Button from 'primevue/button'
import Message from 'primevue/message'

const router = useRouter()
const auth = useAuthStore()

const form = ref({ app: null, email: '', password: '' })
const loading = ref(false)
const loadingApps = ref(false)
const error = ref(null)
const apps = ref([])

onMounted(async () => {
  loadingApps.value = true
  try {
    const res = await api.get('login_ctrl', 'listApps')
    apps.value = res.apps ?? []
    // Pre-select if only one app available
    if (apps.value.length === 1) {
      form.value.app = apps.value[0].db
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
    await auth.login(form.value.email, form.value.password, form.value.app)
    router.push('/')
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.login-wrapper {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--bdus-bg);
}

.login-card {
  width: 100%;
  max-width: 420px;
  padding: 2.5rem;
  background: var(--bdus-surface);
  border-radius: var(--p-border-radius-xl);
  box-shadow: var(--p-card-shadow);
}

.login-title {
  font-size: 1.8rem;
  font-weight: 700;
  margin-bottom: 2rem;
  text-align: center;
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

.app-name {
  font-weight: 600;
}

.app-definition {
  font-size: 0.8rem;
  color: var(--p-text-muted-color);
  margin-top: 0.1rem;
}
</style>
