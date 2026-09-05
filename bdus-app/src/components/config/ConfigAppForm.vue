<template>
  <div class="cfg-panel">
    <div class="cfg-panel-header">
      <h2><SettingOutlined /> {{ t('app_settings') }}</h2>
      <AButton type="primary" size="small" :loading="saving" @click="save">
        <template #icon><SaveOutlined /></template>
        {{ t('save') }}
      </AButton>
    </div>

    <div v-if="loading" class="cfg-loading-center">
      <LoadingOutlined spin />
    </div>

    <AAlert v-if="error" type="error" :message="error" :closable="false" show-icon />

    <div v-if="!loading && form" class="cfg-form-body">

      <!-- ── General ─────────────────────────────────────────────── -->
      <section class="cfg-section">
        <div class="cfg-section-title">{{ t('general') }}</div>

        <div class="cfg-form-row">
          <div class="cfg-form-field">
            <label>{{ t('app_name') }} <span class="cfg-readonly-badge">readonly</span></label>
            <AInput :value="form.name" disabled size="small" />
          </div>
          <div class="cfg-form-field">
            <label>{{ t('language') }}</label>
            <ASelect v-model:value="form.lang" :options="langOptions" size="small" />
          </div>
          <div class="cfg-form-field">
            <label>{{ t('status') }}</label>
            <ASelect v-model:value="form.status" :options="statusSelectOptions" size="small" />
          </div>
        </div>

        <div class="cfg-form-field" style="grid-column:1/-1">
          <label>{{ t('definition') }}</label>
          <ATextarea v-model:value="form.definition" :rows="3" style="width:100%" />
        </div>

        <div class="cfg-form-row">
          <div class="cfg-form-field">
            <label>{{ t('max_image_size') }}</label>
            <AInput v-model:value="form.maxImageSize" size="small" placeholder="1500" />
          </div>
        </div>

      </section>

      <!-- ── Access ──────────────────────────────────────────────── -->
      <section class="cfg-section">
        <div class="cfg-section-title">{{ t('access') }}</div>
        <div class="cfg-form-row">
          <div class="cfg-form-field">
            <label>{{ t('allow_self_registration') }}</label>
            <ASwitch class="cfg-switch" v-model:checked="form.allow_self_registration" :disabled="!form.mail_configured" />
            <small class="cfg-hint">
              {{ form.mail_configured ? t('allow_self_registration_hint') : t('mail_not_configured_hint') }}
            </small>
          </div>
        </div>
      </section>

      <!-- ── OAuth2 / SSO ────────────────────────────────────────── -->
      <section class="cfg-section">
        <div class="cfg-section-title">{{ t('oauth_sso') }}</div>
        <small class="cfg-hint">{{ t('oauth_sso_hint') }}</small>

        <div v-for="p in oauthProviders" :key="p.id" class="cfg-oauth-provider">
          <div class="cfg-oauth-provider-header">
            <span>{{ p.label }}</span>
            <span v-if="isProviderConfigured(p.id)" class="cfg-readonly-badge">{{ t('enabled') }}</span>
          </div>
          <div class="cfg-form-row">
            <div class="cfg-form-field">
              <label>{{ t('client_id') }}</label>
              <AInput v-model:value="form.oauth[p.id].client_id" size="small" />
            </div>
            <div class="cfg-form-field">
              <label>{{ t('client_secret') }}</label>
              <AInputPassword v-model:value="form.oauth[p.id].client_secret" size="small" />
            </div>
            <div class="cfg-form-field">
              <label>{{ t('redirect_uri') }}</label>
              <AInput :value="redirectUri(p.id)" disabled size="small" />
              <small class="cfg-hint">{{ t('redirect_uri_hint') }}</small>
            </div>
          </div>
        </div>
      </section>

      <!-- ── Appearance ────────────────────────────────────────── -->
      <section class="cfg-section">
        <div class="cfg-section-title">{{ t('appearance') }}</div>
        <div class="cfg-form-field">
          <label>{{ t('primary_color') }}</label>
          <div class="color-swatches">
            <button
              v-for="c in colorPalette"
              :key="c.name"
              class="color-swatch"
              :class="{ active: form.color === c.name }"
              :title="c.label"
              :style="{ '--swatch-bg': ANTD_HEX[c.name] }"
              @click="selectColor(c.name)"
            />
            <input
              type="color"
              class="color-swatch color-swatch-custom"
              :class="{ active: isCustomColor(form.color) }"
              :title="t('custom_color')"
              :value="isCustomColor(form.color) ? form.color : '#ffffff'"
              @input="selectColor($event.target.value)"
            />
          </div>
          <AInput
            :value="form.color"
            class="color-hex-input"
            size="small"
            placeholder="#RRGGBB"
            maxlength="7"
            @change="e => onHexTyped(e.target.value)"
          />
        </div>
      </section>

      <!-- ── Database ────────────────────────────────────────────── -->
      <section class="cfg-section">
        <div class="cfg-section-title">{{ t('database') }}</div>
        <div class="cfg-form-row">
          <div class="cfg-form-field">
            <label>{{ t('db_engine') }}</label>
            <ASelect v-model:value="form.db_engine" :options="dbEngineOptions" size="small" />
          </div>
          <div class="cfg-form-field">
            <label>{{ t('db_host') }}</label>
            <AInput v-model:value="form.db_host" size="small" />
          </div>
          <div class="cfg-form-field">
            <label>{{ t('db_port') }}</label>
            <AInput v-model:value="form.db_port" size="small" />
          </div>
        </div>
        <div class="cfg-form-row">
          <div class="cfg-form-field">
            <label>{{ t('db_name') }}</label>
            <AInput v-model:value="form.db_name" size="small" />
          </div>
          <div class="cfg-form-field">
            <label>{{ t('db_username') }}</label>
            <AInput v-model:value="form.db_username" size="small" />
          </div>
          <div class="cfg-form-field">
            <label>{{ t('db_password') }}</label>
            <AInputPassword v-model:value="form.db_password" size="small" />
          </div>
        </div>
      </section>


    </div>
  </div>
