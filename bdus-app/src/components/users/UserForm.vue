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

    <div class="field">
      <label for="password">Password</label>
      <AInputPassword
        id="password"
        v-model:value="form.password"
        :placeholder="form.id ? 'Leave blank to keep current' : 'Required'"
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
const AInput = Input
const AInputPassword = Input.Password

const props = defineProps({
  initial: { type: Object, default: () => ({}) },
  saving: { type: Boolean, default: false }
})

const emit = defineEmits(['save', 'cancel'])

const form = ref({ ...props.initial, password: '' })

watch(() => props.initial, (val) => {
  form.value = { ...val, password: '' }
})

const privilegeOptions = computed(() =>
  (props.initial.privileges ?? []).map(p => ({
    label: p.label,
    value: p.value
  }))
)

function handleSave() {
  const data = { ...form.value }
  if (!data.password) delete data.password
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

.form-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.7rem;
  margin-top: 1.5rem;
}
</style>
