<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import type { ScenarioResult } from '@/api/types'
import { buildScenarioRows, type ScenarioRow } from '@/utils/scenario'
import { formatChange, formatCurrency, formatNumber, formatPercent } from '@/utils/format'

/** Presentación de resultados del simulador: base vs escenario, deltas, presupuesto y riesgos. */
const props = defineProps<{ result: ScenarioResult; currency: string }>()
const { t } = useI18n()
const rows = computed(() => buildScenarioRows(props.result))

function fmt(row: ScenarioRow, value: number | null): string {
  if (row.kind === 'money') return formatCurrency(value, props.currency, 0)
  if (row.kind === 'pct') return formatPercent(value)
  return formatNumber(value, 0)
}
function fmtDelta(row: ScenarioRow): string {
  if (row.delta === null) return '—'
  if (row.kind === 'pct') return `${row.delta > 0 ? '+' : row.delta < 0 ? '−' : ''}${formatNumber(Math.abs(row.delta) * 100, 1)} pp`
  const sign = row.delta > 0 ? '+' : row.delta < 0 ? '−' : ''
  const abs = Math.abs(row.delta)
  return `${sign}${row.kind === 'money' ? formatCurrency(abs, props.currency, 0) : formatNumber(abs, 0)}`
}
function tone(row: ScenarioRow): string {
  if (row.delta === null || Math.abs(row.delta) < 1e-9) return 'text-fg-muted'
  const higherIsBetter = row.key !== 'cost' && row.key !== 'purchase_need_cost' && row.key !== 'purchase_need_units'
  return row.delta > 0 === higherIsBetter ? 'text-success' : 'text-danger'
}
const riskKind = (sev: string) => (sev === 'critical' ? 'danger' : sev === 'info' ? 'info' : 'warning')
</script>

<template>
  <div class="flex flex-col gap-4" data-testid="scenario-results">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h2 class="section-title">{{ t('scenarios.resultsTitle') }}</h2>
      <span class="badge badge-warning">{{ t('scenarios.simulationBadge') }}</span>
    </div>
    <p class="text-sm rounded-xl bg-warning-soft px-4 py-3" data-testid="scenario-disclaimer" role="note">{{ result.disclaimer || t('scenarios.disclaimer') }}</p>

    <div class="table-wrap">
      <table class="table">
        <caption class="sr-only">{{ t('scenarios.resultsTitle') }}</caption>
        <thead>
          <tr>
            <th scope="col">{{ t('scenarios.indicator') }}</th>
            <th scope="col" class="num">{{ t('scenarios.base') }}</th>
            <th scope="col" class="num">{{ t('scenarios.scenario') }}</th>
            <th scope="col" class="num">{{ t('scenarios.difference') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in rows" :key="row.key">
            <th scope="row" class="!normal-case !tracking-normal !text-sm !font-medium !text-fg">{{ t(`scenarios.rows.${row.key}`) }}</th>
            <td class="num">{{ fmt(row, row.base) }}</td>
            <td class="num font-semibold">{{ fmt(row, row.scenario) }}</td>
            <td class="num font-semibold" :class="tone(row)" :data-testid="`delta-${row.key}`">
              {{ fmtDelta(row) }}
              <span v-if="row.deltaPct !== null" class="block text-xs font-normal text-fg-muted">{{ formatChange(row.deltaPct) }}</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="result.budget && result.budget.purchase_budget != null" class="rounded-xl border border-line p-4 text-sm">
      <p class="font-semibold mb-1">{{ t('scenarios.budgetTitle') }}</p>
      <p class="m-0">
        {{ t('scenarios.budgetCoverage', { budget: formatCurrency(result.budget.purchase_budget, currency, 0), pct: formatPercent(result.budget.coverage_pct, 0) }) }}
        <template v-if="(result.budget.shortfall ?? 0) > 0"> {{ t('scenarios.budgetShortfall', { value: formatCurrency(result.budget.shortfall, currency, 0) }) }}</template>
      </p>
    </div>

    <div v-if="result.risks.length" class="flex flex-col gap-2">
      <p class="font-semibold text-sm m-0">{{ t('scenarios.risks') }}</p>
      <InlineNotice v-for="(r, i) in result.risks" :key="i" :kind="riskKind(r.severity)">{{ r.message }}</InlineNotice>
    </div>
    <p v-if="result.base_run" class="hint">{{ t('scenarios.baseRun', { version: result.base_run.version }) }}</p>
  </div>
</template>