</template>

<script setup>
import { LoadingOutlined, SaveOutlined, SettingOutlined } from '@ant-design/icons-vue'
import { ref, computed, onMounted } from 'vue'
import { Button as AButton, Input, Select as ASelect, Alert as AAlert, Switch as ASwitch } from 'ant-design-vue'
import { useToast } from '@/composables/useNotify'
import { useI18n, availableLocales } from '@/i18n'
import { api, assetUrl } from '@/api'
import { COLOR_PALETTE, ANTD_HEX, applyColor } from '@/composables/useAppColor'

const AInput         = Input
const ATextarea      = Input.TextArea
const AInputPassword = Input.Password

const { t }  = useI18n()
const toast  = useToast()

const loading       = ref(false)
const saving        = ref(false)
const error         = ref(null)
const form          = ref(null)
// Available UI languages — owned by the frontend, not fetched from the backend.
// To add a new locale: add the JSON file to src/locale/ and add an entry here.
const langs         = availableLocales.map(l => l.code)
const langOptions   = langs.map(v => ({ value: v, label: v }))
const statusOptions = ref([])
const dbEngines     = ref([])
const statusSelectOptions = computed(() => statusOptions.value.map(v => ({ value: v, label: v })))
const dbEngineOptions     = computed(() => dbEngines.value.map(v => ({ value: v, label: v })))
const colorPalette  = COLOR_PALETTE

// Only google/orcid are supported server-side (Bdus\Controllers\OAuth::SUPPORTED).
const oauthProviders = [
  { id: 'google', label: 'Google' },
  { id: 'orcid',  label: 'ORCID' },
]

function isProviderConfigured(id) {
  const p = form.value?.oauth?.[id]
  return !!(p?.client_id && p?.client_secret)
}

