<script setup lang="ts">
/**
 * Agenda comercial de una fecha, agrupada por cliente (un cliente no se duplica por SKU), con los estados heredados de la Agenda V2.2.
 * Las pospuestas solo reaparecen desde su fecha de reactivación. Sin envíos automáticos: el seguimiento registra lo que una persona hizo.
 */
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import OpportunityValue from './OpportunityValue.vue'
import ProvenanceLine from '@/components/enterprise/ProvenanceLine.vue'
import WorkflowBadge from '@/components/enterprise/WorkflowBadge.vue'
import { radarApi } from '@/api'
import type { RadarFilterQuery, RadarOpportunity } from '@/api/types'
import { useAbility } from '@/composables/useAbility'
import { useAsync } from '@/composables/useAsync'
import { useTenantStore } from '@/stores/tenant'
import { formatNumber, formatProbability, todayISO } from '@/utils/format'
import { AGENDA_STATES, canFollowUp } from '@/utils/radar'

const props = defineProps<{ filters: RadarFilterQuery; currency?: string | null; refreshKey?: number }>()
const emit = defineEmits<{ open: [id: number]; feedback: [id: number] }>()
const { t, te } = useI18n()
const { can } = useAbility()
const tenant = useTenantStore()

const date = ref(todayISO(tenant.timezone))
const page = ref(1)
watch([() => props.filters, date], () => (page.value = 1), { deep: true })

const agenda = useAsync(
  () => radarApi.agenda({ ...props.filters, date: date.value, page: page.value, per_page: 10 }),
  { watch: [() => props.filters, date, page, () => props.refreshKey], scope: () => tenant.currentUuid },
)
const groups = computed(() => agenda.data.value?.data ?? [])
const asOf = computed(() => agenda.data.value?.envelope?.as_of ?? null)
const uncertainty = computed(() => {
  const source = agenda.data.value?.envelope?.uncertainty_source
  return source && te(`envelope.uncertainty.${source}`) ? t(`envelope.uncertainty.${source}`) : t('envelope.uncertainty.unknown')
})

function followUp(row: RadarOpportunity): boolean {
  return canFollowUp(row, can)
}
function stateChips(states: Record<string, number>): { state: string; n: number }[] {
  return AGENDA_STATES.map((s) => ({ state: s, n: states[s] ?? 0 })).filter((x) => x.n > 0)
}
</script>

<template>
  <section class="flex flex-col gap-4" :aria-label="t('radar.tabs.agenda')" data-test="agenda">
    <div class="flex flex-wrap items-end gap-4">
      <div>
        <label class="label" for="agenda-date">{{ t('radar.agenda.date') }}</label>
        <input id="agenda-date" v-model="date" type="date" class="input" data-test="agenda-date">
      </div>
      <p class="hint m-0 max-w-xl">{{ t('radar.agenda.hint') }}</p>
    </div>

    <LoadingState v-if="agenda.loading.value && !agenda.data.value" :lines="4" />
    <ErrorState v-else-if="agenda.error.value" :error="agenda.error.value" compact @retry="agenda.execute" />
    <template v-else-if="agenda.data.value">
      <ProvenanceLine :as-of="asOf" :unit="t('radar.unitOpportunities')" :currency="currency" :uncertainty="uncertainty" />
      <InlineNotice kind="info">{{ t('radar.noSendNotice') }}</InlineNotice>
      <EmptyState v-if="!groups.length" icon="users" :title="t('radar.agenda.emptyTitle')" :description="t('radar.agenda.emptyText')" data-test="agenda-empty" />
      <ul v-else class="list-none p-0 m-0 flex flex-col gap-4">
        <li v-for="g in groups" :key="g.customer.id" class="rounded-xl border border-line p-4" data-test="agenda-group">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <RouterLink :to="{ name: 'customer-detail', params: { id: String(g.customer.id) } }" class="font-semibold">{{ g.customer.code ?? `#${g.customer.id}` }}</RouterLink>
              <p class="m-0 text-xs text-fg-muted">{{ [g.customer.zone, g.customer.segment?.name].filter(Boolean).join(' · ') || '—' }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2 text-sm">
              <span class="badge">{{ t('radar.agenda.count', { n: g.opportunities_count }) }}</span>
              <span class="badge">{{ t('radar.priority', { n: g.top_priority }) }}</span>
              <span v-for="c in stateChips(g.states)" :key="c.state" class="badge" data-test="agenda-state-chip">{{ t(`radar.state.${c.state}`) }}: {{ c.n }}</span>
            </div>
          </div>
          <p v-if="g.next_action" class="m-0 mt-2 text-sm"><span class="font-semibold">{{ t('radar.agenda.nextAction') }}:</span> {{ g.next_action }}</p>
          <ul class="list-none p-0 mt-3 mb-0 flex flex-col divide-y divide-line">
            <li v-for="o in g.opportunities" :key="o.id" class="py-3 grid gap-3 md:grid-cols-[1.4fr_1fr_1fr_auto] items-start" data-test="agenda-row">
              <div>
                <p class="m-0 text-sm font-medium">{{ o.product ? (o.product.name ?? `#${o.product.id}`) : t('radar.wholeCustomer') }}</p>
                <p class="m-0 text-xs text-fg-muted">{{ t(`decisions.kind.${o.kind}`) }} · {{ t('common.days', { n: o.horizon_days }) }}</p>
                <p v-if="o.entity_type === 'customer_product'" class="m-0 text-xs text-fg-muted">
                  <span v-if="o.probability === null">{{ t('customers.noProbability') }}</span>
                  <span v-else>{{ formatProbability(o.probability) }}</span>
                  <span v-if="o.expected_units !== null"> · {{ t('radar.expectedUnits', { n: formatNumber(o.expected_units, 1), unit: o.product?.unit ?? '' }) }}</span>
                </p>
              </div>
              <OpportunityValue :opportunity="o" :currency="currency" />
              <div class="flex flex-col gap-1 items-start">
                <WorkflowBadge domain="agenda" :value="o.state" />
                <WorkflowBadge domain="workflow" :value="o.status" />
                <span v-if="o.follow_up.snoozed_until" class="text-xs text-fg-muted">{{ t('radar.snoozedUntil', { date: o.follow_up.snoozed_until }) }}</span>
              </div>
              <div class="flex flex-col gap-1 items-start">
                <button type="button" class="btn btn-ghost btn-sm" @click="emit('open', o.id)">{{ t('radar.detail') }}</button>
                <button v-if="followUp(o)" type="button" class="btn btn-secondary btn-sm" data-test="agenda-feedback" @click="emit('feedback', o.id)">{{ t('radar.feedback.open') }}</button>
              </div>
            </li>
          </ul>
        </li>
      </ul>
      <PaginationBar v-model:page="page" :meta="agenda.data.value.meta" />
    </template>
  </section>
</template>
