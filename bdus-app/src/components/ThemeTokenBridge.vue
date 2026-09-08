<script setup>
/**
 * Reactively mirrors AntD's own computed design tokens (theme.useToken(),
 * derived from App.vue's ConfigProvider algorithm + colorPrimary) into the
 * --p-* CSS custom properties the app's own scoped CSS was written against
 * (originally PrimeVue Aura token names, kept as the naming convention when
 * PrimeVue was removed — see assets/prime-theme.css). Must render as a
 * descendant of <AConfigProvider> to read the correctly themed token.
 *
 * Replaces a hand-extracted static snapshot of Aura's output: AntD's tokens
 * are already exactly what its own components render with, always in sync
 * with dark mode and the brand color, no per-brand blocks to maintain.
 */
import { watchEffect } from 'vue'
import { theme } from 'ant-design-vue'

const { token } = theme.useToken()

watchEffect(() => {
  const t = token.value
  const s = document.documentElement.style
  s.setProperty('--p-content-background', t.colorBgContainer)
  s.setProperty('--p-content-border-color', t.colorBorder)
  s.setProperty('--p-content-hover-background', t.colorFillTertiary)
  s.setProperty('--p-text-color', t.colorText)
  s.setProperty('--p-text-muted-color', t.colorTextTertiary)
  s.setProperty('--p-primary-color', t.colorPrimary)
  s.setProperty('--p-primary-hover-color', t.colorPrimaryHover)
  s.setProperty('--p-primary-contrast-color', t.colorTextLightSolid)
  s.setProperty('--p-primary-50', t.colorPrimaryBg)
  s.setProperty('--p-primary-700', t.colorPrimaryText)
  s.setProperty('--p-highlight-background', t.colorPrimaryBg)
  // Previously undefined under PrimeVue Aura v4 (see prime-theme.css) — now
  // have an obvious AntD equivalent, fixed as a side effect of this bridge.
  s.setProperty('--p-surface-card', t.colorBgContainer)
  s.setProperty('--p-surface-ground', t.colorBgLayout)
  s.setProperty('--p-surface-border', t.colorBorderSecondary)
  s.setProperty('--p-warning-color', t.colorWarning)
  s.setProperty('--p-card-shadow', t.boxShadowTertiary)
  s.setProperty('--p-border-radius', `${t.borderRadius}px`)
  // Neutral surface scale. Was a static light-only ramp in prime-theme.css
  // that never inverted for dark mode; AntD's own fill scale is dark-aware.
  // 0 = solid card surface; 50→300 = increasingly strong translucent tints;
  // 400 = solid mid grey; 800 = "always-contrasting" (tooltip) surface.
  s.setProperty('--p-surface-0',   t.colorBgContainer)
  s.setProperty('--p-surface-50',  t.colorFillQuaternary)
  s.setProperty('--p-surface-100', t.colorFillTertiary)
  s.setProperty('--p-surface-200', t.colorFillSecondary)
  s.setProperty('--p-surface-300', t.colorFill)
  s.setProperty('--p-surface-400', t.colorTextTertiary)  // solid mid grey (badge fills, SVG strokes)
  s.setProperty('--p-surface-800', t.colorBgSpotlight)
})
</script>

<template><!-- renderless: side-effect only --></template>
