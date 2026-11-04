<script setup lang="ts">
/**
 * Resultados medidos (GET /decisions/outcomes): también las rechazadas, no contactadas y caducadas. La contribución `null` (costo no confiable) se dice «no estimada»
 * con su motivo; un 0 solo aparece si no hubo compra. `observed_*` es la ventana COMPLETA (con o sin acción); lo posterior a la acción registrada, neto de las
 * devoluciones posteriores, va aparte (`after_action`) y es lo único asociable a la acción. Una fila es `observed` o `temporally_associated`, nunca incremental: el
 * incremento es del análisis por experimento. La clasificación explica lo que SÍ y NO afirma. No muestra el grupo del experimento.
 */
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import DataTable, { type Column } from '@/components/ui/DataTable.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import MoneyFigure from '@/components/enterprise/MoneyFigure.vue'
import { outcomeApi } from '@/api'
import type { DecisionOutcome, OutcomeQuery } from '@/api/types'
import { useAbility } from '@/composables/useAbility'
import { useAsync } from '@/composables/useAsync'
import { useTenantStore } from '@/stores/tenant'
import { formatDate, formatNumber } from '@/utils/format'
import { shortHash } from '@/utils/envelope'
import { ACTION_KINDS, ROW_CLASSIFICATIONS, actionLabelKey, classificationTone } from '@/utils/outcomes'

const props = defineProps<{ filters: Pick<OutcomeQuery, 'kind' | 'horizon_days' | 'from' | 'to'>; currency: string }>()
const { t, te } = useI18n()
const { can } = useAbility()
const tenant = useTenantStore()

const classification = ref('')
const action = ref('')
const sort = ref<NonNullable<OutcomeQuery['sort']>>('-measured_at')
const page = ref(1)
watch([() => props.filters, classification, action, sort], () => (page.value = 1), { deep: true })

// Las filas de control solo las envía la API a quien aprueba; el resto nunca las ve ni puede filtrarlas.
const actions = computed(() => ACTION_KINDS.filter((a) => a !== 'control' || canApprove.value))
const query = computed<OutcomeQuery>(() => ({ ...props.filters, classification: classification.value || undefined, action_kind: action.value || undefined, sort: sort.value, page: page.value, per_page: 15 }))
const list = useAsync(() => outcomeApi.list(query.value), { watch: [query], scope: () => tenant.currentUuid })
const rows = computed(() => list.data.value?.data ?? [])

const columns = computed<Column<DecisionOutcome>[]>(() => [
  { key: 'recommendation', label: t('roi.outcomes.cols.recommendation') },
  { key: 'window', label: t('roi.outcomes.cols.window') },
  { key: 'action', label: t('roi.outcomes.cols.action') },
  { key: 'observed', label: t('roi.outcomes.cols.observed') },
  { key: 'contribution', label: t('roi.outcomes.cols.contribution'), align: 'right' },
  { key: 'contact_cost', label: t('roi.outcomes.cols.contactCost'), align: 'right' },
  { key: 'classification', label: t('roi.outcomes.cols.classification') },
])
function lateLines(row: DecisionOutcome): number | null {
  const n = row.evidence?.source?.late_lines_not_counted
  return typeof n === 'number' ? n : null
}
function lateReturnedUnits(row: DecisionOutcome): number | null {
  const n = row.evidence?.source?.late_returned_units_not_counted
  return typeof n === 'number' && n > 0 ? n : null
}
// Devoluciones emitidas después de cerrar la ventana pero conocidas al corte: ya se restaron del neto; se dice para que la cifra no parezca incompleta.
function returnsAfterWindow(row: DecisionOutcome): { units: number; lines: number } | null {
  const r = row.evidence?.observation?.returns_after_window
  return r && typeof r.units === 'number' && r.units > 0 ? { units: r.units, lines: typeof r.lines === 'number' ? r.lines : 0 } : null
}
function fingerprint(row: DecisionOutcome): string | null {
  const v = row.evidence?.source?.fingerprint
  return typeof v === 'string' ? shortHash(v, 12) : null
}
// El texto de la API explica la clasificación con el detalle del experimento (p. ej. «pertenece a un experimento con grupo de control»): solo se muestra a quien
// aprueba. El resto lee un texto propio que no habla del experimento.
const canApprove = computed(() => can('decisions.approve'))
function classificationNote(row: DecisionOutcome): string {
  if (canApprove.value && row.classification_note) return row.classification_note
  return te(`roi.classification.notes.${row.classification}`) ? t(`roi.classification.notes.${row.classification}`) : ''
}
</script>

