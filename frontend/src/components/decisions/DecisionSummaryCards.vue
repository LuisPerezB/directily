<script setup lang="ts">
/**
 * Conteos del Decision Engine por estado. NO suma importes (sumar oportunidades de distintos tipos contaría dos veces la misma venta) y no muestra cuántas
 * recomendaciones se retienen para medición. Los textos de atribución son los del servidor: «oportunidad potencial, atribución no demostrada».
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import KpiCard from '@/components/ui/KpiCard.vue'
import type { DecisionSummary } from '@/api/types'
import { formatInteger } from '@/utils/format'
import { countOf } from '@/utils/enterprise'

const props = defineProps<{ summary: DecisionSummary }>()
const { t } = useI18n()
const statuses = ['suggested', 'assigned', 'reviewed', 'approved', 'in_progress', 'executed', 'measured', 'closed', 'rejected', 'expired']
const chips = computed(() => statuses.map((s) => ({ status: s, n: countOf(props.summary.by_status, s) })).filter((x) => x.n > 0))
</script>

<template>
  <section class="flex flex-col gap-4" :aria-label="t('decisions.summary.title')" data-test="decision-summary">
    <div class="grid gap-4 sm:grid-cols-3">
      <KpiCard :label="t('decisions.summary.awaitingAssignment')" :value="formatInteger(summary.awaiting_assignment)" icon="users" />
      <KpiCard :label="t('decisions.summary.awaitingApproval')" :value="formatInteger(summary.awaiting_approval)" icon="shield" :tone="summary.awaiting_approval > 0 ? 'warning' : 'default'" />
      <KpiCard :label="t('decisions.summary.expiring')" :value="formatInteger(summary.expiring_within_24h)" icon="clock" :tone="summary.expiring_within_24h > 0 ? 'warning' : 'default'" />
    </div>
    <ul class="list-none p-0 m-0 flex flex-wrap gap-2" data-test="status-chips">
      <li v-for="c in chips" :key="c.status" class="badge">{{ t(`decisions.status.${c.status}`) }}: {{ formatInteger(c.n) }}</li>
    </ul>
    <p class="m-0 text-sm text-fg-muted" data-test="summary-attribution">{{ summary.attribution_note }}</p>
  </section>
</template>
