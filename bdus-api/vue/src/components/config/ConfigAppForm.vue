<template>
  <div class="cfg-panel">
    <div class="cfg-panel-header">
      <h2><i class="pi pi-cog" /> {{ t('app_settings') }}</h2>
      <Button :label="t('save')" icon="pi pi-save" size="small" :loading="saving" @click="save" />
    </div>

    <div v-if="loading" class="cfg-loading-center">
      <i class="pi pi-spin pi-spinner" />
    </div>

    <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>

    <div v-if="!loading && form" class="cfg-form-body">

      <!-- ── General ─────────────────────────────────────────────── -->
      <section class="cfg-section">
        <div class="cfg-section-title">{{ t('general') }}</div>

        <div class="cfg-form-row">
          <div class="cfg-form-field">
            <label>{{ t('app_name') }} <span class="cfg-readonly-badge">readonly</span></label>
            <InputText :value="form.name" disabled size="small" />
          </div>
          <div class="cfg-form-field">
            <label>{{ t('language') }}</label>
            <Select v-model="form.lang" :options="langs" size="small" />
          </div>
          <div class="cfg-form-field">
            <label>{{ t('status') }}</label>
            <Select v-model="form.status" :options="statusOptions" size="small" />
          </div>
        </div>

        <div class="cfg-form-field" style="grid-column:1/-1">
          <label>{{ t('definition') }}</label>
          <Textarea v-model="form.definition" rows="3" style="width:100%" />
        </div>

        <div class="cfg-form-row">
          <div class="cfg-form-field">
            <label>{{ t('virtual_keyboard') }}</label>
            <Select
              v-model="form.virtual_keyboard"
              :options="[{ label: t('disabled'), value: '' }, { label: t('enabled'), value: '1' }]"
              option-label="label"
              option-value="value"
              size="small"
            />
          </div>
          <div class="cfg-form-field">
            <label>{{ t('max_image_size') }}</label>
            <InputText v-model="form.maxImageSize" size="small" placeholder="1500" />
          </div>
        </div>
      </section>

      <!-- ── Login options ───────────────────────────────────────── -->
      <section class="cfg-section">
        <div class="cfg-section-title">{{ t('login_options') }}</div>
        <div class="cfg-form-row">
          <div class="cfg-form-field">
            <label>{{ t('api_login_as_user') }}</label>
            <Select
              v-model="form.api_login_as_user"
              :options="userOptions"
              option-label="label"
              option-value="value"
              size="small"
            />
          </div>
        </div>
      </section>

      <!-- ── Database ────────────────────────────────────────────── -->
      <section class="cfg-section">
        <div class="cfg-section-title">{{ t('database') }}</div>
        <div class="cfg-form-row">
          <div class="cfg-form-field">
            <label>{{ t('db_engine') }}</label>
            <Select v-model="form.db_engine" :options="dbEngines" size="small" />
          </div>
          <div class="cfg-form-field">
            <label>{{ t('db_host') }}</label>
            <InputText v-model="form.db_host" size="small" />
          </div>
          <div class="cfg-form-field">
            <label>{{ t('db_port') }}</label>
            <InputText v-model="form.db_port" size="small" />
          </div>
        </div>
        <div class="cfg-form-row">
          <div class="cfg-form-field">
            <label>{{ t('db_name') }}</label>
            <InputText v-model="form.db_name" size="small" />
          </div>
          <div class="cfg-form-field">
            <label>{{ t('db_username') }}</label>
            <InputText v-model="form.db_username" size="small" />
          </div>
          <div class="cfg-form-field">
            <label>{{ t('db_password') }}</label>
            <Password v-model="form.db_password" :feedback="false" toggleMask size="small" />
          </div>
        </div>
      </section>

      <!-- ── External services ───────────────────────────────────── -->
      <section class="cfg-section">
        <div class="cfg-section-title">{{ t('external_services') }}</div>
        <div class="cfg-form-row">
          <div class="cfg-form-field">
            <label>{{ t('google_maps_key') }}</label>
            <InputText v-model="form.gmapskey" size="small" />
          </div>
          <div class="cfg-form-field">
            <label>{{ t('google_analytics_id') }}</label>
            <InputText v-model="form.googleanaytics" size="small" />
          </div>
        </div>
      </section>

    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import Button    from 'primevue/button'
