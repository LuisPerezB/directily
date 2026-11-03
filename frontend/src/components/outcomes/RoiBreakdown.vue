<script setup lang="ts">
/**
 * Desglose por tipo, horizonte, segmento o responsable. Cada columna es de un tipo de valor distinto; no hay total que las combine, y las filas del desglose
 * TAMPOCO son aditivas entre sí: cada fila se deduplica por línea de venta dentro de su grupo, pero una misma venta puede aparecer en dos grupos (p. ej. en el
 * horizonte de 30 y en el de 60 días). La oportunidad potencial no va aquí: se informa por tipo y horizonte en las columnas de valor. Una cifra parcial (pares
 * cliente × producto sin costo confiable) lo dice debajo de la cifra.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import MoneyFigure from '@/components/enterprise/MoneyFigure.vue'
import type { OutcomeRoiReport, RoiBreakdownRow } from '@/api/types'
import { formatInteger } from '@/utils/format'
import { knownContribution } from '@/utils/outcomes'

const props = defineProps<{ breakdown: OutcomeRoiReport['breakdown']; currency: string }>()
const { t, te } = useI18n()
function label(row: RoiBreakdownRow): string {
  return props.breakdown.group_by === 'kind' && te(`decisions.kind.${row.key}`) ? t(`decisions.kind.${row.key}`) : row.label
}
// La contribución conocida de un grupo es "sin datos" cuando hay compras y ningún par cliente × producto tenía costo confiable (la API suma 0 en ese caso).
function observed(row: RoiBreakdownRow): number | null {
  const o = row.observed
  return o ? knownContribution(o.units, o.contribution_known, o.pairs_with_unknown_contribution) : null
}
function associated(row: RoiBreakdownRow): number | null {
  const a = row.associated
  return a ? knownContribution(a.units, a.contribution_known, a.pairs_with_unknown_contribution) : null
}
const rows = computed(() => props.breakdown.rows)
</script>

<template>
  <div class="flex flex-col gap-2">
    <p class="m-0 text-xs text-fg-muted" data-test="breakdown-not-additive">{{ t('roi.breakdown.notAdditive') }}</p>
    <div class="table-wrap" tabindex="0" role="region" :aria-label="t(`roi.breakdown.groupBy.${breakdown.group_by}`)" data-test="breakdown">
      <table class="table">
        <caption class="sr-only">{{ t(`roi.breakdown.groupBy.${breakdown.group_by}`) }}</caption>
        <thead>
          <tr>
            <th scope="col">{{ t(`roi.breakdown.groupBy.${breakdown.group_by}`) }}</th>
            <th scope="col" class="num">{{ t('roi.funnel.identified') }}</th>
            <th scope="col" class="num">{{ t('roi.funnel.attended') }}</th>
            <th scope="col" class="num">{{ t('roi.funnel.measured') }}</th>
            <th scope="col" class="num">{{ t('roi.columns.observedShort') }}</th>
            <th scope="col" class="num">{{ t('roi.columns.associatedShort') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!rows.length"><td colspan="6" class="text-center text-fg-muted py-6">{{ t('roi.breakdown.empty') }}</td></tr>
          <tr v-for="row in rows" :key="row.key" data-test="breakdown-row">
            <th scope="row" class="font-medium">{{ label(row) }}</th>
            <td class="num tabular">{{ formatInteger(row.funnel?.identified ?? 0) }}</td>
            <td class="num tabular">{{ formatInteger(row.funnel?.attended ?? 0) }}</td>
            <td class="num tabular">{{ formatInteger(row.funnel?.measured ?? 0) }}</td>
            <td class="num" data-test="breakdown-observed">
              <MoneyFigure :value="observed(row)" :currency="currency" />
              <span v-if="(row.observed?.pairs_with_unknown_contribution ?? 0) > 0" class="block text-xs text-fg-muted" data-test="partial">{{ t('roi.breakdown.partial', { n: formatInteger(row.observed?.pairs_with_unknown_contribution ?? 0) }) }}</span>
            </td>
            <td class="num" data-test="breakdown-associated">
              <MoneyFigure :value="associated(row)" :currency="currency" />
              <span v-if="(row.associated?.pairs_with_unknown_contribution ?? 0) > 0" class="block text-xs text-fg-muted" data-test="partial">{{ t('roi.breakdown.partial', { n: formatInteger(row.associated?.pairs_with_unknown_contribution ?? 0) }) }}</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
