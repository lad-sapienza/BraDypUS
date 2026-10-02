import { ref, watch, onMounted, onUnmounted } from 'vue'

/**
 * AntD's Table has no "fill the remaining flex space" option: scroll.y wants a
 * concrete pixel number. This measures the wrapper (header and pagination
 * excluded) and keeps that number up to date as the window or the data change.
 *
 * @param {import('vue').Ref<Array>} records re-measured when the rows change
 * @returns {{ wrap: import('vue').Ref<HTMLElement|null>, scrollY: import('vue').Ref<number> }}
 */
export function useFlexTableHeight(records) {
  const wrap    = ref(null)
  const scrollY = ref(400)
  let observer  = null

  function measure() {
    if (!wrap.value) return
    const total       = wrap.value.clientHeight
    const headerH     = wrap.value.querySelector('.ant-table-thead')?.getBoundingClientRect().height ?? 40
    const paginationH = wrap.value.querySelector('.ant-pagination')?.getBoundingClientRect().height ?? 32
    scrollY.value = Math.max(200, total - headerH - paginationH - 16)
  }

  onMounted(() => {
    observer = new ResizeObserver(measure)
    if (wrap.value) observer.observe(wrap.value)
  })
  onUnmounted(() => observer?.disconnect())
  watch(records, measure)

  return { wrap, scrollY }
}
