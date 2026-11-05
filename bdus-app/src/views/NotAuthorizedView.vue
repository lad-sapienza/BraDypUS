<template>
  <AppLayout>
    <div class="not-authorized">
      <AResult status="403" :title="t('not_authorized_title')" :sub-title="t('not_enough_privilege')">
        <template #extra>
          <RouterLink :to="`/${route.params.app}`">
            <AButton type="primary">{{ t('back_to_dashboard') }}</AButton>
          </RouterLink>
        </template>
      </AResult>
    </div>
  </AppLayout>
</template>

<script setup>
import { RouterLink, useRoute } from 'vue-router'
import { Result as AResult, Button as AButton } from 'ant-design-vue'
import AppLayout from '@/components/AppLayout.vue'
import { useI18n } from '@/i18n'

const route = useRoute()
const { t } = useI18n()
</script>
