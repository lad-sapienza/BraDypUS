<template>
  <div class="login-wrapper">
    <div class="login-card new-app-card">
      <h1 class="login-title">BraDypUS</h1>
      <p class="login-subtitle">{{ t('create_new_app') }}</p>

      <!-- ── Not permitted ──────────────────────────────────────── -->
      <Message v-if="!loading && !permitted" severity="warn" :closable="false">
        {{ t('creation_not_permitted') }}
      </Message>

      <!-- ── Success: creation log ──────────────────────────────── -->
      <template v-else-if="done">
        <Message severity="success" :closable="false">{{ t('ok_app_created') }}</Message>
        <div class="creation-log">
          <p class="log-title">{{ t('creation_log') }}</p>
          <ul>
            <li v-for="(entry, i) in log" :key="i">{{ entry }}</li>
          </ul>
        </div>
        <router-link to="/login" class="back-to-login">
          <Button :label="t('login')" icon="pi pi-sign-in" fluid />
        </router-link>
      </template>

      <!-- ── Creation form ──────────────────────────────────────── -->
      <form v-else-if="permitted" @submit.prevent="handleCreate">

        <!-- App info -->
        <div class="form-section-title">{{ t('app_name') }}</div>
        <div class="field">
          <InputText
            v-model="form.name"
            :placeholder="t('app_name')"
            :invalid="!!errors.name"
            :disabled="loading"
            fluid
          />
          <small v-if="errors.name" class="field-error">{{ errors.name }}</small>
          <small class="field-hint">{{ t('app_name_hint') }}</small>
        </div>

        <div class="field">
          <InputText
            v-model="form.definition"
            :placeholder="t('app_definition')"
            :disabled="loading"
            fluid
          />
        </div>

        <!-- Admin account -->
        <div class="form-section-title">{{ t('your_email') }}</div>
        <div class="field">
          <InputText
            v-model="form.email"
            type="email"
            :placeholder="t('your_email')"
            :invalid="!!errors.email"
            :disabled="loading"
            fluid
          />
          <small v-if="errors.email" class="field-error">{{ errors.email }}</small>
        </div>
        <div class="field">
          <Password
            v-model="form.password"
            :placeholder="t('your_password')"
            :invalid="!!errors.password"
            :disabled="loading"
            :feedback="false"
            toggle-mask
            fluid
          />
          <small v-if="errors.password" class="field-error">{{ errors.password }}</small>
        </div>

        <!-- DB engine -->
        <div class="form-section-title">{{ t('db_engine') }}</div>
        <div class="field">
          <Select
            v-model="form.db_engine"
            :options="engines"
            :placeholder="t('db_engine')"
            :invalid="!!errors.db_engine"
            :disabled="loading"
            fluid
          />
          <small v-if="errors.db_engine" class="field-error">{{ errors.db_engine }}</small>
        </div>

        <!-- MySQL / PostgreSQL extra fields -->
        <template v-if="needsDbDetails">
          <div class="field-row">
            <div class="field field-grow">
              <InputText
                v-model="form.db_host"
                :placeholder="t('db_host')"
                :disabled="loading"
                fluid
              />
            </div>
            <div class="field field-narrow">
              <InputText
                v-model="form.db_port"
                :placeholder="t('db_port')"
                :disabled="loading"
                fluid
              />
            </div>
          </div>
          <div class="field">
            <InputText
              v-model="form.db_name"
              :placeholder="t('db_name')"
              :disabled="loading"
              fluid
            />
          </div>
          <div class="field">
            <InputText
              v-model="form.db_username"
              :placeholder="t('db_username')"
              :disabled="loading"
              fluid
            />
          </div>
          <div class="field">
            <Password
              v-model="form.db_password"
              :placeholder="t('db_password')"
              :disabled="loading"
              :feedback="false"
              toggle-mask
              fluid
            />
          </div>
        </template>

        <!-- Error message -->
        <Message v-if="errorMsg" severity="error" :closable="false" class="form-error">
          {{ errorMsg }}
        </Message>

        <Button
          type="submit"
          :label="t('app_create')"
          icon="pi pi-plus"
          :loading="loading"
          fluid
        />
      </form>

      <div v-if="!done" class="back-link">
        <router-link to="/login">← {{ t('login') }}</router-link>
      </div>

    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import InputText from 'primevue/inputtext'
