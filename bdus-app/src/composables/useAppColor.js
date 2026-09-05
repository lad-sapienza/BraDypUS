import { ref } from 'vue'

export const COLOR_PALETTE = [
  { name: 'indigo', label: 'Indigo' },
  { name: 'blue',   label: 'Blue'   },
  { name: 'violet', label: 'Violet' },
  { name: 'emerald',label: 'Emerald'},
  { name: 'teal',   label: 'Teal'   },
  { name: 'amber',  label: 'Amber'  },
  { name: 'rose',   label: 'Rose'   },
  { name: 'slate',  label: 'Slate'  },
]

/* AntD's ConfigProvider theme token wants a single hex; the app's own
 * --p-primary-* CSS custom properties (see assets/prime-theme.css) want
 * a [data-brand] attribute on <html>. Both are driven from here so the
 * brand-color feature stays a single call site regardless of which
 * system a given component reads from.
 *
 * Exported (not just used internally) because it is also the only place
 * that knows a real hex value per palette name — assets/prime-theme.css
 * only ever defined --p-{name}-500 for a couple of these names, so anything
 * that rendered a swatch via var(--p-{name}-500) was invisible for the rest
 * (e.g. ConfigAppForm.vue's colour picker). Read the hex straight from here
 * instead of re-deriving it from CSS custom properties. */
export const ANTD_HEX = {
  indigo:  '#6366f1',
  blue:    '#3b82f6',
  violet:  '#8b5cf6',
  emerald: '#10b981',
  teal:    '#14b8a6',
  amber:   '#f59e0b',
  rose:    '#f43f5e',
  slate:   '#64748b',
}

export const antdPrimaryColor = ref(ANTD_HEX.indigo)

const HEX_COLOR_RE = /^#[0-9a-f]{6}$/i

/**
 * Applies either a named palette entry or an arbitrary "#rrggbb" colour
 * (from ConfigAppForm.vue's custom colour picker/hex field) as the app's
 * brand colour. AntD's ConfigProvider (App.vue) just wants a single hex, so
 * a custom value plugs in exactly like a named one — only the resolution
 * step differs.
 */
export function applyColor(color) {
  if (HEX_COLOR_RE.test(color ?? '')) {
    document.documentElement.dataset.brand = 'custom'
    antdPrimaryColor.value = color
    return
  }
  const name = COLOR_PALETTE.some(c => c.name === color) ? color : 'indigo'
  document.documentElement.dataset.brand = name
  antdPrimaryColor.value = ANTD_HEX[name]
}
