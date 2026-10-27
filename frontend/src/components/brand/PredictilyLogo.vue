<script setup lang="ts">
/**
 * Logotipo de Predictily: una curva de ventas que asciende y se abre en una banda de
 * pronóstico (la incertidumbre), con un punto que marca "hoy".
 */
withDefaults(defineProps<{ size?: number; withText?: boolean; inverted?: boolean }>(), { size: 32, withText: true, inverted: false })
</script>

<template>
  <span class="inline-flex items-center gap-2" :class="inverted ? 'text-white' : 'text-fg'">
    <svg :width="size" :height="size" viewBox="0 0 40 40" role="img" aria-label="Predictily" focusable="false">
      <rect width="40" height="40" rx="11" fill="var(--p-brand)" />
      <path d="M8 28 L15 21.5 L20 24 L26.5 15 L33 17.5 L33 9.5 L26.5 9.5 Z" fill="#ffffff" fill-opacity="0.28" />
      <path d="M8 28 L15 21.5 L20 24 L26.5 14.2" fill="none" stroke="#ffffff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
      <path d="M26.5 14.2 L33 13.4" fill="none" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round" stroke-dasharray="2 2.6" />
      <circle cx="26.5" cy="14.2" r="2.8" fill="#f0a04b" stroke="#ffffff" stroke-width="1.2" />
    </svg>
    <span v-if="withText" class="font-bold tracking-tight" :style="{ fontSize: `${Math.round(size * 0.62)}px` }">Predictily</span>
  </span>
</template>
