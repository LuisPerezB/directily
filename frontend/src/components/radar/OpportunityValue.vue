<script setup lang="ts">
/**
 * Valor de una oportunidad del Radar, sin mezclar naturalezas (V2.5 · W5-E):
 *  - cliente × producto: un PRONÓSTICO (unidades esperadas × último precio neto, supuesto) y una contribución que es «no estimada» si el costo no es confiable;
 *  - a nivel de cliente: una caída OBSERVADA del gasto neto, que no es pronóstico ni contribución.
 * Siempre rotulada «oportunidad potencial, atribución no demostrada» mientras la API no registre evidencia de incremento.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import MoneyFigure from '@/components/enterprise/MoneyFigure.vue'
import WorkflowBadge from '@/components/enterprise/WorkflowBadge.vue'
import type { RadarOpportunity } from '@/api/types'
import { contributionView, valueBasis } from '@/utils/radar'

const props = defineProps<{ opportunity: RadarOpportunity; currency?: string | null }>()
const { t } = useI18n()
const basis = computed(() => valueBasis(props.opportunity))
const contribution = computed(() => contributionView(props.opportunity.value))
const contributionLabel = computed(() => (contribution.value.status === 'unreliable' ? t('enterprise.unreliableCost') : t('enterprise.notEstimatedF')))
</script>

<template>
  <div class="flex flex-col gap-1" data-test="opportunity-value" :data-basis="basis">
    <template v-if="basis === 'forecast'">
      <div>
        <span class="block text-xs text-fg-muted">{{ t('radar.value.forecastGross') }}</span>
        <MoneyFigure :value="opportunity.value.expected_gross_value" :currency="currency" />
      </div>
      <div>
        <span class="block text-xs text-fg-muted">{{ t('radar.value.contribution') }}</span>
        <MoneyFigure :value="contribution.amount" :currency="currency" :reasons="contribution.reasons" :unknown-label="contributionLabel" />
      </div>
    </template>
    <template v-else>
      <div>
        <span class="block text-xs text-fg-muted">{{ t('radar.value.observedDecline') }}</span>
        <MoneyFigure :value="opportunity.value.expected_gross_value" :currency="currency" />
        <span class="block text-xs text-fg-muted" data-test="observed-note">{{ t('radar.value.observedNote') }}</span>
      </div>
    </template>
    <WorkflowBadge domain="attribution" :value="opportunity.value.attribution?.label ?? 'potential_unproven'" />
  </div>
</template>
