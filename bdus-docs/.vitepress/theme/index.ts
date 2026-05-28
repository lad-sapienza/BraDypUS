import DefaultTheme from 'vitepress/theme'
import ScalarApiRef from '../components/ScalarApiRef.vue'
import type { Theme } from 'vitepress'

const theme: Theme = {
  ...DefaultTheme,
  enhanceApp({ app }) {
    app.component('ScalarApiRef', ScalarApiRef)
  },
}

export default theme
