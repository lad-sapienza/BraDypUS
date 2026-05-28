<template>
  <div ref="container" />
</template>

<script setup>
import { ref, onMounted } from 'vue'

const container = ref(null)

onMounted(() => {
  const script = document.createElement('script')
  script.src = 'https://cdn.jsdelivr.net/npm/@scalar/api-reference@latest/dist/browser/standalone.min.js'
  script.crossOrigin = 'anonymous'
  script.onload = () => {
    if (window.Scalar) {
      window.Scalar.createApiReference(container.value, {
        url: '/openapi.yaml',
        theme: 'default',
      })
    }
  }
  document.head.appendChild(script)
})
</script>
