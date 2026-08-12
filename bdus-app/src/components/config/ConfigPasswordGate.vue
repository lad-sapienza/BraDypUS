<template>
  <div class="gate-wrap">
    <div class="gate-card">
      <div class="gate-icon">
        <i class="pi pi-lock" />
      </div>
      <h2 class="gate-title">{{ t('sys_config') }}</h2>
      <p class="gate-sub">{{ t('confirm_password_to_continue') }}</p>

      <form class="gate-form" @submit.prevent="submit">
        <AInputPassword
          v-model:value="password"
          :placeholder="t('password')"
          :status="errorMsg ? 'error' : undefined"
          autofocus
          @keydown.enter="submit"
        />
        <small v-if="errorMsg" class="gate-error">{{ errorMsg }}</small>

        <AButton
          html-type="submit"
          type="primary"
          :loading="busy"
          block
          class="gate-btn"
        >
          <template #icon><i class="pi pi-unlock" /></template>
          {{ t('confirm') }}
        </AButton>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref }            from 'vue'
import { Input, Button as AButton } from 'ant-design-vue'
import { useConfigStore } from '@/stores/config'
import { useI18n }        from '@/i18n'

const { t }   = useI18n()
const store   = useConfigStore()
const AInputPassword = Input.Password

const password = ref('')
const busy     = ref(false)
const errorMsg = ref('')

async function submit() {
  if (!password.value) return
  busy.value     = true
  errorMsg.value = ''
  try {
    await store.unlock(password.value)
    // unlocked — parent watches store.unlocked and renders the config UI
  } catch (e) {
    errorMsg.value = t('invalid_pasword')
    password.value = ''
  } finally {
    busy.value = false
  }
}
</script>

<style scoped>
.gate-wrap {
  display: flex;
  align-items: center;
  justify-content: center;
  flex: 1;
  padding: 2rem;
}

.gate-card {
  width: 100%;
  max-width: 380px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1rem;
  padding: 2.5rem 2rem;
  background: var(--bdus-surface);
  border: 1px solid var(--p-content-border-color);
  border-radius: 12px;
  box-shadow: 0 4px 24px rgba(0,0,0,0.08);
}

.gate-icon {
  font-size: 2.5rem;
  color: var(--p-primary-color);
  line-height: 1;
}

.gate-title {
  margin: 0;
  font-size: 1.2rem;
  font-weight: 700;
}

.gate-sub {
  margin: 0;
  font-size: 0.875rem;
  color: var(--p-text-muted-color);
  text-align: center;
}

.gate-form {
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.gate-error {
  color: var(--p-red-400);
  font-size: 0.8rem;
}

.gate-btn {
  margin-top: 0.25rem;
}
</style>
