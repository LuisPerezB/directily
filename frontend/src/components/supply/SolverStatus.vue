<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import type { SolverStatus } from '@/api/types'
import { formatDecimal } from '@/utils/formatExtra'
import { formatGap, isProvenOptimal, solverTone } from '@/utils/supply'

/**
 * Estado del solver. La palabra «óptimo» solo aparece cuando la brecha está probada (`optimal`); cualquier otro estado
 * lo dice con sus palabras y muestra la brecha cuando se conoce.
 */
const props = defineProps<{ status: SolverStatus | string | null | undefined; gap?: number | null; seconds?: number | null; notes?: string[] }>()
const { t, te } = useI18n()
const tone = computed(() => solverTone(props.status))
const classes = computed(() => ({ success: 'badge-success', info: 'badge-info', warning: 'badge-warning', danger: 'badge-danger' })[tone.value])
const known = computed(() => !!props.status && te(`supply.solver.${props.status}.label`))
const kind = computed(() => (tone.value === 'danger' ? 'danger' : 'warning'))
</script>

<template>
  <div class="flex flex-col gap-2" data-test="solver-status" :data-status="status ?? 'unknown'">
    <div class="flex flex-wrap items-center gap-2">
      <span class="badge" :class="classes" data-test="solver-label">{{ known ? t(`supply.solver.${status}.label`) : t('supply.solver.unknown.label') }}</span>
      <span v-if="gap != null" class="text-sm text-fg-muted" data-test="solver-gap">{{ t('supply.solver.gap', { gap: formatGap(gap) }) }}</span>
      <span v-if="seconds != null" class="text-sm text-fg-muted">{{ t('supply.solver.seconds', { s: formatDecimal(seconds, 1) }) }}</span>
    </div>
    <p class="text-sm m-0">{{ known ? t(`supply.solver.${status}.text`) : t('supply.solver.unknown.text') }}</p>
    <InlineNotice v-if="!isProvenOptimal(status)" :kind="kind" data-test="solver-warning">{{ t('supply.solver.notProven') }}</InlineNotice>
    <ul v-if="notes?.length" class="list-disc pl-5 m-0 text-sm text-fg-muted"><li v-for="(n, i) in notes" :key="i">{{ n }}</li></ul>
  </div>
</template>
