import { ref, onScopeDispose } from 'vue'

/**
 * Reactive `window.matchMedia(query).matches` — updates live on resize and
 * detaches its listener with the owning scope. Falls back to `false` where
 * matchMedia is unavailable.
 *
 * @param {string} query e.g. '(max-width: 640px)'
 */
export function useMediaQuery(query) {
  const mq      = window.matchMedia?.(query)
  const matches = ref(!!mq?.matches)
  if (mq) {
    const onChange = e => { matches.value = e.matches }
    mq.addEventListener('change', onChange)
    onScopeDispose(() => mq.removeEventListener('change', onChange))
  }
  return matches
}
