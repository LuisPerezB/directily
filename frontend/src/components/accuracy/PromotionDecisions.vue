<script setup lang="ts">
/**
 * Decisiones de promoción de la ejecución (V2.5 · W3-B/D, ADR-V25-21): la política heredada (legacy) y la V2.5 se calculan SIEMPRE en paralelo por alcance
 * (global, clase de demanda y, a título informativo, horizonte). Una gobierna lo servido (por defecto la heredada: no cambia lo que se sirve); la otra queda
 * registrada como «sombra de decisión» para ver qué habría servido. Cada decisión trae sus gates con la evidencia que el motor registró. La aprobación humana
 * separada (W4) aparece como pendiente hasta que alguien la complete. Los umbrales son valores iniciales a aprobar en el piloto.
 */
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import { modelQualityApi } from '@/api'
import type { PromotionDecision, PromotionPolicy } from '@/api/types'
import { useAsync } from '@/composables/useAsync'
import { DASH } from '@/utils/format'
import { TONE_CLASS, decisionTone, gateTone, pairByScope } from '@/utils/modelQuality'

const props = defineProps<{ runId: number | null }>()
const { t, te } = useI18n()
const showInformational = ref(false)

const result = useAsync(
  async () => (props.runId === null ? null : modelQualityApi.promotionDecisions({ run_id: props.runId, per_page: 100 })),
  { watch: [() => props.runId] },
)
const summary = computed(() => result.data.value?.summary ?? null)
const pairs = computed(() => pairByScope(result.data.value?.data ?? []).filter((p) => showInformational.value || !p.informational))
const hasInformational = computed(() => pairByScope(result.data.value?.data ?? []).some((p) => p.informational))
const governing = computed<PromotionPolicy | null>(() => summary.value?.governing_policy ?? null)
const different = computed(() => summary.value?.agreement.different ?? [])
const compared = computed(() => summary.value?.agreement.scopes_compared ?? 0)

const family = (f: string | null | undefined): string => (f ? (te(`models.${f}`) ? t(`models.${f}`) : f) : DASH)
const scopeText = (p: { type: string; value: string | null }): string => {
  if (p.type === 'global') return t('promotion.scope.global')
  if (p.type === 'class') return t('promotion.scope.class', { name: p.value && te(`demandClass.${p.value}`) ? t(`demandClass.${p.value}`) : (p.value ?? DASH) })
  if (p.type === 'horizon') return t('promotion.scope.horizon', { n: p.value ?? DASH })
  return `${p.type}${p.value ? `:${p.value}` : ''}`
}
const gateNames = (d: PromotionDecision | null): string[] => Object.keys(d?.gates ?? {})
/** En la política heredada un gate que no se cumple solo significa que LightGBM no reemplaza al mejor estadístico: se muestra como aviso, no como error. */
const gateToneFor = (d: PromotionDecision, status: string) => (d.policy === 'legacy' && status === 'fail' ? 'warning' : gateTone(status))
const gateLabel = (name: string): string => (te(`promotion.gates.${name}`) ? t(`promotion.gates.${name}`) : name)
</script>

