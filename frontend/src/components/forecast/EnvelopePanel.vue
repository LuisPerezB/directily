<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import QualityBadge from '@/components/ui/QualityBadge.vue'
import type { ResultEnvelope } from '@/api/types'
import { envelopeView, shortHash } from '@/utils/envelope'
import { formatDate } from '@/utils/format'

/**
 * Trazabilidad de un resultado (envoltura común ADR-V2-03): aptitud estadística, origen de la incertidumbre,
 * limitaciones y supuestos SIEMPRE visibles, más la huella y la configuración con que se calculó.
 */
const props = withDefaults(defineProps<{ envelope: ResultEnvelope | null | undefined; title?: string | null; compact?: boolean }>(), { title: null, compact: false })
const { t, te } = useI18n()
const view = computed(() => envelopeView(props.envelope))
const uncertaintyText = computed(() => {
  const source = view.value?.uncertainty
  return source && te(`envelope.uncertainty.${source}`) ? t(`envelope.uncertainty.${source}`) : t('envelope.uncertainty.unknown')
})
const resultTypeText = computed(() => {
  const type = view.value?.resultType
  return type && te(`envelope.resultTypes.${type}`) ? t(`envelope.resultTypes.${type}`) : type
})
</script>

<template>
  <section v-if="view" class="rounded-xl border border-line p-4 flex flex-col gap-3" :aria-label="title ?? t('envelope.title')" data-test="envelope-panel">
    <div class="flex flex-wrap items-center gap-2">
      <h3 class="text-sm font-semibold m-0">{{ title ?? t('envelope.title') }}</h3>
      <QualityBadge :status="view.quality" />
      <span v-if="resultTypeText" class="badge">{{ resultTypeText }}</span>
    </div>
    <p v-if="view.abstains" class="text-sm m-0" data-test="envelope-abstention">{{ t(`envelope.abstention.${view.quality}`) }}</p>
    <p class="text-sm text-fg-muted m-0" data-test="envelope-uncertainty">{{ t('envelope.uncertaintyLabel') }}: {{ uncertaintyText }}</p>

    <div>
      <h4 class="text-xs font-semibold uppercase tracking-wide text-fg-muted m-0 mb-1">{{ t('envelope.limitations') }}</h4>
      <ul v-if="view.limitations.length" class="list-disc pl-5 m-0 text-sm flex flex-col gap-0.5" data-test="envelope-limitations">
        <li v-for="(l, i) in view.limitations" :key="i">{{ l }}</li>
      </ul>
      <p v-else class="text-sm text-fg-muted m-0">{{ t('envelope.noLimitations') }}</p>
    </div>
    <div>
      <h4 class="text-xs font-semibold uppercase tracking-wide text-fg-muted m-0 mb-1">{{ t('envelope.assumptions') }}</h4>
      <ul v-if="view.assumptions.length" class="list-disc pl-5 m-0 text-sm flex flex-col gap-0.5" data-test="envelope-assumptions">
        <li v-for="(a, i) in view.assumptions" :key="i">{{ a }}</li>
      </ul>
      <p v-else class="text-sm text-fg-muted m-0">{{ t('envelope.noAssumptions') }}</p>
    </div>

    <dl v-if="!compact" class="grid gap-x-6 gap-y-1 sm:grid-cols-2 lg:grid-cols-4 text-sm border-t border-line pt-3 m-0">
      <div v-if="view.asOf"><dt class="text-fg-muted">{{ t('envelope.asOf') }}</dt><dd class="m-0 font-semibold">{{ formatDate(view.asOf) }}</dd></div>
      <div v-if="view.horizonStart && view.horizonEnd"><dt class="text-fg-muted">{{ t('envelope.horizon') }}</dt><dd class="m-0 font-semibold">{{ formatDate(view.horizonStart, 'short') }} – {{ formatDate(view.horizonEnd, 'short') }}</dd></div>
      <div v-if="view.configVersion"><dt class="text-fg-muted">{{ t('envelope.configVersion') }}</dt><dd class="m-0 font-semibold font-mono text-xs" :title="view.configVersion">{{ shortHash(view.configVersion) }}</dd></div>
      <div v-if="view.snapshotId"><dt class="text-fg-muted">{{ t('envelope.snapshot') }}</dt><dd class="m-0 font-semibold tabular">#{{ view.snapshotId }}</dd></div>
    </dl>
  </section>
</template>
