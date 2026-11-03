<script setup lang="ts">
/**
 * Una cifra económica honesta (V2.5 · W5-E): si la API devuelve `null` NO se muestra 0 ni un guion mudo, se dice «No estimado» (o la etiqueta indicada,
 * p. ej. «Costo no confiable») con los motivos que dio la API. El 0 real (un hecho medido) sí se muestra como 0.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useTenantStore } from '@/stores/tenant'
import { formatCurrency } from '@/utils/format'
import { isKnown } from '@/utils/enterprise'

const props = withDefaults(defineProps<{
  value: number | null | undefined
  currency?: string | null
  /** Códigos de motivo de la API (costo no confiable, etc.); se traducen si tienen texto. */
  reasons?: string[]
  /** Texto cuando no hay cifra. Por defecto «No estimado». */
  unknownLabel?: string | null
  decimals?: number
}>(), { currency: null, reasons: () => [], unknownLabel: null, decimals: 2 })
const { t, te } = useI18n()
const tenant = useTenantStore()
const known = computed(() => isKnown(props.value))
const reasonTexts = computed(() => [...new Set(props.reasons)].map((code) => (te(`enterprise.reasons.${code}`) ? t(`enterprise.reasons.${code}`) : code)))
</script>

<template>
  <span v-if="known" class="tabular" data-test="figure">{{ formatCurrency(value, currency ?? tenant.currency, decimals) }}</span>
  <span v-else class="text-fg-muted" data-test="not-estimated">
    {{ unknownLabel ?? t('enterprise.notEstimated') }}
    <span v-if="reasonTexts.length" class="block text-xs" data-test="not-estimated-reasons">{{ reasonTexts.join(' · ') }}</span>
  </span>
</template>
