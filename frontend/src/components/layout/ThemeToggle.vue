<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import AppIcon from '@/components/ui/AppIcon.vue'
import { useUiStore, type ThemePreference } from '@/stores/ui'

const ui = useUiStore()
const { t } = useI18n()
const order: ThemePreference[] = ['system', 'light', 'dark']
const icon = computed(() => (ui.theme === 'dark' ? 'moon' : ui.theme === 'light' ? 'sun' : 'monitor'))
function cycle(): void {
  const next = order[(order.indexOf(ui.theme) + 1) % order.length] ?? 'system'
  ui.setTheme(next)
}
</script>

<template>
  <button type="button" class="btn btn-ghost btn-sm" :aria-label="t('theme.toggle', { current: t(`theme.${ui.theme}`) })" :title="t(`theme.${ui.theme}`)" @click="cycle">
    <AppIcon :name="icon" :size="18" />
  </button>
</template>