import InputText from 'primevue/inputtext'
import Textarea  from 'primevue/textarea'
import Select    from 'primevue/select'
import Password  from 'primevue/password'
import Message   from 'primevue/message'
import { useToast } from 'primevue/usetoast'
import { useI18n }  from '@/i18n'
import { api }      from '@/api'

const { t }  = useI18n()
const toast  = useToast()

const loading       = ref(false)
const saving        = ref(false)
const error         = ref(null)
const form          = ref(null)
const langs         = ref([])
const statusOptions = ref([])
const dbEngines     = ref([])
const users         = ref([])

const userOptions = computed(() => [
  { label: t('disabled'), value: '' },
  ...users.value.map(u => ({ label: `${u.name} (${u.verbose_privilege})`, value: String(u.id) }))
])

async function load() {
  loading.value = true
  error.value   = null
  try {
    const res = await api.get('config_ctrl', 'getAppProperties')
    if (res.status === 'error') throw new Error(t(res.code))
    form.value          = { ...res.main }
    // PHP may return these as objects ({key:val}) if keys are non-sequential — normalise to arrays
    langs.value         = Array.isArray(res.langs)         ? res.langs         : Object.values(res.langs         ?? {})
    statusOptions.value = Array.isArray(res.status_options) ? res.status_options : Object.values(res.status_options ?? {})
    dbEngines.value     = Array.isArray(res.db_engines)    ? res.db_engines    : Object.values(res.db_engines    ?? {})
    users.value         = res.users         ?? []
    // normalise virtual_keyboard to string for Select
    if (form.value.virtual_keyboard === false || form.value.virtual_keyboard == '0') {
      form.value.virtual_keyboard = ''
    }
    // normalise user-ids to strings
    form.value.api_login_as_user = form.value.api_login_as_user ? String(form.value.api_login_as_user) : ''
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  try {
    const res = await api.post('config_ctrl', 'save_app_properties', form.value)
    toast.add({
      severity: res.status === 'success' ? 'success' : 'error',
      summary:  t('saved'),
      detail:   api.responseMessage(res, t),
      life: 4000
    })
  } catch (e) {
    toast.add({ severity: 'error', summary: e.message, life: 4000 })
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<style scoped>
.cfg-panel {
  flex: 1;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.cfg-panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 1rem 1.25rem 0.75rem;
  border-bottom: 1px solid var(--p-content-border-color);
  flex-shrink: 0;
}
.cfg-panel-header h2 {
  margin: 0;
  font-size: 1rem;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
.cfg-loading-center {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 2rem;
  color: var(--p-text-muted-color);
}
.cfg-form-body {
  flex: 1;
  overflow-y: auto;
  padding: 1rem 1.25rem;
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}
.cfg-section {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}
.cfg-section-title {
  font-weight: 700;
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.07em;
  color: var(--p-text-muted-color);
  border-bottom: 1px solid var(--p-content-border-color);
  padding-bottom: 0.35rem;
}
.cfg-form-row {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 0.75rem;
}
.cfg-form-field {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
}
.cfg-form-field label {
  font-size: 0.8rem;
  font-weight: 500;
  color: var(--p-text-muted-color);
  display: flex;
  align-items: center;
  gap: 0.4rem;
}
.cfg-readonly-badge {
  font-size: 0.65rem;
  background: var(--p-content-hover-background);
  color: var(--p-text-muted-color);
  padding: 0.05rem 0.3rem;
  border-radius: 3px;
}
</style>
