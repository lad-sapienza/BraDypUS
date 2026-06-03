<template>
  <div class="login-wrapper">
    <div class="login-card upgrade-card">
      <h1 class="login-title">BraDypUS</h1>

      <!-- Loading -->
      <div v-if="loading" style="text-align:center;padding:1.5rem">
        <ProgressSpinner style="width:32px;height:32px" />
      </div>

      <!-- Done -->
      <template v-else-if="done">
        <div class="done-banner">
          <span class="pi pi-check-circle done-icon" />
          <div>
            <strong>{{ t('upgrade_complete') }}</strong>
            <p>{{ t('upgrade_complete_hint') }}</p>
          </div>
        </div>
        <Button
          :label="t('enter_app')"
          icon="pi pi-arrow-right"
          fluid
          @click="enterApp"
        />
      </template>

      <!-- Error loading status -->
      <template v-else-if="loadError">
        <Message severity="error">{{ loadError }}</Message>
        <Button :label="t('enter_app')" icon="pi pi-arrow-right" fluid class="mt-2" @click="enterApp" />
      </template>

      <!-- Confirm screen -->
      <template v-else>
        <div class="upgrade-banner">
          <span class="pi pi-info-circle upgrade-icon" />
          <div>
            <strong>{{ t('minor_upgrade_pending') }}</strong>
            <p class="upgrade-hint">{{ t('minor_upgrade_hint') }}</p>
          </div>
        </div>

        <Message v-if="affectsFiles" severity="warn" :closable="false" class="mb-2">
          {{ t('minor_upgrade_affects_files') }}
        </Message>

        <div class="pending-list">
          <div v-for="name in pending" :key="name" class="pending-item">
            <span class="pi pi-clock pending-clock" />
            <code>{{ name }}</code>
          </div>
        </div>

        <Message v-if="applyError" severity="error" :closable="false">{{ applyError }}</Message>

        <Button
          :label="t('minor_upgrade_apply')"
          icon="pi pi-upload"
          severity="warning"
          :loading="applying"
          fluid
          @click="applyUpgrade"
        />

        <Button
          class="mt-2"
          :label="t('minor_upgrade_skip')"
          severity="secondary"
          outlined
          fluid
          :disabled="applying"
          @click="enterApp"
        />
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/api'
import { useI18n } from '@/i18n'
import ProgressSpinner from 'primevue/progressspinner'
import Button from 'primevue/button'
import Message from 'primevue/message'

const { t } = useI18n()
const router = useRouter()
const route  = useRoute()
const auth   = useAuthStore()

const loading      = ref(true)
const done         = ref(false)
const applying     = ref(false)
const pending      = ref([])
const affectsFiles = ref(false)
const loadError    = ref(null)
const applyError   = ref(null)

onMounted(async () => {
  try {
    const res = await api.get('/api/upgrade/status')
    if (res.type === 'minor') {
      pending.value      = res.pending       ?? []
      affectsFiles.value = res.affects_files ?? false
    } else {
      // Nothing to upgrade — go straight to app.
      enterApp()
    }
  } catch (e) {
    loadError.value = e.message ?? 'generic_error'
  } finally {
    loading.value = false
  }
})

async function applyUpgrade() {
  applyError.value = null
  applying.value = true
  try {
    const res = await api.post('/api/upgrade/minor')
    if (res.status === 'success') {
      done.value = true
    } else {
      applyError.value = t(res.code ?? 'upgrade_failed')
    }
  } catch (e) {
    applyError.value = t(e.message ?? 'upgrade_failed')
  } finally {
    applying.value = false
  }
}

function enterApp() {
  const app = auth.user?.app ?? route.params.app
  router.push(`/${app}/`)
}
</script>

<style scoped>
/* Inherits .login-wrapper / .login-card from main.css */

.upgrade-card {
  max-width: 480px;
}

.upgrade-banner {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
  background: var(--p-blue-50);
  border: 1px solid var(--p-blue-200);
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
  color: var(--p-blue-500);
  flex-shrink: 0;
  margin-top: 0.1rem;
}

.pending-list {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  margin-bottom: 1.25rem;
}

.pending-item {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.82rem;
}

.pending-clock {
  color: var(--p-yellow-500);
  font-size: 0.8rem;
  flex-shrink: 0;
}

.pending-item code {
  font-family: monospace;
  background: var(--p-content-hover-background);
  padding: 0.1em 0.35em;
  border-radius: 3px;
}

.done-banner {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
  background: var(--p-green-50);
  border: 1px solid var(--p-green-200);
  border-radius: 6px;
  padding: 0.9rem 1rem;
  margin-bottom: 1.25rem;
}

.done-banner p {
  margin: 0.25rem 0 0;
  font-size: 0.82rem;
  color: var(--p-text-muted-color);
}

.done-icon {
  font-size: 1.3rem;
  color: var(--p-green-500);
  flex-shrink: 0;
  margin-top: 0.1rem;
}

.mt-2 {
  margin-top: 0.5rem;
}
</style>
