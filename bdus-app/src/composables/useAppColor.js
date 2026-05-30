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

export function applyColor(colorName) {
  const name = COLOR_PALETTE.some(c => c.name === colorName) ? colorName : 'indigo'
  const palette = Object.fromEntries(STEPS.map(s => [s, `{${name}.${s}}`]))
  updatePrimaryPalette(palette)
}
