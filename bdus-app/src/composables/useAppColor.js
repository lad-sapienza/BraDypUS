import { ref } from 'vue'
import { updatePrimaryPalette } from '@primeuix/themes'

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

const STEPS = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950]

/* SPIKE: ant-design-vue eval. AntD's ConfigProvider theme token wants a single
 * hex, not a PrimeVue-style {50..950} palette reference — there is no shared
 * "brand color" abstraction between the two systems, so we keep a second,
 * parallel hex map here just for AntD components (App.vue's ConfigProvider). */
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
  const palette = Object.fromEntries(STEPS.map(s => [s, `{${name}.${s}}`]))
  updatePrimaryPalette(palette)
  antdPrimaryColor.value = ANTD_HEX[name]
}
