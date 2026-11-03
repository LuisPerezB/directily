<script setup lang="ts">
/**
 * Análisis por intención de tratamiento (ITT) de cada experimento y horizonte. Solo se monta para quien tiene `decisions.approve` (los tamaños por grupo
 * delatan la fracción del experimento). Sin estimación (`status` distinto de `estimated`) no hay diferencia ni intervalo: solo el motivo. La conversión puede
 * estar estimada sin que la contribución monetaria lo esté (pocos compradores por grupo, varianza nula): entonces no hay cifra en dinero. Si los compradores están
 * muy concentrados (`tail_diagnostic.heavy_tailed`) el intervalo monetario se rotula «indicativo». Un desajuste del cociente de muestras (SRM) se avisa siempre.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import MoneyFigure from '@/components/enterprise/MoneyFigure.vue'
import type { OutcomeExperiment } from '@/api/types'
import { formatInteger, formatNumber, formatPercent } from '@/utils/format'
import { isKnown } from '@/utils/enterprise'
import { experimentEstimated } from '@/utils/outcomes'

const props = defineProps<{ experiments: OutcomeExperiment[]; currency: string }>()
const { t, te } = useI18n()

function title(e: OutcomeExperiment): string {
  return `${e.experiment_key ?? t('roi.itt.unattributed')} · ${t('common.days', { n: e.horizon_days })}`
}
function heavyTailed(e: OutcomeExperiment): boolean {
  return e.contribution?.tail_diagnostic?.heavy_tailed === true
}
function srmMismatch(e: OutcomeExperiment): boolean {
  return e.sample_ratio?.status === 'mismatch'
}
// La contribución no se estimó aunque la conversión sí: el motivo (compradores por grupo, varianza nula, presupuesto del bootstrap…) se dice con su texto.
function contributionReason(e: OutcomeExperiment): string {
  const c = e.contribution
  const reason = c?.reason ?? null
  if (reason === 'buyers_below_min_per_arm' && isKnown(e.min_buyers_per_arm)) return t('roi.itt.noContributionMin', { n: e.min_buyers_per_arm })
  if (reason && te(`roi.reasons.${reason}`)) return t('roi.itt.noContributionReason', { reason: t(`roi.reasons.${reason}`).toLowerCase() })
  return t('roi.itt.noContribution')
}
function dropped(e: OutcomeExperiment): { key: string; treatment: number; control: number }[] {
  return Object.entries(e.dropped_units ?? {}).filter(([, v]) => v.treatment > 0 || v.control > 0).map(([key, v]) => ({ key, treatment: v.treatment, control: v.control }))
}
const items = computed(() => props.experiments)
</script>

<template>
  <section class="flex flex-col gap-3" :aria-label="t('roi.itt.title')" data-test="experiments">
    <h2 class="section-title m-0">{{ t('roi.itt.title') }}</h2>
    <p class="m-0 text-sm text-fg-muted">{{ t('roi.itt.note') }}</p>
    <p v-if="!items.length" class="m-0 text-sm" data-test="no-experiments">{{ t('roi.itt.none') }}</p>
    <article v-for="e in items" :key="`${e.experiment_key ?? '-'}|${e.horizon_days}`" class="rounded-xl border border-line p-4 flex flex-col gap-2" data-test="experiment" :data-status="e.status">
      <header class="flex flex-wrap items-center gap-2">
        <h3 class="text-sm font-semibold m-0">{{ title(e) }}</h3>
        <span class="badge" :class="experimentEstimated(e) ? 'badge-info' : 'badge-warning'">{{ t(`roi.itt.status.${e.status}`) }}</span>
      </header>
      <p v-if="e.sample" class="m-0 text-sm text-fg-muted">{{ t('roi.itt.sample', { treatment: formatInteger(e.sample.treatment), control: formatInteger(e.sample.control), min: e.min_group_size }) }}</p>
      <p v-if="srmMismatch(e)" class="m-0 text-sm text-warning" data-test="itt-srm">{{ t('roi.itt.srmMismatch', { p: formatNumber(e.sample_ratio?.p_value ?? null, 4) }) }}</p>
      <template v-if="experimentEstimated(e) && e.conversion">
        <p class="m-0 text-sm" data-test="itt-conversion">
          {{ t('roi.itt.conversion', { treatment: formatPercent(e.conversion.rate_treatment, 1), control: formatPercent(e.conversion.rate_control, 1), diff: formatPercent(e.conversion.difference, 1), low: formatPercent(e.conversion.ci_low, 1), high: formatPercent(e.conversion.ci_high, 1) }) }}
        </p>
        <p v-if="e.contribution?.buyers" class="m-0 text-xs text-fg-muted" data-test="itt-buyers">{{ t('roi.itt.buyers', { treatment: formatInteger(e.contribution.buyers.treatment), control: formatInteger(e.contribution.buyers.control) }) }}</p>
        <template v-if="e.incremental">
          <p class="m-0 text-sm" data-test="itt-incremental">
            {{ t('roi.itt.incremental') }}: <MoneyFigure :value="e.incremental.contribution" :currency="currency" />
            <span class="text-xs text-fg-muted"> ({{ formatNumber(e.incremental.ci_low, 2) }} a {{ formatNumber(e.incremental.ci_high, 2) }})</span>
          </p>
          <p class="m-0 text-xs text-fg-muted" data-test="itt-interval-method">{{ t('roi.itt.intervalMethod', { resamples: formatInteger(e.contribution?.interval?.resamples ?? 0) }) }}</p>
          <p v-if="heavyTailed(e)" class="m-0 text-xs text-warning" data-test="itt-heavy-tail">{{ t('roi.heavyTail') }}</p>
        </template>
        <p v-else class="m-0 text-sm text-fg-muted" data-test="itt-no-contribution">{{ contributionReason(e) }}</p>
      </template>
      <p v-else-if="e.status !== 'not_analysed'" class="m-0 text-sm" data-test="itt-no-estimate">{{ t('roi.itt.noEstimate') }}</p>
      <p v-if="(e.contamination?.control_units_with_registered_action ?? 0) > 0" class="m-0 text-xs text-warning">{{ t('roi.itt.contamination', { n: e.contamination?.control_units_with_registered_action ?? 0 }) }}</p>
      <ul v-if="dropped(e).length" class="list-disc pl-5 m-0 text-xs text-fg-muted" data-test="itt-dropped">
        <li v-for="d in dropped(e)" :key="d.key">{{ te(`roi.itt.dropped.${d.key}`) ? t(`roi.itt.dropped.${d.key}`, { treatment: formatInteger(d.treatment), control: formatInteger(d.control) }) : d.key }}</li>
      </ul>
      <p v-if="e.note" class="m-0 text-xs text-fg-muted">{{ e.note }}</p>
    </article>
  </section>
</template>
