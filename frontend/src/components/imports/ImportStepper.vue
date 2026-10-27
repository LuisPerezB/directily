<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import AppIcon from '@/components/ui/AppIcon.vue'
import type { WizardStep } from '@/utils/importMapping'

const props = defineProps<{ step: WizardStep }>()
const { t } = useI18n()
const STEPS = ['upload', 'mapping', 'review', 'done'] as const
const index = computed(() => {
  const map: Record<WizardStep, number> = { upload: 0, mapping: 1, validating: 2, review: 2, importing: 3, done: 3, rejected: 2 }
  return map[props.step]
})
</script>

<template>
  <ol class="flex items-center gap-2 sm:gap-4 list-none p-0 m-0 overflow-x-auto" :aria-label="t('imports.stepsLabel')">
    <li v-for="(s, i) in STEPS" :key="s" class="flex items-center gap-2 shrink-0" :aria-current="i === index ? 'step' : undefined">
      <span class="inline-flex w-7 h-7 rounded-full items-center justify-center text-sm font-bold" :class="i < index || (i === 3 && step === 'done') ? 'bg-success text-white' : i === index ? 'bg-brand text-brand-fg' : 'bg-surface-2 text-fg-muted'">
        <AppIcon v-if="i < index || (i === 3 && step === 'done')" name="check" :size="14" />
        <template v-else>{{ i + 1 }}</template>
      </span>
      <span class="text-sm font-medium" :class="i === index ? 'text-fg' : 'text-fg-muted'">{{ t(`imports.steps.${s}`) }}</span>
      <span v-if="i < STEPS.length - 1" class="hidden sm:block w-8 h-px bg-line" aria-hidden="true" />
    </li>
  </ol>
</template>