<template>
  <section class="flex flex-col gap-4" :aria-label="t('promotion.title')" data-test="promotion-decisions">
    <LoadingState v-if="result.loading.value && !result.data.value" :lines="3" />
    <ErrorState v-else-if="result.error.value" :error="result.error.value" compact @retry="result.execute" />
    <EmptyState v-else-if="props.runId === null || !result.data.value?.data.length" icon="layers" :title="t('promotion.emptyTitle')" :description="t('promotion.emptyText')" data-test="promotion-empty" />
    <template v-else>
      <div v-if="summary" class="rounded-xl border border-line p-4 flex flex-col gap-2" data-test="promotion-governing">
        <p class="m-0 text-sm">
          <span class="text-fg-muted">{{ t('promotion.governedBy') }}</span>
          <span v-if="governing" class="badge badge-brand ml-2" :data-policy="governing">{{ t(`promotion.policies.${governing}`) }}</span>
          <span v-else class="badge ml-2">{{ t('promotion.unknownPolicy') }}</span>
        </p>
        <p v-if="governing" class="text-sm m-0">{{ t(`promotion.whyGoverned.${governing}`) }}</p>
        <p class="text-sm m-0" data-test="promotion-agreement">
          <template v-if="compared === 0">{{ t('promotion.agreementNone') }}</template>
          <template v-else-if="!different.length">{{ t('promotion.agreementAll', { n: compared }) }}</template>
          <template v-else>{{ t('promotion.agreementSome', { n: different.length, total: compared }) }}</template>
        </p>
        <ul v-if="different.length" class="m-0 pl-5 text-sm list-disc" data-test="promotion-different">
          <li v-for="d in different" :key="d.scope_key">{{ t('promotion.differentItem', { scope: d.scope_key, legacy: family(d.legacy_served_family), v25: family(d.v25_served_family) }) }}</li>
        </ul>
        <p class="hint m-0">{{ t('promotion.pilotNote') }}</p>
      </div>

      <label v-if="hasInformational" class="inline-flex items-center gap-2 text-sm"><input v-model="showInformational" type="checkbox" /> {{ t('promotion.showHorizons') }}</label>

      <div class="table-wrap">
        <table class="table">
          <caption class="sr-only">{{ t('promotion.title') }}</caption>
          <thead>
            <tr>
              <th scope="col">{{ t('promotion.columns.scope') }}</th>
              <th scope="col">{{ t('promotion.policies.legacy') }}</th>
              <th scope="col">{{ t('promotion.policies.v25') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="pair in pairs" :key="pair.scope_key" :data-scope="pair.scope_key" class="align-top">
              <th scope="row" class="font-medium text-left">
                {{ scopeText((pair.legacy ?? pair.v25)!.scope) }}
                <span v-if="pair.informational" class="badge ml-1" :title="t('promotion.informationalHint')">{{ t('promotion.informational') }}</span>
              </th>
              <td v-for="(d, col) in [pair.legacy, pair.v25]" :key="col" :data-policy="col === 0 ? 'legacy' : 'v25'" :data-effective="d?.is_effective ? 'true' : 'false'">
                <template v-if="d">
                  <p class="m-0 flex flex-wrap items-center gap-1">
                    <span class="badge" :class="TONE_CLASS[decisionTone(d.decision)]" data-test="decision">{{ t(`promotion.decision.${d.decision}`) }}</span>
                    <span v-if="d.is_effective" class="badge badge-brand" data-test="effective">{{ t('promotion.governs') }}</span>
                    <span v-else class="badge" data-test="shadow-policy">{{ t('promotion.shadowPolicy') }}</span>
                  </p>
                  <p class="m-0 mt-1 text-sm">{{ t('promotion.serves') }}: <strong data-test="served">{{ family(d.served_family) }}</strong></p>
                  <p v-if="d.fallback_applied" class="m-0 text-xs text-warning" data-test="fallback">{{ t('promotion.fallback', { planned: family(d.planned_family) }) }}</p>
                  <p class="m-0 mt-1 text-xs text-fg-muted">{{ d.reason }}</p>
                  <details v-if="gateNames(d).length" class="mt-1 text-sm">
                    <summary class="cursor-pointer text-fg-muted hover:text-fg">{{ t('promotion.gatesSummary', { pass: d.gates_summary.pass, fail: d.gates_summary.fail, other: d.gates_summary.not_evaluable + d.gates_summary.not_applicable + d.gates_summary.pending }) }}</summary>
                    <p v-if="d.policy === 'legacy'" class="m-0 mt-1 text-xs text-fg-muted" data-test="legacy-gates-note">{{ t('promotion.legacyGatesNote') }}</p>
                    <ul class="list-none p-0 m-0 mt-1 flex flex-col gap-1" data-test="gates">
                      <li v-for="name in gateNames(d)" :key="name" :data-gate="name" :data-status="d.gates[name]!.status">
                        <span class="badge mr-1" :class="TONE_CLASS[gateToneFor(d, d.gates[name]!.status)]">{{ t(`promotion.gateStatus.${d.gates[name]!.status}`) }}</span>
                        <span class="font-medium">{{ gateLabel(name) }}</span>
                        <span class="block text-xs text-fg-muted">{{ d.gates[name]!.message }}</span>
                      </li>
                    </ul>
                  </details>
                  <p v-if="d.policy === 'v25' && d.approval.status === 'pending'" class="m-0 mt-1 text-xs text-fg-muted" data-test="approval-pending">{{ t('promotion.approvalPending') }}</p>
                  <p v-else-if="d.policy === 'v25'" class="m-0 mt-1 text-xs text-success" data-test="approval-done">{{ t('promotion.approved') }}</p>
                </template>
                <span v-else class="text-fg-muted">{{ DASH }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </section>
</template>
