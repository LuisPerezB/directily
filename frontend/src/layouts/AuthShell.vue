<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import PredictilyLogo from '@/components/brand/PredictilyLogo.vue'
import ThemeToggle from '@/components/layout/ThemeToggle.vue'

/** Marco para pantallas de autenticación y configuración inicial. */
withDefaults(defineProps<{ title: string; subtitle?: string | null; wide?: boolean }>(), { subtitle: null, wide: false })
const { t } = useI18n()
</script>

<template>
  <div class="min-h-screen flex flex-col">
    <header class="flex items-center justify-between px-4 sm:px-8 h-16">
      <RouterLink to="/" class="no-underline"><PredictilyLogo :size="30" /></RouterLink>
      <ThemeToggle />
    </header>
    <main id="main" class="flex-1 flex items-start sm:items-center justify-center px-4 py-8">
      <div class="w-full" :class="wide ? 'max-w-3xl' : 'max-w-md'">
        <div class="card p-6 sm:p-8">
          <h1 class="page-title">{{ title }}</h1>
          <p v-if="subtitle" class="text-fg-muted mt-1 mb-6">{{ subtitle }}</p>
          <div :class="subtitle ? '' : 'mt-6'"><slot /></div>
        </div>
        <div class="mt-4 text-center text-sm text-fg-muted"><slot name="below" /></div>
      </div>
    </main>
    <footer class="py-6 text-center text-xs text-fg-muted">{{ t('brand.slogan') }}</footer>
  </div>
</template>
