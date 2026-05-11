<!--
  ConfigFieldForm — renders a field-editor form auto-generated from fld_structure.json.

  Props:
    tb       {string}  table name (required)
    field    {object}  current field data (empty object for "add new")
    structure {object} fld_structure meta-schema returned by getFldConfig / getFldStructure

  Emits:
    saved(fieldName)  — on successful save
    cancelled         — user hit cancel
-->
<template>
  <form class="cfg-fld-form" @submit.prevent="submit">

    <div v-if="error" class="cfg-fld-error">{{ error }}</div>

    <div class="cfg-fld-grid">
      <template v-for="(meta, key) in structure" :key="key">

        <!-- ── text input ─────────────────────────────────────── -->
        <div v-if="meta.type === 'input'" class="cfg-fld-field" :class="{ required: meta.required }">
          <label>
            {{ key }}
            <span v-if="meta.required" class="cfg-req">*</span>
          </label>
          <InputText
            v-model="form[key]"
            size="small"
            :disabled="meta.readonly && !!origName"
            :placeholder="meta.pattern ?? ''"
            :class="{ 'p-invalid': errors[key] }"
            @blur="validate(key, meta)"
          />
          <small v-if="meta.help" class="cfg-fld-help">{{ meta.help }}</small>
          <small v-if="errors[key]" class="cfg-fld-err-msg">{{ errors[key] }}</small>
        </div>

        <!-- ── single select ──────────────────────────────────── -->
        <div v-else-if="meta.type === 'select'" class="cfg-fld-field" :class="{ required: meta.required }">
          <label>
            {{ key }}
            <span v-if="meta.required" class="cfg-req">*</span>
          </label>
          <Select
            v-model="form[key]"
            :options="selectOptions(meta)"
            :show-clear="!meta.required"
            size="small"
            :class="{ 'p-invalid': errors[key] }"
            @change="validate(key, meta)"
          />
          <small v-if="meta.help" class="cfg-fld-help">{{ meta.help }}</small>
          <small v-if="errors[key]" class="cfg-fld-err-msg">{{ errors[key] }}</small>
        </div>

        <!-- ── multi select ───────────────────────────────────── -->
        <div v-else-if="meta.type === 'multi_select'" class="cfg-fld-field" :class="{ required: meta.required }">
          <label>{{ key }}</label>
          <MultiSelect
            v-model="form[key]"
            :options="meta.values"
            size="small"
            display="chip"
          />
          <small v-if="meta.help" class="cfg-fld-help">{{ meta.help }}</small>
        </div>

      </template>
    </div>

    <div class="cfg-fld-actions">
      <Button type="button" :label="t('cancel')" severity="secondary" size="small" text @click="$emit('cancelled')" />
      <Button type="submit" :label="t('save')" icon="pi pi-save" size="small" :loading="saving" />
    </div>

  </form>
</template>

<script setup>
import { ref, watch } from 'vue'
import Button      from 'primevue/button'
import InputText   from 'primevue/inputtext'
import Select      from 'primevue/select'
import MultiSelect from 'primevue/multiselect'
import { useToast } from 'primevue/usetoast'
import { useI18n }  from '@/i18n'
import { api }      from '@/api'

const props = defineProps({
  tb:        { type: String,  required: true },
  field:     { type: Object,  default: () => ({}) },
  structure: { type: Object,  default: () => ({}) },
})
const emit = defineEmits(['saved', 'cancelled'])

const { t }  = useI18n()
const toast  = useToast()

// Keep the original name so we can detect edit vs add-new
const origName = ref(props.field?.name ?? '')

// Reactive copy of field data
const form   = ref({ ...props.field })
const errors = ref({})
const saving = ref(false)
const error  = ref(null)

// Sync when parent changes the field prop (e.g. selecting a different field)
watch(() => props.field, val => {
  form.value   = { ...val }
  origName.value = val?.name ?? ''
  errors.value = {}
  error.value  = null
}, { deep: true })

// ── Helpers ───────────────────────────────────────────────────────────────

function selectOptions(meta) {
  // meta.values can contain booleans — convert to strings for the dropdown
  return (meta.values ?? []).map(v => (v === true || v === false) ? String(v) : v)
}

function validate(key, meta) {
  if (meta.required && !form.value[key]) {
    errors.value[key] = `${key} is required`
  } else if (meta.pattern && form.value[key] && !new RegExp(meta.pattern).test(form.value[key])) {
    errors.value[key] = `Must match pattern: ${meta.pattern}`
  } else {
    delete errors.value[key]
  }
}

function validateAll() {
  errors.value = {}
  for (const [key, meta] of Object.entries(props.structure)) {
    validate(key, meta)
  }
  return Object.keys(errors.value).length === 0
}

// ── Submit ────────────────────────────────────────────────────────────────

async function submit() {
  if (!validateAll()) {
    error.value = t('errors_in_form')
    return
  }
  error.value = null
  saving.value = true

  try {
    // Determine endpoint: add new vs edit existing
    const isNew     = !origName.value
    const endpoint  = isNew ? 'add_new_fld' : 'save_fld_properties'
    const payload   = {
      ...form.value,
      tb_name:       props.tb,
      fld_orig_name: origName.value,
    }

    const res = await api.post('config_ctrl', endpoint, payload)
    toast.add({
      severity: res.status === 'success' ? 'success' : 'error',
      summary:  t('saved'),
      detail:   api.responseMessage(res, t),
      life: 4000
    })

    if (res.status === 'success') {
      emit('saved', form.value.name)
    }
  } catch (e) {
    toast.add({ severity: 'error', summary: e.message, life: 4000 })
  } finally {
    saving.value = false
  }
}
</script>

<style scoped>
.cfg-fld-form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.cfg-fld-error {
  padding: 0.5rem 0.75rem;
  background: color-mix(in srgb, var(--p-red-400) 12%, transparent);
  color: var(--p-red-500);
  border-radius: 6px;
  font-size: 0.85rem;
}

.cfg-fld-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 0.75rem;
}

.cfg-fld-field {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.cfg-fld-field label {
  font-size: 0.78rem;
  font-weight: 600;
  color: var(--p-text-muted-color);
  display: flex;
  align-items: center;
  gap: 0.25rem;
}

.cfg-req { color: var(--p-red-400); font-size: 0.7rem; }

.cfg-fld-help {
  font-size: 0.72rem;
  color: var(--p-text-muted-color);
  line-height: 1.3;
}

.cfg-fld-err-msg {
  font-size: 0.72rem;
  color: var(--p-red-500);
}

.cfg-fld-actions {
  display: flex;
  gap: 0.5rem;
  justify-content: flex-end;
  padding-top: 0.5rem;
  border-top: 1px solid var(--p-content-border-color);
}
</style>