// Mirrors Controllers\OAuth::callbackUrl() — same host the frontend itself
// was served from/talks to the API on, so it holds for both same-origin dev
// and a separately hosted API (VITE_API_BASE).
function redirectUri(provider) {
  const path = assetUrl(`api/auth/oauth/${provider}/callback`)
  const base = path.startsWith('http') ? path : window.location.origin + path
  return `${base}?app=${form.value.name}`
}

const HEX_COLOR_RE = /^#[0-9a-f]{6}$/i

function isCustomColor(color) {
  return HEX_COLOR_RE.test(color ?? '')
}

function selectColor(name) {
  form.value.color = name
  applyColor(name)
}

// The hex text field mirrors form.color directly (so it also shows a named
// colour's own value); only apply it once it's a syntactically valid hex,
// same rule the <input type="color"> swatch already satisfies by construction.
function onHexTyped(value) {
  const v = value.trim()
  if (isCustomColor(v)) {
    selectColor(v)
  } else {
    form.value.color = v
  }
}

async function load() {
  loading.value = true
  error.value   = null
  try {
    const res = await api.get('/api/config/app')
    if (res.status === 'error') throw new Error(t(res.code))
    form.value          = { ...res.main }
    // Normalise so every provider always has both fields, even when config.json
    // has no "oauth" section at all yet (fresh app) or only lists one provider.
    const oauth = res.main.oauth ?? {}
    form.value.oauth = Object.fromEntries(oauthProviders.map(p => [
      p.id,
      { client_id: oauth[p.id]?.client_id ?? '', client_secret: oauth[p.id]?.client_secret ?? '' },
    ]))
    // PHP may return these as objects ({key:val}) if keys are non-sequential — normalise to arrays
    statusOptions.value = Array.isArray(res.status_options) ? res.status_options : Object.values(res.status_options ?? {})
    dbEngines.value     = Array.isArray(res.db_engines)    ? res.db_engines    : Object.values(res.db_engines    ?? {})
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  try {
    // mail_configured is server-derived/read-only — don't post it back.
    const { mail_configured, ...payload } = form.value
    const res = await api.put('/api/config/app', payload)
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
.cfg-hint {
  font-size: 0.72rem;
  color: var(--p-text-muted-color);
  line-height: 1.3;
}
.cfg-switch {
  /* .cfg-form-field is a column flex container — without this the switch
     stretches to the field's full cross-axis width instead of keeping its
     own ~44px track (AntD sets width:auto, so it inherits stretch). */
  align-self: flex-start;
}
.cfg-readonly-badge {
  font-size: 0.65rem;
  background: var(--p-content-hover-background);
  color: var(--p-text-muted-color);
  padding: 0.05rem 0.3rem;
  border-radius: 3px;
}
.color-swatches {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}
.color-swatch {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  border: 2px solid transparent;
  background: var(--swatch-bg);
  cursor: pointer;
  transition: transform 0.15s, border-color 0.15s;
}
.color-swatch:hover {
  transform: scale(1.15);
}
.color-swatch.active {
  border-color: var(--p-text-color);
  transform: scale(1.15);
  outline: 2px solid var(--p-content-background);
  outline-offset: -3px;
}
.color-swatch-custom {
  -webkit-appearance: none;
  appearance: none;
  padding: 0;
}
.color-swatch-custom::-webkit-color-swatch-wrapper {
  padding: 0;
  border-radius: 50%;
}
.color-swatch-custom::-webkit-color-swatch {
  border: none;
  border-radius: 50%;
}
.color-swatch-custom::-moz-color-swatch {
  border: none;
  border-radius: 50%;
}
.color-hex-input {
  max-width: 140px;
  margin-top: 0.5rem;
}
.cfg-oauth-provider {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  padding: 0.75rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: 6px;
}
.cfg-oauth-provider-header {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-weight: 600;
  font-size: 0.85rem;
}
</style>
