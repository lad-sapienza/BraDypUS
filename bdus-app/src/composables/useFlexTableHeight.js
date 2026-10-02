import { ref, watch, onMounted, onUnmounted } from 'vue'

/** An element's height including its vertical margins (AntD's pagination has 16px above and below). */
function outerHeight(el) {
  const cs = getComputedStyle(el)
  return el.getBoundingClientRect().height + (parseFloat(cs.marginTop) || 0) + (parseFloat(cs.marginBottom) || 0)
}

/**
 * AntD's Table has no "fill the remaining flex space" option: scroll.y wants a
 * concrete pixel number. This measures the wrapper (header and pagination,
 * margins included, taken out) and keeps that number up to date as the window,
 * the toolbar above it (a filter panel opening, chips appearing) or the data change.
 *
 * The wrapper often does not exist yet when this runs (the table list loads
 * first), so the observer attaches whenever the element appears.
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
    const header      = wrap.value.querySelector('.ant-table-thead')
    const pagination  = wrap.value.querySelector('.ant-pagination')
    const headerH     = header ? header.getBoundingClientRect().height : 40
    const paginationH = pagination ? outerHeight(pagination) : 64
    scrollY.value = Math.max(200, Math.floor(total - headerH - paginationH - 2))
  }

  onMounted(() => {
    observer = new ResizeObserver(measure)
    if (wrap.value) observer.observe(wrap.value)
  })
  onUnmounted(() => observer?.disconnect())

  // The wrapper is created later (and again when the table changes): follow it.
  watch(wrap, (el, previous) => {
    if (!observer) return
    if (previous) observer.unobserve(previous)
    if (el) { observer.observe(el); measure() }
  }, { flush: 'post' })

  watch(records, measure, { flush: 'post' })

  return { wrap, scrollY }
}
