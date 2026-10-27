<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import PredictilyLogo from '@/components/brand/PredictilyLogo.vue'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t } = useI18n()
</script>

<template>
  <main id="main" class="min-h-screen flex flex-col items-center justify-center gap-6 px-4">
    <PredictilyLogo :size="36" />
    <div class="card max-w-lg w-full">
      <EmptyState icon="search" :title="t('errors.notFoundTitle')" :description="t('errors.notFoundText')">
        <RouterLink to="/" class="btn btn-primary btn-sm">{{ t('errors.backHome') }}</RouterLink>
      </EmptyState>
    </div>
  </main>
</template>
