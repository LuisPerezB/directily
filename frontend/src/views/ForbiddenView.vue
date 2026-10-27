<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t } = useI18n()
</script>

<template>
  <div class="card">
    <EmptyState icon="shield" :title="t('errors.forbiddenTitle')" :description="t('errors.forbiddenPage')">
      <RouterLink :to="{ name: 'dashboard' }" class="btn btn-primary btn-sm">{{ t('imports.goDashboard') }}</RouterLink>
    </EmptyState>
  </div>
</template>