import Password  from 'primevue/password'
import Select    from 'primevue/select'
import Button    from 'primevue/button'
import Message   from 'primevue/message'
import { api }   from '@/api'
import { useI18n } from '@/i18n'

const { t } = useI18n()

const loading  = ref(true)
const permitted = ref(false)
const engines  = ref([])
const done     = ref(false)
const log      = ref([])
const errorMsg = ref('')

const form = reactive({
  name:        '',
  definition:  '',
  email:       '',
  password:    '',
  db_engine:   null,
  db_host:     '',
  db_port:     '',
  db_name:     '',
  db_username: '',
  db_password: '',
})

const errors = reactive({
  name: '', email: '', password: '', db_engine: '',
})

const needsDbDetails = computed(() =>
  form.db_engine && form.db_engine !== 'sqlite'
)

onMounted(async () => {
  try {
    const res = await api.get('new_app_ctrl', 'getStatus')
    permitted.value = res.permitted ?? false
    engines.value   = res.engines  ?? []
  } catch {
    permitted.value = false
  } finally {
    loading.value = false
  }
})

function validate() {
  errors.name     = ''
  errors.email    = ''
  errors.password = ''
  errors.db_engine = ''
  let ok = true

  if (!form.name || !/^[a-z][a-z0-9_]*$/.test(form.name)) {
    errors.name = t('app_name_hint')
    ok = false
  }
  if (!form.email) {
    errors.email = t('email_password_needed')
    ok = false
  }
  if (!form.password) {
    errors.password = t('email_password_needed')
    ok = false
  }
  if (!form.db_engine) {
    errors.db_engine = t('db_engine')
    ok = false
  }
  return ok
}

async function handleCreate() {
  errorMsg.value = ''
  if (!validate()) return

  loading.value = true
  try {
    const payload = {
      name:       form.name,
      definition: form.definition,
      email:      form.email,
      password:   form.password,
      db_engine:  form.db_engine,
    }
    if (needsDbDetails.value) {
      Object.assign(payload, {
        db_host:     form.db_host,
        db_port:     form.db_port,
        db_name:     form.db_name,
        db_username: form.db_username,
        db_password: form.db_password,
      })
    }

    const res = await api.post('new_app_ctrl', 'create', payload)
    if (res.status === 'error') {
      errorMsg.value = res.detail || t(res.code)
      return
    }
    log.value = res.log ?? []
    done.value = true
  } catch (e) {
    errorMsg.value = e.message
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
/* Re-use login-wrapper / login-card from LoginView */
.new-app-card {
  max-width: 480px;
}

.login-subtitle {
  text-align: center;
  color: var(--p-text-muted-color);
  margin: 0 0 1.25rem;
  font-size: 0.95rem;
}

.form-section-title {
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--p-text-muted-color);
  margin: 1rem 0 0.35rem;
}

.field {
  margin-bottom: 0.75rem;
}

.field-row {
  display: flex;
  gap: 0.5rem;
}
.field-grow  { flex: 1; }
.field-narrow { width: 90px; flex-shrink: 0; }

.field-error {
  color: var(--p-red-500);
  font-size: 0.78rem;
  display: block;
  margin-top: 0.2rem;
}

.field-hint {
  color: var(--p-text-muted-color);
  font-size: 0.78rem;
  display: block;
  margin-top: 0.2rem;
}

.form-error { margin-bottom: 0.75rem; }

/* Log */
.creation-log {
  background: var(--p-surface-100, #f5f5f5);
  border-radius: 6px;
  padding: 0.75rem 1rem;
  margin: 1rem 0;
  font-size: 0.8rem;
  max-height: 220px;
  overflow-y: auto;
}
.log-title {
  font-weight: 600;
  margin: 0 0 0.4rem;
  font-size: 0.85rem;
}
.creation-log ul {
  list-style: disc;
  padding-left: 1.2rem;
  margin: 0;
}
.creation-log li { margin-bottom: 0.15rem; }

.back-to-login { display: block; margin-top: 0.75rem; }
.back-link {
  text-align: center;
  margin-top: 1rem;
  font-size: 0.875rem;
}
.back-link a {
  color: var(--p-text-muted-color);
  text-decoration: none;
}
.back-link a:hover { color: var(--p-primary-color); }
</style>
