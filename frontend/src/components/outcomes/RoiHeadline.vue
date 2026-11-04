<script setup lang="ts">
/**
 * ROI honesto (ADR-V25-14). NUNCA se presenta como probado: se muestra el estado (`roi.status`), su método y, solo con un experimento de control analizado por
 * intención de tratamiento, el incremento neto con su intervalo. Sin eso («potencial no probado») no hay cifra de ROI: solo el motivo. Si la empresa declaró
 * datos de demostración o simulados, el aviso de origen acompaña al ROI.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import MoneyFigure from '@/components/enterprise/MoneyFigure.vue'
import type { OutcomeRoiReport } from '@/api/types'
import { formatNumber, formatPercent } from '@/utils/format'
import { roiView } from '@/utils/outcomes'

const props = defineProps<{ report: OutcomeRoiReport }>()
const { t, te } = useI18n()
const view = computed(() => roiView(props.report.roi))
const currency = computed(() => props.report.currency)
const level = computed(() => props.report.roi.confidence_level ?? null)
const reasons = computed(() => view.value.reasons.map((r) => (te(`roi.reasons.${r}`) ? t(`roi.reasons.${r}`) : r)))
const noFigureKey = computed(() => {
  switch (view.value.status) {
    case 'incremental_conversion_only': return 'roi.headline.noFigureConversionOnly'
    case 'multiple_experiments': return 'roi.headline.noFigureMultiple'
    case 'incremental_estimated': return 'roi.headline.noFigureNet'
    default: return 'roi.headline.noFigure'
  }
})
</script>

<template>
  <section class="rounded-xl border border-line p-4 flex flex-col gap-3" :aria-label="t('roi.headline.title')" data-test="roi-headline" :data-status="view.status">
    <div class="flex flex-wrap items-center gap-2">
      <h2 class="section-title m-0">{{ t('roi.headline.title') }}</h2>
      <span class="badge badge-warning" data-test="roi-status">{{ t(`roi.status.${view.status}`) }}</span>
      <span class="badge" data-test="roi-unproven">{{ t('roi.headline.notProven') }}</span>
    </div>
    <InlineNotice v-if="view.originWarning" kind="warning" data-test="roi-origin-warning">{{ view.originWarning }}</InlineNotice>
    <p class="m-0 text-sm" data-test="roi-message">{{ view.message }}</p>
    <ul v-if="reasons.length" class="list-disc pl-5 m-0 text-sm text-fg-muted" data-test="roi-reasons"><li v-for="(r, i) in reasons" :key="i">{{ r }}</li></ul>

    <div v-if="view.conversion" class="flex flex-col gap-1" data-test="roi-conversion">
      <p class="m-0 text-sm font-semibold">{{ t('roi.headline.conversionTitle') }}</p>
      <p class="m-0 text-sm">
        {{ t('roi.itt.conversion', { treatment: formatPercent(view.conversion.rateTreatment, 1), control: formatPercent(view.conversion.rateControl, 1), diff: formatPercent(view.conversion.difference, 1), low: formatPercent(view.conversion.ciLow, 1), high: formatPercent(view.conversion.ciHigh, 1) }) }}
      </p>
      <p class="m-0 text-xs text-fg-muted">{{ t('roi.headline.conversionNote') }}</p>
    </div>
    <ul v-if="view.multiple.length" class="list-disc pl-5 m-0 text-sm" data-test="roi-multiple">
      <li v-for="e in view.multiple" :key="`${e.key}|${e.horizonDays}`">{{ e.horizonDays !== null ? t('roi.headline.multipleItem', { key: e.key, days: e.horizonDays }) : e.key }}</li>
    </ul>

    <template v-if="view.hasNetFigure && view.net">
      <dl class="grid gap-3 sm:grid-cols-3 m-0" data-test="roi-net">
        <div>
          <dt class="text-sm text-fg-muted">{{ t('roi.headline.net') }}</dt>
          <dd class="m-0 text-xl font-bold"><MoneyFigure :value="view.net.estimate" :currency="currency" /></dd>
          <dd class="m-0 text-xs text-fg-muted">{{ t('roi.headline.interval', { level: level !== null ? formatPercent(level, 0) : '', low: formatNumber(view.net.ciLow, 2), high: formatNumber(view.net.ciHigh, 2) }) }}</dd>
          <dd v-if="view.heavyTailed" class="m-0 text-xs text-warning" data-test="roi-heavy-tail">{{ t('roi.heavyTail') }}</dd>
        </div>
        <div v-if="view.ratio">
          <dt class="text-sm text-fg-muted">{{ t('roi.headline.ratio') }}</dt>
          <dd class="m-0 text-xl font-bold tabular" data-test="roi-ratio">{{ formatNumber(view.ratio.estimate, 2) }}</dd>
          <dd class="m-0 text-xs text-fg-muted">{{ t('roi.headline.ratioInterval', { low: formatNumber(view.ratio.ciLow, 2), high: formatNumber(view.ratio.ciHigh, 2) }) }}</dd>
        </div>
        <div>
          <dt class="text-sm text-fg-muted">{{ t('roi.headline.significance') }}</dt>
          <dd class="m-0 text-sm" data-test="roi-significance">{{ view.net.significant ? t('roi.headline.excludesZero') : t('roi.headline.includesZero') }}</dd>
        </div>
      </dl>
      <p class="m-0 text-xs text-fg-muted" data-test="roi-not-causal">{{ t('roi.headline.notCausal') }}</p>
    </template>
    <p v-else class="m-0 text-sm font-medium" data-test="roi-no-figure">{{ t(noFigureKey) }}</p>

    <details class="text-sm">
      <summary class="cursor-pointer font-semibold">{{ t('roi.method.title') }}</summary>
      <p class="m-0 mt-2" data-test="roi-method">{{ t('roi.method.id', { id: report.method.id, version: report.method.version }) }}</p>
      <p class="m-0 mt-1 text-fg-muted">{{ report.method.summary }}</p>
      <ul class="list-disc pl-5 mt-2 mb-0 text-fg-muted"><li v-for="(text, key) in report.method.estimators" :key="key">{{ text }}</li></ul>
    </details>
  </section>
</template>
