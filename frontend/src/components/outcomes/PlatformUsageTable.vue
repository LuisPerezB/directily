<script setup lang="ts">
/**
 * Uso de la plataforma registrado (`tenant_usage_records`): CANTIDADES medidas. Solo se convierte en dinero con los precios que la empresa configure;
 * sin precio el costo es «no estimado» (nunca cero) y el total no se inventa.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import MoneyFigure from '@/components/enterprise/MoneyFigure.vue'
import type { RoiPlatformUsage } from '@/api/types'
import { formatNumber } from '@/utils/format'

const props = defineProps<{ usage: RoiPlatformUsage; currency: string }>()
const { t, te } = useI18n()
const reason = computed(() => (props.usage.reason && te(`roi.usageReasons.${props.usage.reason}`) ? t(`roi.usageReasons.${props.usage.reason}`) : props.usage.reason))
</script>

<template>
  <div class="flex flex-col gap-2" data-test="platform-usage">
    <p class="m-0 text-xl font-bold"><MoneyFigure :value="usage.total_cost" :currency="currency" :unknown-label="t('roi.costs.usageUnknown')" /></p>
    <p v-if="reason" class="m-0 text-xs text-fg-muted" data-test="usage-reason">{{ reason }}</p>
    <div v-if="usage.metrics.length" class="table-wrap" tabindex="0" role="region" :aria-label="t('roi.costs.usage')">
      <table class="table">
        <caption class="sr-only">{{ t('roi.costs.usage') }}</caption>
        <thead><tr><th scope="col">{{ t('roi.costs.metric') }}</th><th scope="col" class="num">{{ t('roi.costs.quantity') }}</th><th scope="col" class="num">{{ t('roi.costs.unitPrice') }}</th><th scope="col" class="num">{{ t('roi.costs.cost') }}</th></tr></thead>
        <tbody>
          <tr v-for="m in usage.metrics" :key="m.metric">
            <td>{{ m.metric }} <span class="text-xs text-fg-muted">({{ m.unit }})</span></td>
            <td class="num tabular">{{ formatNumber(m.quantity, 2) }}</td>
            <td class="num"><MoneyFigure :value="m.unit_price" :currency="currency" :unknown-label="t('roi.costs.noPrice')" :decimals="4" /></td>
            <td class="num"><MoneyFigure :value="m.cost" :currency="currency" /></td>
          </tr>
        </tbody>
      </table>
    </div>
    <p class="m-0 text-xs text-fg-muted">{{ usage.note }}</p>
  </div>
</template>
