<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import KpiCard from '@/components/ui/KpiCard.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import EnvelopePanel from '@/components/forecast/EnvelopePanel.vue'
import { customerApi } from '@/api'
import { isApiError } from '@/api/errors'
import { useAsync } from '@/composables/useAsync'
import { useTenantStore } from '@/stores/tenant'
import { formatExpectedAmount } from '@/utils/customers'
import { formatChange, formatDate, formatNumber, formatPercent, formatProbability } from '@/utils/format'

/** Ficha del comprador: frecuencia, productos probables, riesgo de inactividad (no es abandono) y cambio de comportamiento. */
const props = defineProps<{ id: string }>()
const { t } = useI18n()
const tenant = useTenantStore()
const result = useAsync(() => customerApi.profile(Number(props.id)), { watch: [() => props.id] })
const profile = computed(() => result.data.value?.data ?? null)
const envelope = computed(() => result.data.value?.envelope ?? null)
const notFound = computed(() => isApiError(result.error.value) && result.error.value.status === 404)
const activity = computed(() => profile.value?.activity ?? null)
const horizons = computed(() => Object.keys(profile.value?.rankings ?? {}).map(Number).sort((a, b) => a - b))
const count = (v: number | null | undefined, d = 1): string => (v == null ? '—' : formatNumber(v, d))
</script>

