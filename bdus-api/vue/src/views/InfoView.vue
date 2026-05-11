<template>
  <AppLayout>
    <div class="info-view">

      <div v-if="loading" class="info-loading">
        <ProgressSpinner style="width:32px;height:32px" />
      </div>

      <Message v-else-if="error" severity="error">{{ error }}</Message>

      <template v-else>
        <!-- Version badge -->
        <div class="info-header">
          <span class="info-title">BraDypUS</span>
          <Tag :value="`v${version}`" severity="secondary" class="info-version-tag" />
        </div>

        <!-- Changelog -->
        <div class="info-changelog changelog-body" v-html="changelogHtml" />
      </template>

    </div>
  </AppLayout>
</template>

<script setup>
import { ref, onMounted }  from 'vue'
import AppLayout           from '@/components/AppLayout.vue'
import ProgressSpinner     from 'primevue/progressspinner'
import Message             from 'primevue/message'
import Tag                 from 'primevue/tag'
import { api }             from '@/api'

const loading      = ref(true)
const error        = ref(null)
const version      = ref('')
const changelogHtml = ref('')

onMounted(async () => {
  try {
    const res = await api.get('info_ctrl', 'getInfo')
    if (res.status === 'error') throw new Error(res.code)
    version.value       = res.version
    changelogHtml.value = res.changelog_html
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
})
</script>

<style scoped>
.info-view {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-height: 0;
  padding: 1.5rem;
  gap: 1.25rem;
  overflow-y: auto;
  max-width: 860px;
}

.info-loading {
  display: flex;
  justify-content: center;
  padding: 2rem;
}

.info-header {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  flex-shrink: 0;
}

.info-title {
  font-size: 1.4rem;
  font-weight: 700;
  color: var(--p-primary-color);
}

.info-version-tag {
  font-size: 0.85rem;
  font-family: monospace;
}

/* ── Changelog markdown output ────────────────────────────── */
.changelog-body :deep(h1) { display: none; } /* hide the "# Changelog" title — redundant */

.changelog-body :deep(h2) {
  font-size: 1.05rem;
  font-weight: 700;
  margin: 1.5rem 0 0.4rem;
  padding-bottom: 0.25rem;
  border-bottom: 1px solid var(--p-content-border-color);
  color: var(--p-text-color);
}

.changelog-body :deep(h3) {
  font-size: 0.9rem;
  font-weight: 600;
  color: var(--p-text-muted-color);
  text-transform: uppercase;
  letter-spacing: 0.05em;
  margin: 0.9rem 0 0.25rem;
}

.changelog-body :deep(ul) {
  margin: 0 0 0.5rem 1.25rem;
  padding: 0;
}

.changelog-body :deep(li) {
  font-size: 0.875rem;
  line-height: 1.6;
  margin-bottom: 0.15rem;
}

.changelog-body :deep(p) {
  font-size: 0.875rem;
  line-height: 1.6;
  margin-bottom: 0.5rem;
}

.changelog-body :deep(code) {
  font-family: monospace;
  font-size: 0.82em;
  background: var(--p-content-hover-background);
  padding: 0.1em 0.35em;
  border-radius: 3px;
}

.changelog-body :deep(a) {
  color: var(--p-primary-color);
}
</style>
