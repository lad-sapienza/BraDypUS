import { ref, computed } from 'vue'
import { api } from '@/api'
import { useI18n } from '@/i18n'
import { useToast } from '@/composables/useNotify'

/**
 * The search configuration of the selected table — its fields and the
 * operators the builder offers — loaded lazily and once per table.
 *
 * @param {import('vue').Ref<{name: string}|null>} table the selected table
 */
export function useSearchConfig(table) {
  const { t } = useI18n()
  const toast = useToast()

  const loading   = ref(false)
  const fields    = ref([])   // [{ value: 'table:field', label, ref_tb?, ref_field? }]
  const operators = ref([])   // raw from the backend: [{ value, key }]
  let loadedFor = null        // the table the config in memory belongs to

  /** Operators with translated labels, as the builder's dropdowns want them. */
  const operatorOptions = computed(() =>
    operators.value.map(op => ({ value: op.value, label: t(op.key) }))
  )

  async function load() {
    const tb = table.value?.name
    if (!tb || loadedFor === tb) return
    loading.value = true
    try {
      const res = await api.get(`/api/search/${tb}/config`)
      if (res.status === 'error') throw new Error(api.responseMessage(res, t))
      fields.value    = res.fields    ?? []
      operators.value = res.operators ?? []
      loadedFor = tb
    } catch (e) {
      toast.add({ severity: 'error', summary: 'Error', detail: e.message, life: 4000 })
    } finally {
      loading.value = false
    }
  }

  /** Forget what is loaded (the table changed): the next load() refetches. */
  function invalidate() { loadedFor = null }

  return { loading, fields, operatorOptions, load, invalidate }
}