<template>
  <section class="flex flex-col gap-4" :aria-label="t('roi.outcomes.title')" data-test="outcomes">
    <div class="flex flex-wrap items-end gap-4">
      <h2 class="section-title m-0 mr-auto">{{ t('roi.outcomes.title') }}</h2>
      <div>
        <label class="label" for="oc-class">{{ t('roi.outcomes.cols.classification') }}</label>
        <select id="oc-class" v-model="classification" class="select">
          <option value="">{{ t('radar.filters.all') }}</option>
          <option v-for="c in ROW_CLASSIFICATIONS" :key="c" :value="c">{{ t(`roi.classification.${c}`) }}</option>
        </select>
      </div>
      <div>
        <label class="label" for="oc-action">{{ t('roi.outcomes.cols.action') }}</label>
        <select id="oc-action" v-model="action" class="select">
          <option value="">{{ t('radar.filters.all') }}</option>
          <option v-for="a in actions" :key="a" :value="a">{{ t(actionLabelKey(a)) }}</option>
        </select>
      </div>
      <div>
        <label class="label" for="oc-sort">{{ t('customers.orderBy') }}</label>
        <select id="oc-sort" v-model="sort" class="select">
          <option v-for="s in ['-measured_at', '-window_to', '-observed_net_amount', '-contribution']" :key="s" :value="s">{{ t(`roi.outcomes.sort.${s.replace('-', 'desc_')}`) }}</option>
        </select>
      </div>
    </div>
    <p class="m-0 text-sm text-fg-muted">{{ t('roi.outcomes.notMeasuredYet') }}</p>

    <ErrorState v-if="list.error.value" :error="list.error.value" compact @retry="list.execute" />
    <template v-else>
      <DataTable :columns="columns" :rows="rows" :row-key="(r) => r.id" :loading="list.loading.value" :caption="t('roi.outcomes.title')" :empty-text="t('roi.outcomes.empty')">
        <template #cell-recommendation="{ row }">
          <RouterLink :to="{ name: 'decision-center', params: { id: String(row.recommendation.id) } }" class="font-medium" data-test="rec-link">#{{ row.recommendation.id }}</RouterLink>
          <span v-if="row.recommendation.kind" class="block text-xs text-fg-muted">{{ t(`decisions.kind.${row.recommendation.kind}`) }}</span>
          <span class="block text-xs text-fg-muted">{{ [row.recommendation.customer?.external_code, row.recommendation.product?.name].filter(Boolean).join(' · ') || '—' }}</span>
        </template>
        <template #cell-window="{ row }">
          {{ formatDate(row.window.from, 'short') }} – {{ formatDate(row.window.to, 'short') }}
          <span v-if="row.window.knowledge_cutoff" class="block text-xs text-fg-muted">{{ t('roi.outcomes.knownUntil', { date: formatDate(row.window.knowledge_cutoff) }) }}</span>
        </template>
        <template #cell-action="{ row }">
          {{ te(actionLabelKey(row.action_kind)) ? t(actionLabelKey(row.action_kind)) : row.action_kind }}
          <span v-if="row.action_at" class="block text-xs text-fg-muted">{{ formatDate(row.action_at) }}</span>
        </template>
        <template #cell-observed="{ row }">
          {{ te(`roi.events.${row.observed_event}`) ? t(`roi.events.${row.observed_event}`) : row.observed_event }}
          <span v-if="row.observed_units !== null" class="block text-xs text-fg-muted">{{ t('roi.outcomes.units', { n: formatNumber(row.observed_units, 1) }) }}</span>
          <span v-if="row.observed_net_amount !== null" class="block text-xs text-fg-muted"><MoneyFigure :value="row.observed_net_amount" :currency="currency" /></span>
          <span v-if="row.observed_units !== null" class="block text-xs text-fg-muted">{{ t('roi.outcomes.wholeWindow') }}</span>
          <div v-if="row.after_action" class="mt-1 rounded-md bg-surface-2 p-2 text-xs" data-test="after-action">
            <p class="m-0 font-semibold">{{ t('roi.outcomes.afterAction') }}</p>
            <p v-if="row.after_action.contribution_status === 'not_applicable_no_purchase' || !(row.after_action.units && row.after_action.units > 0)" class="m-0 text-fg-muted" data-test="after-action-none">{{ t('roi.outcomes.noPurchaseAfterAction') }}</p>
            <template v-else>
              <p class="m-0 text-fg-muted">{{ t('roi.outcomes.units', { n: formatNumber(row.after_action.units, 1) }) }} · <MoneyFigure :value="row.after_action.net_amount" :currency="currency" /></p>
              <p class="m-0 text-fg-muted" data-test="after-action-contribution">{{ t('roi.columns.contributionKnown') }}: <MoneyFigure :value="row.after_action.contribution" :currency="currency" :reasons="row.after_action.contribution_reasons" :unknown-label="t('enterprise.unreliableCost')" /></p>
            </template>
          </div>
        </template>
        <template #cell-contribution="{ row }">
          <span v-if="row.contribution_status === 'not_applicable_no_purchase'" class="text-fg-muted text-sm" data-test="no-purchase">{{ t('roi.outcomes.noPurchase') }}</span>
          <MoneyFigure v-else :value="row.contribution" :currency="currency" :reasons="row.contribution_reasons" :unknown-label="t('enterprise.unreliableCost')" />
        </template>
        <template #cell-contact_cost="{ row }"><MoneyFigure :value="row.contact_cost" :currency="currency" :unknown-label="t('roi.outcomes.costNotConfigured')" /></template>
        <template #cell-classification="{ row }">
          <span class="badge" :class="{ neutral: '', info: 'badge-info', warning: 'badge-warning', success: 'badge-success' }[classificationTone(row.classification)]" data-test="classification">{{ t(`roi.classification.${row.classification}`) }}</span>
          <details class="mt-1 text-xs">
            <summary class="cursor-pointer text-fg-muted">{{ t('roi.outcomes.evidence') }}</summary>
            <p class="m-0 mt-1" data-test="classification-note">{{ classificationNote(row) }}</p>
            <p v-if="fingerprint(row)" class="m-0 text-fg-muted">{{ t('roi.outcomes.fingerprint', { hash: fingerprint(row) }) }}</p>
            <p v-if="lateLines(row)" class="m-0 text-fg-muted">{{ t('roi.outcomes.lateLines', { n: lateLines(row) }) }}</p>
            <p v-if="lateReturnedUnits(row)" class="m-0 text-fg-muted" data-test="late-returned">{{ t('roi.outcomes.lateReturned', { n: formatNumber(lateReturnedUnits(row), 1) }) }}</p>
            <p v-if="returnsAfterWindow(row)" class="m-0 text-fg-muted" data-test="returns-after-window">{{ t('roi.outcomes.returnsAfterWindow', { units: formatNumber(returnsAfterWindow(row)?.units ?? 0, 1), lines: returnsAfterWindow(row)?.lines ?? 0 }) }}</p>
          </details>
        </template>
      </DataTable>
      <PaginationBar v-model:page="page" :meta="list.data.value?.meta ?? null" />
    </template>
  </section>
</template>