<template>
  <div class="flex flex-col gap-6">
    <header>
      <RouterLink :to="{ name: 'customers' }" class="text-sm font-semibold">← {{ t('customers.backToList') }}</RouterLink>
      <h1 class="page-title mt-1">{{ t('customers.profile.title') }}<template v-if="profile"> · {{ profile.customer.code ?? `#${profile.customer.id}` }}</template></h1>
      <p v-if="profile" class="text-fg-muted mt-1">
        {{ profile.customer.segment ?? t('customers.profile.noSegment') }}
        <span v-if="profile.customer.is_generic" class="badge badge-warning ml-2">{{ t('customers.profile.generic') }}</span>
      </p>
    </header>

    <LoadingState v-if="result.loading.value && !profile" :lines="4" />
    <div v-else-if="notFound" class="card"><EmptyState icon="users" :title="t('customers.profile.notFoundTitle')" :description="t('customers.profile.notFoundText')" /></div>
    <div v-else-if="result.error.value" class="card"><ErrorState :error="result.error.value" @retry="result.execute" /></div>

    <template v-else-if="profile">
      <div v-if="profile.status !== 'predicted'" class="card" data-test="profile-fallback">
        <EmptyState icon="users" :title="t(`customers.profile.status.${profile.status}`)" :description="profile.fallback ?? t('customers.profile.fallbackDefault')" />
      </div>

      <template v-if="activity">
        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" :aria-label="t('customers.profile.frequencyTitle')">
          <KpiCard :label="t('customers.profile.frequency')" :value="count(activity.purchase_frequency_per_30d, 2)" :hint="t('customers.profile.frequencyHint')" icon="clock" />
          <KpiCard :label="t('customers.profile.medianInterval')" :value="activity.median_interval_days != null ? t('common.daysValue', { n: count(activity.median_interval_days, 0) }) : '—'" :hint="t('customers.profile.medianIntervalHint')" icon="refresh" />
          <KpiCard :label="t('customers.profile.sinceLast')" :value="activity.days_since_last_purchase != null ? t('common.daysValue', { n: count(activity.days_since_last_purchase, 0) }) : '—'" :hint="activity.last_purchase_date ? formatDate(activity.last_purchase_date) : null" icon="trend-down" />
          <KpiCard :label="t('customers.profile.purchaseDays')" :value="count(activity.n_purchase_days, 0)" icon="box" />
        </section>

        <section class="grid gap-4 lg:grid-cols-2">
          <article class="card p-4 sm:p-6" data-test="inactivity">
            <h2 class="section-title mb-1">{{ t('customers.profile.inactivityTitle') }}</h2>
            <p class="m-0"><span class="badge" :class="activity.inactivity.status === 'active' ? 'badge-success' : activity.inactivity.status === 'insufficient_history' ? '' : 'badge-warning'">{{ t(`customers.profile.inactivity.${activity.inactivity.status}`) }}</span></p>
            <p class="text-sm mt-2 mb-1">
              <template v-if="activity.inactivity.risk != null">{{ t('customers.profile.inactivityRisk', { pct: formatPercent(activity.inactivity.risk, 0) }) }}</template>
              <template v-else>{{ t('customers.profile.inactivityUnknown') }}</template>
            </p>
            <p v-if="activity.inactivity.interval_p75_days != null" class="text-sm text-fg-muted m-0">{{ t('customers.profile.inactivityThresholds', { p75: count(activity.inactivity.interval_p75_days, 0), p90: count(activity.inactivity.interval_p90_days, 0), n: activity.inactivity.n_intervals }) }}</p>
            <InlineNotice kind="info" class="mt-3" data-test="inactivity-definition">
              <strong>{{ t('customers.profile.notAbandonment') }}</strong> {{ activity.inactivity.definition }}
            </InlineNotice>
          </article>
          <article class="card p-4 sm:p-6" data-test="behavior-change">
            <h2 class="section-title mb-1">{{ t('customers.profile.behaviorTitle') }}</h2>
            <p class="m-0"><span class="badge" :class="activity.behavior_change.status === 'stable' ? 'badge-success' : activity.behavior_change.status === 'insufficient_history' ? '' : 'badge-info'">{{ t(`customers.profile.behavior.${activity.behavior_change.status}`) }}</span></p>
            <dl v-if="activity.behavior_change.status !== 'insufficient_history'" class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm mt-2 mb-0">
              <dt class="text-fg-muted">{{ t('customers.profile.frequencyRatio') }}</dt><dd class="m-0 font-semibold">{{ count(activity.behavior_change.frequency_ratio, 2) }}</dd>
              <dt class="text-fg-muted">p</dt><dd class="m-0 font-semibold">{{ count(activity.behavior_change.p_value, 3) }}</dd>
              <dt v-if="activity.behavior_change.spend_ratio != null" class="text-fg-muted">{{ t('customers.profile.spendRatio') }}</dt><dd v-if="activity.behavior_change.spend_ratio != null" class="m-0 font-semibold">{{ formatChange(activity.behavior_change.spend_ratio - 1) }}</dd>
            </dl>
            <p v-else class="text-sm text-fg-muted mt-2 mb-0">{{ t('customers.profile.behaviorUnknown') }}</p>
          </article>
        </section>
      </template>

      <section v-for="h in horizons" :key="h" class="card p-4 sm:p-6" :data-test="`ranking-${h}`">
        <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
          <h2 class="section-title m-0">{{ t('customers.profile.rankingTitle', { n: h }) }}</h2>
          <span v-if="profile.ranking_sources?.[String(h)]" class="badge">{{ t('customers.rankingSource', { source: t(`rankingSources.${profile.ranking_sources[String(h)]}`) }) }}</span>
        </div>
        <p v-if="!profile.rankings[String(h)]?.length" class="text-sm text-fg-muted m-0">{{ t('customers.profile.rankingEmpty') }}</p>
        <div v-else class="table-wrap">
          <table class="table">
            <caption class="sr-only">{{ t('customers.profile.rankingTitle', { n: h }) }}</caption>
            <thead><tr><th scope="col">#</th><th scope="col">{{ t('common.product') }}</th><th scope="col" class="num">{{ t('customers.probability') }}</th><th scope="col" class="num">{{ t('customers.expectedQuantity') }}</th><th scope="col" class="num">{{ t('customers.estimatedAmount') }}</th><th scope="col">{{ t('customers.profile.rankedBy') }}</th></tr></thead>
            <tbody>
              <tr v-for="r in profile.rankings[String(h)]" :key="r.rank">
                <td>{{ r.rank }}</td>
                <td><span class="font-medium block">{{ r.product.name ?? `#${r.product.id}` }}</span><span class="text-xs text-fg-muted">{{ r.product.sku }}</span></td>
                <td class="num">{{ formatProbability(r.purchase_probability) }}</td>
                <td class="num">{{ count(r.expected_quantity, 2) }}</td>
                <td class="num">{{ formatExpectedAmount(r.expected_amount, tenant.currency) }}<span class="block text-xs text-fg-muted">{{ t('customers.amountAssumption') }}</span></td>
                <td>{{ r.ranking_source ? t(`rankingSources.${r.ranking_source}`) : '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
      <p v-if="profile.ranking_source_note" class="hint">{{ profile.ranking_source_note }}</p>
      <EnvelopePanel v-if="envelope" :envelope="envelope" />
    </template>
  </div>
</template>
