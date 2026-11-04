<script setup lang="ts">
/**
 * Costos del periodo. El costo de contacto es el de cada acción atendida: sin costo configurado es DESCONOCIDO, no cero, y el total es parcial. El uso de la plataforma
 * son cantidades medidas; solo se convierte en dinero con los precios que la empresa configure (sin ellos: «costo no estimado»).
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import MoneyFigure from '@/components/enterprise/MoneyFigure.vue'
import type { OutcomeRoiReport } from '@/api/types'
import PlatformUsageTable from './PlatformUsageTable.vue'
import { formatInteger } from '@/utils/format'

const props = defineProps<{ costs: OutcomeRoiReport['costs']; currency: string }>()
const { t } = useI18n()
const contact = computed(() => props.costs.contact)
const usage = computed(() => props.costs.platform_usage)
</script>

<template>
  <section class="flex flex-col gap-4" :aria-label="t('roi.costs.title')" data-test="costs">
    <h2 class="section-title m-0">{{ t('roi.costs.title') }}</h2>
    <div class="grid gap-4 lg:grid-cols-2">
      <article class="rounded-xl border border-line p-4 flex flex-col gap-1" data-test="contact-cost">
        <h3 class="text-sm font-semibold m-0">{{ t('roi.costs.contact') }}</h3>
        <p class="m-0 text-xl font-bold"><MoneyFigure :value="contact.actions_cost_unknown > 0 && contact.total === 0 ? null : contact.total" :currency="currency" :unknown-label="t('roi.costs.contactUnknown')" /></p>
        <p class="m-0 text-xs text-fg-muted">{{ t('roi.costs.contactActions', { n: formatInteger(contact.actions) }) }}</p>
        <p v-if="contact.actions_cost_unknown > 0" class="m-0 text-xs text-warning" data-test="contact-partial">{{ t('roi.costs.contactPartial', { n: formatInteger(contact.actions_cost_unknown) }) }}</p>
      </article>
      <article class="rounded-xl border border-line p-4 flex flex-col gap-2">
        <h3 class="text-sm font-semibold m-0">{{ t('roi.costs.usage') }}</h3>
        <PlatformUsageTable :usage="usage" :currency="currency" />
      </article>
    </div>
  </section>
</template>
