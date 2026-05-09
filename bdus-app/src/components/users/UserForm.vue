<template>
  <form @submit.prevent="handleSave">
    <input type="hidden" name="id" :value="form.id" />

    <div class="field">
      <label for="name">Name</label>
      <InputText id="name" v-model="form.name" required fluid />
    </div>

    <div class="field">
      <label for="email">Email</label>
      <InputText id="email" v-model="form.email" type="email" required fluid />
    </div>

    <div class="field">
      <label for="privilege">Role</label>
      <Select
        id="privilege"
        v-model="form.privilege"
        :options="privilegeOptions"
        optionLabel="label"
        optionValue="value"
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
        :placeholder="form.id ? 'Leave blank to keep current' : 'Required'"
        fluid
      />
    </div>

    <div class="form-actions">
      <Button
        type="button"
        label="Cancel"
        severity="secondary"
        outlined
        @click="$emit('cancel')"
      />
      <Button
        type="submit"
        label="Save"
        icon="pi pi-check"
        :loading="saving"
      />
    </div>
  </form>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import Select from 'primevue/select'
import Button from 'primevue/button'

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
