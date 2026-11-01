<script setup lang="ts">
/** Reporte de calidad extendido del core de Datos. Destaca "cero observado" frente a "ausente"; no mide precisión de pronósticos. */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import { dataCoreApi } from '@/api'
import { useAsync } from '@/composables/useAsync'
import { formatDate, formatDateTime, formatInteger, formatPercent } from '@/utils/format'
import { useTenantStore } from '@/stores/tenant'

const { t } = useI18n()
const tenant = useTenantStore()
const report = useAsync(() => dataCoreApi.extendedQuality())
const r = computed(() => report.data.value)
const tone = (status: string): string => (status === 'ok' ? 'badge-success' : status === 'warning' ? 'badge-warning' : '')
const entities = computed(() => Object.entries(r.value?.sections.temporal_coverage.entities ?? {}))
</script>

<template>
  <section class="flex flex-col gap-5" :aria-label="t('quality.extendedTitle')" data-test="quality-panel">
    <LoadingState v-if="report.loading.value && !r" :lines="4" />
    <ErrorState v-else-if="report.error.value" :error="report.error.value" compact @retry="report.execute" />
    <template v-else-if="r">
      <div class="flex flex-wrap items-center gap-2">
        <span class="badge" :class="tone(r.overall_status)">{{ t(`quality.overall.${r.overall_status}`) }}</span>
        <span class="text-sm text-fg-muted">{{ t('quality.generatedAt', { date: formatDateTime(r.generated_at, tenant.timezone) }) }}</span>
        <button type="button" class="btn btn-ghost btn-sm" @click="report.execute">{{ t('common.retry') }}</button>
      </div>
      <ul class="list-disc pl-5 m-0 text-sm text-fg-muted"><li v-for="(n, i) in r.notes" :key="i">{{ n }}</li></ul>

      <article class="rounded-xl border border-line p-4" data-test="sales-days">
        <div class="flex flex-wrap items-center gap-2 mb-1"><h3 class="section-title m-0">{{ t('quality.salesDays') }}</h3><span class="badge" :class="tone(r.sections.sales_days.status)">{{ t(`quality.status.${r.sections.sales_days.status}`) }}</span></div>
        <p class="text-sm m-0">{{ r.sections.sales_days.message }}</p>
        <dl v-if="r.sections.sales_days.range" class="grid gap-x-8 gap-y-2 sm:grid-cols-3 text-sm mt-3 mb-0">
          <div><dt class="text-fg-muted">{{ t('quality.observedZero') }}</dt><dd class="m-0 text-xl font-bold tabular" data-test="observed-zero">{{ formatInteger(r.sections.sales_days.observed_zero_days) }}</dd><dd class="m-0 hint">{{ t('quality.observedZeroHint') }}</dd></div>
          <div><dt class="text-fg-muted">{{ t('quality.absent') }}</dt><dd class="m-0 text-xl font-bold tabular" data-test="absent">{{ formatInteger(r.sections.sales_days.absent_days) }}</dd><dd class="m-0 hint">{{ t('quality.absentHint') }}</dd></div>
          <div><dt class="text-fg-muted">{{ t('quality.range') }}</dt><dd class="m-0 font-semibold">{{ formatDate(r.sections.sales_days.range.from) }} – {{ formatDate(r.sections.sales_days.range.to) }}</dd><dd class="m-0 hint">{{ t('quality.longestGaps', { n: r.sections.sales_days.longest_gaps?.[0]?.days ?? 0 }) }}</dd></div>
        </dl>
      </article>

      <article class="rounded-xl border border-line p-4" data-test="temporal-coverage">
        <div class="flex flex-wrap items-center gap-2 mb-2"><h3 class="section-title m-0">{{ t('quality.temporal') }}</h3><span class="badge" :class="tone(r.sections.temporal_coverage.status)">{{ t(`quality.status.${r.sections.temporal_coverage.status}`) }}</span></div>
        <div class="table-wrap">
          <table class="table">
            <caption class="sr-only">{{ t('quality.temporal') }}</caption>
            <thead><tr><th scope="col">{{ t('coverage.entity') }}</th><th scope="col">{{ t('quality.observed') }}</th><th scope="col" class="num">{{ t('quality.daysWithRecords') }}</th><th scope="col">{{ t('coverage.completeThrough') }}</th><th scope="col">{{ t('common.status') }}</th></tr></thead>
            <tbody>
              <tr v-for="[name, e] in entities" :key="name">
                <td class="font-medium">{{ t(`coverage.entities.${name}`) }}</td>
                <td>{{ e.observed_from ? `${formatDate(e.observed_from)} – ${formatDate(e.observed_to)}` : '—' }}</td>
                <td class="num">{{ formatInteger(e.days_with_records) }}</td>
                <td>{{ formatDate(e.coverage.complete_through) }}</td>
                <td><span class="badge" :class="['declared', 'inferred'].includes(e.status) ? 'badge-success' : e.status === 'no_data' ? '' : 'badge-warning'">{{ t(`quality.temporalStatus.${e.status}`) }}</span><span v-if="e.message" class="block text-xs text-fg-muted max-w-xs">{{ e.message }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </article>

      <div class="grid gap-4 lg:grid-cols-2">
        <article class="rounded-xl border border-line p-4" data-test="customer-identity">
          <div class="flex flex-wrap items-center gap-2 mb-1"><h3 class="section-title m-0">{{ t('quality.identity') }}</h3><span class="badge" :class="tone(r.sections.customer_identity.status)">{{ t(`quality.status.${r.sections.customer_identity.status}`) }}</span></div>
          <p class="text-sm m-0">{{ t('quality.identityShare', { pct: r.sections.customer_identity.share_without_stable_identifier != null ? formatPercent(r.sections.customer_identity.share_without_stable_identifier, 0) : '—', n: formatInteger(r.sections.customer_identity.documents_total) }) }}</p>
          <p class="hint mt-1 mb-0">{{ r.sections.customer_identity.message }}</p>
        </article>
        <article class="rounded-xl border border-line p-4" data-test="price-history">
          <div class="flex flex-wrap items-center gap-2 mb-1"><h3 class="section-title m-0">{{ t('quality.prices') }}</h3><span class="badge" :class="tone(r.sections.price_history.status)">{{ t(`quality.status.${r.sections.price_history.status}`) }}</span></div>
          <p class="text-sm m-0">{{ t('quality.priceSummary', { v: formatInteger(r.sections.price_history.products_with_price_variation), big: formatInteger(r.sections.price_history.products_with_variation_over_20pct), unknown: formatInteger(r.sections.price_history.price_history_rows_without_known_at) }) }}</p>
          <p class="hint mt-1 mb-0">{{ r.sections.price_history.message }}</p>
        </article>
        <article class="rounded-xl border border-line p-4" data-test="code-changes">
          <div class="flex flex-wrap items-center gap-2 mb-1"><h3 class="section-title m-0">{{ t('quality.codes') }}</h3><span class="badge" :class="tone(r.sections.product_code_changes.status)">{{ t(`quality.status.${r.sections.product_code_changes.status}`) }}</span></div>
          <p class="text-sm m-0">{{ r.sections.product_code_changes.message }}</p>
          <ul v-if="r.sections.product_code_changes.groups.length" class="list-disc pl-5 mt-2 mb-0 text-sm"><li v-for="(g, i) in r.sections.product_code_changes.groups.slice(0, 5)" :key="i">{{ g.name }}: {{ g.skus.join(', ') }}</li></ul>
        </article>
        <article class="rounded-xl border border-line p-4" data-test="units">
          <div class="flex flex-wrap items-center gap-2 mb-1"><h3 class="section-title m-0">{{ t('quality.units') }}</h3><span class="badge" :class="tone(r.sections.units.status)">{{ t(`quality.status.${r.sections.units.status}`) }}</span></div>
          <p class="text-sm m-0">{{ t('quality.unitsSummary', { n: formatInteger(r.sections.units.incompatible_without_conversion), c: formatInteger(r.sections.units.declared_conversions) }) }}</p>
          <p class="hint mt-1 mb-0">{{ r.sections.units.message }}</p>
          <ul v-if="r.sections.units.categories_with_mixed_units.length" class="list-disc pl-5 mt-2 mb-0 text-sm"><li v-for="(c, i) in r.sections.units.categories_with_mixed_units" :key="i">{{ c.category }}: {{ c.units.join(', ') }}</li></ul>
        </article>
      </div>
      <InlineNotice kind="info">{{ t('quality.extendedNote') }}</InlineNotice>
    </template>
  </section>
</template>
