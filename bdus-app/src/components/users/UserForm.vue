<template>
  <form @submit.prevent="handleSave">
    <input type="hidden" name="id" :value="form.id" />

    <div class="field">
      <label for="name">Name</label>
      <AInput id="name" v-model:value="form.name" required />
    </div>

    <div class="field">
      <label for="email">Email</label>
      <AInput id="email" v-model:value="form.email" type="email" required />
    </div>

    <div class="field">
      <label for="privilege">Role</label>
      <ASelect
        id="privilege"
        v-model:value="form.privilege"
        :options="privilegeOptions"
      />
    </div>

    <div v-if="groupOptions" class="field">
      <label for="groups">{{ t('grp_groups') }}</label>
      <ASelect
        id="groups"
        v-model:value="form.groups"
        mode="multiple"
        :options="groupOptions"
        option-filter-prop="label"
      />
      <small v-if="Number(form.privilege) === 39" class="field-hint">{{ t('grp_enter_hint') }}</small>
    </div>

    <div class="field">
      <label for="password">Password</label>
      <AInputPassword
        id="password"
        v-model:value="form.password"
        :placeholder="form.id ? 'Leave blank to keep current' : 'Required'"
      />
    </div>

    <div v-if="needsCurrentPassword" class="field">
      <label for="current_password">{{ t('current_password') }}</label>
      <AInputPassword
        id="current_password"
        v-model:value="form.current_password"
        autocomplete="current-password"
      />
    </div>

    <div class="form-actions">
      <AButton html-type="button" @click="$emit('cancel')">Cancel</AButton>
      <AButton type="primary" html-type="submit" :loading="saving">
        <template #icon><CheckOutlined /></template>
        Save
      </AButton>
    </div>
  </form>
</template>

<script setup>
import { CheckOutlined } from '@ant-design/icons-vue'
import { ref, computed, watch } from 'vue'
import { Input, Select as ASelect, Button as AButton } from 'ant-design-vue'
import { useI18n } from '@/i18n'
import { useAuthStore } from '@/stores/auth'
const AInput = Input
const { t } = useI18n()
const auth = useAuthStore()
const AInputPassword = Input.Password

const props = defineProps({
  initial: { type: Object, default: () => ({}) },
  saving: { type: Boolean, default: false },
  groupOptions: { type: Array, default: null },   // [{ value, label }] — admins only; null hides the field
})

const emit = defineEmits(['save', 'cancel'])

const form = ref({ ...props.initial, password: '', current_password: '' })

watch(() => props.initial, (val) => {
  form.value = { ...val, password: '', current_password: '' }
})

const privilegeOptions = computed(() =>
  (props.initial.privileges ?? []).map(p => ({
    label: p.label,
    value: p.value
  }))
)

// Non-admins changing their own password must confirm the current one.
const needsCurrentPassword = computed(() =>
  !!form.value.password && !auth.user?.can_admin && Number(form.value.id) === Number(auth.user?.id)
)

function handleSave() {
  const data = { ...form.value }
  if (!data.password) delete data.password
  if (!data.password || !needsCurrentPassword.value) delete data.current_password
  emit('save', data)
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

.field-hint {
  color: var(--p-text-muted-color);
}

.form-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.7rem;
  margin-top: 1.5rem;
}
</style>
