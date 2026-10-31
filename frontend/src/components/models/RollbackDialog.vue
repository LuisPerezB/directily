<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'

/**
 * Confirmación de una reversión de modelo. No ejecuta nada por sí misma: emite `confirm` solo cuando la persona
 * lo acepta de forma explícita. Muestra qué cambia y, si la API rechazó la operación, el motivo.
 */
withDefaults(defineProps<{
  open: boolean
  title: string
  message: string
  details?: { label: string; value: string }[]
  busy?: boolean
  errorText?: string | null
}>(), { details: () => [], busy: false, errorText: null })
const emit = defineEmits<{ confirm: []; cancel: [] }>()
const { t } = useI18n()
</script>

<template>
  <ConfirmDialog :open="open" :title="title" :message="message" :confirm-label="t('rollback.confirm')" :busy="busy" danger @confirm="emit('confirm')" @cancel="emit('cancel')">
    <dl v-if="details.length" class="mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm" data-test="rollback-details">
      <template v-for="d in details" :key="d.label">
        <dt class="text-fg-muted">{{ d.label }}</dt>
        <dd class="m-0 font-semibold">{{ d.value }}</dd>
      </template>
    </dl>
    <p class="hint mt-3">{{ t('rollback.noRetrain') }}</p>
    <p v-if="errorText" class="field-error mt-2" role="alert" data-test="rollback-error">{{ errorText }}</p>
  </ConfirmDialog>
</template>
