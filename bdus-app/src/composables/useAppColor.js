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
 * system a given component reads from. */
const ANTD_HEX = {
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

export function applyColor(colorName) {
  const name = COLOR_PALETTE.some(c => c.name === colorName) ? colorName : 'indigo'
  document.documentElement.dataset.brand = name
  antdPrimaryColor.value = ANTD_HEX[name]
}
